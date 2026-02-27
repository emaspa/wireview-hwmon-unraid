#!/bin/bash
#
# prepare-kernel.sh - Download Unraid release and prepare kernel headers
#                     for out-of-tree module compilation.
#
# Usage: prepare-kernel.sh <UNRAID_VERSION>
#
# 1. Downloads the Unraid release zip and extracts bzmodules to determine
#    the exact kernel version (e.g. 6.12.54-Unraid).
# 2. Downloads pre-configured kernel source from ich777/unraid_kernel
#    GitHub releases (includes the correct .config for the Unraid kernel).
# 3. Runs `make modules_prepare` to generate headers for module builds.
#
set -euo pipefail

UNRAID_VERSION="${1:?Usage: prepare-kernel.sh <UNRAID_VERSION>}"
CACHE_DIR="/cache"
WORK_DIR="/build/kernel"
VERSIONS_CONF="/src/build/unraid-versions.conf"

mkdir -p "$WORK_DIR" "$CACHE_DIR"

echo "=== Preparing kernel headers for Unraid ${UNRAID_VERSION} ==="

# ── Step 1: Download Unraid release and extract kernel version ──────────

ARCHIVE="unRAIDServer-${UNRAID_VERSION}-x86_64.zip"
UNRAID_ZIP="$CACHE_DIR/${ARCHIVE}"
if [ ! -f "$UNRAID_ZIP" ] || ! unzip -t "$UNRAID_ZIP" > /dev/null 2>&1; then
    rm -f "$UNRAID_ZIP"

    # Determine download URL:
    # 1. Use UNRAID_DOWNLOAD_URL env var if set
    # 2. Look up in versions config file
    # 3. Fail with instructions
    DL_URL="${UNRAID_DOWNLOAD_URL:-}"

    if [ -z "$DL_URL" ] && [ -f "$VERSIONS_CONF" ]; then
        DL_URL=$(grep "^${UNRAID_VERSION}|" "$VERSIONS_CONF" | cut -d'|' -f2)
    fi

    if [ -z "$DL_URL" ]; then
        echo "ERROR: No download URL known for Unraid ${UNRAID_VERSION}"
        echo ""
        echo "Options:"
        echo "  1. Add the URL to build/unraid-versions.conf"
        echo "  2. Set UNRAID_DOWNLOAD_URL env var when running the container"
        echo ""
        echo "You can find download URLs at https://unraid.net/download"
        exit 1
    fi

    echo "Downloading Unraid ${UNRAID_VERSION}..."
    wget -q --show-progress -O "$UNRAID_ZIP" "$DL_URL" || {
        echo "ERROR: Could not download Unraid ${UNRAID_VERSION}"
        echo "Tried: ${DL_URL}"
        rm -f "$UNRAID_ZIP"
        exit 1
    }
fi

# Verify it's actually a zip file
if ! unzip -t "$UNRAID_ZIP" > /dev/null 2>&1; then
    echo "ERROR: Downloaded file is not a valid zip archive"
    rm -f "$UNRAID_ZIP"
    exit 1
fi

echo "Download OK ($(du -h "$UNRAID_ZIP" | cut -f1))"

# Extract bzmodules from the zip to determine kernel version
echo "Extracting Unraid release..."
cd "$WORK_DIR"
unzip -o -j "$UNRAID_ZIP" "*/bzmodules" 2>/dev/null || \
unzip -o -j "$UNRAID_ZIP" "bzmodules" 2>/dev/null || {
    echo "Trying to extract all files and find bzmodules..."
    unzip -o "$UNRAID_ZIP" -d "$WORK_DIR/unraid-extract"
    found=$(find "$WORK_DIR/unraid-extract" -name "bzmodules" -type f | head -1)
    if [ -n "$found" ]; then
        cp "$found" "$WORK_DIR/bzmodules"
    fi
}

if [ ! -f "$WORK_DIR/bzmodules" ]; then
    echo "ERROR: Could not find bzmodules in Unraid release"
    exit 1
fi

# Extract kernel version from bzmodules (squashfs)
# Unraid 7.x layout: /modules/<kver>/  (not /lib/modules/<kver>/)
echo "Extracting kernel version from bzmodules..."
mkdir -p "$WORK_DIR/modules"
unsquashfs -f -n -d "$WORK_DIR/modules" "$WORK_DIR/bzmodules"

# Find kernel version directory — try both layouts
KVER=""
for modpath in "$WORK_DIR/modules/lib/modules" "$WORK_DIR/modules/modules"; do
    if [ -d "$modpath" ]; then
        KVER=$(ls "$modpath" 2>/dev/null | grep -E '^[0-9]+\.' | head -1)
        if [ -n "$KVER" ]; then
            break
        fi
    fi
done
if [ -z "$KVER" ]; then
    echo "ERROR: Could not determine kernel version from bzmodules"
    exit 1
fi
echo "Kernel version: $KVER"

# ── Step 2: Download pre-configured kernel source ───────────────────────
#
# ich777/unraid_kernel publishes kernel source tarballs with the correct
# .config already applied for each Unraid kernel version. This is far more
# reliable than extracting the config from bzroot (which Unraid doesn't
# include) or using defconfig (which produces an ABI-incompatible module).

KERNEL_TAR="$CACHE_DIR/linux-${KVER}.tar.xz"
ICH777_URL="https://github.com/ich777/unraid_kernel/releases/download/${KVER}/linux-${KVER}.tar.xz"

if [ ! -f "$KERNEL_TAR" ]; then
    echo "Downloading pre-configured kernel source for ${KVER}..."
    wget -q --show-progress -O "$KERNEL_TAR" "$ICH777_URL" || {
        echo ""
        echo "WARNING: Could not download pre-configured kernel source from:"
        echo "  $ICH777_URL"
        echo ""
        echo "This kernel version may not be available from ich777/unraid_kernel."
        echo "Falling back to kernel.org source with defconfig (module may not load)."
        echo ""
        rm -f "$KERNEL_TAR"
        KERNEL_TAR=""
    }
fi

if [ -n "$KERNEL_TAR" ] && [ -f "$KERNEL_TAR" ]; then
    # Use ich777's pre-configured kernel source
    # The tarball extracts to "." (no subdirectory), so create the target dir first
    KSRC="$WORK_DIR/linux-${KVER}"
    mkdir -p "$KSRC"
    echo "Extracting pre-configured kernel source..."
    tar xf "$KERNEL_TAR" -C "$KSRC"

    if [ ! -f "$KSRC/.config" ]; then
        echo "ERROR: Pre-configured kernel source has no .config — archive may be corrupt"
        exit 1
    fi
    echo "Using Unraid kernel config from pre-configured source"
else
    # Fallback: download from kernel.org + defconfig
    BASE_KVER=$(echo "$KVER" | sed 's/-.*$//')
    MAJOR_VER=$(echo "$BASE_KVER" | cut -d. -f1)

    KERNEL_TAR="$CACHE_DIR/linux-${BASE_KVER}.tar.xz"
    if [ ! -f "$KERNEL_TAR" ]; then
        echo "Downloading kernel source ${BASE_KVER} from kernel.org..."
        wget -q --show-progress -O "$KERNEL_TAR" \
            "https://cdn.kernel.org/pub/linux/kernel/v${MAJOR_VER}.x/linux-${BASE_KVER}.tar.xz"
    fi

    echo "Extracting kernel source..."
    cd "$WORK_DIR"
    tar xf "$KERNEL_TAR"
    KSRC="$WORK_DIR/linux-${BASE_KVER}"

    echo "WARNING: Using defconfig — kernel module may not load on Unraid"
    cd "$KSRC" && make defconfig
    sed -i "s/^CONFIG_LOCALVERSION=.*/CONFIG_LOCALVERSION=\"-Unraid\"/" .config 2>/dev/null || \
        echo 'CONFIG_LOCALVERSION="-Unraid"' >> .config
fi

# ── Step 3: Prepare kernel headers for out-of-tree module build ─────────

cd "$KSRC"

# Disable module signing if not available (common in cross-compile)
scripts/config --disable CONFIG_MODULE_SIG_ALL 2>/dev/null || true
scripts/config --set-str CONFIG_MODULE_SIG_KEY "" 2>/dev/null || true

echo "Preparing kernel headers..."
make olddefconfig
make modules_prepare

# Save kernel version and source path for the build script
echo "$KVER" > /build/KERNEL_VERSION
echo "$KSRC" > /build/KERNEL_SOURCE

echo "=== Kernel headers ready: ${KVER} (source: ${KSRC}) ==="

#!/bin/bash
#
# prepare-kernel.sh - Download Unraid release and prepare kernel headers
#                     for out-of-tree module compilation.
#
# Usage: prepare-kernel.sh <UNRAID_VERSION>
#
# 1. Determines the exact kernel version (e.g. 6.12.54-Unraid): from
#    build/unraid-versions.conf when listed there, otherwise by downloading
#    the Unraid release zip and reading bzmodules.
# 2. Downloads pre-configured kernel source from ich777/unraid_kernel
#    GitHub releases (includes the correct .config for the Unraid kernel).
#    Without it, falls back to kernel.org source plus the config shipped in
#    the release image, which then has to be downloaded after all.
# 3. Runs `make modules_prepare` to generate headers for module builds.
#
set -euo pipefail

UNRAID_VERSION="${1:?Usage: prepare-kernel.sh <UNRAID_VERSION>}"
CACHE_DIR="/cache"
WORK_DIR="/build/kernel"
VERSIONS_CONF="/src/build/unraid-versions.conf"

mkdir -p "$WORK_DIR" "$CACHE_DIR"

echo "=== Preparing kernel headers for Unraid ${UNRAID_VERSION} ==="

# ── Step 1: Kernel version ──────────────────────────────────────────────

ICH777_URL_FOR() { echo "https://github.com/ich777/unraid_kernel/releases/download/$1/linux-$1.tar.xz"; }

# Download the Unraid release image and read the kernel version from it.
# Sets KVER; leaves the extracted modules tree (with the shipped kernel
# config, when the release has one) under $WORK_DIR/modules.
extract_from_release() {

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

    # Fallback: extract version from bzimage if bzmodules layout changed
    if [ -z "$KVER" ]; then
        echo "Could not find kernel version in bzmodules, trying bzimage..."
        unzip -o -j "$UNRAID_ZIP" "*/bzimage" 2>/dev/null || \
        unzip -o -j "$UNRAID_ZIP" "bzimage" 2>/dev/null || true
        if [ -f "$WORK_DIR/bzimage" ]; then
            KVER=$(file "$WORK_DIR/bzimage" | grep -oP 'version \K[^ ]+')
        fi
    fi

    if [ -z "$KVER" ]; then
        echo "ERROR: Could not determine kernel version from bzmodules or bzimage"
        exit 1
    fi
}

KVER=""
if [ -f "$VERSIONS_CONF" ]; then
    KVER=$(grep "^${UNRAID_VERSION}|" "$VERSIONS_CONF" | cut -d'|' -f3)
fi

if [ -n "$KVER" ]; then
    echo "Kernel version from unraid-versions.conf: $KVER"
    # The shortcut needs the pre-configured source; fetch it now so a
    # missing tarball falls back to the release image below.
    KERNEL_TAR="$CACHE_DIR/linux-${KVER}.tar.xz"
    if [ ! -f "$KERNEL_TAR" ]; then
        echo "Downloading pre-configured kernel source for ${KVER}..."
        wget -q --show-progress -O "$KERNEL_TAR" "$(ICH777_URL_FOR "$KVER")" || {
            echo "No pre-configured kernel source for ${KVER}; using the release image instead."
            rm -f "$KERNEL_TAR"
            KVER=""
        }
    fi
fi

if [ -z "$KVER" ]; then
    extract_from_release
fi
echo "Kernel version: $KVER"

# ── Step 2: Download pre-configured kernel source ───────────────────────
#
# ich777/unraid_kernel publishes kernel source tarballs with the correct
# .config already applied for each Unraid kernel version. This is far more
# reliable than extracting the config from bzroot (which Unraid doesn't
# include) or using defconfig (which produces an ABI-incompatible module).

KERNEL_TAR="$CACHE_DIR/linux-${KVER}.tar.xz"
ICH777_URL=$(ICH777_URL_FOR "$KVER")

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
    # Fallback: kernel.org source + the real kernel config that Unraid ships
    # inside the release image (src/linux-<kver>/config in bzmodules, seen
    # since 7.3.2). A defconfig build produces an ABI-incompatible module
    # that the running kernel refuses to load, so if neither ich777's
    # pre-configured source nor the shipped config is available, fail the
    # build instead of packaging a module that cannot load.
    SHIPPED_CONFIG="$WORK_DIR/modules/src/linux-${KVER}/config"
    if [ ! -f "$SHIPPED_CONFIG" ]; then
        echo "ERROR: no pre-configured kernel source (ich777/unraid_kernel) and the"
        echo "Unraid release image does not ship src/linux-${KVER}/config."
        echo "Cannot produce a loadable module for ${KVER}."
        exit 1
    fi

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

    echo "Using the kernel config shipped in the Unraid release image"
    cp "$SHIPPED_CONFIG" "$KSRC/.config"
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

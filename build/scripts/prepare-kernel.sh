#!/bin/bash
#
# prepare-kernel.sh - Download Unraid release and prepare kernel headers
#                     for out-of-tree module compilation.
#
# Usage: prepare-kernel.sh <UNRAID_VERSION>
#
# Downloads the Unraid release zip, extracts bzmodules (squashfs) and bzroot
# (initramfs) to obtain the kernel version and configuration, then downloads
# the matching kernel source from kernel.org and prepares it for building.
#
set -euo pipefail

UNRAID_VERSION="${1:?Usage: prepare-kernel.sh <UNRAID_VERSION>}"
CACHE_DIR="/cache"
WORK_DIR="/build/kernel"
VERSIONS_CONF="/src/build/unraid-versions.conf"

mkdir -p "$WORK_DIR" "$CACHE_DIR"

echo "=== Preparing kernel headers for Unraid ${UNRAID_VERSION} ==="

# Download Unraid release zip
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

# Extract bzroot and bzmodules from the zip
echo "Extracting Unraid release..."
cd "$WORK_DIR"
# Files may be at top level or inside a subdirectory
unzip -o -j "$UNRAID_ZIP" "*/bzroot" "*/bzmodules" "*/bzimage" 2>/dev/null || \
unzip -o -j "$UNRAID_ZIP" "bzroot" "bzmodules" "bzimage" 2>/dev/null || {
    echo "Trying to extract all files and find bzroot/bzmodules..."
    unzip -o "$UNRAID_ZIP" -d "$WORK_DIR/unraid-extract"
    for f in bzroot bzmodules bzimage; do
        found=$(find "$WORK_DIR/unraid-extract" -name "$f" -type f | head -1)
        if [ -n "$found" ]; then
            cp "$found" "$WORK_DIR/$f"
        fi
    done
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
            MODULES_BASE="$modpath"
            break
        fi
    fi
done
if [ -z "$KVER" ]; then
    echo "ERROR: Could not determine kernel version from bzmodules"
    exit 1
fi
echo "Kernel version: $KVER"

# Extract base kernel version (e.g., "6.12.54" from "6.12.54-Unraid")
BASE_KVER=$(echo "$KVER" | sed 's/-.*$//')
MAJOR_VER=$(echo "$BASE_KVER" | cut -d. -f1)

# Extract kernel config from bzroot (initramfs)
# Unraid bzroot is a concatenated cpio archive (microroot + main root)
echo "Extracting kernel config from bzroot..."
mkdir -p "$WORK_DIR/initramfs"
cd "$WORK_DIR/initramfs"

# Try different decompression methods for the bzroot
if file "$WORK_DIR/bzroot" | grep -q "gzip"; then
    zcat "$WORK_DIR/bzroot" | cpio -id 2>/dev/null || true
elif file "$WORK_DIR/bzroot" | grep -q "XZ"; then
    xzcat "$WORK_DIR/bzroot" | cpio -id 2>/dev/null || true
elif file "$WORK_DIR/bzroot" | grep -q "cpio"; then
    cpio -id < "$WORK_DIR/bzroot" 2>/dev/null || true
else
    # Try all methods
    (zcat "$WORK_DIR/bzroot" 2>/dev/null || \
     xzcat "$WORK_DIR/bzroot" 2>/dev/null || \
     cat "$WORK_DIR/bzroot") | cpio -id 2>/dev/null || true
fi

# Look for kernel config in various locations
KCONFIG=""
for candidate in \
    "$WORK_DIR/initramfs/boot/config-${KVER}" \
    "$WORK_DIR/initramfs/etc/kernel/config-${KVER}" \
    "${MODULES_BASE}/${KVER}/build/.config" \
    "${MODULES_BASE}/${KVER}/config"; do
    if [ -f "$candidate" ]; then
        KCONFIG="$candidate"
        echo "Found kernel config: $candidate"
        break
    fi
done

# If no config found, try extracting from /proc/config.gz if present
if [ -z "$KCONFIG" ] && [ -f "$WORK_DIR/initramfs/proc/config.gz" ]; then
    zcat "$WORK_DIR/initramfs/proc/config.gz" > "$WORK_DIR/kernel.config"
    KCONFIG="$WORK_DIR/kernel.config"
    echo "Found kernel config: proc/config.gz"
fi

# Download kernel source
KERNEL_TAR="$CACHE_DIR/linux-${BASE_KVER}.tar.xz"
if [ ! -f "$KERNEL_TAR" ]; then
    echo "Downloading kernel source ${BASE_KVER}..."
    wget -q --show-progress -O "$KERNEL_TAR" \
        "https://cdn.kernel.org/pub/linux/kernel/v${MAJOR_VER}.x/linux-${BASE_KVER}.tar.xz"
fi

echo "Extracting kernel source..."
cd "$WORK_DIR"
tar xf "$KERNEL_TAR"
KSRC="$WORK_DIR/linux-${BASE_KVER}"

# Apply kernel config
if [ -n "$KCONFIG" ]; then
    echo "Applying Unraid kernel config..."
    cp "$KCONFIG" "$KSRC/.config"
else
    echo "WARNING: No kernel config found, using defconfig + Unraid local version"
    cd "$KSRC" && make defconfig
fi

# Set the local version to match Unraid
cd "$KSRC"
sed -i "s/^CONFIG_LOCALVERSION=.*/CONFIG_LOCALVERSION=\"-Unraid\"/" .config 2>/dev/null || \
    echo 'CONFIG_LOCALVERSION="-Unraid"' >> .config

# Disable module signing if not available (common in cross-compile)
scripts/config --disable CONFIG_MODULE_SIG_ALL 2>/dev/null || true
scripts/config --set-str CONFIG_MODULE_SIG_KEY "" 2>/dev/null || true

# Prepare kernel for out-of-tree module build
echo "Preparing kernel headers..."
make olddefconfig
make modules_prepare

# Save kernel version and source path for the build script
echo "$KVER" > /build/KERNEL_VERSION
echo "$KSRC" > /build/KERNEL_SOURCE

echo "=== Kernel headers ready: ${KVER} (source: ${KSRC}) ==="

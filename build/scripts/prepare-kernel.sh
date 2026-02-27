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

mkdir -p "$WORK_DIR" "$CACHE_DIR"

echo "=== Preparing kernel headers for Unraid ${UNRAID_VERSION} ==="

# Download Unraid release zip
UNRAID_ZIP="$CACHE_DIR/unRAIDServer-${UNRAID_VERSION}-x86_64.zip"
if [ ! -f "$UNRAID_ZIP" ]; then
    echo "Downloading Unraid ${UNRAID_VERSION}..."
    wget -q -O "$UNRAID_ZIP" \
        "https://releases.unraid.net/stable/unRAIDServer-${UNRAID_VERSION}-x86_64.zip" || \
    wget -q -O "$UNRAID_ZIP" \
        "https://releases.unraid.net/next/unRAIDServer-${UNRAID_VERSION}-x86_64.zip" || {
        echo "ERROR: Could not download Unraid ${UNRAID_VERSION}"
        exit 1
    }
fi

# Extract bzroot and bzmodules from the zip
echo "Extracting Unraid release..."
cd "$WORK_DIR"
unzip -o -j "$UNRAID_ZIP" "*/bzroot" "*/bzmodules" "*/bzimage" 2>/dev/null || \
unzip -o "$UNRAID_ZIP" "bzroot" "bzmodules" "bzimage" 2>/dev/null || {
    echo "ERROR: Could not extract bzroot/bzmodules from zip"
    exit 1
}

# Extract kernel version from bzmodules (squashfs containing /lib/modules/<kver>/)
echo "Extracting kernel version from bzmodules..."
mkdir -p "$WORK_DIR/modules"
unsquashfs -f -d "$WORK_DIR/modules" "$WORK_DIR/bzmodules" > /dev/null 2>&1

KVER=$(ls "$WORK_DIR/modules/lib/modules/" | head -1)
if [ -z "$KVER" ]; then
    echo "ERROR: Could not determine kernel version from bzmodules"
    exit 1
fi
echo "Kernel version: $KVER"

# Extract base kernel version (e.g., "6.12.54" from "6.12.54-Unraid")
BASE_KVER=$(echo "$KVER" | sed 's/-.*$//')
MAJOR_MINOR=$(echo "$BASE_KVER" | cut -d. -f1-2)

# Extract kernel config from bzroot (initramfs)
echo "Extracting kernel config from bzroot..."
mkdir -p "$WORK_DIR/initramfs"
cd "$WORK_DIR/initramfs"
# bzroot may be gzip or xz compressed cpio
(zcat "$WORK_DIR/bzroot" 2>/dev/null || xzcat "$WORK_DIR/bzroot" 2>/dev/null) | cpio -id 2>/dev/null || true

# Look for kernel config
KCONFIG=""
for candidate in \
    "$WORK_DIR/initramfs/boot/config-${KVER}" \
    "$WORK_DIR/initramfs/proc/config.gz" \
    "$WORK_DIR/modules/lib/modules/${KVER}/build/.config"; do
    if [ -f "$candidate" ]; then
        KCONFIG="$candidate"
        break
    fi
done

# Download kernel source
KERNEL_TAR="$CACHE_DIR/linux-${BASE_KVER}.tar.xz"
if [ ! -f "$KERNEL_TAR" ]; then
    echo "Downloading kernel source ${BASE_KVER}..."
    wget -q -O "$KERNEL_TAR" \
        "https://cdn.kernel.org/pub/linux/kernel/v${MAJOR_MINOR%%.*}.x/linux-${BASE_KVER}.tar.xz"
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
    echo "WARNING: No kernel config found, using default config"
    cd "$KSRC" && make defconfig
fi

# Set the local version to match Unraid
cd "$KSRC"
sed -i "s/^CONFIG_LOCALVERSION=.*/CONFIG_LOCALVERSION=\"-Unraid\"/" .config 2>/dev/null || \
    echo 'CONFIG_LOCALVERSION="-Unraid"' >> .config

# Prepare kernel for out-of-tree module build
echo "Preparing kernel headers..."
make olddefconfig
make modules_prepare

# Save kernel version and source path for the build script
echo "$KVER" > /build/KERNEL_VERSION
echo "$KSRC" > /build/KERNEL_SOURCE

echo "=== Kernel headers ready: ${KVER} (source: ${KSRC}) ==="

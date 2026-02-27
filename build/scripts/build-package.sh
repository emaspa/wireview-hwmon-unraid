#!/bin/bash
#
# build-package.sh - Build wireview-hwmon Unraid package (.txz)
#
# This is the Docker entrypoint. It:
# 1. Prepares kernel headers for the target Unraid version
# 2. Builds the kernel module against those headers
# 3. Builds the userspace tools (statically linked)
# 4. Assembles and creates the .txz Slackware package
#
# Usage: Run inside Docker container with:
#   -v /path/to/repo:/src     (repository root)
#   -v /path/to/output:/output (where .txz will be written)
#   -v /path/to/cache:/cache   (optional: cache downloads)
#   -e UNRAID_VERSION=7.2.3
#   -e PLUGIN_VERSION=2026.02.27
#
set -euo pipefail

UNRAID_VERSION="${UNRAID_VERSION:?UNRAID_VERSION must be set}"
PLUGIN_VERSION="${PLUGIN_VERSION:-1.0.0}"
SRC_DIR="/src"
OUTPUT_DIR="/output"
PKG_DIR="/build/pkg"

echo "======================================"
echo "Building wireview-hwmon for Unraid ${UNRAID_VERSION}"
echo "Plugin version: ${PLUGIN_VERSION}"
echo "======================================"

# Step 1: Prepare kernel headers
/build/scripts/prepare-kernel.sh "$UNRAID_VERSION"

KVER=$(cat /build/KERNEL_VERSION)
KSRC=$(cat /build/KERNEL_SOURCE)

echo ""
echo "=== Building kernel module ==="

# Step 2: Copy upstream source to writable location (kernel build writes to M= dir)
UPSTREAM="/build/upstream"
cp -a "${SRC_DIR}/upstream" "$UPSTREAM"

# KBUILD_MODPOST_WARN: modpost symbol warnings are expected when cross-compiling
# against kernel headers without a full kernel build. The symbols resolve at
# module load time on the actual Unraid system.
make -C "$KSRC" M="$UPSTREAM" KBUILD_MODPOST_WARN=1 modules
MODULE_FILE=$(find "$UPSTREAM" -name 'wireview_hwmon.ko' -o -name 'wireview_hwmon.ko.xz' | head -1)
if [ -z "$MODULE_FILE" ]; then
    echo "ERROR: Kernel module build failed"
    exit 1
fi
echo "Built: $MODULE_FILE"

echo ""
echo "=== Building userspace tools ==="

# Step 3: Build userspace tools (statically linked for portability across Unraid versions)
gcc -Wall -Wextra -Wno-format-truncation -O2 -static \
    -o /build/wireviewd "$UPSTREAM/wireviewd.c"
gcc -Wall -Wextra -O2 -static \
    -o /build/wireviewctl "$UPSTREAM/wireviewctl.c"

echo "Built: wireviewd, wireviewctl"

echo ""
echo "=== Assembling package ==="

# Step 4: Assemble package directory tree
rm -rf "$PKG_DIR"
mkdir -p "$PKG_DIR"

# Copy the src/ tree (emhttp pages, rc.d script, udev rules, slack-desc)
cp -a "$SRC_DIR/src/"* "$PKG_DIR/"

# Place built binaries
install -m 755 /build/wireviewd "$PKG_DIR/usr/local/bin/wireviewd"
install -m 755 /build/wireviewctl "$PKG_DIR/usr/local/bin/wireviewctl"

# Fix ownership and permissions (Unraid expects root:root, executable PHP/scripts)
chown -R root:root "$PKG_DIR"
chmod 755 "$PKG_DIR/etc/rc.d/rc.wireviewd"
chmod 755 "$PKG_DIR/usr/local/emhttp/plugins/wireview-hwmon/scripts/"*.sh 2>/dev/null || true
chmod 755 "$PKG_DIR/usr/local/emhttp/plugins/wireview-hwmon/include/"*.php 2>/dev/null || true

# Place kernel module
mkdir -p "$PKG_DIR/lib/modules/${KVER}/extra"
cp "$MODULE_FILE" "$PKG_DIR/lib/modules/${KVER}/extra/wireview_hwmon.ko"

# Step 5: Create .txz package
PKG_NAME="wireview-hwmon-${PLUGIN_VERSION}-x86_64-${KVER}.txz"
echo "Creating ${PKG_NAME}..."

cd "$PKG_DIR"
tar cJf "${OUTPUT_DIR}/${PKG_NAME}" .

echo ""
echo "=== Package created: ${OUTPUT_DIR}/${PKG_NAME} ==="
ls -lh "${OUTPUT_DIR}/${PKG_NAME}"

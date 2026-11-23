FROM ubuntu:24.04

ARG UNRAID_VERSION=7.2.3
# Ubuntu archive for apt, e.g. http://azure.archive.ubuntu.com/ubuntu on
# GitHub's runners. Empty keeps the image's own archive.
ARG UBUNTU_MIRROR=

RUN if [ -n "$UBUNTU_MIRROR" ]; then \
        sed -i -E "s#https?://(archive|security)\.ubuntu\.com/ubuntu/?#${UBUNTU_MIRROR%/}/#g" \
            /etc/apt/sources.list.d/ubuntu.sources; \
    fi \
    && apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    bc \
    bison \
    flex \
    libelf-dev \
    libssl-dev \
    cpio \
    wget \
    xz-utils \
    musl-tools \
    musl-dev \
    linux-libc-dev \
    squashfs-tools \
    ca-certificates \
    kmod \
    pkg-config \
    unzip \
    file \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build

# Download and extract Unraid release to get kernel modules/config
# Unraid releases contain bzroot (initramfs), bzmodules (squashfs), bzimage (kernel)
COPY scripts/prepare-kernel.sh /build/scripts/
RUN chmod +x /build/scripts/prepare-kernel.sh

COPY scripts/build-package.sh /build/scripts/
RUN chmod +x /build/scripts/build-package.sh

ENTRYPOINT ["/build/scripts/build-package.sh"]

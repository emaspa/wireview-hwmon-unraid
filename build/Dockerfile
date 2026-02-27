FROM ubuntu:24.04

ARG UNRAID_VERSION=7.2.3

RUN apt-get update && apt-get install -y --no-install-recommends \
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
    squashfs-tools \
    ca-certificates \
    kmod \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build

# Download and extract Unraid release to get kernel modules/config
# Unraid releases contain bzroot (initramfs), bzmodules (squashfs), bzimage (kernel)
COPY scripts/prepare-kernel.sh /build/scripts/
RUN chmod +x /build/scripts/prepare-kernel.sh

COPY scripts/build-package.sh /build/scripts/
RUN chmod +x /build/scripts/build-package.sh

ENTRYPOINT ["/build/scripts/build-package.sh"]

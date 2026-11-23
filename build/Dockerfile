FROM ubuntu:24.04

ARG UNRAID_VERSION=7.2.3
# Ubuntu archive for apt, e.g. http://azure.archive.ubuntu.com/ubuntu on
# GitHub's runners. Empty keeps the image's own archive. If the mirror fails,
# the install runs again from the image's own archive.
ARG UBUNTU_MIRROR=

RUN set -e; \
    src=/etc/apt/sources.list.d/ubuntu.sources; cp "$src" /tmp/ubuntu.sources.orig; \
    apt_install() { \
        apt-get -o Acquire::Retries=3 -o Acquire::http::Timeout=20 update \
        && apt-get -o Acquire::Retries=3 -o Acquire::http::Timeout=20 install -y --no-install-recommends \
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
            file; \
    }; \
    if [ -n "$UBUNTU_MIRROR" ]; then \
        sed -i -E "s#https?://(archive|security)\.ubuntu\.com/ubuntu/?#${UBUNTU_MIRROR%/}/#g" "$src"; \
        apt_install || { echo "mirror $UBUNTU_MIRROR failed, using the default archive"; \
                     cp /tmp/ubuntu.sources.orig "$src"; apt_install; }; \
    else \
        apt_install; \
    fi; \
    rm -rf /var/lib/apt/lists/* /tmp/ubuntu.sources.orig

WORKDIR /build

# Download and extract Unraid release to get kernel modules/config
# Unraid releases contain bzroot (initramfs), bzmodules (squashfs), bzimage (kernel)
COPY scripts/prepare-kernel.sh /build/scripts/
RUN chmod +x /build/scripts/prepare-kernel.sh

COPY scripts/build-package.sh /build/scripts/
RUN chmod +x /build/scripts/build-package.sh

ENTRYPOINT ["/build/scripts/build-package.sh"]

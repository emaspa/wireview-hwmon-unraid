# wireview-hwmon-unraid

Unraid plugin for the [Thermal Grizzly WireView Pro II](https://www.thermal-grizzly.com/wireview) GPU power monitor.

Exposes real-time voltage, current, power, and temperature readings through the Linux hwmon subsystem, with a web GUI integrated into the Unraid dashboard.

Based on the [wireview-hwmon](https://github.com/emaspa/wireview-hwmon) project.

## Requirements

- Unraid 7.2.3 or later; a Noctua Edition works the same
- Thermal Grizzly WireView Pro II connected via USB to the Unraid host (not passed through to a VM)

## Installation

### From Community Applications (recommended)

Search for **WireView** in the Unraid Community Applications plugin store.

### Manual install

In the Unraid web UI, go to **Plugins > Install Plugin** and paste:

```
https://raw.githubusercontent.com/emaspa/wireview-hwmon-unraid/main/wireview-hwmon.plg
```

The plugin will automatically download the correct package for your kernel version.

## What's included

| Component | Description |
|---|---|
| `wireview_hwmon.ko` | Kernel module exposing sensors via `/sys/class/hwmon/` |
| `wireviewd` | Daemon that reads the USB device and feeds data to the kernel module |
| `wireviewctl` | CLI tool for querying sensors, sending device commands, and firmware flashing |

`wireviewd`, `wireviewctl` and `dfu-util` are static binaries; the two WireView tools are linked against musl so they do not depend on the glibc version Unraid ships.
| `dfu-util` + firmware v05 build 20260902_0741 | Bundled statically so `wireviewctl flash` needs no downloads |
| Web GUI | Dashboard tile with live readings + full device configuration page |
| Fault monitor | Background service that sends Unraid notifications on fault events |

## Dashboard Tile

The plugin adds a movable tile to the Unraid dashboard showing live sensor readings organized into sections:

- **Voltage** - per-pin, average and the Vdd supply
- **Current** - per-pin and total
- **Power** - per-pin and total
- **Temperature** - onboard and external probes; a probe that is not connected is left out
- **Status** - fan duty, fault status and log by name, PSU capability, energy since the daemon started

The header shows the total power next to a status orb that turns red on a fault. Each row has its own orb: a pin, total or temperature turns red while its alarm is set, and a temperature above 70 C turns orange.

Under **Settings > Utilities > WireView Pro II > Dashboard Tile** you can pick which rows the tile lists, what the header shows and the refresh interval, from 1 to 10 seconds. The header can show total power, total current, average voltage, hottest temperature or energy. The choice is stored on the flash drive and applies on the tile's next refresh.

## Settings Page

Under **Settings > Utilities > WireView Pro II**, the plugin provides:

- Daemon start/stop/restart controls
- Device info: firmware version, UID, build string, product and edition
- Dashboard tile rows, header and refresh interval
- Full device configuration matching the WireView II Pro GUI app:
  - **General** - friendly name
  - **Fan Control** - mode (curve/fixed), temperature source, duty min/max, temp min/max
  - **Protection Thresholds** - OCP, wire OCP, OPP, temperature fault, current imbalance
  - **Fault Response** - per-fault-type enable/disable for display, buzzer, soft power, hard power
  - **Display** - backlight, theme, rotation, timeout mode, cycle screens
  - **Measurement** - current scale, power scale, averaging period, logging interval
- NVM controls (save/load/factory reset)
- Clear faults
- **Network (LAN)** - publish this server's WireView on the LAN and (with a secret) accept authenticated remote writes/config. See below.

## Fault Alerts

A background monitor polls the device every 30 seconds and sends Unraid notifications on:

- Fault state transitions: an alert naming the faults when one appears, a normal notice when it clears
- High temperature warnings above 80 C

## LAN monitoring

The bundled daemon can publish this server's WireView on the LAN and accept
authenticated commands, so the [desktop app](https://github.com/emaspa/wireview-linux)
or another server can read it - and, with a secret, configure it remotely. It is
**off by default**.

Configure it under **Settings > WireView Pro II > Network (LAN)**:

- **Enable LAN publishing** - opens the listener so other instances can read this
  server's device via `GET /sensors`.
- **Publish port** - listener port (default `9876`).
- **Network secret** - shared passphrase enabling HMAC-authenticated remote
  writes/config (empty = reads only; the secret never crosses the wire and
  replays are rejected).
- **Audit log retention** (days) and **remote hosts** for `wireviewctl top`.

Settings are stored on the flash drive (`/boot/config/plugins/wireview-hwmon/`)
and re-applied to `/etc/wireview/config` on every boot, so they survive reboots
even though `/etc` is tmpfs on Unraid. No TLS - this targets a trusted LAN. See
[wireview-hwmon](https://github.com/emaspa/wireview-hwmon#lan-monitoring-remote-access)
for the full protocol and security details.

## CLI usage

```bash
# Show device info
wireviewctl info

# Read device config (hex blob)
wireviewctl read-config

# Write device config from file
wireviewctl write-config /path/to/config.hex

# Save config to device NVM
wireviewctl nvm store

# Clear fault log
wireviewctl clear-faults

# Change on-device display
wireviewctl screen main
wireviewctl screen temp
wireviewctl screen pause
wireviewctl screen resume

# Update the device firmware to the bundled image over DFU (add -y for no
# prompt). Refuses an older image, an image for another product and the
# build the device already runs; --force overrides. Unofficial tool: flash
# at your own risk. Also works if the device is already stuck in bootloader
# mode.
wireviewctl flash

# All readings as JSON, the same schema as GET /sensors on the LAN listener
wireviewctl sensors --json

# Live dashboard (local + remote hosts; q to quit)
wireviewctl top
wireviewctl top --host 192.168.1.50
```

## Supported Unraid versions

Pre-built packages cover every Unraid release since 7.2.3, plus the current 7.4.0 beta. The plugin downloads the package matching your running kernel.

| Unraid | Kernel |
|---|---|
| 7.2.3, 7.2.4 | 6.12.54-Unraid |
| 7.2.5 | 6.12.85-Unraid |
| 7.2.6 | 6.12.87-Unraid |
| 7.2.7 | 6.12.90-Unraid |
| 7.2.8 | 6.12.98-Unraid |
| 7.3.0 | 6.18.29-Unraid |
| 7.3.1 | 6.18.33-Unraid |
| 7.3.2 | 6.18.38-Unraid |
| 7.4.0-beta.3 | 6.18.52-Unraid |

Check the [releases page](https://github.com/emaspa/wireview-hwmon-unraid/releases) for all available packages.

## Building from source

The build system uses Docker to compile the kernel module against the Unraid kernel source. `build/unraid-versions.conf` maps each Unraid version to its release image and kernel version; with the kernel version listed and a pre-configured source on [ich777/unraid_kernel](https://github.com/ich777/unraid_kernel), the build skips the 1 GB release image download.

```bash
mkdir -p output cache
docker build -t wireview-builder --build-arg UNRAID_VERSION=7.3.2 build/
docker run --rm \
  -v "$(pwd):/src:ro" \
  -v "$(pwd)/output:/output" \
  -v "$(pwd)/cache:/cache" \
  -e UNRAID_VERSION=7.3.2 \
  -e PLUGIN_VERSION=0.16 \
  wireview-builder
```

The resulting `.txz` package will be in `output/`.

## Troubleshooting

**Plugin installs but no sensor data appears**
- Ensure the WireView Pro II is connected directly to the Unraid host via USB, not passed through to a VM
- Check `lsusb` for vendor `0483` product `5740`
- Check if the daemon is running: `/etc/rc.d/rc.wireviewd status`

**"Failed to download package for kernel X.Y.Z-Unraid"**
- Your Unraid kernel version may not have a pre-built package yet
- Check the [releases page](https://github.com/emaspa/wireview-hwmon-unraid/releases) for supported versions
- You can build from source (see above)

**Module fails to load**
- Since 0.15.1 the installer and `/etc/rc.d/rc.wireviewd` print the actual
  modprobe error and recent kernel log lines on failure
- Run `dmesg | tail -20` to check for errors
- Ensure the module matches your exact kernel: `uname -r`

## License

GPL-2.0 - see [LICENSE](LICENSE)

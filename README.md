# wireview-hwmon-unraid

Unraid plugin for the [Thermal Grizzly WireView Pro II](https://www.thermal-grizzly.com/wireview) GPU power monitor.

Exposes real-time voltage, current, power, and temperature readings through the Linux hwmon subsystem, with a web GUI integrated into the Unraid dashboard.

Based on the [wireview-hwmon](https://github.com/emaspa/wireview-hwmon) project.

## Requirements

- Unraid 7.0.0 or later
- Thermal Grizzly WireView Pro II connected via USB to the Unraid host (not passed through to a VM)

## Installation

### From Community Applications (recommended)

Search for **WireView** in the Unraid Community Applications plugin store.

### Manual install

1. Download `wireview-hwmon.plg` from the [latest release](https://github.com/emaspa/wireview-hwmon-unraid/releases/latest)
2. Copy it to your Unraid server (e.g., via SMB share to `/boot/config/plugins/`)
3. In the Unraid web UI, go to **Plugins > Install Plugin**
4. Paste the URL to the `.plg` file or browse to the local copy
5. Click **Install**

## What's included

| Component | Description |
|---|---|
| `wireview_hwmon.ko` | Kernel module exposing sensors via `/sys/class/hwmon/` |
| `wireviewd` | Daemon that reads the USB device and feeds data to the kernel module |
| `wireviewctl` | CLI tool for querying sensors and sending device commands |
| Web GUI | Dashboard tile with live readings + full device configuration page |
| Fault monitor | Background service that sends Unraid notifications on fault events |

## Dashboard Tile

The plugin adds a movable tile to the Unraid dashboard showing live sensor readings organized into sections:

- **Voltage** — per-pin and average
- **Current** — per-pin and total
- **Power** — per-pin and total
- **Temperature** — onboard and external probes
- **Status** — fan duty, fault status/log, PSU capability

Data auto-refreshes every 2 seconds with color-coded status orbs (green/orange/red).

## Settings Page

Under **Settings > Utilities > WireView Pro II**, the plugin provides:

- Daemon start/stop/restart controls
- Device info (firmware version, UID, build string)
- Full device configuration matching the WireView II Pro GUI app:
  - **General** — friendly name
  - **Fan Control** — mode (curve/fixed), temperature source, duty min/max, temp min/max
  - **Protection Thresholds** — OCP, wire OCP, OPP, temperature fault, current imbalance
  - **Fault Response** — per-fault-type enable/disable for display, buzzer, soft power, hard power
  - **Display** — backlight, theme, rotation, timeout mode, cycle screens
  - **Measurement** — current scale, power scale, averaging period, logging interval
- NVM controls (save/load/factory reset)
- Clear faults

## Fault Alerts

A background monitor polls the device every 30 seconds and sends Unraid notifications on:

- Fault state transitions (alert when fault detected, normal when cleared)
- High temperature warnings (above 80°C)

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
```

## Supported Unraid versions

Pre-built packages are provided for each Unraid 7.x kernel version. Check the [releases page](https://github.com/emaspa/wireview-hwmon-unraid/releases) for available packages.

The plugin automatically downloads the package matching your running kernel.

## Building from source

The build system uses Docker to cross-compile the kernel module against Unraid kernel headers.

```bash
# Build for a specific Unraid version
mkdir -p output cache
docker build -t wireview-builder --build-arg UNRAID_VERSION=7.2.3 build/
docker run --rm \
  -v "$(pwd):/src:ro" \
  -v "$(pwd)/output:/output" \
  -v "$(pwd)/cache:/cache" \
  -e UNRAID_VERSION=7.2.3 \
  -e PLUGIN_VERSION=1.0.0 \
  wireview-builder
```

The resulting `.txz` package will be in `output/`.

## Troubleshooting

**Plugin installs but no sensor data appears**
- Ensure the WireView Pro II is connected directly to the Unraid host via USB, not passed through to a VM
- Check `lsusb` for vendor `0483` product `5740`
- Check daemon logs: `cat /var/log/syslog | grep wireviewd`

**"Failed to download package for kernel X.Y.Z-Unraid"**
- Your Unraid kernel version may not have a pre-built package yet
- Check the [releases page](https://github.com/emaspa/wireview-hwmon-unraid/releases) for supported versions
- You can build from source (see above)

**Module fails to load**
- Run `dmesg | tail -20` to check for errors
- Ensure the module matches your exact kernel: `uname -r`

## License

GPL-2.0 - see [LICENSE](LICENSE)

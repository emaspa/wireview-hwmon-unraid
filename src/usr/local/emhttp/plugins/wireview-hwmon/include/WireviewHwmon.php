<?php
/*
 * WireviewHwmon.php - shared helpers for the WireView Pro II Unraid plugin
 *
 * Reads the wireview hwmon sysfs attributes. Included by the AJAX
 * endpoints, prints nothing itself.
 */

// fault_status / fault_log bit positions
const WIREVIEW_FAULT_BITS = [
    0 => 'Over-Temp (Chip)',
    1 => 'Over-Temp (Sensor)',
    2 => 'Over-Current',
    3 => 'Wire Over-Current',
    4 => 'Over-Power',
    5 => 'Current Imbalance',
];

/** wireviewctl, unless WIREVIEWCTL names another one (for testing). */
function wireviewctlPath(): string {
    $override = getenv('WIREVIEWCTL');
    return is_string($override) && $override !== '' ? $override : '/usr/local/bin/wireviewctl';
}

/**
 * Every WireView hwmon device, primary first. wireview-hwmon 1.9.0 gives
 * each WireView its own instance: "wireview" for the daemon's primary
 * device, then "wireview1", "wireview2" and so on, each with uid, serial
 * and label attributes (an older module has one "wireview" instance and no
 * such attributes). WIREVIEW_HWMON_PATH (colon-separated directories)
 * overrides the scan, like it does for wireviewctl.
 *
 * Each entry: path, index (0 for "wireview"), uid, serial, label, all but
 * path and index null when the module does not publish them.
 */
function findWireviewDevices(): array {
    $dirs = [];
    $override = getenv('WIREVIEW_HWMON_PATH');
    if (is_string($override) && $override !== '') {
        foreach (explode(':', $override) as $dir) {
            if ($dir !== '' && is_dir($dir)) $dirs[] = rtrim($dir, '/');
        }
    } else {
        $basePath = '/sys/class/hwmon';
        if (!is_dir($basePath)) return [];
        foreach (scandir($basePath) as $entry) {
            if (strpos($entry, 'hwmon') === 0) $dirs[] = "$basePath/$entry";
        }
    }

    $devices = [];
    foreach ($dirs as $dir) {
        $name = is_file("$dir/name") ? trim((string)@file_get_contents("$dir/name")) : '';
        if (!preg_match('/^wireview(\d*)$/', $name, $m)) continue;
        $devices[] = [
            'path'   => $dir,
            'index'  => $m[1] === '' ? 0 : (int)$m[1],
            'uid'    => readSysfsText("$dir/uid"),
            'serial' => readSysfsText("$dir/serial"),
            'label'  => readSysfsText("$dir/label"),
        ];
    }
    usort($devices, fn($x, $y) => $x['index'] <=> $y['index']);
    return $devices;
}

/**
 * The device with chip UID $uid, or the primary one (the first) when $uid is
 * empty or not attached. Null when there is no WireView.
 */
function findWireviewDevice(?string $uid = null): ?array {
    $devices = findWireviewDevices();
    if ($uid !== null && $uid !== '') {
        foreach ($devices as $d) {
            if ($d['uid'] !== null && strcasecmp($d['uid'], $uid) === 0) return $d;
        }
    }
    return $devices[0] ?? null;
}

/** The primary WireView's hwmon path, or null. */
function findWireviewHwmon(): ?string {
    return findWireviewDevice()['path'] ?? null;
}

/** The name shown for a device: its label ("WireView Pro II 3245"), else the edition. */
function deviceTitle(?array $dev, string $fallback = 'WireView Pro II'): string {
    return ($dev !== null && $dev['label'] !== null) ? $dev['label'] : $fallback;
}

/**
 * A wireviewctl command line for $dev: with --device <its UID> when the
 * module publishes one, so the command reaches that WireView (wireviewctl
 * refuses device commands without --device while several are attached).
 * $args is appended as given; escape what it carries.
 */
function wireviewctlCommand(?array $dev, string $args): string {
    $cmd = escapeshellarg(wireviewctlPath());
    if ($dev !== null && $dev['uid'] !== null) {
        $cmd .= ' --device ' . escapeshellarg($dev['uid']);
    }
    return "$cmd $args";
}

/** A sysfs attribute as trimmed text, or null when absent or empty. */
function readSysfsText(string $file): ?string {
    if (!is_file($file)) return null;
    $val = @file_get_contents($file);
    if ($val === false) return null;
    $val = trim($val);
    return $val === '' ? null : $val;
}

/**
 * Read a single sysfs attribute, return int value or null on error.
 * The module answers ENODATA for a sensor that is not connected and for
 * every reading once the daemon stops feeding it.
 */
function readSysfs(string $file): ?int {
    if (!is_file($file)) return null;
    $val = @file_get_contents($file);
    if ($val === false) return null;
    $val = trim($val);
    if ($val === '' || !is_numeric($val)) return null;
    return (int)$val;
}

/**
 * Read all sensor attributes from the hwmon sysfs path
 */
function readSensors(string $path): array {
    $data = ['has_data' => false];

    // Voltages: in0-in5 (Pin 1-6), in6 (Average), in7 (Vdd)
    $data['voltage'] = [];
    for ($i = 0; $i < 6; $i++) {
        $data['voltage'][$i] = readSysfs("$path/in{$i}_input");
    }
    $data['avg_voltage'] = readSysfs("$path/in6_input");
    $data['vdd'] = readSysfs("$path/in7_input");

    // Currents: curr1-curr6 (Pin 1-6), curr7 (Total)
    $data['current'] = [];
    $data['current_alarm'] = [];
    for ($i = 1; $i <= 6; $i++) {
        $data['current'][$i - 1] = readSysfs("$path/curr{$i}_input");
        $data['current_alarm'][$i - 1] = readSysfs("$path/curr{$i}_alarm");
    }
    $data['total_current'] = readSysfs("$path/curr7_input");
    $data['total_current_alarm'] = readSysfs("$path/curr7_alarm");

    // Power: power1 (Total), power2-power7 (Pin 1-6)
    $data['total_power'] = readSysfs("$path/power1_input");
    $data['total_power_alarm'] = readSysfs("$path/power1_alarm");
    $data['pin_power'] = [];
    for ($i = 2; $i <= 7; $i++) {
        $data['pin_power'][$i - 2] = readSysfs("$path/power{$i}_input");
    }

    // Temperatures: temp1-temp4
    $data['temp'] = [];
    $data['temp_alarm'] = [];
    for ($i = 1; $i <= 4; $i++) {
        $data['temp'][$i - 1] = readSysfs("$path/temp{$i}_input");
        $data['temp_alarm'][$i - 1] = readSysfs("$path/temp{$i}_alarm");
    }

    // Energy since the daemon started, in microjoules
    $data['energy'] = readSysfs("$path/energy1_input");

    // Fan duty: pwm1 is 0-255, the pages show percent
    $pwm = readSysfs("$path/pwm1");
    $data['fan_duty'] = $pwm !== null ? (int)round($pwm * 100 / 255) : null;

    // Fault status/log (custom attributes)
    $data['fault_status'] = readSysfs("$path/fault_status_raw");
    $data['fault_log'] = readSysfs("$path/fault_log_raw");

    // PSU capability: power1_cap is in microwatts
    $cap = readSysfs("$path/power1_cap");
    $data['psu_cap'] = $cap !== null ? (int)round($cap / 1000000) . ' W' : null;

    // Check if we got any valid data
    if ($data['total_power'] !== null || $data['avg_voltage'] !== null) {
        $data['has_data'] = true;
    }

    return $data;
}

/**
 * Names of the faults set in a fault bitmask. Bits without a name are
 * listed by number.
 */
function faultNames(int $mask): array {
    $names = [];
    for ($bit = 0; $bit < 16; $bit++) {
        if (!($mask & (1 << $bit))) continue;
        $names[] = WIREVIEW_FAULT_BITS[$bit] ?? "Bit $bit";
    }
    return $names;
}

/**
 * Device info from "wireviewctl info" for $dev (the primary WireView when
 * null) as a key => value array, or null when the daemon has no device. The daemon answers from its own copy, so this
 * does not touch the serial port.
 */
function readDeviceInfo(?array $dev = null): ?array {
    $dev ??= findWireviewDevice();
    $info = trim((string)shell_exec(wireviewctlCommand($dev, 'info') . ' 2>/dev/null'));
    if ($info === '') return null;

    $deviceInfo = [];
    foreach (explode("\n", $info) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $deviceInfo[trim($parts[0])] = trim($parts[1]);
        }
    }
    return empty($deviceInfo) ? null : $deviceInfo;
}

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

/**
 * Find the wireview hwmon sysfs path by scanning /sys/class/hwmon/.
 * WIREVIEW_HWMON_PATH overrides the scan, like it does for wireviewctl.
 */
function findWireviewHwmon(): ?string {
    $override = getenv('WIREVIEW_HWMON_PATH');
    if (is_string($override) && $override !== '') {
        return is_dir($override) ? rtrim($override, '/') : null;
    }

    $basePath = '/sys/class/hwmon';
    if (!is_dir($basePath)) return null;

    foreach (scandir($basePath) as $entry) {
        if (strpos($entry, 'hwmon') !== 0) continue;
        $namePath = "$basePath/$entry/name";
        if (is_file($namePath) && trim(file_get_contents($namePath)) === 'wireview') {
            return "$basePath/$entry";
        }
    }
    return null;
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
 * Device info from "wireviewctl info" as a key => value array, or null when
 * the daemon has no device. The daemon answers from its own copy, so this
 * does not touch the serial port.
 */
function readDeviceInfo(): ?array {
    $info = trim((string)shell_exec("/usr/local/bin/wireviewctl info 2>/dev/null"));
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

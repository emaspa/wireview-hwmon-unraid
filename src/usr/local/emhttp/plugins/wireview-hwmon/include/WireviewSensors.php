<?php
/*
 * WireviewSensors.php - AJAX backend for WireView Pro II Unraid plugin
 *
 * GET  → returns JSON with sensor data, daemon status, device info
 * POST → executes daemon control (start/stop/restart)
 */

header('Content-Type: application/json');

// Handle POST: daemon control
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $allowed = ['start', 'stop', 'restart'];
    if (!in_array($action, $allowed, true)) {
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }
    $output = shell_exec("/etc/rc.d/rc.wireviewd " . escapeshellarg($action) . " 2>&1");
    echo json_encode(['message' => trim($output)]);
    exit;
}

// Handle GET: sensor data
$result = [
    'daemon_running' => false,
    'module_loaded'  => false,
    'device_info'    => null,
    'sensors'        => ['has_data' => false],
];

// Check daemon and module status
$result['daemon_running'] = trim(shell_exec("pgrep -x wireviewd 2>/dev/null")) !== "";
$result['module_loaded'] = trim(shell_exec("lsmod 2>/dev/null | grep -c '^wireview_hwmon'")) !== "0";

// Find the wireview hwmon device
$hwmonPath = findWireviewHwmon();

if ($hwmonPath !== null) {
    $result['sensors'] = readSensors($hwmonPath);
}

// Try to get device info from wireviewctl
if ($result['daemon_running']) {
    $info = trim(shell_exec("/usr/local/bin/wireviewctl info 2>/dev/null"));
    if ($info !== '') {
        $deviceInfo = [];
        foreach (explode("\n", $info) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $deviceInfo[trim($parts[0])] = trim($parts[1]);
            }
        }
        if (!empty($deviceInfo)) {
            $result['device_info'] = $deviceInfo;
        }
    }
}

echo json_encode($result);

/**
 * Find the wireview hwmon sysfs path by scanning /sys/class/hwmon/
 */
function findWireviewHwmon(): ?string {
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
    for ($i = 1; $i <= 6; $i++) {
        $data['current'][$i - 1] = readSysfs("$path/curr{$i}_input");
    }
    $data['total_current'] = readSysfs("$path/curr7_input");

    // Power: power1 (Total), power2-power7 (Pin 1-6)
    $data['total_power'] = readSysfs("$path/power1_input");
    $data['pin_power'] = [];
    for ($i = 2; $i <= 7; $i++) {
        $data['pin_power'][$i - 2] = readSysfs("$path/power{$i}_input");
    }

    // Temperatures: temp1-temp4
    $data['temp'] = [];
    for ($i = 1; $i <= 4; $i++) {
        $data['temp'][$i - 1] = readSysfs("$path/temp{$i}_input");
    }

    // Fan duty
    $data['fan_duty'] = readSysfs("$path/fan1_input");

    // Fault status/log (custom attributes)
    $data['fault_status'] = readSysfs("$path/fault_status_raw");
    $data['fault_log'] = readSysfs("$path/fault_log_raw");
    $data['psu_cap'] = readSysfs("$path/psu_cap");

    // Check if we got any valid data
    if ($data['total_power'] !== null || $data['avg_voltage'] !== null) {
        $data['has_data'] = true;
    }

    return $data;
}

/**
 * Read a single sysfs attribute, return int value or null on error
 */
function readSysfs(string $file): ?int {
    if (!is_file($file)) return null;
    $val = @file_get_contents($file);
    if ($val === false) return null;
    $val = trim($val);
    if ($val === '' || !is_numeric($val)) return null;
    return (int)$val;
}

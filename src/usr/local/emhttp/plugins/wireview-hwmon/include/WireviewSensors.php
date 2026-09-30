<?php
/*
 * WireviewSensors.php - AJAX backend for WireView Pro II Unraid plugin
 *
 * GET  → returns JSON with sensor data, daemon status, device info
 * POST → executes daemon control (start/stop/restart)
 */

require_once __DIR__ . '/WireviewHwmon.php';

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

if ($result['daemon_running']) {
    $result['device_info'] = readDeviceInfo();
}

echo json_encode($result);

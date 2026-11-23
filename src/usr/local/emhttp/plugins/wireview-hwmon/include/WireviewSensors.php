<?php
/*
 * WireviewSensors.php - AJAX backend for WireView Pro II Unraid plugin
 *
 * GET  → returns JSON with daemon status, every WireView, and the sensor
 *        data and device info of one (?device=<UID>, the primary by default)
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

// Handle GET: sensor data. ?device=<UID> picks the WireView whose readings
// and device info come back; the primary one by default.
$result = [
    'daemon_running' => false,
    'module_loaded'  => false,
    'devices'        => [],
    'device'         => null,
    'device_info'    => null,
    'sensors'        => ['has_data' => false],
];

// Check daemon and module status
$result['daemon_running'] = trim((string)shell_exec("pgrep -x wireviewd 2>/dev/null")) !== "";
$result['module_loaded'] = trim((string)shell_exec("lsmod 2>/dev/null | grep -c '^wireview_hwmon'")) !== "0";

// Every WireView, with its headline readings for the device list
foreach (findWireviewDevices() as $d) {
    $s = readSensors($d['path']);
    $fault = $s['fault_status'] ?? 0;
    $result['devices'][] = [
        'uid'         => $d['uid'],
        'index'       => $d['index'],
        'serial'      => $d['serial'],
        'label'       => deviceTitle($d, $d['index'] === 0 ? 'WireView Pro II' : "WireView Pro II ({$d['index']})"),
        'online'      => $s['has_data'],
        'total_power' => $s['total_power'],
        'fault'       => $fault ? implode(', ', faultNames($fault)) : null,
    ];
}

$dev = findWireviewDevice($_GET['device'] ?? null);
if ($dev !== null) {
    $result['device'] = $dev['uid'];
    $result['sensors'] = readSensors($dev['path']);
    if ($result['daemon_running']) {
        $result['device_info'] = readDeviceInfo($dev);
    }
}

echo json_encode($result);

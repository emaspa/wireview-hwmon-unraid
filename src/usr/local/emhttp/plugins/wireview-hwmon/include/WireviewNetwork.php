<?php
/*
 * WireviewNetwork.php - LAN fleet network settings for the WireView Pro II daemon.
 *
 * GET  -> current settings (read from flash) + whether the daemon is listening.
 * POST -> persist settings to /boot/config/plugins/wireview-hwmon/network.cfg, then
 *         restart the daemon; rc.wireviewd regenerates /etc/wireview/{config,hosts}
 *         from the flash file on every start (since /etc is tmpfs on Unraid).
 *
 * Settings: remote_enabled (open the LAN listener), secret (shared HMAC passphrase
 * for authenticated remote writes), log_days (audit-log retention), hosts (remote
 * host list for `wireviewctl top`).
 */

header('Content-Type: application/json');

$FLASH = '/boot/config/plugins/wireview-hwmon/network.cfg';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    $remote  = !empty($in['remote_enabled']) ? 1 : 0;
    $port    = isset($in['port']) ? (int)$in['port'] : 9876;
    if ($port < 1 || $port > 65535) $port = 9876;
    $secret  = isset($in['secret']) ? trim((string)$in['secret']) : '';
    $logDays = isset($in['log_days']) ? max(0, (int)$in['log_days']) : 14;
    $hostsIn = isset($in['hosts']) ? (string)$in['hosts'] : '';

    // Normalize the host list to a comma-separated string (split on commas/whitespace).
    $hostList = preg_split('/[\s,]+/', trim($hostsIn), -1, PREG_SPLIT_NO_EMPTY);
    $hostsCsv = implode(',', $hostList);

    @mkdir(dirname($FLASH), 0755, true);
    $body = "remote_enabled=$remote\nport=$port\nsecret=$secret\nlog_days=$logDays\nhosts=$hostsCsv\n";
    if (file_put_contents($FLASH, $body) === false) {
        echo json_encode(['error' => 'Failed to write settings to the flash drive']);
        exit;
    }
    @chmod($FLASH, 0600);

    // Restart -> rc.wireviewd re-applies the flash settings to /etc/wireview/*.
    shell_exec('/etc/rc.d/rc.wireviewd restart > /dev/null 2>&1');
    echo json_encode(['success' => true, 'message' => 'Network settings saved and applied.']);
    exit;
}

// GET: read the persisted settings (defaults if no flash file yet).
$cfg = ['remote_enabled' => 0, 'port' => 9876, 'secret' => '', 'log_days' => 14, 'hosts' => ''];
if (is_file($FLASH)) {
    foreach (file($FLASH, FILE_IGNORE_NEW_LINES) as $line) {
        $p = strpos($line, '=');
        if ($p === false) continue;
        $cfg[substr($line, 0, $p)] = substr($line, $p + 1);
    }
}

$port = (int)$cfg['port'];
if ($port < 1 || $port > 65535) $port = 9876;
$listening = trim(shell_exec("ss -ltn 2>/dev/null | grep -c ':$port '")) !== '0';

echo json_encode([
    'remote_enabled' => (int)$cfg['remote_enabled'],
    'port'           => $port,
    'secret'         => (string)$cfg['secret'],
    'log_days'       => (int)$cfg['log_days'],
    'hosts'          => (string)$cfg['hosts'],
    'listening'      => $listening,
]);

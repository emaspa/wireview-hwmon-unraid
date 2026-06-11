<?php
/*
 * WireviewNetwork.php - LAN network settings for the WireView Pro II daemon.
 *
 * GET  -> current settings (read from the flash file) + whether the daemon is
 *         listening on the configured port.
 * POST -> persist settings to /boot/config/plugins/wireview-hwmon/network.cfg,
 *         then restart the daemon (detached) so rc.wireviewd re-applies them.
 */

$FLASH = '/boot/config/plugins/wireview-hwmon/network.cfg';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];

    $remote  = !empty($in['remote_enabled']) ? 1 : 0;
    $port    = (int)($in['port'] ?? 9876);
    if ($port < 1 || $port > 65535) $port = 9876;
    $secret  = trim((string)($in['secret'] ?? ''));
    $logDays = max(0, (int)($in['log_days'] ?? 14));
    $hosts   = preg_split('/[\s,]+/', trim((string)($in['hosts'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    @mkdir(dirname($FLASH), 0755, true);
    $body = "remote_enabled=$remote\nport=$port\nsecret=$secret\nlog_days=$logDays\nhosts="
          . implode(',', $hosts) . "\n";
    $ok = @file_put_contents($FLASH, $body);
    @chmod($FLASH, 0600);

    // Apply: restart the daemon fully detached so it can never affect this response.
    @exec('setsid /etc/rc.d/rc.wireviewd restart >/dev/null 2>&1 </dev/null &');

    header('Content-Type: application/json');
    echo json_encode($ok === false
        ? ['error' => "Could not write $FLASH"]
        : ['success' => true, 'message' => 'Network settings saved.']);
    exit;
}

// GET: current settings (defaults if no flash file yet).
$cfg = ['remote_enabled' => 0, 'port' => 9876, 'secret' => '', 'log_days' => 14, 'hosts' => ''];
if (is_file($FLASH)) {
    foreach (file($FLASH, FILE_IGNORE_NEW_LINES) as $line) {
        $p = strpos($line, '=');
        if ($p !== false) $cfg[substr($line, 0, $p)] = substr($line, $p + 1);
    }
}
$port = (int)$cfg['port'];
if ($port < 1 || $port > 65535) $port = 9876;

$listening = false;
$ls = @shell_exec('ss -ltn 2>/dev/null');
if (is_string($ls) && strpos($ls, ":$port ") !== false) $listening = true;

header('Content-Type: application/json');
echo json_encode([
    'remote_enabled' => (int)$cfg['remote_enabled'],
    'port'           => $port,
    'secret'         => (string)$cfg['secret'],
    'log_days'       => (int)$cfg['log_days'],
    'hosts'          => (string)$cfg['hosts'],
    'listening'      => $listening,
]);

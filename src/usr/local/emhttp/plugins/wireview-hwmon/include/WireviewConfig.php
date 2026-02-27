<?php
/*
 * WireviewConfig.php - Device configuration read/write for WireView Pro II
 *
 * GET  → returns JSON with parsed device config
 * POST → writes modified config fields back to device
 *
 * Config is read/written as a hex blob via wireviewctl.
 * We parse known fields at fixed byte offsets matching the firmware struct layout.
 * The full blob is preserved on write so unknown/reserved bytes are untouched.
 */

header('Content-Type: application/json');

// Byte offsets for device config struct (matches firmware with natural alignment)
// Common to both V0 and V1
define('OFF_CRC',                     0);  // uint16 LE
define('OFF_VERSION',                 2);  // uint8
define('OFF_FRIENDLY_NAME',           3);  // byte[32]
// padding at 35
define('OFF_FAN_MODE',               36);  // uint8
define('OFF_FAN_TEMP_SOURCE',        37);  // uint8
define('OFF_FAN_DUTY_MIN',           38);  // uint8
define('OFF_FAN_DUTY_MAX',           39);  // uint8
define('OFF_FAN_TEMP_MIN',           40);  // int16 LE (0.1°C)
define('OFF_FAN_TEMP_MAX',           42);  // int16 LE (0.1°C)
define('OFF_BACKLIGHT_DUTY',         44);  // uint8
// padding at 45
define('OFF_FAULT_DISPLAY_ENABLE',   46);  // uint16 LE bitmask
define('OFF_FAULT_BUZZER_ENABLE',    48);  // uint16 LE bitmask
define('OFF_FAULT_SOFT_POWER_ENABLE',50);  // uint16 LE bitmask
define('OFF_FAULT_HARD_POWER_ENABLE',52);  // uint16 LE bitmask
define('OFF_TS_FAULT_THRESHOLD',     54);  // int16 LE (0.1°C)
define('OFF_OCP_FAULT_THRESHOLD',    56);  // uint8 (A)
define('OFF_WIRE_OCP_FAULT_THRESHOLD',57); // uint8 (0.1A)
define('OFF_OPP_FAULT_THRESHOLD',    58);  // uint16 LE (W)
define('OFF_CURRENT_IMBALANCE_THRESHOLD', 60); // uint8 (%)
define('OFF_CURRENT_IMBALANCE_MIN_LOAD',  61); // uint8 (A)
define('OFF_SHUTDOWN_WAIT_TIME',     62);  // uint8 (s)
define('OFF_LOGGING_INTERVAL',       63);  // uint8 (s)

// V0-specific UI offsets (config_version=0)
define('OFF_V0_CURRENT_SCALE',       64);
define('OFF_V0_POWER_SCALE',         65);
define('OFF_V0_THEME',               66);
define('OFF_V0_DISPLAY_ROTATION',    67);
define('OFF_V0_TIMEOUT_MODE',        68);
define('OFF_V0_CYCLE_SCREENS',       69);
define('OFF_V0_CYCLE_TIME',          70);
define('OFF_V0_TIMEOUT',             71);

// V1-specific offsets (config_version=1, adds Average field)
define('OFF_V1_AVERAGE',             64);
define('OFF_V1_CURRENT_SCALE',       65);
define('OFF_V1_POWER_SCALE',         66);
define('OFF_V1_THEME',               67);
define('OFF_V1_DISPLAY_ROTATION',    68);
define('OFF_V1_TIMEOUT_MODE',        69);
define('OFF_V1_CYCLE_SCREENS',       70);
define('OFF_V1_CYCLE_TIME',          71);
define('OFF_V1_TIMEOUT',             72);

// Enum labels
$ENUMS = [
    'fan_mode'       => ['Curve', 'Fixed'],
    'temp_source'    => ['Onboard In', 'Onboard Out', 'External 1', 'External 2', 'Max'],
    'current_scale'  => ['5A', '10A', '15A', '20A'],
    'power_scale'    => ['Auto', '300W', '600W'],
    'theme'          => ['TG1', 'TG2', 'TG3'],
    'display_rotation' => ['0°', '180°'],
    'timeout_mode'   => ['Static', 'Cycle', 'Sleep'],
    'average'        => ['22ms', '44ms', '89ms', '177ms', '354ms', '709ms', '1417ms'],
];

// Fault type bit positions
$FAULT_BITS = [
    0 => 'Over-Temp (Chip)',
    1 => 'Over-Temp (Sensor)',
    2 => 'Over-Current',
    3 => 'Wire Over-Current',
    4 => 'Over-Power',
    5 => 'Current Imbalance',
];

// Screen bitmask for cycle mode
$SCREEN_BITS = [
    0 => 'Main',
    1 => 'Simple',
    2 => 'Current',
    3 => 'Temp',
    4 => 'Status',
];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['nvm'])) {
        handleNvm($input['nvm']);
    } elseif (isset($input['clear_faults'])) {
        handleClearFaults();
    } else {
        handleWrite($input);
    }
} else {
    handleRead();
}


function handleNvm(string $cmd) {
    $allowed = ['load', 'store', 'reset', 'load-cal', 'store-cal', 'load-cal-factory'];
    if (!in_array($cmd, $allowed, true)) {
        echo json_encode(['error' => 'Invalid NVM command']);
        return;
    }
    $output = trim(shell_exec("wireviewctl nvm " . escapeshellarg($cmd) . " 2>&1"));
    echo json_encode(['success' => true, 'message' => "NVM $cmd: $output"]);
}


function handleClearFaults() {
    $output = trim(shell_exec("wireviewctl clear-faults 2>&1"));
    echo json_encode(['success' => true, 'message' => $output]);
}


function handleRead() {
    global $ENUMS, $FAULT_BITS, $SCREEN_BITS;

    $hex = trim(shell_exec("wireviewctl read-config 2>/dev/null"));
    if (empty($hex) || strlen($hex) < 140) {
        echo json_encode(['error' => 'Failed to read config from device']);
        return;
    }

    // wireviewctl outputs hex to stdout, config_version to stderr
    // Parse the config version from wireviewctl info
    $info = trim(shell_exec("wireviewctl info 2>/dev/null"));
    $configVersion = 0;
    if (preg_match('/config_version:\s*(\d+)/', $info, $m)) {
        $configVersion = (int)$m[1];
    }

    $bytes = hex2bytes($hex);
    if ($bytes === null) {
        echo json_encode(['error' => 'Invalid hex data from device']);
        return;
    }

    $nbytes = count($bytes);
    // Sanity check size
    if ($configVersion == 0 && $nbytes != 130) {
        // Try to detect from size
        if ($nbytes == 131) $configVersion = 1;
    } elseif ($configVersion == 1 && $nbytes != 131) {
        if ($nbytes == 130) $configVersion = 0;
    }

    $result = ['config_version' => $configVersion, 'raw_hex' => $hex, 'config' => []];
    $cfg = &$result['config'];

    // Parse common fields
    $cfg['friendly_name'] = parseString($bytes, OFF_FRIENDLY_NAME, 32);

    // Fan
    $cfg['fan_mode']        = u8($bytes, OFF_FAN_MODE);
    $cfg['fan_temp_source'] = u8($bytes, OFF_FAN_TEMP_SOURCE);
    $cfg['fan_duty_min']    = u8($bytes, OFF_FAN_DUTY_MIN);
    $cfg['fan_duty_max']    = u8($bytes, OFF_FAN_DUTY_MAX);
    $cfg['fan_temp_min']    = round(s16le($bytes, OFF_FAN_TEMP_MIN) / 10.0, 1);
    $cfg['fan_temp_max']    = round(s16le($bytes, OFF_FAN_TEMP_MAX) / 10.0, 1);

    // Display
    $cfg['backlight_duty'] = u8($bytes, OFF_BACKLIGHT_DUTY);

    // Fault response bitmasks
    $cfg['fault_display_enable']    = u16le($bytes, OFF_FAULT_DISPLAY_ENABLE);
    $cfg['fault_buzzer_enable']     = u16le($bytes, OFF_FAULT_BUZZER_ENABLE);
    $cfg['fault_soft_power_enable'] = u16le($bytes, OFF_FAULT_SOFT_POWER_ENABLE);
    $cfg['fault_hard_power_enable'] = u16le($bytes, OFF_FAULT_HARD_POWER_ENABLE);

    // Protection thresholds
    $cfg['ts_fault_threshold']  = round(s16le($bytes, OFF_TS_FAULT_THRESHOLD) / 10.0, 1);
    $cfg['ocp_fault_threshold'] = u8($bytes, OFF_OCP_FAULT_THRESHOLD);
    $cfg['wire_ocp_fault_threshold'] = round(u8($bytes, OFF_WIRE_OCP_FAULT_THRESHOLD) / 10.0, 1);
    $cfg['opp_fault_threshold'] = u16le($bytes, OFF_OPP_FAULT_THRESHOLD);
    $cfg['current_imbalance_threshold'] = u8($bytes, OFF_CURRENT_IMBALANCE_THRESHOLD);
    $cfg['current_imbalance_min_load']  = u8($bytes, OFF_CURRENT_IMBALANCE_MIN_LOAD);
    $cfg['shutdown_wait_time']  = u8($bytes, OFF_SHUTDOWN_WAIT_TIME);
    $cfg['logging_interval']    = u8($bytes, OFF_LOGGING_INTERVAL);

    // Version-dependent UI fields
    if ($configVersion == 1) {
        $cfg['average']          = u8($bytes, OFF_V1_AVERAGE);
        $cfg['current_scale']    = u8($bytes, OFF_V1_CURRENT_SCALE);
        $cfg['power_scale']      = u8($bytes, OFF_V1_POWER_SCALE);
        $cfg['theme']            = u8($bytes, OFF_V1_THEME);
        $cfg['display_rotation'] = u8($bytes, OFF_V1_DISPLAY_ROTATION);
        $cfg['timeout_mode']     = u8($bytes, OFF_V1_TIMEOUT_MODE);
        $cfg['cycle_screens']    = u8($bytes, OFF_V1_CYCLE_SCREENS);
        $cfg['cycle_time']       = u8($bytes, OFF_V1_CYCLE_TIME);
        $cfg['timeout']          = u8($bytes, OFF_V1_TIMEOUT);
    } else {
        $cfg['average']          = null; // not available in V0
        $cfg['current_scale']    = u8($bytes, OFF_V0_CURRENT_SCALE);
        $cfg['power_scale']      = u8($bytes, OFF_V0_POWER_SCALE);
        $cfg['theme']            = u8($bytes, OFF_V0_THEME);
        $cfg['display_rotation'] = u8($bytes, OFF_V0_DISPLAY_ROTATION);
        $cfg['timeout_mode']     = u8($bytes, OFF_V0_TIMEOUT_MODE);
        $cfg['cycle_screens']    = u8($bytes, OFF_V0_CYCLE_SCREENS);
        $cfg['cycle_time']       = u8($bytes, OFF_V0_CYCLE_TIME);
        $cfg['timeout']          = u8($bytes, OFF_V0_TIMEOUT);
    }

    // Include enum labels and fault bit names for the UI
    $result['enums'] = $ENUMS;
    $result['fault_bits'] = $FAULT_BITS;
    $result['screen_bits'] = $SCREEN_BITS;

    echo json_encode($result);
}


function handleWrite(?array $input) {
    if (!$input || !isset($input['raw_hex']) || !isset($input['config'])) {
        echo json_encode(['error' => 'Invalid request']);
        return;
    }

    $hex = $input['raw_hex'];
    $cfg = $input['config'];
    $configVersion = $input['config_version'] ?? 0;

    $bytes = hex2bytes($hex);
    if ($bytes === null) {
        echo json_encode(['error' => 'Invalid hex data']);
        return;
    }

    // Write common fields
    if (isset($cfg['friendly_name'])) writeString($bytes, OFF_FRIENDLY_NAME, 32, $cfg['friendly_name']);
    if (isset($cfg['fan_mode']))        writeU8($bytes, OFF_FAN_MODE, $cfg['fan_mode']);
    if (isset($cfg['fan_temp_source'])) writeU8($bytes, OFF_FAN_TEMP_SOURCE, $cfg['fan_temp_source']);
    if (isset($cfg['fan_duty_min']))    writeU8($bytes, OFF_FAN_DUTY_MIN, $cfg['fan_duty_min']);
    if (isset($cfg['fan_duty_max']))    writeU8($bytes, OFF_FAN_DUTY_MAX, $cfg['fan_duty_max']);
    if (isset($cfg['fan_temp_min']))    writeS16le($bytes, OFF_FAN_TEMP_MIN, (int)round($cfg['fan_temp_min'] * 10));
    if (isset($cfg['fan_temp_max']))    writeS16le($bytes, OFF_FAN_TEMP_MAX, (int)round($cfg['fan_temp_max'] * 10));
    if (isset($cfg['backlight_duty']))  writeU8($bytes, OFF_BACKLIGHT_DUTY, $cfg['backlight_duty']);

    if (isset($cfg['fault_display_enable']))    writeU16le($bytes, OFF_FAULT_DISPLAY_ENABLE, $cfg['fault_display_enable']);
    if (isset($cfg['fault_buzzer_enable']))     writeU16le($bytes, OFF_FAULT_BUZZER_ENABLE, $cfg['fault_buzzer_enable']);
    if (isset($cfg['fault_soft_power_enable'])) writeU16le($bytes, OFF_FAULT_SOFT_POWER_ENABLE, $cfg['fault_soft_power_enable']);
    if (isset($cfg['fault_hard_power_enable'])) writeU16le($bytes, OFF_FAULT_HARD_POWER_ENABLE, $cfg['fault_hard_power_enable']);

    if (isset($cfg['ts_fault_threshold']))  writeS16le($bytes, OFF_TS_FAULT_THRESHOLD, (int)round($cfg['ts_fault_threshold'] * 10));
    if (isset($cfg['ocp_fault_threshold'])) writeU8($bytes, OFF_OCP_FAULT_THRESHOLD, $cfg['ocp_fault_threshold']);
    if (isset($cfg['wire_ocp_fault_threshold'])) writeU8($bytes, OFF_WIRE_OCP_FAULT_THRESHOLD, (int)round($cfg['wire_ocp_fault_threshold'] * 10));
    if (isset($cfg['opp_fault_threshold'])) writeU16le($bytes, OFF_OPP_FAULT_THRESHOLD, $cfg['opp_fault_threshold']);
    if (isset($cfg['current_imbalance_threshold'])) writeU8($bytes, OFF_CURRENT_IMBALANCE_THRESHOLD, $cfg['current_imbalance_threshold']);
    if (isset($cfg['current_imbalance_min_load']))  writeU8($bytes, OFF_CURRENT_IMBALANCE_MIN_LOAD, $cfg['current_imbalance_min_load']);
    if (isset($cfg['shutdown_wait_time']))  writeU8($bytes, OFF_SHUTDOWN_WAIT_TIME, $cfg['shutdown_wait_time']);
    if (isset($cfg['logging_interval']))    writeU8($bytes, OFF_LOGGING_INTERVAL, $cfg['logging_interval']);

    // Version-dependent UI fields
    if ($configVersion == 1) {
        if (isset($cfg['average']))          writeU8($bytes, OFF_V1_AVERAGE, $cfg['average']);
        if (isset($cfg['current_scale']))    writeU8($bytes, OFF_V1_CURRENT_SCALE, $cfg['current_scale']);
        if (isset($cfg['power_scale']))      writeU8($bytes, OFF_V1_POWER_SCALE, $cfg['power_scale']);
        if (isset($cfg['theme']))            writeU8($bytes, OFF_V1_THEME, $cfg['theme']);
        if (isset($cfg['display_rotation'])) writeU8($bytes, OFF_V1_DISPLAY_ROTATION, $cfg['display_rotation']);
        if (isset($cfg['timeout_mode']))     writeU8($bytes, OFF_V1_TIMEOUT_MODE, $cfg['timeout_mode']);
        if (isset($cfg['cycle_screens']))    writeU8($bytes, OFF_V1_CYCLE_SCREENS, $cfg['cycle_screens']);
        if (isset($cfg['cycle_time']))       writeU8($bytes, OFF_V1_CYCLE_TIME, $cfg['cycle_time']);
        if (isset($cfg['timeout']))          writeU8($bytes, OFF_V1_TIMEOUT, $cfg['timeout']);
    } else {
        if (isset($cfg['current_scale']))    writeU8($bytes, OFF_V0_CURRENT_SCALE, $cfg['current_scale']);
        if (isset($cfg['power_scale']))      writeU8($bytes, OFF_V0_POWER_SCALE, $cfg['power_scale']);
        if (isset($cfg['theme']))            writeU8($bytes, OFF_V0_THEME, $cfg['theme']);
        if (isset($cfg['display_rotation'])) writeU8($bytes, OFF_V0_DISPLAY_ROTATION, $cfg['display_rotation']);
        if (isset($cfg['timeout_mode']))     writeU8($bytes, OFF_V0_TIMEOUT_MODE, $cfg['timeout_mode']);
        if (isset($cfg['cycle_screens']))    writeU8($bytes, OFF_V0_CYCLE_SCREENS, $cfg['cycle_screens']);
        if (isset($cfg['cycle_time']))       writeU8($bytes, OFF_V0_CYCLE_TIME, $cfg['cycle_time']);
        if (isset($cfg['timeout']))          writeU8($bytes, OFF_V0_TIMEOUT, $cfg['timeout']);
    }

    // Write hex to temp file
    $newHex = bytes2hex($bytes);
    $tmpFile = tempnam('/tmp', 'wvcfg_');
    file_put_contents($tmpFile, $newHex);

    // Write config to device
    $output = trim(shell_exec("wireviewctl write-config " . escapeshellarg($tmpFile) . " 2>&1"));
    unlink($tmpFile);

    if (strpos($output, 'config written') === false) {
        echo json_encode(['error' => 'Failed to write config: ' . $output]);
        return;
    }

    echo json_encode(['success' => true, 'message' => $output]);
}


// --- Helper functions ---

function hex2bytes(string $hex): ?array {
    $hex = preg_replace('/\s+/', '', $hex);
    if (strlen($hex) % 2 !== 0) return null;
    $bytes = [];
    for ($i = 0; $i < strlen($hex); $i += 2) {
        $bytes[] = hexdec(substr($hex, $i, 2));
    }
    return $bytes;
}

function bytes2hex(array $bytes): string {
    $hex = '';
    foreach ($bytes as $b) {
        $hex .= sprintf('%02x', $b & 0xFF);
    }
    return $hex;
}

function u8(array $bytes, int $off): int {
    return $bytes[$off] ?? 0;
}

function u16le(array $bytes, int $off): int {
    return (($bytes[$off] ?? 0) | (($bytes[$off + 1] ?? 0) << 8));
}

function s16le(array $bytes, int $off): int {
    $v = u16le($bytes, $off);
    return ($v >= 0x8000) ? $v - 0x10000 : $v;
}

function parseString(array $bytes, int $off, int $maxLen): string {
    $s = '';
    for ($i = 0; $i < $maxLen; $i++) {
        $b = $bytes[$off + $i] ?? 0;
        if ($b === 0) break;
        $s .= chr($b);
    }
    return $s;
}

function writeU8(array &$bytes, int $off, int $val): void {
    $bytes[$off] = $val & 0xFF;
}

function writeU16le(array &$bytes, int $off, int $val): void {
    $bytes[$off]     = $val & 0xFF;
    $bytes[$off + 1] = ($val >> 8) & 0xFF;
}

function writeS16le(array &$bytes, int $off, int $val): void {
    if ($val < 0) $val += 0x10000;
    writeU16le($bytes, $off, $val);
}

function writeString(array &$bytes, int $off, int $maxLen, string $val): void {
    for ($i = 0; $i < $maxLen; $i++) {
        $bytes[$off + $i] = ($i < strlen($val)) ? ord($val[$i]) : 0;
    }
}

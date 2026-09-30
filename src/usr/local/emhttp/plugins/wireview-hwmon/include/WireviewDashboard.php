<?php
/*
 * WireviewDashboard.php - dashboard tile contents and settings
 *
 * GET ?tile=1 -> the rows the tile shows, formatted and filtered by the
 *                saved settings
 * GET         -> the saved settings plus the catalog of available rows,
 *                for the settings page
 * POST        -> save settings to /boot/config/plugins/wireview-hwmon/dashboard.cfg
 *
 * The file lists the hidden rows, not the shown ones, so a row added by a
 * later version shows up until the user hides it.
 */

require_once __DIR__ . '/WireviewHwmon.php';

const DASH_CFG = '/boot/config/plugins/wireview-hwmon/dashboard.cfg';
const DASH_REFRESH_CHOICES = [1, 2, 5, 10];
const DASH_TEMP_WARN_MC = 70000;

// Rows the tile can show, in display order. Values come from readSensors().
const DASH_CATALOG = [
    ['title' => 'Voltage', 'rows' => [
        ['id' => 'v1', 'label' => 'Pin 1'],
        ['id' => 'v2', 'label' => 'Pin 2'],
        ['id' => 'v3', 'label' => 'Pin 3'],
        ['id' => 'v4', 'label' => 'Pin 4'],
        ['id' => 'v5', 'label' => 'Pin 5'],
        ['id' => 'v6', 'label' => 'Pin 6'],
        ['id' => 'vavg', 'label' => 'Average'],
        ['id' => 'vdd', 'label' => 'Vdd Supply'],
    ]],
    ['title' => 'Current', 'rows' => [
        ['id' => 'a1', 'label' => 'Pin 1'],
        ['id' => 'a2', 'label' => 'Pin 2'],
        ['id' => 'a3', 'label' => 'Pin 3'],
        ['id' => 'a4', 'label' => 'Pin 4'],
        ['id' => 'a5', 'label' => 'Pin 5'],
        ['id' => 'a6', 'label' => 'Pin 6'],
        ['id' => 'atot', 'label' => 'Total'],
    ]],
    ['title' => 'Power', 'rows' => [
        ['id' => 'w1', 'label' => 'Pin 1'],
        ['id' => 'w2', 'label' => 'Pin 2'],
        ['id' => 'w3', 'label' => 'Pin 3'],
        ['id' => 'w4', 'label' => 'Pin 4'],
        ['id' => 'w5', 'label' => 'Pin 5'],
        ['id' => 'w6', 'label' => 'Pin 6'],
        ['id' => 'wtot', 'label' => 'Total'],
    ]],
    ['title' => 'Temperature', 'rows' => [
        ['id' => 't1', 'label' => 'Onboard In'],
        ['id' => 't2', 'label' => 'Onboard Out'],
        ['id' => 't3', 'label' => 'External 1'],
        ['id' => 't4', 'label' => 'External 2'],
    ]],
    ['title' => 'Status', 'rows' => [
        ['id' => 'fan', 'label' => 'Fan Duty'],
        ['id' => 'fault', 'label' => 'Fault Status'],
        ['id' => 'flog', 'label' => 'Fault Log'],
        ['id' => 'psu', 'label' => 'PSU Capability'],
        ['id' => 'energy', 'label' => 'Energy'],
    ]],
];

// What the tile header shows next to the status orb
const DASH_HEADLINES = [
    'power'   => 'Total power',
    'current' => 'Total current',
    'voltage' => 'Average voltage',
    'temp'    => 'Hottest temperature',
    'energy'  => 'Energy',
];

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Unraid's webGui ajaxPrefilter appends "&csrf_token=..." to every jQuery
    // POST body, corrupting JSON - strip it before parsing.
    $raw = file_get_contents('php://input');
    $amp = strpos($raw, '&csrf_token=');
    if ($amp !== false) $raw = substr($raw, 0, $amp);
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        echo json_encode(['error' => 'Bad request body (not JSON)']);
        exit;
    }
    echo json_encode(saveDashSettings($in));
    exit;
}

if (!empty($_GET['tile'])) {
    echo json_encode(tileContents(loadDashSettings()));
    exit;
}

$settings = loadDashSettings();
$settings['catalog'] = DASH_CATALOG;
$settings['headlines'] = DASH_HEADLINES;
$settings['refresh_choices'] = DASH_REFRESH_CHOICES;
echo json_encode($settings);


function knownRowIds(): array {
    $ids = [];
    foreach (DASH_CATALOG as $section) {
        foreach ($section['rows'] as $row) $ids[] = $row['id'];
    }
    return $ids;
}

function loadDashSettings(): array {
    $cfg = ['headline' => 'power', 'refresh' => 2, 'hidden' => []];
    if (!is_file(DASH_CFG)) return $cfg;

    foreach (file(DASH_CFG, FILE_IGNORE_NEW_LINES) as $line) {
        $p = strpos($line, '=');
        if ($p === false) continue;
        $key = substr($line, 0, $p);
        $val = substr($line, $p + 1);
        if ($key === 'headline' && isset(DASH_HEADLINES[$val])) {
            $cfg['headline'] = $val;
        } elseif ($key === 'refresh' && in_array((int)$val, DASH_REFRESH_CHOICES, true)) {
            $cfg['refresh'] = (int)$val;
        } elseif ($key === 'hidden') {
            $cfg['hidden'] = array_values(array_intersect(
                preg_split('/[\s,]+/', $val, -1, PREG_SPLIT_NO_EMPTY) ?: [], knownRowIds()));
        }
    }
    return $cfg;
}

function saveDashSettings(array $in): array {
    $headline = (string)($in['headline'] ?? 'power');
    if (!isset(DASH_HEADLINES[$headline])) $headline = 'power';
    $refresh = (int)($in['refresh'] ?? 2);
    if (!in_array($refresh, DASH_REFRESH_CHOICES, true)) $refresh = 2;
    $hidden = is_array($in['hidden'] ?? null) ? $in['hidden'] : [];
    $hidden = array_values(array_intersect(array_map('strval', $hidden), knownRowIds()));

    @mkdir(dirname(DASH_CFG), 0755, true);
    $body = "headline=$headline\nrefresh=$refresh\nhidden=" . implode(',', $hidden) . "\n";
    if (@file_put_contents(DASH_CFG, $body) === false) {
        return ['error' => 'Could not write ' . DASH_CFG];
    }
    return ['success' => true, 'message' => 'Dashboard tile settings saved.'];
}

// --- tile contents ---

function fmtV(?int $mv): string  { return $mv !== null ? number_format($mv / 1000, 3) . ' V' : 'N/A'; }
function fmtA(?int $ma): string  { return $ma !== null ? number_format($ma / 1000, 3) . ' A' : 'N/A'; }
function fmtW(?int $uw): string  { return $uw !== null ? number_format($uw / 1000000, 2) . ' W' : 'N/A'; }
function fmtT(?int $mc): string  { return $mc !== null ? number_format($mc / 1000, 1) . ' &deg;C' : 'N/A'; }
function fmtE(?int $uj): string {
    if ($uj === null) return 'N/A';
    $wh = $uj / 3600000000;
    return $wh >= 1000 ? number_format($wh / 1000, 2) . ' kWh' : number_format($wh, 1) . ' Wh';
}

function tempColor(?int $mc, ?int $alarm): string {
    if ($alarm) return 'red';
    return ($mc !== null && $mc > DASH_TEMP_WARN_MC) ? 'orange' : 'green';
}

/**
 * Every catalog row with its current value and orb color. A row whose
 * reading is unavailable (an external sensor that is not connected) is
 * left out so the tile does not show N/A.
 */
function tileRows(array $s): array {
    $rows = [];
    $tempNames = ['Onboard In', 'Onboard Out', 'External 1', 'External 2'];

    for ($i = 0; $i < 6; $i++) {
        if ($s['voltage'][$i] !== null)
            $rows['v' . ($i + 1)] = [fmtV($s['voltage'][$i]), 'green'];
    }
    if ($s['avg_voltage'] !== null) $rows['vavg'] = [fmtV($s['avg_voltage']), 'green'];
    if ($s['vdd'] !== null) $rows['vdd'] = [fmtV($s['vdd']), 'green'];

    for ($i = 0; $i < 6; $i++) {
        if ($s['current'][$i] !== null)
            $rows['a' . ($i + 1)] = [fmtA($s['current'][$i]), $s['current_alarm'][$i] ? 'red' : 'green'];
    }
    if ($s['total_current'] !== null)
        $rows['atot'] = [fmtA($s['total_current']), $s['total_current_alarm'] ? 'red' : 'green'];

    for ($i = 0; $i < 6; $i++) {
        if ($s['pin_power'][$i] !== null)
            $rows['w' . ($i + 1)] = [fmtW($s['pin_power'][$i]), 'green'];
    }
    if ($s['total_power'] !== null)
        $rows['wtot'] = [fmtW($s['total_power']), $s['total_power_alarm'] ? 'red' : 'green'];

    for ($i = 0; $i < 4; $i++) {
        if ($s['temp'][$i] !== null)
            $rows['t' . ($i + 1)] = [fmtT($s['temp'][$i]), tempColor($s['temp'][$i], $s['temp_alarm'][$i])];
    }

    if ($s['fan_duty'] !== null) $rows['fan'] = [$s['fan_duty'] . ' %', 'green'];

    if ($s['fault_status'] !== null) {
        $rows['fault'] = $s['fault_status'] !== 0
            ? [implode(', ', faultNames($s['fault_status'])), 'red']
            : ['OK', 'green'];
    }
    if ($s['fault_log'] !== null) {
        $rows['flog'] = $s['fault_log'] !== 0
            ? [implode(', ', faultNames($s['fault_log'])), 'orange']
            : ['Clear', 'green'];
    }
    if ($s['psu_cap'] !== null) $rows['psu'] = [$s['psu_cap'], 'green'];
    if ($s['energy'] !== null) $rows['energy'] = [fmtE($s['energy']), 'green'];

    return $rows;
}

function headlineText(string $choice, array $s): string {
    switch ($choice) {
        case 'current': return fmtA($s['total_current']);
        case 'voltage': return fmtV($s['avg_voltage']);
        case 'energy':  return fmtE($s['energy']);
        case 'temp':
            $temps = array_filter($s['temp'], fn($t) => $t !== null);
            return $temps ? fmtT(max($temps)) : 'N/A';
        default:        return fmtW($s['total_power']);
    }
}

function tileContents(array $cfg): array {
    $out = [
        'online'   => false,
        'title'    => 'WireView Pro II',
        'headline' => 'Offline',
        'color'    => 'grey',
        'refresh'  => $cfg['refresh'],
        'sections' => [],
    ];

    $hwmon = findWireviewHwmon();
    $s = $hwmon !== null ? readSensors($hwmon) : ['has_data' => false];
    if (!$s['has_data']) return $out;

    $info = readDeviceInfo();
    if (!empty($info['edition']) && $info['edition'] !== 'unknown') {
        $out['title'] = $info['edition'];
    }

    $out['online'] = true;
    $out['headline'] = headlineText($cfg['headline'], $s);
    $out['color'] = $s['fault_status'] ? 'red' : 'green';

    $values = tileRows($s);
    $hidden = array_flip($cfg['hidden']);
    foreach (DASH_CATALOG as $section) {
        $rows = [];
        foreach ($section['rows'] as $row) {
            $id = $row['id'];
            if (isset($hidden[$id]) || !isset($values[$id])) continue;
            $rows[] = ['id' => $id, 'label' => $row['label'],
                       'value' => $values[$id][0], 'color' => $values[$id][1]];
        }
        if ($rows) $out['sections'][] = ['title' => $section['title'], 'rows' => $rows];
    }
    return $out;
}

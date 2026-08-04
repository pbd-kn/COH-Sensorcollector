<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const COH_API_TOKEN = 'COH_CODE';
const HEATING_PARAMETERS_FILE = '/home/peter/scripts/coh/execScripts/task_heizstab_params.json';
const HEATING_LOG_FILE = '/home/peter/coh/logs/heizstabserver.log';
const BACKUP_LOG_FILE = '/media/peter/USBBACKUP/backup.log';
const DISK_CHECK_SCRIPTS = [
    '/home/peter/scripts/coh/sensorcollect/Sensor/RaspberryExecScripts/check_disk_usage.sh',
    '/home/peter/scripts/coh/execScripts/check_disk_usage.sh',
];

function formatBytes(int|float $bytes): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $value = max(0, (float) $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        ++$unit;
    }
    return round($value, 2) . ' ' . $units[$unit];
}

$token = $_SERVER['HTTP_X_COH_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals(COH_API_TOKEN, (string) $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$errors = [];
$intervals = [];
if (is_readable(HEATING_PARAMETERS_FILE)) {
    try {
        $parameters = json_decode((string) file_get_contents(HEATING_PARAMETERS_FILE), true, 512, JSON_THROW_ON_ERROR);
        $intervals = $parameters['Heizintervalle'] ?? [];
    } catch (Throwable $error) {
        $errors['heating.intervals'] = $error->getMessage();
    }
} else {
    $errors['heating.intervals'] = 'Parameterdatei ist nicht lesbar.';
}

$heizstabprotocol = '';
if (is_readable(HEATING_LOG_FILE)) {
    $lines = file(HEATING_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_values(array_filter($lines, static fn (string $line): bool =>
        stripos($line, 'Info') !== false || stripos($line, 'Error') !== false
    ));
    $heizstabprotocol = implode("\n", array_slice($lines, -9));
} else {
    $errors['heating.protocol'] = 'Protokolldatei ist nicht lesbar.';
}

$backuperrors = [];
$backupprotocol = [];
$backupReadable = is_readable(BACKUP_LOG_FILE);
$backupExists = file_exists(BACKUP_LOG_FILE);

if ($backupReadable) {
    $lines = file(
        BACKUP_LOG_FILE,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        $errors['backup.protocol'] = 'Datei konnte nicht gelesen werden.';
    } else {
        $backupprotocol = array_slice($lines, -12);

        $errorLines = array_filter(
            $lines,
            static fn (string $line): bool =>
                stripos($line, 'Fehler') !== false
        );

        $backuperrors = array_slice(
            array_values($errorLines),
            -9
        );
    }
} else {
    $errors['backup.protocol'] ='Nicht lesbar: ' . BACKUP_LOG_FILE;
}

$total = @disk_total_space('/');
$free = @disk_free_space('/');
$diskUsagePhp = null;
$diskUsed = null;
if (is_numeric($total) && (float) $total > 0 && is_numeric($free)) {
    $total = (float) $total;
    $free = (float) $free;
    $diskUsed = $total - $free;
    $diskUsagePhp = round(($diskUsed / $total) * 100, 2);
} else {
    $errors['system.diskUsage'] = 'Festplattenbelegung konnte nicht ermittelt werden.';
}

$diskUsageDf = null;
$diskScriptPartition = null;
$diskCheckResult = null;
$diskCheckScript = null;
foreach (DISK_CHECK_SCRIPTS as $candidate) {
    if (is_readable($candidate)) {
        $diskCheckScript = $candidate;
        break;
    }
}
$diskCheckScriptExecuted = false;
if ($diskCheckScript !== null && function_exists('shell_exec')) {
    $diskScriptOutput = shell_exec('bash ' . escapeshellarg($diskCheckScript));
    $diskCheckScriptExecuted = true;
    try {
        $diskScriptResult = json_decode(trim((string) $diskScriptOutput), true, 512, JSON_THROW_ON_ERROR);
        $diskCheckResult = $diskScriptResult;
        $diskScriptPartition = $diskScriptResult[0]['Partition'] ?? null;
        $diskUsageDf = isset($diskScriptResult[0]['value'])
            ? (float) $diskScriptResult[0]['value']
            : null;
        if ($diskUsageDf === null) {
            $errors['system.diskUsageDf'] = 'Das Festplattenscript lieferte keinen Belegungswert.';
        }
    } catch (Throwable $error) {
        $errors['system.diskUsageDf'] = 'Ungültige Scriptausgabe: ' . $error->getMessage();
    }
} else {
    $errors['system.diskUsageDf'] = 'check_disk_usage.sh wurde an keinem bekannten Pfad gefunden oder shell_exec ist deaktiviert.';
}

$diskFilesystem = $diskScriptPartition;
$diskMountPoint = '/';
if (function_exists('shell_exec')) {
    $dfOutput = trim((string) shell_exec('df -PB1 / 2>/dev/null | tail -n 1'));
    $dfParts = preg_split('/\s+/', $dfOutput);
    if (is_array($dfParts) && count($dfParts) >= 6) {
        $diskFilesystem = $dfParts[0];
        $diskMountPoint = $dfParts[5];
        if ($diskUsageDf === null) {
            $diskUsageDf = (float) rtrim($dfParts[4], '%');
        }
    }
}

$serverRunning = false;
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlineFile) {
    $commandLine = @file_get_contents($cmdlineFile);
    if (is_string($commandLine) && str_contains($commandLine, 'json-heizung.php')) {
        $serverRunning = true;
        break;
    }
}

echo json_encode([
    'ok' => true,
    'readAt' => date(DATE_ATOM),
    'values' => [
        'system' => [
            // Bestehender Pfad bleibt kompatibel und verwendet weiterhin PHP.
            'diskUsage' => $diskUsagePhp,
            'diskUsagePhp' => $diskUsagePhp,
            'diskUsageDf' => $diskUsageDf,
            'diskCheckResult' => $diskCheckResult,
            'diskCheckScript' => $diskCheckScript,
            'diskCheckScriptExecuted' => $diskCheckScriptExecuted,
            'diskFilesystem' => $diskFilesystem,
            'diskMountPoint' => $diskMountPoint,
            'diskTotalBytes' => is_numeric($total) ? (int) $total : null,
            'diskTotalHuman' => is_numeric($total) ? formatBytes($total) : null,
            'diskUsedBytes' => is_numeric($diskUsed) ? (int) $diskUsed : null,
            'diskUsedHuman' => is_numeric($diskUsed) ? formatBytes($diskUsed) : null,
            'diskFreeBytes' => is_numeric($free) ? (int) $free : null,
            'diskFreeHuman' => is_numeric($free) ? formatBytes($free) : null,
        ],
        'heating' => [
            'serverStatus' => $serverRunning ? 1 : 0,
            'intervals' => $intervals,
            'protocol' => $heizstabprotocol,
        ],
        'backup' => [
            'file' => BACKUP_LOG_FILE,
            'exists' => $backupExists,
            'readable' => $backupReadable,
            'errors' => $backuperrors,
            'protocol' => $backupprotocol,
            'readError' => $errors['backup.protocol'] ?? null,
        ],
    ],
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

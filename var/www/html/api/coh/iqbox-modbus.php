<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function iqboxRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function iqboxPrivateHost(string $host): bool
{
    if (strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.local')) {
        return true;
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

$configuredToken = trim((string) (getenv('COH_API_TOKEN') ?: ''));
$tokenFile = '/home/peter/coh/config/api-token';
if ($configuredToken === '' && is_readable($tokenFile)) {
    $configuredToken = trim((string) file_get_contents($tokenFile));
}
if ($configuredToken === '') {
    iqboxRespond(503, ['ok' => false, 'error' => 'API-Token ist auf dem Raspberry nicht konfiguriert']);
}
$requestToken = (string) ($_SERVER['HTTP_X_COH_TOKEN'] ?? ($_GET['token'] ?? ''));
if (!hash_equals($configuredToken, $requestToken)) {
    iqboxRespond(401, ['ok' => false, 'error' => 'unauthorized']);
}

$host = trim((string) ($_GET['host'] ?? 'ASP-HSR2103J2311E08738.local'));
$port = (int) ($_GET['port'] ?? 502);
$unitId = (int) ($_GET['unitId'] ?? 1);
$timeout = (float) ($_GET['timeout'] ?? 3);
if ($host === '' || !iqboxPrivateHost($host)) {
    iqboxRespond(400, ['ok' => false, 'error' => 'Nur lokale StoragePro-Adressen sind erlaubt']);
}
if ($port < 1 || $port > 65535 || $unitId < 0 || $unitId > 255 || $timeout < 0.1 || $timeout > 30) {
    iqboxRespond(400, ['ok' => false, 'error' => 'Ungueltige Modbus-Verbindungsparameter']);
}

$classCandidates = [
    '/home/peter/coh/sensorCollect/Sensor/AmpereStorageProModbus.php',
    dirname(__DIR__, 4) . '/sensorCollect/Sensor/AmpereStorageProModbus.php',
];
$classFile = null;
foreach ($classCandidates as $candidate) {
    if (is_readable($candidate)) {
        $classFile = $candidate;
        break;
    }
}
if ($classFile === null) {
    iqboxRespond(500, ['ok' => false, 'error' => 'AmpereStorageProModbus.php fehlt auf dem Raspberry']);
}

try {
    require_once $classFile;
    if (!class_exists('AmpereStorageProModbus')) {
        iqboxRespond(500, ['ok' => false, 'error' => 'AmpereStorageProModbus-Klasse konnte nicht geladen werden']);
    }
    $client = new AmpereStorageProModbus($host, $port, $unitId, $timeout);
    $snapshot = $client->readSnapshot();
    $client->close();
    iqboxRespond(200, ['ok' => true, 'readAt' => date(DATE_ATOM), 'snapshot' => $snapshot]);
} catch (Throwable $error) {
    iqboxRespond(502, ['ok' => false, 'error' => 'StoragePro-Modbus: ' . $error->getMessage()]);
}

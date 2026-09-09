<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/api_env.php';
$configuredToken = cohRequireApiToken();

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function isPrivateDeviceHost(string $host): bool
{
    if (strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.local')) {
        return true;
    }

    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

$token = $_SERVER['HTTP_X_COH_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals($configuredToken, (string) $token)) {
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

$deviceUrl = trim((string) ($_GET['deviceUrl'] ?? ''));
if ($deviceUrl === '') {
    respond(400, ['ok' => false, 'error' => 'deviceUrl fehlt']);
}
if (!preg_match('~^https?://~i', $deviceUrl)) {
    $deviceUrl = 'http://' . $deviceUrl;
}
$parts = parse_url($deviceUrl);
$host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
if ($host === '' || !isPrivateDeviceHost($host)) {
    respond(400, ['ok' => false, 'error' => 'Nur lokale Tasmota-Adressen sind erlaubt']);
}

$url = rtrim($deviceUrl, '/') . '/cm?cmnd=' . rawurlencode('Status 10');
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($body === false) {
    respond(502, ['ok' => false, 'error' => 'Tasmota-cURL-Fehler: ' . $error]);
}
if ($status !== 200) {
    respond(502, ['ok' => false, 'error' => 'Tasmota HTTP ' . $status]);
}

try {
    $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    respond(502, ['ok' => false, 'error' => 'Ungültige Tasmota-JSON-Antwort: ' . $error->getMessage()]);
}
if (!is_array($data)) {
    respond(502, ['ok' => false, 'error' => 'Tasmota-Antwort ist kein JSON-Objekt']);
}

$scriptVariables = [
    'Verbrauch_heute' => 'bez_tag',
    'Einspeisung_heute' => 'einsp_tag',
    'Jahr_aktuell' => 'akt_jahr',
    'Verbrauch_Jahr' => 'bez_jahr',
    'Einspeisung_Jahr' => 'einsp_jahr',
    'Jahr_Vorjahr' => 'vor_jahr',
    'Verbrauch_Vorjahr' => 'bez_vjahr',
    'Einspeisung_Vorjahr' => 'einsp_vjahr',
];
foreach ($scriptVariables as $jsonName => $scriptName) {
    $scriptUrl = rtrim($deviceUrl, '/') . '/cm?cmnd=' . rawurlencode('script?' . $scriptName);
    $scriptCh = curl_init($scriptUrl);
    curl_setopt_array($scriptCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $scriptBody = curl_exec($scriptCh);
    $scriptStatus = (int) curl_getinfo($scriptCh, CURLINFO_HTTP_CODE);
    $scriptError = curl_error($scriptCh);
    curl_close($scriptCh);

    if ($scriptBody === false) {
        respond(502, ['ok' => false, 'error' => 'Tasmota-Script-cURL-Fehler: ' . $scriptError]);
    }
    if ($scriptStatus !== 200) {
        respond(502, ['ok' => false, 'error' => 'Tasmota-Script HTTP ' . $scriptStatus]);
    }

    try {
        $scriptData = json_decode((string) $scriptBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        respond(502, ['ok' => false, 'error' => 'Ungueltige Tasmota-Script-JSON-Antwort: ' . $error->getMessage()]);
    }
    if (!array_key_exists($scriptName, $scriptData['script'] ?? [])) {
        respond(502, ['ok' => false, 'error' => "Tasmota-Scriptwert '$scriptName' fehlt"]);
    }

    $data['StatusSNS'][$jsonName] = $scriptData['script'][$scriptName];
}

respond(200, [
    'ok' => true,
    'readAt' => date(DATE_ATOM),
    'data' => $data,
]);

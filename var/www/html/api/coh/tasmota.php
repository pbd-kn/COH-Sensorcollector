<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const COH_API_TOKEN = 'COH_CODE';

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
if (!hash_equals(COH_API_TOKEN, (string) $token)) {
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

respond(200, [
    'ok' => true,
    'readAt' => date(DATE_ATOM),
    'data' => $data,
]);
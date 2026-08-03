<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const SYR_KEYS = [
    'VLV','BAT','FLO','BAR','CEL','PRF','SRN','VER','WIP','WGW','MAC1','EIP','EGW','MAC2','WFS','WFR',
    'ALA','WRN','NOT','ALM','ALW','ALN','VOL','CND','WTI','CEN','DSV','DRP','DTT','DTC','DOM','DST','DMA',
    'MM','DBD','DBT','DPL','DCM','AMA','ALD','SLP','SLE','SLV','SLT','SLF','SOF','SLO','SMF',
];

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requestHeader(string $name): string
{
    $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$serverName] ?? ''));
}

function normalizeDeviceUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('deviceUrl fehlt.');
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'http://' . $url;
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        throw new RuntimeException('Ungueltige deviceUrl.');
    }
    $authority = (!empty($parts['user'])
        ? rawurlencode((string) $parts['user']) . (!empty($parts['pass']) ? ':' . rawurlencode((string) $parts['pass']) : '') . '@'
        : '')
        . (string) $parts['host'] . ':' . (int) ($parts['port'] ?? 5333);

    return strtolower((string) $parts['scheme']) . '://' . $authority . rtrim((string) ($parts['path'] ?? ''), '/');
}

function syrGet(string $key, string $baseUrl): array
{
    $url = $baseUrl . '/trio/get/' . rawurlencode(strtolower($key));
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($handle);
    if ($body === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException("SYR-cURL-Fehler fuer $key: $error");
    }
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("SYR HTTP $status fuer $key.");
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("SYR lieferte ungueltiges JSON fuer $key: " . json_last_error_msg());
    }

    return $decoded;
}

function singleValue(array $payload, string $key): mixed
{
    foreach (['get' . $key, $key] as $payloadKey) {
        if (array_key_exists($payloadKey, $payload)) {
            return $payload[$payloadKey];
        }
    }

    return count($payload) === 1 ? reset($payload) : null;
}

$expectedToken = (string) (getenv('COH_API_TOKEN') ?: 'COH_CODE');
$providedToken = requestHeader('X-COH-TOKEN');
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    respond(401, ['ok' => false, 'error' => 'Unauthorized']);
}

try {
    $baseUrl = normalizeDeviceUrl((string) ($_GET['deviceUrl'] ?? ''));
    $raw = syrGet('all', $baseUrl);
    $data = [];

    if ($raw !== []) {
        foreach ($raw as $payloadKey => $value) {
            if (!is_string($payloadKey) || !str_starts_with($payloadKey, 'get')) {
                continue;
            }
            $key = strtoupper(substr($payloadKey, 3));
            if ($key !== '') {
                $data[$key] = $value;
            }
        }
    } else {
        foreach (SYR_KEYS as $key) {
            $data[$key] = singleValue(syrGet($key, $baseUrl), $key);
        }
    }

    // /get/all liefert diese drei Alarmwerte fehlerhaft.
    foreach (['ALM', 'ALW', 'ALN'] as $key) {
        $data[$key] = singleValue(syrGet($key, $baseUrl), $key);
    }

    respond(200, ['ok' => true, 'data' => $data]);
} catch (Throwable $error) {
    respond(502, ['ok' => false, 'error' => $error->getMessage()]);
}

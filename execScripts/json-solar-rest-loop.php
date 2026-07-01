<?php

// Interaktiver Leser fuer die REST-API der Smartbox/IQ-Box.
// Leere Eingabe zeigt alle Werte, "raw" schreibt die komplette JSON-Antwort als Datei, "q" beendet.
// Eingaben mit % funktionieren wie SQL LIKE, z.B. "%battery%power%".

$baseUrl = 'http://192.168.178.26';
$username = 'installer';
$password = 'sfjimorx';
$cookieFile = __DIR__ . '/ampere_cookie.txt';

date_default_timezone_set('Europe/Berlin');

if (isset($argv[1]) && trim($argv[1]) !== '') {
    $baseUrl = normalizeBaseUrl($argv[1]);
} else {
    $baseUrl = normalizeBaseUrl($baseUrl);
}

function normalizeBaseUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('~^https?://~i', $value)) {
        $value = 'http://' . $value;
    }

    return rtrim($value, '/');
}

function ampereLogin(string $baseUrl, string $username, string $password, string $cookieFile): void
{
    $dir = dirname($cookieFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $ch = curl_init($baseUrl . '/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $username,
            'password' => $password,
        ]),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Login failed: ' . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($code, [200, 302, 303], true)) {
        throw new RuntimeException("Login HTTP error $code");
    }
}

function ampereRequest(string $baseUrl, string $path, string $username, string $password, string $cookieFile, bool $retry = false): array
{
    if (!file_exists($cookieFile)) {
        ampereLogin($baseUrl, $username, $password, $cookieFile);
    }

    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Connection: close'],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (in_array($code, [301, 302, 303, 401, 403], true)) {
        if ($retry) {
            throw new RuntimeException("Auth failed after retry HTTP $code");
        }

        @unlink($cookieFile);
        ampereLogin($baseUrl, $username, $password, $cookieFile);
        return ampereRequest($baseUrl, $path, $username, $password, $cookieFile, true);
    }

    if ($code !== 200) {
        throw new RuntimeException("HTTP error $code");
    }

    return [
        'ok' => true,
        'http_code' => 200,
        'data' => (string)$response,
    ];
}

function loadItemsRaw(string $baseUrl, string $username, string $password, string $cookieFile): array
{
    $result = ampereRequest($baseUrl, '/rest/items/', $username, $password, $cookieFile);
    $data = json_decode($result['data'], true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg());
    }

    return $data;
}

function normalizeItems(array $data): array
{
    if (isset($data['items']) && is_array($data['items'])) {
        return $data['items'];
    }

    return $data;
}

function normalizeUnit(string $unit): string
{
    $unit = trim($unit);
    $unit = @iconv('UTF-8', 'UTF-8//IGNORE', $unit);
    $unit = str_replace(["\xEF\xBB\xBF", 'Â'], '', $unit);
    return strtolower(trim($unit));
}

function convertState($state): array
{
    $rawState = $state;
    if ($state === null || $state === 'NULL' || $state === 'UNDEF') {
        return [
            'valid' => false,
            'rawState' => $rawState,
            'timestampMs' => '',
            'timestampUnix' => '',
            'datetime' => '',
            'rawValue' => '',
            'rawUnit' => '',
            'value' => '',
            'unit' => '',
        ];
    }

    $stateText = trim((string)$state);
    $timestampMs = '';
    $timestampUnix = '';
    $datetime = '';
    $valuePart = $stateText;

    if (str_contains($stateText, '|')) {
        [$maybeTimestamp, $rest] = explode('|', $stateText, 2);
        if (ctype_digit($maybeTimestamp)) {
            $timestampMs = $maybeTimestamp;
            $seconds = (int)floor(((int)$timestampMs) / 1000);
            $timestampUnix = (string)$seconds;
            $datetime = date('Y-m-d H:i:s', $seconds);
            $valuePart = trim($rest);
        }
    }

    $rawValue = $valuePart;
    $rawUnit = '';
    $value = $valuePart;
    $unit = '';

    if (preg_match('/^\s*([+-]?[0-9]+(?:[.,][0-9]+)?(?:[eE][+-]?[0-9]+)?)\s*(.*?)\s*$/u', $valuePart, $m)) {
        $rawValue = $m[1];
        $rawUnit = $m[2] ?? '';
        $numeric = (float)str_replace(',', '.', $rawValue);
        $unitNormalized = normalizeUnit($rawUnit);

        switch ($unitNormalized) {
            case 'w':
                $value = round($numeric / 1000, 4);
                $unit = 'kW';
                break;
            case 'kw':
                $value = round($numeric, 4);
                $unit = 'kW';
                break;
            case 'wh':
                $value = round($numeric / 1000, 4);
                $unit = 'kWh';
                break;
            case 'ws':
                $value = round($numeric / 3600000, 4);
                $unit = 'kWh';
                break;
            case 'kwh':
                $value = round($numeric, 4);
                $unit = 'kWh';
                break;
            case '%':
                $value = round($numeric, 4);
                $unit = '%';
                break;
            case 'a':
                $value = round($numeric, 4);
                $unit = 'A';
                break;
            case 'v':
                $value = round($numeric, 4);
                $unit = 'V';
                break;
            case 'c':
            case '°c':
                $value = round($numeric, 4);
                $unit = 'C';
                break;
            default:
                $value = round($numeric, 4);
                $unit = $rawUnit;
                break;
        }
    }

    return [
        'valid' => true,
        'rawState' => $rawState,
        'timestampMs' => $timestampMs,
        'timestampUnix' => $timestampUnix,
        'datetime' => $datetime,
        'rawValue' => $rawValue,
        'rawUnit' => $rawUnit,
        'value' => $value,
        'unit' => $unit,
    ];
}

function nameShort(string $name): string
{
    $name = preg_replace('/^sajhybrid_/', '', $name) ?? $name;
    $name = preg_replace('/HSR[0-9A-Z]+_/', '', $name) ?? $name;
    return $name;
}

function likeToRegex(string $pattern): string
{
    $regex = preg_quote($pattern, '/');
    $regex = str_replace('%', '.*', $regex);
    return '/^' . $regex . '$/i';
}

function itemMatches(array $item, string $filter): bool
{
    $filter = trim($filter);
    if ($filter === '') {
        return true;
    }

    $haystacks = [
        (string)($item['name'] ?? ''),
        (string)($item['label'] ?? ''),
        (string)($item['link'] ?? ''),
        (string)($item['state'] ?? ''),
        (string)($item['type'] ?? ''),
    ];

    if (str_contains($filter, '%')) {
        $regex = likeToRegex($filter);
        foreach ($haystacks as $text) {
            if (preg_match($regex, $text)) {
                return true;
            }
        }
        return false;
    }

    foreach ($haystacks as $text) {
        if (strcasecmp($text, $filter) === 0 || stripos($text, $filter) !== false) {
            return true;
        }
    }

    return false;
}

function formatNullable($value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return (string)$value;
}

function printHelp(): void
{
    echo PHP_EOL;
    echo 'Bedienung:' . PHP_EOL;
    echo '  leer          alle Werte anzeigen' . PHP_EOL;
    echo '  text          Suche nach Text in Name, Label, Link, State oder Typ' . PHP_EOL;
    echo '  %text%        LIKE-Suche mit % als Platzhalter, z.B. %battery%power%' . PHP_EOL;
    echo '  r             REST-Daten neu laden' . PHP_EOL;
    echo '  raw           komplette JSON-Antwort als Datei speichern' . PHP_EOL;
    echo '  ?             diese Hilfe anzeigen' . PHP_EOL;
    echo '  q             beenden' . PHP_EOL;
}

function writeRawJsonFile(array $rawData): string
{
    $file = __DIR__ . '/json-solar-rest-loop-raw.json';
    $json = json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Raw JSON konnte nicht codiert werden: ' . json_last_error_msg());
    }

    if (file_put_contents($file, $json . PHP_EOL) === false) {
        throw new RuntimeException("Raw JSON konnte nicht geschrieben werden: $file");
    }

    return $file;
}

function printItems(array $items, string $filter): void
{
    $rows = [];
    foreach ($items as $index => $item) {
        if (!is_array($item) || !itemMatches($item, $filter)) {
            continue;
        }

        $state = $item['state'] ?? null;
        $converted = convertState($state);
        $rows[] = [
            'lfnr' => $index,
            'label' => (string)($item['label'] ?? ''),
            'datetime' => $converted['datetime'],
            'timestampMs' => $converted['timestampMs'],
            'timestampUnix' => $converted['timestampUnix'],
            'rawState' => formatNullable($converted['rawState']),
            'rawValue' => (string)$converted['rawValue'],
            'rawUnit' => (string)$converted['rawUnit'],
            'value' => (string)$converted['value'],
            'unit' => (string)$converted['unit'],
            'readOnly' => isset($item['stateDescription']['readOnly'])
                ? ($item['stateDescription']['readOnly'] ? 'true' : 'false')
                : '',
            'nameShort' => nameShort((string)($item['name'] ?? '')),
            'name' => (string)($item['name'] ?? ''),
            'type' => (string)($item['type'] ?? ''),
            'link' => (string)($item['link'] ?? ''),
        ];
    }

    echo PHP_EOL;
    echo 'Treffer: ' . count($rows) . PHP_EOL;
    echo str_repeat('=', 100) . PHP_EOL;

    foreach ($rows as $row) {
        echo '[' . $row['lfnr'] . '] ' . $row['name'] . PHP_EOL;
        echo '    Label:     ' . $row['label'] . PHP_EOL;
        echo '    Kurzname:  ' . $row['nameShort'] . PHP_EOL;
        echo '    Typ:       ' . $row['type'] . PHP_EOL;
        echo '    Raw:       ' . $row['rawState'] . PHP_EOL;
        echo '    Wert:      ' . $row['value'] . ($row['unit'] !== '' ? ' ' . $row['unit'] : '') . PHP_EOL;

        if ($row['rawValue'] !== '' || $row['rawUnit'] !== '') {
            echo '    Rohwert:   ' . $row['rawValue'] . ($row['rawUnit'] !== '' ? ' ' . $row['rawUnit'] : '') . PHP_EOL;
        }

        if ($row['datetime'] !== '') {
            echo '    Zeit:      ' . $row['datetime'] . PHP_EOL;
            echo '    Unix:      ' . $row['timestampUnix'] . PHP_EOL;
            echo '    Unix ms:   ' . $row['timestampMs'] . PHP_EOL;
        }

        echo '    ReadOnly:  ' . $row['readOnly'] . PHP_EOL;
        echo '    Link:      ' . $row['link'] . PHP_EOL;
        echo str_repeat('-', 100) . PHP_EOL;
    }
}

try {
    echo "Smartbox REST: $baseUrl" . PHP_EOL;
    echo 'Lade /rest/items ...' . PHP_EOL;
    $rawData = loadItemsRaw($baseUrl, $username, $password, $cookieFile);
    $items = normalizeItems($rawData);
    echo 'Items geladen: ' . count($items) . PHP_EOL;
    printHelp();

    while (true) {
        echo PHP_EOL . 'Filter> ';
        $line = fgets(STDIN);
        if ($line === false) {
            echo PHP_EOL;
            break;
        }

        $filter = trim($line);
        if (in_array(strtolower($filter), ['q', 'quit', 'exit'], true)) {
            break;
        }

        if (in_array(strtolower($filter), ['?', 'h', 'help', 'hilfe'], true)) {
            printHelp();
            continue;
        }

        if (in_array(strtolower($filter), ['r', 'reload'], true)) {
            $rawData = loadItemsRaw($baseUrl, $username, $password, $cookieFile);
            $items = normalizeItems($rawData);
            echo 'Items neu geladen: ' . count($items) . PHP_EOL;
            continue;
        }

        if (in_array(strtolower($filter), ['raw', 'json'], true)) {
            $file = writeRawJsonFile($rawData);
            echo "Raw JSON gespeichert: $file" . PHP_EOL;
            continue;
        }

        printItems($items, $filter);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

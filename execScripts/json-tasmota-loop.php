<?php

declare(strict_types=1);

/*
 * Interaktiver Lesetest fuer Tasmota Status 10.
 * Modi:
 *   local - Tasmota im lokalen Netz direkt lesen
 *   http  - Tasmota ueber /api/coh/tasmota.php auf dem Raspberry lesen
 *
 * Einzelwerte aus StatusSNS.M60:
 *   TS_E_in_108   - bezogene Energie in kWh
 *   TS_E_out_208  - gelieferte Energie in kWh
 *   TS_Power      - Gesamtleistung in W
 *   TS_Power_L1   - Leistung Phase L1 in W
 *   TS_Power_L2   - Leistung Phase L2 in W
 *   TS_Power_L3   - Leistung Phase L3 in W
 *
 * Beispiele:
 *   php json-tasmota-loop.php
 *   php json-tasmota-loop.php --mode=local --url=http://192.168.178.69
 *   php json-tasmota-loop.php --mode=http --http-url=http://192.168.178.49
 *   php json-tasmota-loop.php --params=task_tasmota_params.json
 */

date_default_timezone_set('Europe/Berlin');

$options = parseCliOptions($argv);
$paramsFile = (string) ($options['params'] ?? (__DIR__ . '/task_tasmota_params.json'));
$params = loadJsonFile($paramsFile);
$localConfig = is_array($params['local'] ?? null) ? $params['local'] : [];
$httpConfig = is_array($params['http'] ?? null) ? $params['http'] : [];

$mode = strtolower((string) ($options['mode'] ?? ($params['mode'] ?? 'local')));
$deviceUrl = normalizeBaseUrl((string) ($options['url'] ?? ($localConfig['deviceUrl'] ?? 'http://192.168.178.69')));
$httpBaseUrl = normalizeBaseUrl((string) ($options['http-url'] ?? ($httpConfig['baseUrl'] ?? 'http://192.168.178.49')));
$httpPath = '/' . ltrim((string) ($options['http-path'] ?? ($httpConfig['path'] ?? '/api/coh/tasmota.php')), '/');
$httpToken = (string) ($options['token'] ?? ($httpConfig['token'] ?? 'COH_CODE'));
$timeout = max(1, (int) ($options['timeout'] ?? ($params['timeout'] ?? 15)));

if (!in_array($mode, ['local', 'http'], true)) {
    fwrite(STDERR, "FEHLER: Modus muss 'local' oder 'http' sein.\n");
    exit(1);
}

try {
    printConfiguration();
    $rawData = loadTasmotaData();
    $items = flattenJson($rawData);
    echo 'Werte geladen: ' . count($items) . PHP_EOL;
    printHelp();

    while (true) {
        echo PHP_EOL . 'Filter> ';
        $line = fgets(STDIN);
        if ($line === false) {
            echo PHP_EOL;
            break;
        }
        $input = trim($line);
        $lower = strtolower($input);

        if (in_array($lower, ['q', 'quit', 'exit'], true)) {
            break;
        }
        if (in_array($lower, ['?', 'h', 'help', 'hilfe'], true)) {
            printHelp();
            continue;
        }
        if (in_array($lower, ['sensoren', 'sensors', 'einzelwerte'], true)) {
            printDocumentedSensors();
            continue;
        }
        if (in_array($lower, ['r', 'reload'], true)) {
            $rawData = loadTasmotaData();
            $items = flattenJson($rawData);
            echo 'Werte neu geladen: ' . count($items) . PHP_EOL;
            continue;
        }
        if (preg_match('/^mode\s+(local|http)$/i', $input, $matches)) {
            $mode = strtolower($matches[1]);
            echo "Modus gewechselt: $mode" . PHP_EOL;
            printConfiguration();
            $rawData = loadTasmotaData();
            $items = flattenJson($rawData);
            echo 'Werte geladen: ' . count($items) . PHP_EOL;
            continue;
        }
        if (in_array($lower, ['raw', 'json'], true)) {
            $file = __DIR__ . '/tasmota-status-' . date('Ymd-His') . '.json';
            $json = json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
                echo 'WARNUNG: Raw-JSON konnte nicht geschrieben werden.' . PHP_EOL;
            } else {
                echo "Raw-JSON gespeichert: $file" . PHP_EOL;
            }
            continue;
        }
        if (preg_match('/^(?:get|wert|value)\s+(.+)$/i', $input, $matches)) {
            printPath($rawData, trim($matches[1]));
            continue;
        }

        printItems($items, $input);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

function loadTasmotaData(): array
{
    global $mode, $deviceUrl, $httpBaseUrl, $httpPath, $httpToken, $timeout;

    if ($mode === 'local') {
        $url = rtrim($deviceUrl, '/') . '/cm?cmnd=' . rawurlencode('Status 10');
        return requestJson($url, [], $timeout);
    }

    $url = rtrim($httpBaseUrl, '/') . $httpPath . '?' . http_build_query(
        ['deviceUrl' => $deviceUrl],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $payload = requestJson($url, ['X-COH-TOKEN: ' . $httpToken], $timeout);
    if (empty($payload['ok']) || !is_array($payload['data'] ?? null)) {
        throw new RuntimeException('Ungueltige Raspberry-Tasmota-Antwort: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    return $payload['data'];
}

function requestJson(string $url, array $headers, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("cURL-Fehler bei $url: $error");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("HTTP $status bei $url: " . trim((string) $body));
    }

    try {
        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException("Ungueltige JSON-Antwort von $url: " . $error->getMessage(), 0, $error);
    }
    if (!is_array($data)) {
        throw new RuntimeException("JSON-Antwort von $url ist kein Objekt.");
    }

    return $data;
}

function flattenJson(array $data, string $prefix = ''): array
{
    $result = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $result = array_merge($result, flattenJson($value, $path));
        } else {
            $result[] = ['key' => $path, 'value' => $value];
        }
    }

    return $result;
}

function printItems(array $items, string $filter): void
{
    $matches = 0;
    foreach ($items as $item) {
        if ($filter !== '' && !matchesFilter($item['key'], $filter)) {
            continue;
        }
        echo $item['key'] . ': ' . formatValue($item['value']) . PHP_EOL;
        ++$matches;
    }
    if ($matches === 0) {
        echo 'Keine passenden Werte.' . PHP_EOL;
    }
}

function matchesFilter(string $key, string $filter): bool
{
    if (str_contains($filter, '%')) {
        $pattern = '~^' . str_replace('%', '.*', preg_quote($filter, '~')) . '$~i';
        return preg_match($pattern, $key) === 1;
    }

    return stripos($key, $filter) !== false;
}

function printPath(array $data, string $path): void
{
    $path = preg_replace('~^tasmota\.~i', '', $path);
    $cursor = $data;
    foreach (explode('.', (string) $path) as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            echo "Pfad nicht gefunden: $path" . PHP_EOL;
            return;
        }
        $cursor = $cursor[$part];
    }

    echo $path . ': ' . formatValue($cursor) . PHP_EOL;
}

function formatValue(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return 'null';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    return (string) $value;
}

function printConfiguration(): void
{
    global $mode, $deviceUrl, $httpBaseUrl, $httpPath, $httpToken, $paramsFile, $timeout;

    echo 'Tasmota Status-10-Test' . PHP_EOL;
    echo "Modus:       $mode" . PHP_EOL;
    echo "Tasmota:     $deviceUrl" . PHP_EOL;
    echo "HTTP API:    $httpBaseUrl$httpPath" . PHP_EOL;
    echo 'HTTP Token:  ' . ($httpToken !== '' ? 'gesetzt' : 'fehlt') . PHP_EOL;
    echo "Timeout:     $timeout s" . PHP_EOL;
    echo "Parameter:   $paramsFile" . PHP_EOL;
}

function printHelp(): void
{
    echo PHP_EOL;
    echo "Befehle:\n";
    echo "  [leer]                  alle Werte anzeigen\n";
    echo "  TEXT oder %MUSTER%      Werte filtern\n";
    echo "  get PFAD                Einzelwert lesen, z.B. get StatusSNS.M60.TS_Power\n";
    echo "  sensoren                dokumentierte Einzelwerte und Einheiten anzeigen\n";
    echo "  mode local|http         Zugriffsart wechseln\n";
    echo "  r                       Daten neu laden\n";
    echo "  raw                     kompletten Payload als JSON speichern\n";
    echo "  q                       beenden\n";
}

function printDocumentedSensors(): void
{
    $sensors = [
        'TS_E_in_108' => ['Bezogene Energie', 'kWh'],
        'TS_E_out_208' => ['Gelieferte Energie', 'kWh'],
        'TS_Power' => ['Gesamtleistung', 'W'],
        'TS_Power_L1' => ['Leistung Phase L1', 'W'],
        'TS_Power_L2' => ['Leistung Phase L2', 'W'],
        'TS_Power_L3' => ['Leistung Phase L3', 'W'],
    ];

    echo PHP_EOL . "Dokumentierte Einzelwerte:\n";
    foreach ($sensors as $name => [$description, $unit]) {
        echo sprintf("  %-16s %-22s %s\n", $name, $description, $unit);
        echo "    Pfad: StatusSNS.M60.$name\n";
        echo "    Abruf: get StatusSNS.M60.$name\n";
    }
}

function parseCliOptions(array $arguments): array
{
    $result = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $parts = explode('=', substr($argument, 2), 2);
        $result[$parts[0]] = $parts[1] ?? '1';
    }

    return $result;
}

function loadJsonFile(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("Parameterdatei fehlt: $file");
    }
    try {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException("Ungueltige Parameterdatei $file: " . $error->getMessage(), 0, $error);
    }
    if (!is_array($data)) {
        throw new RuntimeException("Parameterdatei $file enthaelt kein JSON-Objekt.");
    }

    return $data;
}

function normalizeBaseUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        throw new InvalidArgumentException('URL fehlt.');
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'http://' . $url;
    }

    return rtrim($url, '/');
}
<?php
declare(strict_types=1);

/*
 * Interaktiver Leser fuer den my-PV/ELWA Heizstab.
 * Leere Eingabe zeigt alle Werte aus data.jsn und setup.jsn.
 * "raw" schreibt data.jsn und setup.jsn als komplette JSON-Dateien.
 * Eingaben mit "/" rufen einen Heizstab-Pfad direkt auf, z.B. /data.jsn?bststrt=0.
 * "mode local" und "mode api" wechseln zwischen lokalem Heizstab und offizieller my-PV API.
 * "power", "on" und "off" verwenden /control.html und funktionieren nur,
 * wenn vorher per "post ctrl=1" auf HTTP-Regelung geschaltet wurde.
 * "modbus" oder "ctrl2" schaltet danach wieder auf Modbus-TCP (ctrl=2).
 * "q" beendet, "r" laedt neu, "%" funktioniert wie SQL LIKE.
 *
 * Beispiele:
 *   php json-heizstab-rest-loop.php
 *   php json-heizstab-rest-loop.php --url=https://192.168.178.68
 *   php json-heizstab-rest-loop.php --url=https://192.168.178.68 --password=14881488 --insecure=1
 *   php json-heizstab-rest-loop.php --params=task_heizstab_params.json
 */

date_default_timezone_set('Europe/Berlin');

$options = parseCliOptions($argv);
$paramsFile = (string)($options['params'] ?? (__DIR__ . '/task_heizstab_params.json'));
$params = loadJsonFile($paramsFile);
$authConfig = is_array($params['heizstabAuth'] ?? null) ? $params['heizstabAuth'] : [];

$baseUrl = normalizeBaseUrl((string)($options['url'] ?? ($params['urlheizStab'] ?? 'https://192.168.178.68')));
$loginPath = (string)($options['login'] ?? ($authConfig['loginPath'] ?? '/auth.jsn'));
$password = (string)($options['password'] ?? ($authConfig['password'] ?? ''));
$passwordField = (string)($options['field'] ?? ($authConfig['passwordField'] ?? 'pw'));
$username = array_key_exists('username', $options)
    ? (string)$options['username']
    : (isset($authConfig['username']) ? (string)$authConfig['username'] : null);
$usernameField = array_key_exists('username-field', $options)
    ? (string)$options['username-field']
    : (isset($authConfig['usernameField']) ? (string)$authConfig['usernameField'] : null);
$extraFields = is_array($authConfig['extraFields'] ?? null) ? $authConfig['extraFields'] : [];
$insecureTls = filterBool($options['insecure'] ?? ($authConfig['insecureTls'] ?? false));
$authEnabled = filterBool($options['auth'] ?? ($authConfig['enabled'] ?? true));
$cookieDir = (string)($options['cookie-dir'] ?? ($authConfig['cookieDir'] ?? sys_get_temp_dir()));
$cookieFile = (string)($options['cookie'] ?? ($authConfig['cookieFile'] ?? buildDefaultCookieFile($baseUrl, $cookieDir)));
$mode = strtolower((string)($options['mode'] ?? ($params['heizstabRestMode'] ?? 'local')));
$apiConfig = is_array($params['heizstabApi'] ?? null) ? $params['heizstabApi'] : [];
$apiBaseUrl = normalizeBaseUrl((string)($options['api-url'] ?? ($apiConfig['baseUrl'] ?? 'https://api.my-pv.com/api/v1')));
$apiSerial = (string)($options['api-serial'] ?? ($apiConfig['serial'] ?? ''));
$apiTokenEnv = (string)($apiConfig['apiTokenEnv'] ?? 'MYPV_API_TOKEN');
$apiToken = (string)($options['api-token'] ?? ($apiConfig['apiToken'] ?? (getenv($apiTokenEnv) ?: '')));
$apiInsecureTls = filterBool($options['api-insecure'] ?? ($apiConfig['insecureTls'] ?? false));
$controlConfig = is_array($params['heizstabControl'] ?? null) ? $params['heizstabControl'] : [];
$localPowerOn = max(0, (int)($options['power-on'] ?? ($controlConfig['powerOn'] ?? ($apiConfig['powerOn'] ?? 3000))));

try {
    echo "Heizstab REST: $baseUrl" . PHP_EOL;
    echo "Modus:         $mode" . PHP_EOL;
    echo "Params:        " . (is_file($paramsFile) ? $paramsFile : 'nicht gefunden') . PHP_EOL;
    echo "Login URL:     " . buildUrl($baseUrl, $loginPath) . PHP_EOL;
    echo "Login aktiv:   " . ($authEnabled ? 'ja' : 'nein') . PHP_EOL;
    echo "Cookie:        $cookieFile" . PHP_EOL;
    echo "TLS check:     " . ($insecureTls ? 'aus' : 'an') . PHP_EOL;
    echo "API URL:       $apiBaseUrl" . PHP_EOL;
    echo "API Serial:    $apiSerial" . PHP_EOL;
    echo "API Token:     " . ($apiToken !== '' ? 'gesetzt' : 'fehlt') . PHP_EOL;
    echo PHP_EOL . ($mode === 'api' ? 'Lade API-Daten ...' : 'Lade data.jsn und setup.jsn ...') . PHP_EOL;

    $rawData = [];
    $items = safeLoadItemsForMode($mode, $baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
    echo 'Werte geladen: ' . count($items) . PHP_EOL;
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
            $items = safeLoadItemsForMode($mode, $baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
            echo 'Werte neu geladen: ' . count($items) . PHP_EOL;
            continue;
        }

        if (preg_match('/^mode\s+(local|api)$/i', $filter, $matches)) {
            $mode = strtolower($matches[1]);
            echo "Modus gewechselt: $mode" . PHP_EOL;
            resetSessionForMode($mode, $cookieFile);
            $items = safeLoadItemsForMode($mode, $baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
            echo 'Werte geladen: ' . count($items) . PHP_EOL;
            continue;
        }

        if (in_array(strtolower($filter), ['login', 'relogin'], true)) {
            resetSessionForMode($mode, $cookieFile);
            $items = safeLoadItemsForMode($mode, $baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
            echo 'Werte geladen: ' . count($items) . PHP_EOL;
            continue;
        }

        if (in_array(strtolower($filter), ['raw', 'json'], true)) {
            $files = writeRawJsonFiles($rawData);
            foreach ($files as $source => $file) {
                echo "Raw JSON gespeichert ($source): $file" . PHP_EOL;
            }
            continue;
        }

        if (in_array(strtolower($filter), ['api endpoints', 'endpoints'], true)) {
            printApiEndpoints();
            continue;
        }

        if (preg_match('/^api\s+get\s+logdata(?:\s+(.*))?$/i', $filter, $matches)) {
            executeApiLogdata(trim((string)($matches[1] ?? '')));
            continue;
        }

        if (preg_match('/^api\s+get\s+(isOnline|isPowerControlPossible|data(?:\/soc)?|solarForecast|setup)$/i', $filter, $matches)) {
            executeApiRequestAndPrint('GET', $matches[1]);
            continue;
        }

        if (preg_match('/^api\s+get\s+(.+)$/i', $filter, $matches)) {
            $name = trim($matches[1]);
            echo "WARNUNG: '$name' ist kein unterstuetzter my-PV-API-Endpunkt." . PHP_EOL;
            echo "Ein geladenes Feld lesen Sie mit 'get $name', eindeutig z.B. 'get api.data.$name'." . PHP_EOL;
            continue;
        }

        if (preg_match('/^(?:get|wert|value|select)\s+(.+)$/i', $filter, $matches)) {
            printSelectedItem($items, trim($matches[1]));
            continue;
        }

        if (preg_match('/^(?:(?:api\s+)?power|post\s+power)\s+(\d+)(?:\s+(\d+))?$/i', $filter, $matches)) {
            $watts = min(6500, (int)$matches[1]);
            $minutes = max(1, (int)($matches[2] ?? 20));
            executeApiRequestAndPrint('POST', 'power', buildApiPowerPayload($watts, $minutes));
            continue;
        }

        if (preg_match('/^(?:api\s+)?put\s+setup\s+(.+)$/i', $filter, $matches)) {
            $payload = json_decode($matches[1], true);
            if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
                echo 'WARNUNG: setup erwartet ein JSON-Objekt, z.B. api put setup {"ww1target":55}' . PHP_EOL;
                continue;
            }
            executeApiRequestAndPrint('PUT', 'setup', $payload);
            continue;
        }

        if (preg_match('/^post\s+(.+)$/i', $filter, $matches)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: post ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabPost($baseUrl, trim($matches[1]), $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (in_array(strtolower($filter), ['http', 'ctrl1', 'ctrl=1'], true)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: http/ctrl1 ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabPost($baseUrl, 'ctrl=1', $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
                echo 'HTTP-Regelung aktiv: power/on/off kann jetzt ueber control.html arbeiten.' . PHP_EOL;
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (in_array(strtolower($filter), ['modbus', 'modbustcp', 'ctrl2', 'ctrl=2'], true)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: modbus/ctrl2 ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabPost($baseUrl, 'ctrl=2', $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
                echo 'Modbus-TCP-Regelung aktiv: ctrl=2.' . PHP_EOL;
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (in_array(strtolower($filter), ['booston', 'boost'], true)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: boost ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabPost($baseUrl, 'bststrt=1', $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (in_array(strtolower($filter), ['boostoff'], true)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: boostoff ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabPost($baseUrl, 'bststrt=0', $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (in_array(strtolower($filter), ['on', 'ein', 'poweron'], true)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: on/ein ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabControlPower($baseUrl, $localPowerOn, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (in_array(strtolower($filter), ['off', 'aus', 'poweroff'], true)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: off/aus ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabControlPower($baseUrl, 0, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (preg_match('/^power\s+(-?\d+)$/i', $filter, $matches)) {
            if ($mode !== 'local') {
                echo 'WARNUNG: power ist nur in mode local erlaubt. Im API-Modus wird kein Schreibbefehl gesendet.' . PHP_EOL;
                continue;
            }

            try {
                executeHeizstabControlPower($baseUrl, max(0, (int)$matches[1]), $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        if (str_starts_with($filter, '/')) {
            try {
                if ($mode === 'api') {
                    executeApiPath($filter);
                } else {
                    executeHeizstabPath($baseUrl, $filter, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
                }
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }

        printItems($items, $filter);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function loadHeizstabItems(
    string &$baseUrl,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields,
    array &$rawData
): array {
    $data = fetchJsonWithRelogin($baseUrl, '/data.jsn', $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
    $setup = fetchJsonWithRelogin($baseUrl, '/setup.jsn', $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);
    $rawData = [
        'data.jsn' => $data,
        'setup.jsn' => $setup,
    ];

    return array_merge(
        flattenJson($data, 'data.jsn'),
        flattenJson($setup, 'setup.jsn')
    );
}

function loadItemsForMode(
    string $mode,
    string &$baseUrl,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields,
    array &$rawData
): array {
    if ($mode === 'api') {
        return loadApiItems($rawData);
    }

    return loadHeizstabItems($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
}

function safeLoadItemsForMode(
    string $mode,
    string &$baseUrl,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields,
    array &$rawData
): array {
    try {
        return loadItemsForMode($mode, $baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
    } catch (Throwable $e) {
        echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;

        $rawData = [];
        return [];
    }
}

function resetSessionForMode(string $mode, string $cookieFile): void
{
    if ($mode === 'local') {
        if (is_file($cookieFile)) {
            @unlink($cookieFile);
        }

        echo 'Lokale Heizstab-Session geloescht, der naechste Zugriff macht einen neuen Login.' . PHP_EOL;
        return;
    }

    if ($mode === 'api') {
        echo 'API-Modus verwendet den my-PV API-Token aus der Parameterdatei, kein Browser-Login noetig.' . PHP_EOL;
        return;
    }
}

function loadApiItems(array &$rawData): array
{
    $data = apiRequest('GET', 'data');
    $setup = apiRequest('GET', 'setup');
    $rawData = [
        'api.data' => $data,
        'api.setup' => $setup,
    ];

    return array_merge(
        flattenJson($data, 'api.data'),
        flattenJson($setup, 'api.setup')
    );
}

function executeApiPath(string $path): void
{
    $endpoint = ltrim($path, '/');
    $result = apiRequest('GET', $endpoint);

    echo 'GET ' . buildApiUrl($endpoint) . PHP_EOL;
    foreach (flattenJson($result, 'api.' . $endpoint) as $item) {
        echo $item['key'] . ': ' . formatValue($item['value']) . PHP_EOL;
    }
}

function executeApiRequestAndPrint(string $method, string $endpoint, ?array $payload = null): void
{
    try {
        $result = apiRequest($method, $endpoint, $payload);
        echo $method . ' ' . buildApiUrl($endpoint) . PHP_EOL;
        if ($payload !== null) {
            echo 'Gesendet: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
        if ($result === []) {
            echo 'Erfolgreich (leere Antwort).' . PHP_EOL;
            return;
        }
        printCompactItems(flattenJson($result, 'api.' . $endpoint));
    } catch (Throwable $e) {
        echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
    }
}

function buildApiPowerPayload(int $watts, int $minutes): array
{
    return [
        'power' => max(0, min(6500, $watts)),
        'validForMinutes' => max(1, $minutes),
        'timeBoostOverride' => 0,
        'timeBoostValue' => 0,
        'legionellaBoostBlock' => 1,
    ];
}

function executeApiLogdata(string $arguments): void
{
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{4}-\d{2}-\d{2})(?:\s+(15m|1h|1d|1mo|1y))?(?:\s+([A-Za-z_]+\/[A-Za-z_]+))?$/', $arguments, $matches)) {
        echo 'Verwendung: api get logdata BEGIN ENDE [INTERVALL] [ZEITZONE]' . PHP_EOL;
        echo 'Beispiel:   api get logdata 2026-07-20 2026-07-21 1h Europe/Berlin' . PHP_EOL;
        echo 'Intervalle: 15m, 1h, 1d, 1mo oder 1y; Zeitzone ist standardmaessig Europe/Berlin.' . PHP_EOL;
        echo 'Hinweis: my-PV erlaubt maximal 50 logdata-Abfragen pro Geraet in 24 Stunden.' . PHP_EOL;
        return;
    }

    $beginDate = $matches[1];
    $endDate = $matches[2];
    if (!isValidIsoDate($beginDate) || !isValidIsoDate($endDate) || $beginDate > $endDate) {
        echo 'WARNUNG: Ungueltiger Zeitraum. Erwartet wird BEGIN <= ENDE im Format JJJJ-MM-TT.' . PHP_EOL;
        return;
    }

    $query = [
        'beginDate' => $beginDate,
        'endDate' => $endDate,
        'timezone' => $matches[4] ?? 'Europe/Berlin',
    ];
    if (($matches[3] ?? '') !== '') {
        $query['interval'] = $matches[3];
    }

    executeApiRequestAndPrint('GET', 'logdata?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
}

function isValidIsoDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function apiRequest(string $method, string $endpoint, ?array $payload = null): array
{
    global $apiBaseUrl, $apiSerial, $apiToken, $apiInsecureTls;

    if ($apiSerial === '') {
        throw new InvalidArgumentException('API serial fehlt');
    }

    if ($apiToken === '') {
        throw new InvalidArgumentException('API token fehlt');
    }

    $url = buildApiUrl($endpoint);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    if ($apiInsecureTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? [], JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("API Request fehlgeschlagen fuer $url: $error");
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("API HTTP Fehler $code fuer $url Antwort: " . trim((string)$response));
    }

    $decoded = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("API Antwort ist kein JSON fuer $url: " . json_last_error_msg() . ' Antwort: ' . trim((string)$response));
    }

    return is_array($decoded) ? $decoded : ['value' => $decoded];
}

function buildApiUrl(string $endpoint): string
{
    global $apiBaseUrl, $apiSerial;

    return rtrim($apiBaseUrl, '/') . '/device/' . rawurlencode($apiSerial) . '/' . ltrim($endpoint, '/');
}

function fetchJsonWithRelogin(
    string &$baseUrl,
    string $path,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): array {
    if ($authEnabled) {
        ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
    }

    $result = curlGet(buildUrl($baseUrl, $path), $cookieFile, $insecureTls, $authEnabled);

    if (shouldSwitchToHttps($baseUrl, $result['http_code'], $result['body'])) {
        $baseUrl = switchBaseUrlToHttps($baseUrl);
        echo "$path HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl" . PHP_EOL;
        @unlink($cookieFile);

        if ($authEnabled) {
            ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        }

        $result = curlGet(buildUrl($baseUrl, $path), $cookieFile, $insecureTls, $authEnabled);
    }

    if ($authEnabled && in_array($result['http_code'], [301, 302, 303, 401, 403], true)) {
        echo "$path Session abgelaufen oder Login erforderlich (HTTP {$result['http_code']}). Re-Login ..." . PHP_EOL;
        @unlink($cookieFile);
        elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        $result = curlGet(buildUrl($baseUrl, $path), $cookieFile, $insecureTls, $authEnabled);
    }

    if ($result['http_code'] !== 200) {
        throw new RuntimeException("GET HTTP Fehler {$result['http_code']} fuer " . buildUrl($baseUrl, $path) . ' Antwort: ' . trim($result['body']));
    }

    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Antwort von $path ist kein JSON: " . json_last_error_msg() . ' Antwort: ' . trim($result['body']));
    }

    if (!is_array($decoded)) {
        throw new RuntimeException("Antwort von $path ist JSON, aber kein Array");
    }

    return $decoded;
}

function executeHeizstabPath(
    string &$baseUrl,
    string $path,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): void {
    $result = fetchRawWithRelogin($baseUrl, $path, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);

    echo 'GET ' . buildUrl($baseUrl, $path) . PHP_EOL;
    echo 'HTTP ' . $result['http_code'] . PHP_EOL;

    $body = trim($result['body']);
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach (flattenJson($decoded, ltrim($path, '/')) as $item) {
            echo $item['path'] . ': ' . formatValue($item['value']) . PHP_EOL;
        }
        return;
    }

    echo $body . PHP_EOL;
}

function executeHeizstabPost(
    string &$baseUrl,
    string $body,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): void {
    $result = postSetupWithRelogin($baseUrl, $body, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields);

    echo 'POST ' . buildUrl($baseUrl, '/setup.jsn') . PHP_EOL;
    echo 'Body: ' . $body . PHP_EOL;
    echo 'HTTP ' . $result['http_code'] . PHP_EOL;

    $responseBody = trim($result['body']);
    $decoded = json_decode($responseBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach (flattenJson($decoded, 'setup.jsn POST') as $item) {
            echo $item['path'] . ': ' . formatValue($item['value']) . PHP_EOL;
        }
        return;
    }

    echo $responseBody . PHP_EOL;
}

function executeHeizstabControlPower(
    string &$baseUrl,
    int $power,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): void {
    $power = max(0, min(6500, $power));
    $body = http_build_query(['power' => $power]);
    $result = postFormWithRelogin($baseUrl, '/control.html', $body, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, false);
    $url = buildUrl($baseUrl, '/control.html');

    echo 'POST ' . $url . PHP_EOL;
    echo 'Full POST: ' . $url . ' Body: ' . $body . PHP_EOL;
    echo 'cURL: ' . buildCurlPostCommand($url, $body, $cookieFile, $insecureTls, $authEnabled) . PHP_EOL;
    echo 'Hinweis: power/on/off funktioniert nur nach HTTP-Regelung: post ctrl=1' . PHP_EOL;
    echo 'Body: ' . $body . PHP_EOL;
    echo 'HTTP ' . $result['http_code'] . PHP_EOL;

    $responseBody = trim($result['body']);
    if ($responseBody !== '') {
        echo $responseBody . PHP_EOL;
    }
}

function buildCurlPostCommand(string $url, string $body, string $cookieFile, bool $insecureTls, bool $authEnabled): string
{
    $parts = ['curl'];

    if ($insecureTls) {
        $parts[] = '-k';
    }

    $parts[] = '-X';
    $parts[] = 'POST';
    $parts[] = '-H';
    $parts[] = shellQuoteForDisplay('Content-Type: application/x-www-form-urlencoded');
    $parts[] = '-H';
    $parts[] = shellQuoteForDisplay('Accept: application/json');

    if ($authEnabled) {
        $parts[] = '-b';
        $parts[] = shellQuoteForDisplay($cookieFile);
        $parts[] = '-c';
        $parts[] = shellQuoteForDisplay($cookieFile);
    }

    $parts[] = '--data';
    $parts[] = shellQuoteForDisplay($body);
    $parts[] = shellQuoteForDisplay($url);

    return implode(' ', $parts);
}

function shellQuoteForDisplay(string $value): string
{
    return '"' . str_replace('"', '\\"', $value) . '"';
}

function fetchRawWithRelogin(
    string &$baseUrl,
    string $path,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): array {
    if ($authEnabled) {
        ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
    }

    $result = curlGet(buildUrl($baseUrl, $path), $cookieFile, $insecureTls, $authEnabled);

    if (shouldSwitchToHttps($baseUrl, $result['http_code'], $result['body'])) {
        $baseUrl = switchBaseUrlToHttps($baseUrl);
        echo "$path HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl" . PHP_EOL;
        @unlink($cookieFile);

        if ($authEnabled) {
            ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        }

        $result = curlGet(buildUrl($baseUrl, $path), $cookieFile, $insecureTls, $authEnabled);
    }

    if ($authEnabled && in_array($result['http_code'], [301, 302, 303, 401, 403], true)) {
        echo "$path Session abgelaufen oder Login erforderlich (HTTP {$result['http_code']}). Re-Login ..." . PHP_EOL;
        @unlink($cookieFile);
        elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        $result = curlGet(buildUrl($baseUrl, $path), $cookieFile, $insecureTls, $authEnabled);
    }

    return $result;
}

function postSetupWithRelogin(
    string &$baseUrl,
    string $body,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): array {
    return postFormWithRelogin($baseUrl, '/setup.jsn', $body, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, true);
}

function postFormWithRelogin(
    string &$baseUrl,
    string $path,
    string $body,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    bool $authEnabled,
    ?string $usernameField,
    ?string $username,
    array $extraFields,
    bool $appendPassword
): array {
    if ($authEnabled) {
        ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
    }

    $postBody = $appendPassword ? appendPasswordField($body, $passwordField, $password, $authEnabled) : $body;
    $result = curlPostForm(buildUrl($baseUrl, $path), $postBody, $cookieFile, $insecureTls, $authEnabled);

    if (shouldSwitchToHttps($baseUrl, $result['http_code'], $result['body'])) {
        $baseUrl = switchBaseUrlToHttps($baseUrl);
        echo "$path HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl" . PHP_EOL;
        @unlink($cookieFile);

        if ($authEnabled) {
            ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        }

        $postBody = $appendPassword ? appendPasswordField($body, $passwordField, $password, $authEnabled) : $body;
        $result = curlPostForm(buildUrl($baseUrl, $path), $postBody, $cookieFile, $insecureTls, $authEnabled);
    }

    if ($authEnabled && in_array($result['http_code'], [301, 302, 303, 401, 403], true)) {
        echo "$path Session abgelaufen oder Login erforderlich (HTTP {$result['http_code']}). Re-Login ..." . PHP_EOL;
        @unlink($cookieFile);
        elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        $postBody = $appendPassword ? appendPasswordField($body, $passwordField, $password, $authEnabled) : $body;
        $result = curlPostForm(buildUrl($baseUrl, $path), $postBody, $cookieFile, $insecureTls, $authEnabled);
    }

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        throw new RuntimeException("POST HTTP Fehler {$result['http_code']} fuer " . buildUrl($baseUrl, $path) . ' Antwort: ' . trim($result['body']));
    }

    return $result;
}

function appendPasswordField(string $body, string $passwordField, string $password, bool $authEnabled): string
{
    if (!$authEnabled || $password === '' || preg_match('/(?:^|&)' . preg_quote($passwordField, '/') . '=/', $body)) {
        return $body;
    }

    return $body . ($body === '' ? '' : '&') . rawurlencode($passwordField) . '=' . rawurlencode($password);
}

function ensureElwaLogin(
    string &$baseUrl,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): void {
    if (file_exists($cookieFile) && filesize($cookieFile) > 0) {
        return;
    }

    if ($password === '') {
        throw new RuntimeException('Login ist aktiv, aber password fehlt');
    }

    elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
}

function elwaLogin(
    string &$baseUrl,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls,
    ?string $usernameField,
    ?string $username,
    array $extraFields
): void {
    $dir = dirname($cookieFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $postFields = $extraFields;
    if ($usernameField !== null && $usernameField !== '' && $username !== null && $username !== '') {
        $postFields[$usernameField] = $username;
    }
    $postFields[$passwordField] = $password;

    $url = buildUrl($baseUrl, $loginPath);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    applyTlsOptions($ch, $insecureTls);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        if (!$insecureTls && isSelfSignedCertificateError($error)) {
            $insecureTls = true;
            echo "Selbstsigniertes Zertifikat erkannt, TLS-Pruefung wird deaktiviert." . PHP_EOL;
            elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
            return;
        }

        throw new RuntimeException('ELWA Login fehlgeschlagen: ' . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (shouldSwitchToHttps($baseUrl, $code, (string)$response)) {
        $baseUrl = switchBaseUrlToHttps($baseUrl);
        echo "HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl" . PHP_EOL;
        elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        return;
    }

    if (!in_array($code, [200, 204, 302, 303], true)) {
        throw new RuntimeException('ELWA Login HTTP Fehler: ' . $code . ' Antwort: ' . trim((string)$response));
    }
}

function curlGet(string $url, string $cookieFile, bool &$insecureTls, bool $authEnabled): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];

    if ($authEnabled) {
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
    }

    curl_setopt_array($ch, $options);
    applyTlsOptions($ch, $insecureTls);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        if (!$insecureTls && isSelfSignedCertificateError($error)) {
            $insecureTls = true;
            echo "Selbstsigniertes Zertifikat erkannt, TLS-Pruefung wird deaktiviert." . PHP_EOL;
            return curlGet($url, $cookieFile, $insecureTls, $authEnabled);
        }

        throw new RuntimeException("GET fehlgeschlagen fuer $url: $error");
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => (int)$code,
        'body' => (string)$response,
    ];
}

function curlPostForm(string $url, string $body, string $cookieFile, bool &$insecureTls, bool $authEnabled): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ];

    if ($authEnabled) {
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
    }

    curl_setopt_array($ch, $options);
    applyTlsOptions($ch, $insecureTls);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        if (!$insecureTls && isSelfSignedCertificateError($error)) {
            $insecureTls = true;
            echo "Selbstsigniertes Zertifikat erkannt, TLS-Pruefung wird deaktiviert." . PHP_EOL;
            return curlPostForm($url, $body, $cookieFile, $insecureTls, $authEnabled);
        }

        throw new RuntimeException("POST fehlgeschlagen fuer $url: $error");
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => (int)$code,
        'body' => (string)$response,
    ];
}

function flattenJson(array $data, string $source, string $prefix = ''): array
{
    $rows = [];

    foreach ($data as $key => $value) {
        $keyText = (string)$key;
        $path = $prefix === ''
            ? $keyText
            : (is_int($key) ? $prefix . '[' . $keyText . ']' : $prefix . '.' . $keyText);

        if (is_array($value)) {
            $rows = array_merge($rows, flattenJson($value, $source, $path));
            continue;
        }

        $rows[] = [
            'source' => $source,
            'path' => $path,
            'key' => $keyText,
            'value' => $value,
            'type' => gettype($value),
        ];
    }

    return $rows;
}

function printHelp(): void
{
    echo PHP_EOL;
    echo 'Bedienung:' . PHP_EOL;
    echo '  leer/list     alle Werte als kompakte, nach Quelle gruppierte Tabelle anzeigen' . PHP_EOL;
    echo '  text          Suche in Quelle, Key, Pfad, Wert oder Typ' . PHP_EOL;
    echo '  get NAME      geladenes Feld lesen, z.B. get device oder get api.data.device' . PHP_EOL;
    echo '                alternativ: wert NAME oder value NAME' . PHP_EOL;
    echo '                bei doppelten Keys mit Quelle/Pfad eindeutig auswaehlen' . PHP_EOL;
    echo '  %text%        LIKE-Suche mit % als Platzhalter, z.B. %temp%' . PHP_EOL;
    echo '  mode local    lokale Heizstab-REST-Schnittstelle verwenden' . PHP_EOL;
    echo '  mode api      offizielle my-PV API wie checkheizstabdata.php verwenden' . PHP_EOL;
    echo '  login         Session des aktuellen Modus loeschen und neu laden' . PHP_EOL;
    echo '  http/ctrl1    nur local: HTTP-Regelung aktivieren (POST setup.jsn ctrl=1)' . PHP_EOL;
    echo '  post ctrl=1   nur local: gleich wie http/ctrl1; Voraussetzung fuer power/on/off' . PHP_EOL;
    echo '  modbus/ctrl2  nur local: Modbus-TCP-Regelung aktivieren (POST setup.jsn ctrl=2)' . PHP_EOL;
    echo '  post ctrl=2   nur local: gleich wie modbus/ctrl2; zurueck auf Modbus-TCP' . PHP_EOL;
    echo '  on/ein        nur local: Heizstab einschalten (POST control.html power=powerOn)' . PHP_EOL;
    echo '  off/aus       nur local: Heizstab ausschalten (POST control.html power=0)' . PHP_EOL;
    echo '  power 1000    nur local: Leistung setzen (POST control.html power=1000, 0-6500 W)' . PHP_EOL;
    echo '  boost         lokalen Sicherstellungs-Boost starten (POST setup.jsn bststrt=1)' . PHP_EOL;
    echo '  boostoff      lokalen Sicherstellungs-Boost stoppen (POST setup.jsn bststrt=0)' . PHP_EOL;
    echo '  post a=b      lokalen POST auf setup.jsn ausfuehren, z.B. post bststrt=1' . PHP_EOL;
    echo '  /data.jsn?bststrt=0  direkten Heizstab-Pfad ausfuehren' . PHP_EOL;
    echo '  /data         im API-Modus API-Endpunkt data ausfuehren' . PHP_EOL;
    echo '  endpoints     alle unterstuetzten my-PV-Cloud-Endpunkte anzeigen' . PHP_EOL;
    echo '  api get NAME       echten Cloud-Endpunkt abrufen, z.B. api get isOnline' . PHP_EOL;
    echo '  api get logdata BEGIN ENDE [INTERVALL] [ZEITZONE]' . PHP_EOL;
    echo '                     z.B. api get logdata 2026-07-20 2026-07-21 1h Europe/Berlin' . PHP_EOL;
    echo '  api power W [MIN]  Cloud-Leistung setzen, z.B. api power 1000 20 oder api power 0' . PHP_EOL;
    echo '  POST power W [MIN] Kurzform fuer api power W [MIN]' . PHP_EOL;
    echo '  api put setup JSON Cloud-Setup aendern, z.B. api put setup {"ww1target":55}' . PHP_EOL;
    echo '  PUT setup JSON     Kurzform fuer api put setup JSON' . PHP_EOL;
    echo '  /setup.jsn            direkten Heizstab-Pfad ausfuehren' . PHP_EOL;
    echo '  r             data.jsn und setup.jsn neu laden' . PHP_EOL;
    echo '  raw           data.jsn und setup.jsn komplett als JSON-Dateien speichern' . PHP_EOL;
    echo '  ?             diese Hilfe anzeigen' . PHP_EOL;
    echo '  q             beenden' . PHP_EOL;
}

function printApiEndpoints(): void
{
    echo PHP_EOL . 'my-PV Cloud API (AC ELWA 2):' . PHP_EOL;
    echo '  api get isOnline                 Online-Status' . PHP_EOL;
    echo '  api get isPowerControlPossible   Fernsteuerung moeglich?' . PHP_EOL;
    echo '  api get data                     Live-Daten' . PHP_EOL;
    echo '  api get data/soc                 State of Charge' . PHP_EOL;
    echo '  api get solarForecast            Solarprognose' . PHP_EOL;
    echo '  api get logdata BEGIN ENDE [INTERVALL] [ZEITZONE]' . PHP_EOL;
    echo '                                      Logdaten; max. 50 Aufrufe je 24 Stunden' . PHP_EOL;
    echo '  api get setup                    Einstellungen lesen' . PHP_EOL;
    echo '  api power W [MIN]                Leistung fuer AC ELWA 2 setzen' . PHP_EOL;
    echo '  api put setup JSON               Einstellungen aendern' . PHP_EOL;
    echo 'Hinweis: get NAME liest ein geladenes Feld; api get NAME ruft die Cloud auf.' . PHP_EOL;
    echo 'Dokumentation: https://api.my-pv.com/api-docs/' . PHP_EOL;
}

function writeRawJsonFiles(array $rawData): array
{
    $files = [];

    foreach ($rawData as $source => $data) {
        $baseName = $source === 'data.jsn' ? 'json-heizstab-data-raw.json' : 'json-heizstab-setup-raw.json';
        $file = __DIR__ . '/' . $baseName;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Raw JSON fuer $source konnte nicht codiert werden: " . json_last_error_msg());
        }

        if (file_put_contents($file, $json . PHP_EOL) === false) {
            throw new RuntimeException("Raw JSON konnte nicht geschrieben werden: $file");
        }

        $files[$source] = $file;
    }

    return $files;
}

function printItems(array $items, string $filter): void
{
    if (in_array(strtolower(trim($filter)), ['', 'list', 'alle', 'all'], true)) {
        printCompactItems($items);
        return;
    }

    $rows = array_values(array_filter($items, static fn (array $item): bool => itemMatches($item, $filter)));

    printCompactItems($rows);
}

function printCompactItems(array $rows): void
{
    echo PHP_EOL . 'Treffer: ' . count($rows) . PHP_EOL;
    if ($rows === []) {
        echo 'Keine Werte gefunden.' . PHP_EOL;
        return;
    }

    $numberWidth = max(2, strlen((string)(count($rows) - 1)));
    $pathWidth = min(52, max(4, ...array_map(static fn (array $row): int => strlen((string)$row['path']), $rows)));
    $source = null;
    foreach ($rows as $index => $row) {
        if ($source !== $row['source']) {
            $source = $row['source'];
            echo PHP_EOL . '[' . $source . ']' . PHP_EOL;
            echo str_pad('Nr', $numberWidth) . '  ' . str_pad('Pfad innerhalb der Quelle', $pathWidth) . '  Wert' . PHP_EOL;
            echo str_repeat('-', $numberWidth) . '  ' . str_repeat('-', $pathWidth) . '  ' . str_repeat('-', 24) . PHP_EOL;
        }
        $path = (string)$row['path'];
        if (strlen($path) > $pathWidth) {
            $path = '...' . substr($path, -($pathWidth - 3));
        }
        echo str_pad((string)$index, $numberWidth, ' ', STR_PAD_LEFT) . '  '
            . str_pad($path, $pathWidth) . '  ' . formatValue($row['value']) . PHP_EOL;
    }
}

function printSelectedItem(array $items, string $selection, string $label = 'Wert'): void
{
    if (ctype_digit($selection) && isset($items[(int)$selection])) {
        $matches = [$items[(int)$selection]];
    } else {
        $matches = array_values(array_filter($items, static function (array $item) use ($selection): bool {
            return strcasecmp((string)$item['key'], $selection) === 0
                || strcasecmp((string)$item['path'], $selection) === 0
                || strcasecmp((string)$item['source'] . '.' . (string)$item['path'], $selection) === 0;
        }));
    }

    if ($matches === []) {
        $sources = array_values(array_unique(array_map(static fn (array $item): string => (string)$item['source'], $items)));
        $key = str_contains($selection, '.') ? substr($selection, strrpos($selection, '.') + 1) : $selection;
        $suggestions = array_values(array_filter($items, static fn (array $item): bool => strcasecmp((string)$item['key'], $key) === 0));

        echo "Keinen exakten $label fuer '$selection' gefunden." . PHP_EOL;
        echo 'Aktuell geladene Quellen: ' . ($sources === [] ? 'keine' : implode(', ', $sources)) . PHP_EOL;
        if ($suggestions !== []) {
            echo 'Vorhandene vollstaendige Pfade:' . PHP_EOL;
            foreach ($suggestions as $item) {
                echo '  get ' . $item['source'] . '.' . $item['path'] . PHP_EOL;
            }
        } else {
            echo "Mit 'list' oder einer Textsuche finden Sie den Namen." . PHP_EOL;
        }
        return;
    }

    printCompactItems($matches);
}

function itemMatches(array $item, string $filter): bool
{
    $filter = trim($filter);
    if ($filter === '') {
        return true;
    }

    $haystacks = [
        (string)$item['source'],
        (string)$item['path'],
        (string)$item['source'] . '.' . (string)$item['path'],
        (string)$item['key'],
        formatValue($item['value']),
        (string)$item['type'],
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
        if (stripos($text, $filter) !== false) {
            return true;
        }
    }

    return false;
}

function formatValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    return (string)$value;
}

function likeToRegex(string $pattern): string
{
    $regex = preg_quote($pattern, '/');
    $regex = str_replace('%', '.*', $regex);
    return '/^' . $regex . '$/i';
}

function shouldSwitchToHttps(string $baseUrl, int $httpCode, string $response): bool
{
    if (str_starts_with(strtolower($baseUrl), 'https://')) {
        return false;
    }

    if (in_array($httpCode, [301, 302, 303], true)) {
        return true;
    }

    $response = strtolower($response);
    return str_contains($response, "location.href='https://")
        || str_contains($response, 'location.href="https://')
        || str_contains($response, 'redirecting to https');
}

function switchBaseUrlToHttps(string $baseUrl): string
{
    return preg_replace('~^http://~i', 'https://', $baseUrl) ?? $baseUrl;
}

function isSelfSignedCertificateError(string $error): bool
{
    $error = strtolower($error);

    return str_contains($error, 'self-signed certificate')
        || str_contains($error, 'certificate problem')
        || str_contains($error, 'unable to get local issuer certificate');
}

function applyTlsOptions($ch, bool $insecureTls): void
{
    if (!$insecureTls) {
        return;
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}

function normalizeBaseUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('URL darf nicht leer sein');
    }

    if (!preg_match('~^https?://~i', $value)) {
        $value = 'http://' . $value;
    }

    return rtrim($value, '/');
}

function buildUrl(string $baseUrl, string $path): string
{
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function buildDefaultCookieFile(string $baseUrl, string $cookieDir): string
{
    $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'unknown';
    $port = parse_url($baseUrl, PHP_URL_PORT);
    $cookieName = 'heizstab_' . sanitizeCookieName($host . ($port ? '_' . $port : '')) . '_cookie.txt';

    return rtrim($cookieDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cookieName;
}

function sanitizeCookieName(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value) ?? 'unknown';
    return trim($value, '_') ?: 'unknown';
}

function parseCliOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
        } else {
            $options[$arg] = true;
        }
    }

    return $options;
}

function loadJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function filterBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'ja', 'on'], true);
}

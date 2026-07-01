<?php
declare(strict_types=1);

/*
 * Interaktiver Leser fuer den my-PV/ELWA Heizstab.
 * Leere Eingabe zeigt alle Werte aus data.jsn und setup.jsn.
 * "raw" schreibt data.jsn und setup.jsn als komplette JSON-Dateien.
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

try {
    echo "Heizstab REST: $baseUrl" . PHP_EOL;
    echo "Params:        " . (is_file($paramsFile) ? $paramsFile : 'nicht gefunden') . PHP_EOL;
    echo "Login URL:     " . buildUrl($baseUrl, $loginPath) . PHP_EOL;
    echo "Login aktiv:   " . ($authEnabled ? 'ja' : 'nein') . PHP_EOL;
    echo "Cookie:        $cookieFile" . PHP_EOL;
    echo "TLS check:     " . ($insecureTls ? 'aus' : 'an') . PHP_EOL;
    echo PHP_EOL . 'Lade data.jsn und setup.jsn ...' . PHP_EOL;

    $rawData = [];
    $items = loadHeizstabItems($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
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
            $items = loadHeizstabItems($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $authEnabled, $usernameField, $username, $extraFields, $rawData);
            echo 'Werte neu geladen: ' . count($items) . PHP_EOL;
            continue;
        }

        if (in_array(strtolower($filter), ['raw', 'json'], true)) {
            $files = writeRawJsonFiles($rawData);
            foreach ($files as $source => $file) {
                echo "Raw JSON gespeichert ($source): $file" . PHP_EOL;
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

function flattenJson(array $data, string $source, string $prefix = ''): array
{
    $rows = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;

        if (is_array($value)) {
            $rows = array_merge($rows, flattenJson($value, $source, $path));
            continue;
        }

        $rows[] = [
            'source' => $source,
            'path' => $path,
            'key' => (string)$key,
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
    echo '  leer          alle Werte anzeigen' . PHP_EOL;
    echo '  text          Suche in Quelle, Key, Pfad, Wert oder Typ' . PHP_EOL;
    echo '  %text%        LIKE-Suche mit % als Platzhalter, z.B. %temp%' . PHP_EOL;
    echo '  r             data.jsn und setup.jsn neu laden' . PHP_EOL;
    echo '  raw           data.jsn und setup.jsn komplett als JSON-Dateien speichern' . PHP_EOL;
    echo '  ?             diese Hilfe anzeigen' . PHP_EOL;
    echo '  q             beenden' . PHP_EOL;
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
    $rows = array_values(array_filter($items, static fn (array $item): bool => itemMatches($item, $filter)));

    echo PHP_EOL;
    echo 'Treffer: ' . count($rows) . PHP_EOL;
    echo str_repeat('=', 100) . PHP_EOL;

    foreach ($rows as $index => $row) {
        echo '[' . $index . '] ' . $row['source'] . ' -> ' . $row['path'] . PHP_EOL;
        echo '    Key:    ' . $row['key'] . PHP_EOL;
        echo '    Wert:   ' . formatValue($row['value']) . PHP_EOL;
        echo '    Typ:    ' . $row['type'] . PHP_EOL;
        echo '    Quelle: ' . $row['source'] . PHP_EOL;
        echo str_repeat('-', 100) . PHP_EOL;
    }
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

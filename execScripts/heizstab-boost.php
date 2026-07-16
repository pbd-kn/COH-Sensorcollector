<?php
declare(strict_types=1);

/*
 * Schaltet den Heizstab-Boost einmalig ein oder aus.
 *
 * Beispiele:
 *   php heizstab-boost.php ein
 *   php heizstab-boost.php aus
 *   php heizstab-boost.php ein --params=/pfad/task_heizstab_params.json
 */

const DEFAULT_PARAMS = [
    'urlheizStab' => 'https://192.168.178.68',
    'heizstabAuth' => [
        'enabled' => true,
        'loginPath' => '/auth.jsn',
        'password' => '14881488',
        'passwordField' => 'pw',
        'cookieDir' => '/home/peter/scripts/coh/cookies',
        'insecureTls' => true,
        'extraFields' => [],
    ],
    'heizstabControl' => [
        'enabled' => true,
        'mode' => 'boost-local',
        'boostOnBody' => 'bststrt=1',
        'boostOffBody' => 'bststrt=0',
    ],
];

$options = parseCliArgs($argv);
$command = strtolower((string)($options['_'][0] ?? ''));

if (!in_array($command, ['ein', 'aus', 'on', 'off', 'booston', 'boostoff', 'boost'], true)) {
    printUsage();
    exit(2);
}

$enable = in_array($command, ['ein', 'on', 'booston', 'boost'], true);
$paramsFile = (string)($options['params'] ?? (__DIR__ . '/task_heizstab_params.json'));

try {
    $params = array_replace_recursive(DEFAULT_PARAMS, loadOptionalJsonFile($paramsFile));

    $authConfig = is_array($params['heizstabAuth'] ?? null) ? $params['heizstabAuth'] : [];
    $controlConfig = is_array($params['heizstabControl'] ?? null) ? $params['heizstabControl'] : [];

    if (array_key_exists('enabled', $controlConfig) && !filterBool($controlConfig['enabled'])) {
        throw new RuntimeException('heizstabControl.enabled ist deaktiviert');
    }

    $baseUrl = normalizeBaseUrl((string)($options['url'] ?? ($params['urlheizStab'] ?? '')));
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

    $body = $enable
        ? normalizeBoostBody((string)($controlConfig['boostOnBody'] ?? ($controlConfig['boostOnPath'] ?? 'bststrt=1')), 'bststrt=1')
        : normalizeBoostBody((string)($controlConfig['boostOffBody'] ?? ($controlConfig['boostOffPath'] ?? 'bststrt=0')), 'bststrt=0');

    executeHeizstabPost(
        $baseUrl,
        $body,
        $loginPath,
        $passwordField,
        $password,
        $cookieFile,
        $insecureTls,
        $authEnabled,
        $usernameField,
        $username,
        $extraFields
    );

    echo 'Boostmodus ' . ($enable ? 'eingeschaltet' : 'ausgeschaltet') . ' (' . $body . ')' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function printUsage(): void
{
    echo 'Verwendung:' . PHP_EOL;
    echo '  php heizstab-boost.php ein' . PHP_EOL;
    echo '  php heizstab-boost.php aus' . PHP_EOL;
    echo PHP_EOL;
    echo 'Parameter:' . PHP_EOL;
    echo '  ein   Boostmodus einschalten' . PHP_EOL;
    echo '  aus   Boostmodus ausschalten' . PHP_EOL;
    echo PHP_EOL;
    echo 'Optional:' . PHP_EOL;
    echo '  --params=DATEI   Werte aus JSON-Datei ueberschreiben die eingebauten Defaults' . PHP_EOL;
}

function parseCliArgs(array $argv): array
{
    $result = ['_' => []];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            [$key, $value] = array_pad(explode('=', $arg, 2), 2, '1');
            $result[$key] = $value;
            continue;
        }

        $result['_'][] = $arg;
    }

    return $result;
}

function loadOptionalJsonFile(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException("Parameterdatei kann nicht gelesen werden: $file");
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException("Parameterdatei ist kein gueltiges JSON: " . json_last_error_msg());
    }

    return $data;
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
): array {
    if ($authEnabled) {
        ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
    }

    $postBody = appendPasswordField($body, $passwordField, $password, $authEnabled);
    $result = curlPostForm(buildUrl($baseUrl, '/setup.jsn'), $postBody, $cookieFile, $insecureTls, $authEnabled);

    if ($authEnabled && in_array($result['http_code'], [301, 302, 303, 401, 403], true)) {
        @unlink($cookieFile);
        ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
        $postBody = appendPasswordField($body, $passwordField, $password, $authEnabled);
        $result = curlPostForm(buildUrl($baseUrl, '/setup.jsn'), $postBody, $cookieFile, $insecureTls, $authEnabled);
    }

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        throw new RuntimeException("POST HTTP Fehler {$result['http_code']} fuer " . buildUrl($baseUrl, '/setup.jsn') . ' Antwort: ' . trim($result['body']));
    }

    return $result;
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
            elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls, $usernameField, $username, $extraFields);
            return;
        }

        throw new RuntimeException('ELWA Login fehlgeschlagen: ' . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($code, [200, 204, 302, 303], true)) {
        throw new RuntimeException('ELWA Login HTTP Fehler: ' . $code . ' Antwort: ' . trim((string)$response));
    }
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

function appendPasswordField(string $body, string $passwordField, string $password, bool $authEnabled): string
{
    if (!$authEnabled || $password === '' || preg_match('/(?:^|&)' . preg_quote($passwordField, '/') . '=/', $body)) {
        return $body;
    }

    return $body . ($body === '' ? '' : '&') . rawurlencode($passwordField) . '=' . rawurlencode($password);
}

function normalizeBoostBody(string $value, string $fallback): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    $query = parse_url($value, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        return $query;
    }

    return ltrim($value, '?');
}

function normalizeBaseUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('urlheizStab darf nicht leer sein');
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

function applyTlsOptions($ch, bool $insecureTls): void
{
    if (!$insecureTls) {
        return;
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}

function isSelfSignedCertificateError(string $error): bool
{
    $error = strtolower($error);

    return str_contains($error, 'self-signed certificate')
        || str_contains($error, 'certificate problem')
        || str_contains($error, 'unable to get local issuer certificate');
}

function filterBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value !== 0;
    }

    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'ja', 'on', 'ein'], true);
}

<?php
declare(strict_types=1);

/*
 * Separater Test fuer den ELWA/Heizstab-Login.
 *
 * Beispiele:
 *   php checkheizstabdata.php
 *   php checkheizstabdata.php --url=https://192.168.178.68 --password=sfjimorx --insecure=1
 *   php checkheizstabdata.php --url=http://192.168.178.68 --password=sfjimorx --field=pw
 *   php checkheizstabdata.php --url=https://192.168.178.68 --password=sfjimorx --insecure=1 --loop=1 --repeat=15
 *   php checkheizstabdata.php --api=1 --serial=1601502403220274 --token=... --power=0
 *   php checkheizstabdata.php --api=1 --serial=1601502403220274 --token=... --switch-power=3000
 */

$options = parseCliOptions($argv);
$paramsFile = (string)($options['params'] ?? (__DIR__ . '/task_heizstab_params.json'));
$params = loadJsonFile($paramsFile);
$apiConfig = is_array($params['heizstabApi'] ?? null) ? $params['heizstabApi'] : [];
$apiMode = filterBool($options['api'] ?? ($apiConfig['enabled'] ?? false)) || isset($options['serial']) || isset($options['token']);

if ($apiMode) {
    $apiBaseUrl = rtrim((string)($options['api-url'] ?? ($apiConfig['baseUrl'] ?? 'https://api.my-pv.com/api/v1')), '/');
    $serial = trim((string)($options['serial'] ?? ($apiConfig['serial'] ?? '1601502403220274')));
    $tokenEnv = (string)($apiConfig['apiTokenEnv'] ?? 'MYPV_API_TOKEN');
    $token = (string)($options['token'] ?? ($apiConfig['apiToken'] ?? (getenv($tokenEnv) ?: '')));
    $insecureTls = filterBool($options['insecure'] ?? ($apiConfig['insecureTls'] ?? false));
    $loop = filterBool($options['loop'] ?? false);
    $repeatSeconds = max(1, (int)($options['repeat'] ?? 15));
    $power = isset($options['power']) ? (int)$options['power'] : null; // direkter POST ohne Rueckfrage
    $askSwitch = filterBool($options['ask'] ?? ($power === null));
    $switchPower = max(0, (int)($options['switch-power'] ?? $power ?? ($apiConfig['testPower'] ?? ($apiConfig['powerOn'] ?? 3000))));
    $switchOffAfterSeconds = max(1, (int)($options['off-after'] ?? ($apiConfig['testDurationSeconds'] ?? 600)));
    $validForMinutes = max(1, (int)($options['valid'] ?? ($apiConfig['testValidForMinutes'] ?? ($apiConfig['validForMinutes'] ?? 10))));
    $switchAsked = false;

    echo "Params:     " . (file_exists($paramsFile) ? $paramsFile : 'nicht gefunden') . "\n";
    echo "API URL:    $apiBaseUrl\n";
    echo "Serial:     $serial\n";
    echo "Token:      " . formatSecretInfo($token) . "\n";
    echo "TLS check:  " . ($insecureTls ? 'aus' : 'an') . "\n";
    echo "Loop:       " . ($loop ? "ja, alle {$repeatSeconds}s" : 'nein') . "\n";
    echo "POST power: " . ($power === null ? 'nein' : $power . ' W') . "\n";
    echo "Rueckfrage: " . ($askSwitch ? "ja, {$switchPower} W fuer {$switchOffAfterSeconds}s" : 'nein') . "\n\n";

    try {
        $token = resolveWorkingApiToken($apiBaseUrl, $serial, $token, $insecureTls);
        do {
            echo "==== " . date('Y-m-d H:i:s') . " ====\n";
            $compatible = myPvApiRequest($apiBaseUrl, $serial, $token, $insecureTls, 'GET', 'isPowerControlPossible');
            echo "isPowerControlPossible: " . json_encode($compatible, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $online = myPvApiRequest($apiBaseUrl, $serial, $token, $insecureTls, 'GET', 'isOnline');
            echo "isOnline: " . json_encode($online, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

            $data = myPvApiRequest($apiBaseUrl, $serial, $token, $insecureTls, 'GET', 'data');
            echo "data Array-Elemente: " . count($data) . "\n";
            echo "data wichtige Werte: " . json_encode(pickKeys($data, ['boostactive', 'bststrt', 'power_elwa2', 'power', 'power_max', 'power_nominal', 'temp1', 'ww1target', 'ww1boost', 'ctrl', 'ctrlstate']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

            $setup = myPvApiRequest($apiBaseUrl, $serial, $token, $insecureTls, 'GET', 'setup');
            echo "setup Array-Elemente: " . count($setup) . "\n";
            echo "setup wichtige Werte: " . json_encode(pickKeys($setup, ['ww1target', 'ww1boost', 'maxpwr', 'ctrl', 'ctrlstate', 'bstmode']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

            if ($power !== null) {
                $response = setMyPvPower($apiBaseUrl, $serial, $token, $insecureTls, max(0, $power), $validForMinutes);
                echo "POST power Antwort: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }

            if ($askSwitch && !$switchAsked) {
                $switchAsked = true;
                if (!isPowerControlCurrentlyAvailable($compatible, $online, $data)) {
                    echo "Heizstab wird nicht geschaltet: API meldet das Geraet aktuell nicht online/steuerbar.\n";
                    echo "Hinweis: Wenn /power 503 'Device offline' liefert, muss das Geraet in der my-PV Cloud online sein.\n";
                    continue;
                }

                if (askYesNo("Heizstab fuer {$switchOffAfterSeconds}s mit {$switchPower} W einschalten? [j/N] ")) {
                    echo "Schalte Heizstab ein: {$switchPower} W fuer {$validForMinutes} Minuten API-Gueltigkeit.\n";
                    $response = setMyPvPower($apiBaseUrl, $serial, $token, $insecureTls, $switchPower, $validForMinutes);
                    echo "Einschalten Antwort: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

                    try {
                        echo "Warte {$switchOffAfterSeconds}s bis zum Ausschalten ...\n";
                        sleep($switchOffAfterSeconds);
                    } finally {
                        echo "Schalte Heizstab aus: 0 W.\n";
                        $response = setMyPvPower($apiBaseUrl, $serial, $token, $insecureTls, 0, $validForMinutes);
                        echo "Ausschalten Antwort: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                    }
                } else {
                    echo "Heizstab bleibt unveraendert.\n";
                }
            }

            echo "\n";
            if ($loop) {
                echo "Warte {$repeatSeconds}s ...\n\n";
                sleep($repeatSeconds);
            }
        } while ($loop);
    } catch (Throwable $e) {
        fwrite(STDERR, "FEHLER: " . $e->getMessage() . "\n");
        exit(1);
    }

    exit(0);
}

$baseUrl = normalizeBaseUrl($options['url'] ?? 'https://192.168.178.68');
$password = (string)($options['password'] ?? '14881488');
$passwordField = (string)($options['field'] ?? 'pw');
$loginPath = (string)($options['login'] ?? '/auth.jsn');
$insecureTls = filterBool($options['insecure'] ?? false);
$cookieFile = (string)($options['cookie'] ?? buildDefaultCookieFile($baseUrl, sys_get_temp_dir()));
$loop = filterBool($options['loop'] ?? false);
$repeatSeconds = max(1, (int)($options['repeat'] ?? 15));

echo "Base URL:   $baseUrl\n";
echo "Login URL:  " . buildUrl($baseUrl, $loginPath) . "\n";
echo "Cookie:     $cookieFile\n";
echo "PW field:   $passwordField\n";
echo "TLS check:  " . ($insecureTls ? 'aus' : 'an') . "\n\n";
echo "Loop:       " . ($loop ? "ja, alle {$repeatSeconds}s" : 'nein') . "\n\n";

try {
    do {
        echo "==== " . date('Y-m-d H:i:s') . " ====\n";
        ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
        $data = fetchAndPrintJsonWithRelogin($baseUrl, '/data.jsn', $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
        $setup = fetchAndPrintJsonWithRelogin($baseUrl, '/setup.jsn', $loginPath, $passwordField, $password, $cookieFile, $insecureTls);

        echo "data.jsn Array-Elemente: " . count($data) . "\n";
        echo "setup.jsn Array-Elemente: " . count($setup) . "\n\n";

        if ($loop) {
            echo "Warte {$repeatSeconds}s ...\n\n";
            sleep($repeatSeconds);
        }
    } while ($loop);
} catch (Throwable $e) {
    fwrite(STDERR, "FEHLER: " . $e->getMessage() . "\n");
    exit(1);
}

function ensureElwaLogin(
    string &$baseUrl,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls
): void {
    if (file_exists($cookieFile) && filesize($cookieFile) > 0) {
        echo "Cookie vorhanden, Login wird wiederverwendet.\n";
        return;
    }

    echo "Kein Cookie vorhanden, Login wird ausgeführt.\n";
    $loginResponse = elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
    echo "Login OK\n";
    echo "Login Antwort:\n";
    echo trim($loginResponse) . "\n\n";
}

function elwaLogin(    string &$baseUrl,string $loginPath,string $passwordField,string $password,string $cookieFile,bool &$insecureTls): string {
    $url = buildUrl($baseUrl, $loginPath);
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([$passwordField => $password]),
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
            echo "Selbstsigniertes Zertifikat erkannt, TLS-Prüfung wird für diesen Test deaktiviert.\n";
            return elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
        }

        throw new RuntimeException('ELWA Login fehlgeschlagen: ' . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (shouldSwitchToHttps($baseUrl, $code, (string)$response)) {
        $baseUrl = switchBaseUrlToHttps($baseUrl);
        echo "HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl\n";
        return elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
    }

    if (!in_array($code, [200, 204, 302, 303], true)) {
        throw new RuntimeException('ELWA Login HTTP Fehler: ' . $code . ' Antwort: ' . $response);
    }

    return (string)$response;
}

function fetchAndPrintJsonWithRelogin(
    string &$baseUrl,
    string $path,
    string $loginPath,
    string $passwordField,
    string $password,
    string $cookieFile,
    bool &$insecureTls
): array
{
    $url = buildUrl($baseUrl, $path);
    $result = curlGet($url, $cookieFile, $insecureTls);

    if (shouldSwitchToHttps($baseUrl, $result['http_code'], $result['body'])) {
        $baseUrl = switchBaseUrlToHttps($baseUrl);
        echo "$path HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl\n";
        @unlink($cookieFile);
        ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);

        $url = buildUrl($baseUrl, $path);
        $result = curlGet($url, $cookieFile, $insecureTls);
    }

    if (in_array($result['http_code'], [301, 302, 303, 401, 403], true)) {
        echo "$path Session abgelaufen oder Login erforderlich (HTTP {$result['http_code']}). Re-Login ...\n";
        @unlink($cookieFile);
        $loginResponse = elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
        echo "Re-Login OK\n";
        echo "Login Antwort:\n";
        echo trim($loginResponse) . "\n\n";
        $result = curlGet($url, $cookieFile, $insecureTls);
    }

    if ($result['http_code'] !== 200) {
        throw new RuntimeException("GET HTTP Fehler {$result['http_code']} fuer $url Antwort: {$result['body']}");
    }

    $response = $result['body'];
    $decoded = json_decode($response, true);

    echo "$path HTTP OK\n";

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Antwort von $path ist kein JSON: " . json_last_error_msg() . " Antwort: " . trim($response));
    }

    if (!is_array($decoded)) {
        throw new RuntimeException("Antwort von $path ist JSON, aber kein Array");
    }

    //echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    return $decoded;
}

function curlGet(string $url, string $cookieFile, bool &$insecureTls): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    applyTlsOptions($ch, $insecureTls);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        if (!$insecureTls && isSelfSignedCertificateError($error)) {
            $insecureTls = true;
            echo "Selbstsigniertes Zertifikat erkannt, TLS-Prüfung wird für diesen Test deaktiviert.\n";
            return curlGet($url, $cookieFile, $insecureTls);
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

function myPvApiRequest(string $apiBaseUrl, string $serial, string $token, bool $insecureTls, string $method, string $endpoint, ?array $payload = null): array
{
    if ($serial === '') {
        throw new InvalidArgumentException('serial darf nicht leer sein');
    }

    if ($token === '') {
        throw new InvalidArgumentException('token fehlt. Entweder --token=... oder Umgebungsvariable MYPV_API_TOKEN setzen.');
    }

    $url = rtrim($apiBaseUrl, '/') . '/device/' . rawurlencode($serial) . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    if ($insecureTls) {
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
        $message = "API HTTP Fehler $code fuer $url Antwort: " . trim((string)$response);
        if ($code === 401) {
            $message .= "\nHinweis: my-PV lehnt die Authentifizierung ab. Bitte API-Token exakt aus den Device Settings kopieren, passende Seriennummer aus demselben Cloud-Geraet verwenden und nach Token-Erstellung mindestens 10 Minuten warten.";
        }
        throw new RuntimeException($message);
    }

    $decoded = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("API Antwort ist kein JSON fuer $url: " . json_last_error_msg() . ' Antwort: ' . trim((string)$response));
    }

    return is_array($decoded) ? $decoded : ['value' => $decoded];
}

function resolveWorkingApiToken(string $apiBaseUrl, string $serial, string $token, bool $insecureTls): string
{
    $variants = buildTokenVariants($token);
    $lastError = null;

    foreach ($variants as $label => $variant) {
        try {
            myPvApiRequest($apiBaseUrl, $serial, $variant, $insecureTls, 'GET', 'isPowerControlPossible');
            if ($variant !== $token) {
                echo "Token-Variante funktioniert: $label (" . formatSecretInfo($variant) . ")\n";
            }
            return $variant;
        } catch (RuntimeException $e) {
            $lastError = $e;
            if (!str_contains($e->getMessage(), 'API HTTP Fehler 401')) {
                throw $e;
            }

            echo "Token-Variante fehlgeschlagen: $label (" . formatSecretInfo($variant) . ") HTTP 401\n";
        }
    }

    if ($lastError !== null) {
        throw $lastError;
    }

    return $token;
}

function buildTokenVariants(string $token): array
{
    $variants = ['original' => $token];

    if (preg_match('/^my([A-Fa-f0-9]{20,})PV$/', $token, $matches)) {
        $variants['ohne my/PV'] = $matches[1];
    }

    return $variants;
}

function setMyPvPower(string $apiBaseUrl, string $serial, string $token, bool $insecureTls, int $power, int $validForMinutes): array
{
    return myPvApiRequest($apiBaseUrl, $serial, $token, $insecureTls, 'POST', 'power', [
        'power' => max(0, $power),
        'validForMinutes' => max(1, $validForMinutes),
        'timeBoostOverride' => 0,
        'timeBoostValue' => 0,
        'legionellaBoostBlock' => 1,
    ]);
}

function isPowerControlCurrentlyAvailable(array $compatible, array $online, array $data): bool
{
    if (array_key_exists('isPowerControlPossible', $compatible) && $compatible['isPowerControlPossible'] === false) {
        echo "Power-Control ist laut API fuer diese Firmware nicht moeglich.\n";
        return false;
    }

    if (array_key_exists('isOnline', $online) && $online['isOnline'] === false) {
        echo "Geraet ist laut API offline.\n";
        return false;
    }

    if (array_key_exists('online', $online) && $online['online'] === false) {
        echo "Geraet ist laut API offline.\n";
        return false;
    }

    return true;
}

function askYesNo(string $question): bool
{
    echo $question;
    $answer = fgets(STDIN);

    if ($answer === false) {
        return false;
    }

    return in_array(strtolower(trim($answer)), ['j', 'ja', 'y', 'yes'], true);
}

function formatSecretInfo(string $secret): string
{
    if ($secret === '') {
        return 'fehlt';
    }

    $length = strlen($secret);
    if ($length <= 10) {
        return 'gesetzt, Laenge ' . $length;
    }

    return 'gesetzt, Laenge ' . $length . ', Maske ' . substr($secret, 0, 4) . '...' . substr($secret, -4);
}

function pickKeys(array $data, array $keys): array
{
    $result = [];

    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $result[$key] = $data[$key];
        }
    }

    return $result;
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

<?php

declare(strict_types=1);

/**
 * Gemeinsame Zugriffe fuer alle Tasks in execScripts.
 *
 * - AmpereIqHttpAccess: Ampere.IQ-HTTPS inklusive Tokenrefresh/Neuanmeldung
 * - HeizstabCloudAccess: offizielle my-PV-Cloud-API mit Bearer-Token
 * - HeizstabLocalAccess: lokaler HTTPS-Zugriff mit Login-Cookie
 */
final class TaskAccess
{
    public static function loadParameters(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("Parameterdatei fehlt: $file");
        }

        $parameters = json_decode((string)file_get_contents($file), true);
        if (!is_array($parameters)) {
            throw new RuntimeException("Ungueltige JSON-Parameterdatei: $file");
        }

        return $parameters;
    }

    public static function ampereIq(array $parameters, string $baseDir = __DIR__, ?callable $logger = null): AmpereIqHttpAccess
    {
        return AmpereIqHttpAccess::fromTaskParameters($parameters, $baseDir, $logger);
    }

    public static function heizstabCloud(array $parameters, ?callable $logger = null): HeizstabCloudAccess
    {
        return new HeizstabCloudAccess(
            is_array($parameters['heizstabApi'] ?? null) ? $parameters['heizstabApi'] : [],
            $logger
        );
    }

    public static function heizstabLocal(array $parameters, string $baseDir = __DIR__, ?callable $logger = null): HeizstabLocalAccess
    {
        return new HeizstabLocalAccess(
            (string)($parameters['urlheizStab'] ?? ''),
            is_array($parameters['heizstabAuth'] ?? null) ? $parameters['heizstabAuth'] : [],
            $baseDir,
            $logger
        );
    }

    public static function loggerAdapter(object $logger): callable
    {
        return static function (string $level, string $message) use ($logger): void {
            if ($level === 'debug' && method_exists($logger, 'debugMe')) {
                $logger->debugMe($message);
                return;
            }
            if ($level === 'info' && method_exists($logger, 'Info')) {
                $logger->Info($message);
                return;
            }
            if (method_exists($logger, 'Error')) {
                $logger->Error($message);
            }
        };
    }
}

final class HeizstabCloudAccess
{
    private array $config;
    private $logger;

    public function __construct(array $config, ?callable $logger = null)
    {
        $this->config = $config + [
            'baseUrl' => 'https://api.my-pv.com/api/v1',
            'serial' => '',
            'apiToken' => '',
            'apiTokenEnv' => 'MYPV_API_TOKEN',
            'dataEndpoint' => 'data',
            'setupEndpoint' => 'setup',
            'powerEndpoint' => 'power',
            'insecureTls' => false,
            'validForMinutes' => 20,
            'timeBoostOverride' => 0,
            'timeBoostValue' => 0,
            'legionellaBoostBlock' => 1,
        ];
        $this->logger = $logger;
    }

    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    public function data(): array
    {
        return $this->get((string)$this->config['dataEndpoint']);
    }

    public function setup(): array
    {
        return $this->get((string)$this->config['setupEndpoint']);
    }

    public function isOnline(): array
    {
        return $this->get('isOnline');
    }

    public function isPowerControlPossible(): array
    {
        return $this->get('isPowerControlPossible');
    }

    public function setPower(int $power, ?int $validForMinutes = null): array
    {
        return $this->request('POST', (string)$this->config['powerEndpoint'], [
            'power' => max(0, $power),
            'validForMinutes' => max(1, $validForMinutes ?? (int)$this->config['validForMinutes']),
            'timeBoostOverride' => (int)$this->config['timeBoostOverride'],
            'timeBoostValue' => (int)$this->config['timeBoostValue'],
            'legionellaBoostBlock' => (int)$this->config['legionellaBoostBlock'],
        ]);
    }

    public function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $serial = trim((string)$this->config['serial']);
        $token = (string)$this->config['apiToken'];
        if ($token === '') {
            $environmentToken = getenv((string)$this->config['apiTokenEnv']);
            $token = is_string($environmentToken) ? $environmentToken : '';
        }
        if ($serial === '' || $token === '') {
            throw new RuntimeException('my-PV-Cloudzugriff: serial oder apiToken fehlt.');
        }

        $url = rtrim((string)$this->config['baseUrl'], '/') . '/device/' . rawurlencode($serial)
            . '/' . ltrim($endpoint, '/');
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];
        if (!empty($this->config['insecureTls'])) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = false;
        }
        if (strtoupper($method) !== 'GET') {
            $json = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('my-PV-Payload konnte nicht als JSON erzeugt werden.');
            }
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            $options[CURLOPT_POSTFIELDS] = $json;
        }

        return $this->executeJsonRequest($url, $options, 'my-PV-Cloud');
    }

    private function executeJsonRequest(string $url, array $options, string $label): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("$label cURL-Fehler bei $url: $error");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("$label HTTP $status bei $url: " . trim((string)$body));
        }
        if (trim((string)$body) === '') {
            return [];
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("$label lieferte ungueltiges JSON bei $url: " . json_last_error_msg());
        }

        return $decoded;
    }
}

final class HeizstabLocalAccess
{
    private string $baseUrl;
    private array $auth;
    private string $cookieFile;
    private $logger;

    public function __construct(string $baseUrl, array $auth = [], string $baseDir = __DIR__, ?callable $logger = null)
    {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->auth = $auth + [
            'enabled' => true,
            'loginPath' => '/auth.jsn',
            'username' => null,
            'password' => '',
            'usernameField' => null,
            'passwordField' => 'pw',
            'extraFields' => [],
            'cookieDir' => sys_get_temp_dir(),
            'cookieFile' => '',
            'insecureTls' => false,
        ];
        $cookieDir = (string)$this->auth['cookieDir'];
        if ($cookieDir === '') {
            $cookieDir = $baseDir;
        } elseif (!self::isAbsolutePath($cookieDir)) {
            $cookieDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($cookieDir, '/\\');
        }
        $configuredCookie = trim((string)$this->auth['cookieFile']);
        $this->cookieFile = $configuredCookie !== ''
            ? $configuredCookie
            : rtrim($cookieDir, '/\\') . DIRECTORY_SEPARATOR . 'heizstab_'
                . preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)(parse_url($this->baseUrl, PHP_URL_HOST) ?: 'local'))
                . '_cookie.txt';
        $this->logger = $logger;
    }

    public function data(): array
    {
        return $this->getJson('/data.jsn');
    }

    public function setup(): array
    {
        return $this->getJson('/setup.jsn');
    }

    public function getJson(string $path): array
    {
        $response = $this->request('GET', $path);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Lokaler Heizstab lieferte ungueltiges JSON fuer $path: " . json_last_error_msg());
        }
        return $decoded;
    }

    public function postSetup(string|array $values): array
    {
        $body = is_array($values)
            ? http_build_query($values, '', '&', PHP_QUERY_RFC3986)
            : ltrim(trim($values), '?');
        $passwordField = (string)$this->auth['passwordField'];
        if (!empty($this->auth['enabled']) && (string)$this->auth['password'] !== ''
            && !preg_match('/(?:^|&)' . preg_quote($passwordField, '/') . '=/', $body)) {
            $body .= ($body === '' ? '' : '&') . rawurlencode($passwordField) . '='
                . rawurlencode((string)$this->auth['password']);
        }

        return $this->request('POST', '/setup.jsn', $body);
    }

    public function setPower(int $power): array
    {
        return $this->request(
            'POST',
            '/control.html',
            http_build_query(['power' => max(0, min(6500, $power))])
        );
    }

    public function resetSession(): void
    {
        if (is_file($this->cookieFile) && !unlink($this->cookieFile)) {
            throw new RuntimeException("Lokales Heizstab-Cookie konnte nicht geloescht werden: {$this->cookieFile}");
        }
    }

    public function cookieFile(): string
    {
        return $this->cookieFile;
    }

    public function request(string $method, string $path, ?string $body = null, bool $retried = false): array
    {
        if (!empty($this->auth['enabled']) && !is_file($this->cookieFile)) {
            $this->login();
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if (!empty($this->auth['enabled'])) {
            $options[CURLOPT_COOKIEJAR] = $this->cookieFile;
            $options[CURLOPT_COOKIEFILE] = $this->cookieFile;
        }
        if (!empty($this->auth['insecureTls'])) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = false;
        }
        if (strtoupper($method) !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $response = $this->execute($url, $options);
        if (!empty($this->auth['enabled']) && in_array($response['status'], [301, 302, 303, 401, 403], true)) {
            if ($retried) {
                throw new RuntimeException("Lokaler Heizstab Login nach Wiederholung fehlgeschlagen: HTTP {$response['status']}");
            }
            if (is_file($this->cookieFile)) {
                @unlink($this->cookieFile);
            }
            $this->login();
            return $this->request($method, $path, $body, true);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("Lokaler Heizstab HTTP {$response['status']} bei $url: " . trim($response['body']));
        }

        return $response;
    }

    private function login(): void
    {
        if (empty($this->auth['enabled'])) {
            return;
        }
        if ((string)$this->auth['password'] === '') {
            throw new RuntimeException('Lokaler Heizstab Login ist aktiv, aber password fehlt.');
        }
        $directory = dirname($this->cookieFile);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Cookie-Verzeichnis konnte nicht angelegt werden: $directory");
        }

        $fields = is_array($this->auth['extraFields']) ? $this->auth['extraFields'] : [];
        if (!empty($this->auth['usernameField']) && $this->auth['username'] !== null && $this->auth['username'] !== '') {
            $fields[(string)$this->auth['usernameField']] = (string)$this->auth['username'];
        }
        $fields[(string)$this->auth['passwordField']] = (string)$this->auth['password'];
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim((string)$this->auth['loginPath'], '/');
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ];
        if (!empty($this->auth['insecureTls'])) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = false;
        }

        $response = $this->execute($url, $options);
        if (!in_array($response['status'], [200, 204, 302, 303], true)) {
            throw new RuntimeException("Lokaler Heizstab Login HTTP {$response['status']} bei $url");
        }
        $this->log('debug', "Lokaler Heizstab Login erfolgreich: $url");
    }

    private function execute(string $url, array $options): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Lokaler Heizstab cURL-Fehler bei $url: $error");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$body, 'url' => $url];
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (strlen($path) >= 3
                && ctype_alpha($path[0])
                && $path[1] === ':'
                && ($path[2] === '\\' || $path[2] === '/'));
    }

    private static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('urlheizStab fehlt.');
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }
}

/**
 * Reiner HTTP-/OAuth-Zugriff auf die Ampere.IQ-Cloud.
 * Die Auswahl und Interpretation einzelner API-Werte bleibt im aufrufenden Task.
 */
final class AmpereIqHttpAccess
{
    private const AUTH0_BASE_URL = 'https://ekd-solar-prod.eu.auth0.com';
    private const AUTH0_CLIENT_ID = 'ZmEv1aRHlnkhRBHGzdXpig91kkZhNghK';
    private const AUTH0_AUDIENCE = 'de.ekd.iot-cloud.backend';
    private const AUTH0_SCOPE = 'openid email profile offline_access';
    private const AUTH0_REDIRECT_URI = 'de.ekdsolar.customerapp://ekd-solar-prod.eu.auth0.com/android/de.ekdsolar.customerapp/callback';
    private const API_BASE_URL = 'https://product.ekd-iot.de';
    private const APP_VERSION = '1.14.4';

    private string $paramsFile;
    private int $retries;
    private int $retryDelay;
    private $logger;
    private ?array $context = null;

    public function __construct(string $paramsFile, int $retries = 3, int $retryDelay = 10, ?callable $logger = null)
    {
        $this->paramsFile = $paramsFile;
        $this->retries = max(1, $retries);
        $this->retryDelay = max(0, $retryDelay);
        $this->logger = $logger;
    }

    public static function fromTaskParameters(array $parameters, string $baseDir = __DIR__, ?callable $logger = null): self
    {
        $config = is_array($parameters['ampereIqCloud'] ?? null) ? $parameters['ampereIqCloud'] : [];
        if (array_key_exists('enabled', $config) && empty($config['enabled'])) {
            throw new RuntimeException('Ampere.IQ-Cloudzugriff ist deaktiviert.');
        }

        $paramsFile = trim((string)($config['paramsFile'] ?? 'task_solar_params.json'));
        if ($paramsFile === '') {
            throw new RuntimeException('ampereIqCloud.paramsFile fehlt.');
        }
        if (!self::isAbsolutePath($paramsFile)) {
            $paramsFile = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($paramsFile, '/\\');
        }

        return new self(
            $paramsFile,
            (int)($config['retries'] ?? 3),
            (int)($config['retryDelay'] ?? 10),
            $logger
        );
    }

    public function get(string $path): array
    {
        return $this->requestWithRetry('GET', $path);
    }

    public function patch(string $path, array $values): array
    {
        return $this->requestWithRetry('PATCH', $path, $values);
    }

    public function installationId(): string
    {
        return (string)$this->getContext()['installationId'];
    }

    public function paramsFile(): string
    {
        return $this->paramsFile;
    }

    private function requestWithRetry(string $method, string $path, ?array $values = null): array
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            try {
                $context = $this->getContext();
                $resolvedPath = str_replace(
                    ['{installationId}', '{id}'],
                    rawurlencode($context['installationId']),
                    $path
                );
                return $this->apiRequest($method, $resolvedPath, $context['accessToken'], $values);
            } catch (Throwable $error) {
                $lastError = $error;
                $this->context = null;
                $this->log('error', "Ampere.IQ HTTP-Versuch $attempt/{$this->retries} fehlgeschlagen: {$error->getMessage()}");

                if ($this->isAuthenticationFailure($error)) {
                    try {
                        $this->passwordLogin();
                    } catch (Throwable $loginError) {
                        $lastError = $loginError;
                        $this->log('error', 'Automatischer Ampere.IQ-Login fehlgeschlagen: ' . $loginError->getMessage());
                    }
                }
                if ($attempt < $this->retries && $this->retryDelay > 0) {
                    sleep($this->retryDelay);
                }
            }
        }

        throw new RuntimeException(
            'Ampere.IQ-HTTP-Zugriff nach ' . $this->retries . ' Versuchen fehlgeschlagen: '
            . ($lastError?->getMessage() ?? 'unbekannter Fehler'),
            0,
            $lastError
        );
    }

    private function getContext(): array
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $tokens = $this->loadTokens();
        if ($tokens === null) {
            $tokens = $this->passwordLogin();
        }
        try {
            $tokens = $this->ensureAccessToken($tokens);
        } catch (Throwable $error) {
            if (!$this->isAuthenticationFailure($error)) {
                throw $error;
            }
            $tokens = $this->passwordLogin();
            $tokens = $this->ensureAccessToken($tokens);
        }

        $accessToken = (string)($tokens['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Die Ampere.IQ-Anmeldung lieferte keinen Access-Token.');
        }
        try {
            $installations = $this->apiRequest('GET', '/api/v1/installation', $accessToken);
        } catch (Throwable $error) {
            if (!$this->isAuthenticationFailure($error)) {
                throw $error;
            }
            $tokens = $this->passwordLogin();
            $accessToken = (string)($tokens['access_token'] ?? '');
            $installations = $this->apiRequest('GET', '/api/v1/installation', $accessToken);
        }

        if (!array_is_list($installations) || $installations === []) {
            throw new RuntimeException('Im Ampere.IQ-Konto wurde keine Installation gefunden.');
        }
        $installation = $installations[0];
        $installationId = is_array($installation) ? trim((string)($installation['id'] ?? '')) : '';
        if ($installationId === '') {
            throw new RuntimeException('Die Ampere.IQ-Installations-ID fehlt.');
        }

        return $this->context = [
            'accessToken' => $accessToken,
            'installationId' => $installationId,
        ];
    }

    private function apiRequest(string $method, string $path, string $accessToken, ?array $values = null): array
    {
        $options = [
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
                'x-client-type: de.ekd.customerapp',
                'x-client-version: ' . self::APP_VERSION,
            ],
        ];
        if (strtoupper($method) !== 'GET') {
            $json = json_encode($values ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Ampere.IQ-Payload konnte nicht als JSON erzeugt werden.');
            }
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            $options[CURLOPT_POSTFIELDS] = $json;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        return $this->httpJson(self::API_BASE_URL . $path, $options);
    }

    private function ensureAccessToken(array $tokens): array
    {
        $accessToken = (string)($tokens['access_token'] ?? '');
        $expiresAt = (int)($tokens['expires_at'] ?? $this->jwtExpiresAt($accessToken));
        if ($accessToken !== '' && $expiresAt > time() + 60) {
            return $tokens;
        }

        $refreshToken = (string)($tokens['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new RuntimeException('Kein Refresh-Token vorhanden.');
        }

        $refreshed = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => self::AUTH0_CLIENT_ID,
            'refresh_token' => $refreshToken,
            'scope' => self::AUTH0_SCOPE,
        ]);
        if (empty($refreshed['refresh_token'])) {
            $refreshed['refresh_token'] = $refreshToken;
        }
        $refreshed['obtained_at'] = time();
        $refreshed['expires_at'] = time() + (int)($refreshed['expires_in'] ?? 0);
        $this->saveTokens($refreshed);
        return $refreshed;
    }

    private function passwordLogin(): array
    {
        $parameters = $this->loadParameters();
        $ampereIq = is_array($parameters['ampereIq'] ?? null) ? $parameters['ampereIq'] : $parameters;
        $username = trim((string)($ampereIq['username'] ?? ''));
        $password = (string)($ampereIq['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new RuntimeException("Ampere.IQ-Zugangsdaten fehlen in {$this->paramsFile}");
        }

        $authorization = $this->createAuthorizationRequest();
        $cookieFile = tempnam(sys_get_temp_dir(), 'ampere-auth-');
        if ($cookieFile === false) {
            throw new RuntimeException('TemporÃ¤re Auth0-Cookie-Datei konnte nicht angelegt werden.');
        }
        try {
            $loginUrl = $this->followAuth0ToHtmlPage($authorization['url'], $cookieFile);
            if (!str_contains($loginUrl, '/u/login?')) {
                throw new RuntimeException("Unerwartete Auth0-Anmeldeseite: $loginUrl");
            }
            $response = $this->auth0HttpRequest($loginUrl, $cookieFile, [
                'state' => $this->getQueryParameter($loginUrl, 'state'),
                'username' => $username,
                'password' => $password,
                'action' => 'default',
            ]);
            $callbackUrl = $this->followAuth0ResponseToCallback($response, $cookieFile);
            $tokens = $this->exchangeAuthorizationCallback(
                $callbackUrl,
                $authorization['verifier'],
                $authorization['state']
            );
            $this->log('info', 'Ampere.IQ-Anmeldung wurde automatisch erneuert.');
            return $tokens;
        } finally {
            @unlink($cookieFile);
        }
    }

    private function createAuthorizationRequest(): array
    {
        $verifier = $this->base64UrlEncode(random_bytes(64));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));
        $state = $this->base64UrlEncode(random_bytes(24));
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => self::AUTH0_CLIENT_ID,
            'redirect_uri' => self::AUTH0_REDIRECT_URI,
            'scope' => self::AUTH0_SCOPE,
            'audience' => self::AUTH0_AUDIENCE,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'url' => self::AUTH0_BASE_URL . '/authorize?' . $query,
            'verifier' => $verifier,
            'state' => $state,
        ];
    }

    private function exchangeAuthorizationCallback(string $callbackUrl, string $verifier, string $expectedState): array
    {
        $params = [];
        parse_str((string)(parse_url($callbackUrl, PHP_URL_QUERY) ?: ''), $params);
        if (isset($params['error'])) {
            throw new RuntimeException('Auth0-Anmeldung fehlgeschlagen: ' . (string)($params['error_description'] ?? $params['error']));
        }
        if (!hash_equals($expectedState, (string)($params['state'] ?? ''))) {
            throw new RuntimeException('Der OAuth-State der RÃ¼cksprung-URL stimmt nicht.');
        }
        $code = (string)($params['code'] ?? '');
        if ($code === '') {
            throw new RuntimeException('In der Auth0-RÃ¼cksprung-URL fehlt der Anmeldecode.');
        }

        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => self::AUTH0_CLIENT_ID,
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => self::AUTH0_REDIRECT_URI,
        ]);
        $tokens['obtained_at'] = time();
        $tokens['expires_at'] = time() + (int)($tokens['expires_in'] ?? 0);
        $this->saveTokens($tokens);
        return $tokens;
    }

    private function followAuth0ToHtmlPage(string $url, string $cookieFile): string
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->auth0HttpRequest($url, $cookieFile);
            if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
                $url = $this->resolveAuth0Location($response['location']);
                continue;
            }
            if ($response['status'] === 200) {
                return $response['url'];
            }
            throw new RuntimeException("Auth0 lieferte HTTP {$response['status']} bei {$response['url']}");
        }
        throw new RuntimeException('Zu viele Weiterleitungen vor der Auth0-Anmeldeseite.');
    }

    private function followAuth0ResponseToCallback(array $response, string $cookieFile): string
    {
        for ($i = 0; $i < 12; $i++) {
            if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
                if (!preg_match('~^https?://~i', $response['location']) && !str_starts_with($response['location'], '/')) {
                    return $response['location'];
                }
                $response = $this->auth0HttpRequest($this->resolveAuth0Location($response['location']), $cookieFile);
                continue;
            }
            throw new RuntimeException("Automatischer Auth0-Login endete mit HTTP {$response['status']} bei {$response['url']}");
        }
        throw new RuntimeException('Zu viele Weiterleitungen nach der Auth0-Anmeldung.');
    }

    private function auth0HttpRequest(string $url, string $cookieFile, ?array $postFields = null): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 Ampere-IQ-Task/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8'],
        ];
        if ($postFields !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($postFields, '', '&', PHP_QUERY_RFC3986);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER][] = 'Origin: ' . self::AUTH0_BASE_URL;
            $options[CURLOPT_REFERER] = $url;
        }
        $caFile = $this->resolveCaFile();
        if ($caFile !== null) {
            $options[CURLOPT_CAINFO] = $caFile;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Auth0-cURL-Fehler bei $url: $error");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        $headers = substr((string)$raw, 0, $headerSize);
        $location = '';
        if (preg_match('/^location:\s*(.+)$/mi', $headers, $matches)) {
            $location = trim($matches[1]);
        }
        return [
            'status' => $status,
            'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
            'location' => $location,
            'body' => substr((string)$raw, $headerSize),
        ];
    }

    private function tokenRequest(array $fields): array
    {
        return $this->httpJson(self::AUTH0_BASE_URL . '/oauth/token', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
    }

    private function httpJson(string $url, array $options): array
    {
        $curlOptions = $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ];
        $caFile = $this->resolveCaFile();
        if ($caFile !== null) {
            $curlOptions[CURLOPT_CAINFO] = $caFile;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL-Fehler bei $url: $error");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? (string)($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? json_encode($decoded))
                : trim((string)$body);
            throw new RuntimeException("HTTP $status bei $url" . ($message !== '' ? ": $message" : ''));
        }
        if (trim((string)$body) === '') {
            return [];
        }
        if (!is_array($decoded)) {
            throw new RuntimeException("UngÃ¼ltige JSON-Antwort von $url: " . json_last_error_msg());
        }
        return $decoded;
    }

    private function loadParameters(): array
    {
        if (!is_file($this->paramsFile)) {
            throw new RuntimeException("Ampere.IQ-Parameterdatei fehlt: {$this->paramsFile}");
        }
        $parameters = json_decode((string)file_get_contents($this->paramsFile), true);
        if (!is_array($parameters)) {
            throw new RuntimeException("UngÃ¼ltige Ampere.IQ-Parameterdatei: {$this->paramsFile}");
        }
        return $parameters;
    }

    private function loadTokens(): ?array
    {
        $parameters = $this->loadParameters();
        $ampereIq = is_array($parameters['ampereIq'] ?? null) ? $parameters['ampereIq'] : $parameters;
        $tokens = $ampereIq['tokens'] ?? null;
        return is_array($tokens) ? $tokens : null;
    }

    private function saveTokens(array $tokens): void
    {
        $parameters = $this->loadParameters();
        if (!isset($parameters['ampereIq']) || !is_array($parameters['ampereIq'])) {
            $parameters['ampereIq'] = [];
        }
        $parameters['ampereIq']['tokens'] = $tokens;
        $json = json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($this->paramsFile, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Ampere.IQ-Tokens konnten nicht gespeichert werden: {$this->paramsFile}");
        }
        @chmod($this->paramsFile, 0600);
    }

    private function isAuthenticationFailure(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        foreach (['kein refresh-token', 'http 400 bei ' . strtolower(self::AUTH0_BASE_URL) . '/oauth/token', 'http 401 ', 'http 403 ', 'invalid_grant', 'invalid refresh token'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function resolveCaFile(): ?string
    {
        $configured = trim((string)(getenv('AMPERE_IQ_CA_FILE') ?: ''));
        if ($configured !== '') {
            if (!is_file($configured)) {
                throw new RuntimeException("AMPERE_IQ_CA_FILE wurde nicht gefunden: $configured");
            }
            return $configured;
        }
        foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
            $file = trim((string)ini_get($setting));
            if ($file !== '' && is_file($file)) {
                return $file;
            }
        }
        $bundled = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_file($bundled)) {
            return $bundled;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        foreach (glob('C:/wamp*/apps/phpmyadmin*/vendor/composer/ca-bundle/res/cacert.pem') ?: [] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        throw new RuntimeException('Kein CA-ZertifikatsbÃ¼ndel gefunden.');
    }

    private function resolveAuth0Location(string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        return str_starts_with($location, '/') ? self::AUTH0_BASE_URL . $location : $location;
    }

    private function getQueryParameter(string $url, string $name): string
    {
        $parameters = [];
        parse_str((string)(parse_url($url, PHP_URL_QUERY) ?: ''), $parameters);
        $value = (string)($parameters[$name] ?? '');
        if ($value === '') {
            throw new RuntimeException("Parameter $name fehlt in $url");
        }
        return $value;
    }

    private function jwtExpiresAt(string $token): int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return 0;
        }
        $payload = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        return is_array($payload) ? (int)($payload['exp'] ?? 0) : 0;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':'
                && ($path[2] === '\\' || $path[2] === '/'));
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/AmpereIqHttpAccess.php';

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


<?php

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\SimpleHttpClient;

class HeizstabSensorService implements SensorFetcherInterface
{
    private ?array $aktData = null;
    private ?array $setupData = null;
    private string $heizstabCookieFile = '/home/peter/scripts/coh/cookies/heizstab_cookie.txt';
    private string $loginPath = '/auth.jsn';
    private string $password = '14881488';
    private string $passwordField = 'pw';
    private bool $insecureTls = true;
    private string $cloudApiBaseUrl = 'https://api.my-pv.com/api/v1';

    public function __construct(private mysql_dialog $db, private Logger $logger, private SimpleHttpClient $httpClient)
    {
    }

    public function supports($sensor): bool
    {
        return strtolower($sensor['sensorSource']) === 'heizstab';
    }

    public function fetch($sensor): array
    {
        return ['Heizstab' => 'Fetched Heizstab data'];
    }

    public function fetchArr(array $sensors): ?array
    {
        $debugval = false;
        $this->logger->setDebug($debugval);
        $this->logger->debugMe('HeizstabSensorService fetchArr len ' . count($sensors));
        $res = [];

        try {
            $access = '';
            if (count($sensors) > 0) {
                $access = trim((string)($sensors[0]['geraeteUrl'] ?? ''));
                $this->logger->debugMe('Heizstab Sensorservice Zugriff aus erster geraeteUrl len sensors:' . count($sensors));
            }

            if ($access === '') {
                $this->logger->Error('Heizstab: geraeteUrl fehlt. Erwartet wird IP/Hostname oder serialnummer:APIKey');
                if ($debugval) {
                    $this->logger->setDebug(false);
                }
                return null;
            }

            if ($this->getDataFromDevice($access) === null) {
                $this->logger->Error('getDataFromDevice null');
                if ($debugval) {
                    $this->logger->setDebug(false);
                }
                return null;
            }

            foreach ($sensors as $sensor) {
                $lokalAccess = $sensor['sensorID'];
                if (!empty($sensor['sensorLokalId'])) {
                    $lokalAccess = $sensor['sensorLokalId'];
                }

                $value = $this->getHeizstabdata($lokalAccess);
                $einheit = $sensor['sensorEinheit'];
                $this->logger->debugMe('Heizstab Sensorservice SensorID ' . $sensor['sensorID'] . " lokalAccess $lokalAccess value $value Einheit $einheit");

                if (!empty($sensor['transFormProcedur'])) {
                    $this->logger->debugMe('Heizstab Sensorservice SensorID ' . $sensor['sensorID'] . ' transFormProcedur ' . $sensor['transFormProcedur']);
                    if (method_exists($this, $sensor['transFormProcedur'])) {
                        $arr = $this->{$sensor['transFormProcedur']}($value);
                        $einheit = $arr['einheit'];
                        $value = $arr['wert'];
                    } else {
                        $this->logger->Error('Heizstab transFormProcedur ' . $sensor['transFormProcedur'] . ' fuer SensorID ' . $sensor['sensorID'] . ' existiert nicht');
                    }
                }

                if ($value === null) {
                    $this->logger->Info('Heizstab Sensorservice keinen wert fuer sensorID: ' . $sensor['sensorID'] . " lokalAccess $lokalAccess");
                    $value = 0;
                }

                $res[$sensor['sensorID']] = [
                    'sensorID' => $sensor['sensorID'],
                    'sensorValue' => $value,
                    'sensorEinheit' => $einheit,
                    'sensorValueType' => $sensor['sensorValueType'],
                    'sensorSource' => $sensor['sensorSource'],
                ];
            }

            if ($debugval) {
                $this->logger->setDebug(false);
            }
            return $res;
        } catch (\Throwable $e) {
            $this->logger->debugMe('Heizstab: Fehler bei : ' . $e->getMessage());
            return null;
        }
    }

    private function getDataFromDevice(string $access)
    {
        try {
            $cloudAccess = $this->parseCloudApiAccess($access);
            if ($cloudAccess === false) {
                return null;
            }

            if ($cloudAccess !== null) {
                [$serial, $apiKey] = $cloudAccess;
                return $this->getDataFromCloudApi($serial, $apiKey);
            }

            foreach ($this->getLocalBaseUrlCandidates($access) as $baseUrl) {
                if (!$this->ensureElwaLogin($baseUrl)) {
                    @unlink($this->heizstabCookieFile);
                    continue;
                }

                $v1 = $this->fetchJsonWithRelogin($baseUrl, '/data.jsn');
                $v2 = $this->fetchJsonWithRelogin($baseUrl, '/setup.jsn');
                if ($v1 === null || $v2 === null) {
                    $this->logger->Error("Heizstab: Fehler bei Lesen der lokalen Daten ueber $baseUrl");
                    @unlink($this->heizstabCookieFile);
                    continue;
                }

                $this->aktData = $v1;
                $this->setupData = $v2;
                return 'OK';
            }

            $this->logLocalReachability($access);
            return null;
        } catch (\Throwable $e) {
            $this->logger->Error('Heizstab: Fehler bei getDataFromDevice : ' . $e->getMessage());
            return null;
        }
    }

    private function parseCloudApiAccess(string $access): array|false|null
    {
        if (
            !str_contains($access, ':')
            || str_starts_with($access, 'http://')
            || str_starts_with($access, 'https://')
            || filter_var($access, FILTER_VALIDATE_IP)
            || str_contains($access, '.')
            || str_contains($access, '/')
        ) {
            return null;
        }

        [$serial, $apiKey] = array_map('trim', explode(':', $access, 2));
        if (!preg_match('/^[0-9]{8,20}$/', $serial)) {
            $this->logger->Error("Heizstab: serial '$serial' fehlerhaft");
            return false;
        }

        if (strlen($apiKey) < 20 || preg_match('/^[A-Za-z0-9._-]+$/', $apiKey) !== 1) {
            $this->logger->Error("Heizstab: serial '$serial' Api key fehlerhaft");
            return false;
        }

        return [$serial, $apiKey];
    }

    private function getLocalBaseUrlCandidates(string $access): array
    {
        if (str_starts_with($access, 'http://')) {
            return [$access];
        }

        if (str_starts_with($access, 'https://')) {
            return [
                $access,
                'http://' . substr($access, strlen('https://')),
            ];
        }

        return [
            'https://' . $access,
            'http://' . $access,
        ];
    }

    private function logLocalReachability(string $access): void
    {
        $host = $this->getLocalHost($access);
        if ($host === '') {
            $this->logger->Error("Heizstab: lokaler Zugriff fehlgeschlagen, Host konnte aus '$access' nicht ermittelt werden");
            return;
        }

        $tcp443 = $this->canConnectTcp($host, 443);
        $tcp80 = $this->canConnectTcp($host, 80);
        $ping = $this->pingHost($host);

        $this->logger->Error(
            "Heizstab Erreichbarkeit $host: "
            . 'ping=' . $ping
            . ', tcp443=' . ($tcp443 ? 'ok' : 'nicht erreichbar')
            . ', tcp80=' . ($tcp80 ? 'ok' : 'nicht erreichbar')
        );

        if (!$tcp443 && !$tcp80 && $ping !== 'ok') {
            $this->logger->Error("Heizstab $host scheint nicht erreichbar zu sein. Bitte Sicherung, Stromversorgung, WLAN/LAN und IP-Adresse pruefen.");
        }
    }

    private function getLocalHost(string $access): string
    {
        $candidate = $access;
        if (!str_starts_with($candidate, 'http://') && !str_starts_with($candidate, 'https://')) {
            $candidate = 'http://' . $candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private function canConnectTcp(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if (!is_resource($fp)) {
            return false;
        }

        fclose($fp);
        return true;
    }

    private function pingHost(string $host): string
    {
        if (!filter_var($host, FILTER_VALIDATE_IP) || !function_exists('exec')) {
            return 'nicht geprueft';
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? 'ping -n 1 -w 1000 ' . escapeshellarg($host)
            : 'ping -c 1 -W 1 ' . escapeshellarg($host);

        $output = [];
        $code = 1;
        @exec($command, $output, $code);

        return $code === 0 ? 'ok' : 'fehler';
    }

    private function getDataFromCloudApi(string $serial, string $apiKey)
    {
        $v1 = $this->cloudApiGetJson($serial, $apiKey, 'data');
        $v2 = $this->cloudApiGetJson($serial, $apiKey, 'setup');
        if ($v1 === null || $v2 === null) {
            $this->logger->Error('Heizstab: Fehler bei Lesen der Daten ueber my-PV Cloud API');
            return null;
        }

        $this->aktData = $v1;
        $this->setupData = $v2;
        return 'OK';
    }

    private function cloudApiGetJson(string $serial, string $apiKey, string $endpoint): ?array
    {
        $url = rtrim($this->cloudApiBaseUrl, '/') . '/device/' . rawurlencode($serial) . '/' . ltrim($endpoint, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $this->applyTlsOptions($ch);

        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->Error('Heizstab Cloud API cURL Fehler: ' . curl_error($ch) . " URL: $url");
            curl_close($ch);
            return null;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            $this->logger->Error("Heizstab Cloud API HTTP Fehler [$code] URL: $url Antwort: " . trim((string)$response));
            return null;
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            $this->logger->Error("Heizstab Cloud API Antwort von $endpoint ist kein JSON-Array");
            return null;
        }

        return $decoded;
    }

    private function ensureElwaLogin(string $baseUrl): bool
    {
        if (file_exists($this->heizstabCookieFile) && filesize($this->heizstabCookieFile) > 0) {
            return true;
        }

        return $this->elwaLogin($baseUrl);
    }

    private function elwaLogin(string $baseUrl): bool
    {
        $dir = dirname($this->heizstabCookieFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $url = $this->buildUrl($baseUrl, $this->loginPath);    // auth.jsn https request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([$this->passwordField => $this->password]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->heizstabCookieFile,
            CURLOPT_COOKIEFILE => $this->heizstabCookieFile,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $this->applyTlsOptions($ch);

        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->Error('Heizstab Login cURL Fehler: ' . curl_error($ch) . " URL: $url");
            curl_close($ch);
            return false;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!in_array($code, [200, 204, 302, 303], true)) {
            $this->logger->Error("Heizstab Login HTTP Fehler [$code] URL: $url Antwort: " . trim((string)$response));
            return false;
        }

        return true;
    }

    private function fetchJsonWithRelogin(string $baseUrl, string $path): ?array
    {
        $result = $this->curlGet($this->buildUrl($baseUrl, $path));
        if (in_array($result['http_code'], [301, 302, 303, 401, 403], true)) {
            @unlink($this->heizstabCookieFile);
            if (!$this->elwaLogin($baseUrl)) {
                return null;
            }
            $result = $this->curlGet($this->buildUrl($baseUrl, $path));
        }

        if ($result['http_code'] !== 200) {
            $this->logger->Error("Heizstab GET HTTP Fehler {$result['http_code']} fuer " . $this->buildUrl($baseUrl, $path) . " Antwort: {$result['body']}");
            return null;
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            $this->logger->Error("Heizstab Antwort von $path ist kein JSON-Array");
            return null;
        }

        return $decoded;
    }

    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->heizstabCookieFile,
            CURLOPT_COOKIEFILE => $this->heizstabCookieFile,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $this->applyTlsOptions($ch);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->Error("Heizstab GET cURL Fehler: $error URL: $url");
            return [
                'http_code' => 0,
                'body' => '',
            ];
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => (int)$code,
            'body' => (string)$response,
        ];
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function applyTlsOptions($ch): void
    {
        if (!$this->insecureTls) {
            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    private function getHeizstabdata($sensorID)
    {
        if (isset($this->aktData[$sensorID])) {
            $this->logger->debugMe(" getHeizstabdata return $sensorID " . $this->aktData[$sensorID]);
            return $this->aktData[$sensorID];
        }

        if (isset($this->setupData[$sensorID])) {
            $this->logger->debugMe(" getHeizstabdata return $sensorID " . $this->setupData[$sensorID]);
            return $this->setupData[$sensorID];
        }

        $aliases = [
            'boostactive' => ['bststrt'],
            'ctrl' => ['ctrlstate'],
            'maxpwr' => ['power_nominal'],
            'power_elwa2' => ['power', 'power_act', 'power_actual'],
            'ww1boost' => ['ww1target'],
        ];

        foreach ($aliases[$sensorID] ?? [] as $alias) {
            if (isset($this->aktData[$alias])) {
                $this->logger->debugMe(" getHeizstabdata return alias $sensorID/$alias " . $this->aktData[$alias]);
                return $this->aktData[$alias];
            }
            if (isset($this->setupData[$alias])) {
                $this->logger->debugMe(" getHeizstabdata return alias $sensorID/$alias " . $this->setupData[$alias]);
                return $this->setupData[$alias];
            }
        }

        $this->logger->Error("Heizstab: Fehler bei getHeizstabdata : $sensorID weder in aktData noch setupData");
        return null;
    }

    private function elwaPwrkWh($stat)
    {
        $resArr['wert'] = round($stat / 1000, 2);
        $resArr['einheit'] = 'kWh';
        return $resArr;
    }

    private function elwaPwr($stat)
    {
        $resArr['wert'] = $stat;
        $resArr['einheit'] = '%';
        return $resArr;
    }

    private function elwaTemp($stat)
    {
        $resArr['wert'] = round($stat / 10, 2);
        $resArr['einheit'] = '°C';
        return $resArr;
    }

    private function elwaProt($stat)
    {
        $resArr['wert'] = $stat;
        switch ($stat) {
            case 0:
                $v = 'Auto Detec';
                break;
            case 1:
                $v = 'HTTP';
                break;
            case 2:
                $v = 'Modbus TCP';
                break;
            default:
                $v = 'Protokoll undefinioert';
                break;
        }
        $resArr['einheit'] = $v;
        return $resArr;
    }
}
?>

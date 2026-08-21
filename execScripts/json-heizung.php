<?php
// gedacht als endlosschleife die über Parameter aus einer dartei versorgt wird versorgt wird
// check ob geheizt werden soll
// Schreibt die wesentlichen Werte der Smartbox und des Heizstabes in die Datenbank   (derzeit noch nicht)

// start // php json-heizung.php &
// sicherer Modbustest // php json-heizung.php modbus-test [Parameterdatei]
// beenden mit ssh ende oder 
// ps aux | grep json-heizung
// kill (erste Zahl aus dem ergebnis

require_once __DIR__ . '/Logger.php';

// Der IQ-Box-SOC-Zugriff ist direkt implementiert; keine Zusatzdatei erforderlich.
$debug=false;
$logf="/home/peter/coh/logs/heizstabserver.log";
$logger = new Logger();
$logger->setLogfile ($logf);
$logger->setDebug($debug);
$logger->Info("json-heizung startet mit lokalen Energie-Zugriffen");
// als Globale Daten verwenden
$urlheizStab='http://192.168.178.46/';                  // default falls nicht in params
$paramsFile = __DIR__ . '/task_heizstab_params.json';   // Parameterdatei neben diesem Script
$iqBoxModbus = [
    'enabled' => true,
    'host' => 'ASP-HSR2103J2311E08738.local',
    'port' => 502,
    'unitId' => 1,
    'timeout' => 3.0,
];
$heizstabCookieDir = '/home/peter/scripts/coh/cookies';
$heizstabCookieFile = '';
$heizstabAuth = [
    'enabled'       => true,
    'loginPath'     => '/auth.jsn',
    'username'      => null,
    'password'      => '',
    'usernameField' => null,
    'passwordField' => 'pw',
    'extraFields'   => [],
    'insecureTls'   => false,
];
$heizstabApi = [
    'enabled'                 => false,
    'baseUrl'                 => 'https://api.my-pv.com/api/v1',
    'serial'                  => '',
    'apiToken'                => '',
    'apiTokenEnv'             => 'MYPV_API_TOKEN',
    'dataEndpoint'            => 'data',
    'setupEndpoint'           => 'setup',
    'powerEndpoint'           => 'power',
    'insecureTls'             => false,
    'powerOn'                 => 3000,
    'targetWaterTemp'         => 60,
    'validForMinutes'         => 20,
    'timeBoostOverride'       => 0,
    'timeBoostValue'          => 0,
    'legionellaBoostBlock'    => 1,
];
$heizstabControl = [
    'enabled'      => true,
    'mode'         => 'boost-local',
    'boostOnBody'  => 'bststrt=1',
    'boostOffBody' => 'bststrt=0',
];
$logger->Info("Restart json-heizung Logfile $logf paramsFile $paramsFile");

$logfile="";
$logfileHandle;
$aktData = [];
$setupData = [];
$hystereseSoll=40; // wenn heizen eingeschaltet wird, so muss der füllstand des Akkus mindestens
$hysterese=0;      // nach einem einschalten der Heizung wird erst wieder geheizt wenn die Hysterese des Akkus erreicht wird,
$heizstabDurchRegelungAktiv=false; // nur dann am Intervallende automatisch ausschalten
$repeat = 10;      // while Schleife alle 10 Min


function normalizeBaseUrl(string $value, string $defaultScheme = 'http'): string
{
    $value = trim($value);
    if ($value === '') { return ''; }
    if (!preg_match('~^https?://~i', $value)) { $value = $defaultScheme . '://' . $value; }

    return rtrim($value, '/') . '/';
}

function buildUrl(string $baseUrl, string $path): string { return rtrim($baseUrl, '/') . '/' . ltrim($path, '/'); }

function buildDefaultCookieFile(string $baseUrl, string $cookieDir, string $prefix): string
{
    $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'unknown';
    $port = parse_url($baseUrl, PHP_URL_PORT);
    $cookieHost = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $host . ($port ? '_' . $port : '')) ?? 'unknown';
    $cookieHost = trim($cookieHost, '_') ?: 'unknown';
    $cookieName = $prefix . '_' . $cookieHost . '_cookie.txt';
    return rtrim($cookieDir, '/') . '/' . $cookieName;
}

function configureIqBoxModbus(array $params): void
{
    global $iqBoxModbus;
    if (!isset($params['iqBoxModbus']) || !is_array($params['iqBoxModbus'])) { return; }
    $cfg = $params['iqBoxModbus'];
    $iqBoxModbus['enabled'] = !array_key_exists('enabled', $cfg) || !empty($cfg['enabled']);
    if (isset($cfg['host']) && trim((string)$cfg['host']) !== '') { $iqBoxModbus['host'] = trim((string)$cfg['host']); }
    $iqBoxModbus['port'] = (int)($cfg['port'] ?? $iqBoxModbus['port']);
    $iqBoxModbus['unitId'] = (int)($cfg['unitId'] ?? $iqBoxModbus['unitId']);
    $iqBoxModbus['timeout'] = max(0.1, (float)($cfg['timeout'] ?? $iqBoxModbus['timeout']));
}

/** Liest ausschliesslich das StoragePro-SOC-Register 0xA00C per Modbus TCP. */
function readIqBoxBatterySoc(array $config): float
{
    $host = (string)$config['host'];
    $port = (int)$config['port'];
    $unitId = (int)$config['unitId'];
    $timeout = (float)$config['timeout'];
    $errno = 0;
    $error = '';
    $socket = @stream_socket_client("tcp://$host:$port", $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) { throw new RuntimeException("Modbus-Verbindung zur IQ-Box $host:$port fehlgeschlagen: $error ($errno)"); }
    try {
        $seconds = (int)$timeout;
        stream_set_timeout($socket, $seconds, (int)(($timeout - $seconds) * 1000000));
        $transactionId = random_int(1, 65535);
        $request = pack('nnnCCnn', $transactionId, 0, 6, $unitId, 3, 0xA00C, 1);
        writeModbusData($socket, $request);
        $header = readModbusData($socket, 7);
        $mbap = unpack('ntransaction/nprotocol/nlength/Cunit', $header);
        if (!is_array($mbap) || $mbap['transaction'] !== $transactionId || $mbap['protocol'] !== 0 || $mbap['unit'] !== $unitId) {
            throw new RuntimeException('Ungueltiger Modbus-MBAP-Header von der IQ-Box.');
        }
        $pdu = readModbusData($socket, (int)$mbap['length'] - 1);
        $function = ord($pdu[0] ?? "\0");
        if (($function & 0x80) !== 0) {
            throw new RuntimeException('IQ-Box meldet Modbus-Exception ' . ord($pdu[1] ?? "\0") . ' fuer Register 0xA00C.');
        }
        if ($function !== 3 || strlen($pdu) !== 4 || ord($pdu[1]) !== 2) {
            throw new RuntimeException('Unerwartete Modbus-Antwort fuer das Batterie-SOC.');
        }
        $register = unpack('nvalue', substr($pdu, 2, 2));
        return round(((int)$register['value']) * 0.01, 2);
    } finally {
        fclose($socket);
    }
}

function writeModbusData($socket, string $data): void
{
    $written = 0;
    while ($written < strlen($data)) {
        $count = fwrite($socket, substr($data, $written));
        if ($count === false || $count === 0) { throw new RuntimeException('Modbus-Anfrage konnte nicht vollstaendig gesendet werden.'); }
        $written += $count;
    }
}

function readModbusData($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($socket);
            $reason = !empty($meta['timed_out']) ? 'Timeout' : 'Verbindung beendet';
            throw new RuntimeException("Modbus-Antwort unvollstaendig: $reason.");
        }
        $data .= $chunk;
    }
    return $data;
}
function getLocalRegulationValues()
{
    global $iqBoxModbus, $logger;
    if (empty($iqBoxModbus['enabled'])) { $logger->Error('IQ-Box-Modbuszugriff ist deaktiviert'); return false; }
    try {
        $val='batterySoc';      //Für errormessage
        $batterySoc = readIqBoxBatterySoc($iqBoxModbus);
        // Der Heizstab ist per Modbus durch die IQ-Box belegt. Daher lokal per data.jsn/setup.jsn lesen.
        $val='temperature';      //Für errormessage
        $temperature = normalizeTemperatureValue(getHeizstabdata('temp1'));
        $val='targetTemperature';      //Für errormessage
        $targetTemperature = getTargetWaterTemp();
        if (!is_numeric($batterySoc) || !is_numeric($temperature) || !is_numeric($targetTemperature)) {
            throw new RuntimeException('Regelungswerte fehlen: batterySoc=' . formatLogValue($batterySoc) . ', temperature=' . formatLogValue($temperature) . ', targetTemperature=' . formatLogValue($targetTemperature));
        }
        return ['batterySoc'=>(float)$batterySoc, 'temperature'=>(float)$temperature, 'targetTemperature'=>(float)$targetTemperature, 'temperatureTimestamp'=>date(DATE_ATOM)];
    } catch (Throwable $e) { $logger->Error("Lokale Regelungswerte konnten nicht gelesen werden: bei $val " . $e->getMessage()); return false; }
}

function formatLogValue($value): string
{
    if ($value === null) { return 'null'; }
    return is_scalar($value) ? (string)$value : gettype($value);
}
function configureHeizstabAuth(array $params): void
{
    global $urlheizStab, $heizstabAuth, $heizstabCookieDir, $heizstabCookieFile;

    if (!isset($params['heizstabAuth']) || !is_array($params['heizstabAuth'])) { $heizstabAuth['enabled'] = false; return; }

    $cfg = $params['heizstabAuth'];
    $heizstabAuth['enabled']       = !empty($cfg['enabled']);
    $heizstabAuth['loginPath']     = (string)($cfg['loginPath'] ?? $heizstabAuth['loginPath']);
    $heizstabAuth['username']      = isset($cfg['username']) ? (string)$cfg['username'] : null;
    $heizstabAuth['password']      = (string)($cfg['password'] ?? '');
    $heizstabAuth['usernameField'] = isset($cfg['usernameField']) ? (string)$cfg['usernameField'] : null;
    $heizstabAuth['passwordField'] = (string)($cfg['passwordField'] ?? $heizstabAuth['passwordField']);
    $heizstabAuth['extraFields']   = is_array($cfg['extraFields'] ?? null) ? $cfg['extraFields'] : [];
    $heizstabAuth['insecureTls']   = !empty($cfg['insecureTls']);

    $heizstabCookieDir = (string)($cfg['cookieDir'] ?? $heizstabCookieDir);
    $heizstabCookieFile = !empty($cfg['cookieFile']) ? (string)$cfg['cookieFile'] : buildDefaultCookieFile($urlheizStab, $heizstabCookieDir, 'heizstab');
}

function configureHeizstabApi(array $params): void
{
    global $heizstabApi;

    if (!isset($params['heizstabApi']) || !is_array($params['heizstabApi'])) {
        $heizstabApi['enabled'] = false;
        return;
    }

    $cfg = $params['heizstabApi'];
    $heizstabApi['enabled']              = !empty($cfg['enabled']);
    $heizstabApi['baseUrl']              = rtrim((string)($cfg['baseUrl'] ?? $heizstabApi['baseUrl']), '/');
    $heizstabApi['serial']               = trim((string)($cfg['serial'] ?? $heizstabApi['serial']));
    $heizstabApi['apiToken']             = (string)($cfg['apiToken'] ?? $heizstabApi['apiToken']);
    $heizstabApi['apiTokenEnv']          = (string)($cfg['apiTokenEnv'] ?? $heizstabApi['apiTokenEnv']);
    $heizstabApi['dataEndpoint']         = trim((string)($cfg['dataEndpoint'] ?? $heizstabApi['dataEndpoint']), '/');
    $heizstabApi['setupEndpoint']        = trim((string)($cfg['setupEndpoint'] ?? $heizstabApi['setupEndpoint']), '/');
    $heizstabApi['powerEndpoint']        = trim((string)($cfg['powerEndpoint'] ?? $heizstabApi['powerEndpoint']), '/');
    $heizstabApi['insecureTls']          = !empty($cfg['insecureTls']);
    $heizstabApi['powerOn']              = max(0, (int)($cfg['powerOn'] ?? $heizstabApi['powerOn']));
    $heizstabApi['targetWaterTemp']      = max(1, (float)($cfg['targetWaterTemp'] ?? $heizstabApi['targetWaterTemp']));
    $heizstabApi['validForMinutes']      = max(1, (int)($cfg['validForMinutes'] ?? $heizstabApi['validForMinutes']));
    $heizstabApi['timeBoostOverride']    = (int)($cfg['timeBoostOverride'] ?? $heizstabApi['timeBoostOverride']);
    $heizstabApi['timeBoostValue']       = (int)($cfg['timeBoostValue'] ?? $heizstabApi['timeBoostValue']);
    $heizstabApi['legionellaBoostBlock'] = (int)($cfg['legionellaBoostBlock'] ?? $heizstabApi['legionellaBoostBlock']);
}

function configureHeizstabControl(array $params): void
{
    global $heizstabControl;

    if (!isset($params['heizstabControl']) || !is_array($params['heizstabControl'])) { return; }

    $cfg = $params['heizstabControl'];
    $heizstabControl['enabled']      = !empty($cfg['enabled']);
    $heizstabControl['mode']         = (string)($cfg['mode'] ?? $heizstabControl['mode']);
    $heizstabControl['boostOnBody']  = (string)($cfg['boostOnBody'] ?? ($cfg['boostOnPath'] ?? $heizstabControl['boostOnBody']));
    $heizstabControl['boostOffBody'] = (string)($cfg['boostOffBody'] ?? ($cfg['boostOffPath'] ?? $heizstabControl['boostOffBody']));
    $heizstabControl['boostOnBody']  = normalizeBoostBody($heizstabControl['boostOnBody'], 'bststrt=1');
    $heizstabControl['boostOffBody'] = normalizeBoostBody($heizstabControl['boostOffBody'], 'bststrt=0');
}

function normalizeBoostBody(string $value, string $fallback): string
{
    $value = trim($value);
    if ($value === '') { return $fallback; }
    $query = parse_url($value, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') { return $query; }
    return ltrim($value, '?');
}

function heizstabBoostSicherstellung(bool $enable): bool
{
    global $heizstabControl, $logger;

    if (empty($heizstabControl['enabled'])) { $logger->Info('Heizstab-Steuerung deaktiviert, Sicherstellung wird nicht geschaltet'); return false; }
    if ($heizstabControl['mode'] !== 'boost-local') { $logger->Error('Unbekannter Heizstab-Steuermodus: ' . $heizstabControl['mode']); return false; }

    $body = $enable ? $heizstabControl['boostOnBody'] : $heizstabControl['boostOffBody'];
    $logger->Info('fkt: heizstabBoostSicherstellung ' . ($enable ? 'starten' : 'stoppen') . " POST /setup.jsn Body: $body");

    $response = heizstabPostSetup($body);
    if ($response === false) { $logger->Error("fkt: heizstabBoostSicherstellung konnte nicht geschaltet werden mit $body"); return false; }
    return true;
}

function heizstabPostSetup(string $body, bool $retryAfterLogin = false)
{
    global $urlheizStab, $heizstabAuth, $heizstabCookieFile, $logger;

    if (!empty($heizstabAuth['enabled']) && !file_exists($heizstabCookieFile)) { if (!heizstabLogin()) { return false; } }

    $url = buildUrl($urlheizStab, '/setup.jsn');
    $postBody = $body;
    if (!empty($heizstabAuth['enabled']) && $heizstabAuth['password'] !== '') {
        $passwordField = $heizstabAuth['passwordField'];
        if (!preg_match('/(?:^|&)' . preg_quote($passwordField, '/') . '=/', $postBody)) {
            $postBody .= ($postBody === '' ? '' : '&')
                . rawurlencode($passwordField) . '=' . rawurlencode($heizstabAuth['password']);
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    if (!empty($heizstabAuth['enabled'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $heizstabCookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $heizstabCookieFile);
    }

    if (!empty($heizstabAuth['insecureTls'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $logger->Error('Heizstab POST setup cURL Fehler: ' . curl_error($ch) . " URL: $url");
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!empty($heizstabAuth['enabled']) && in_array($httpCode, [301, 302, 303, 401, 403], true)) {
        if ($retryAfterLogin) {
            $logger->Error("Heizstab POST setup Session ungueltig nach  retryAfterLogin[$httpCode] URL: $url");
            return false;
        }

        @unlink($heizstabCookieFile);    // cookiefile löschen
        if (!heizstabLogin()) {          // erneuter versuch
            return false;
        }

        return heizstabPostSetup($body, true);     // nach nwuem login mit retry daten lesen.
    }

    if ($httpCode >= 400) {
        $logger->Error("Heizstab POST setup HTTP Fehler [$httpCode] URL: $url Antwort: " . trim((string)$response));
        return false;
    }

    return $response;
}

/*
 * macht auth login für Heizstab
 * return false login failed
 */

function heizstabLogin(): bool
{
    global $urlheizStab, $heizstabAuth, $heizstabCookieFile, $logger;
    if (empty($heizstabAuth['enabled'])) { return true; }
    if ($heizstabAuth['password'] === '') { $logger->Error("Heizstab Login aktiv, aber password fehlt"); return false; }
    $dir = dirname($heizstabCookieFile);
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }

    $postFields = $heizstabAuth['extraFields'];
    if (!empty($heizstabAuth['usernameField']) && $heizstabAuth['username'] !== null && $heizstabAuth['username'] !== '') {
        $postFields[$heizstabAuth['usernameField']] = $heizstabAuth['username'];
    }
    $postFields[$heizstabAuth['passwordField']] = $heizstabAuth['password'];

    $loginUrl = buildUrl($urlheizStab, $heizstabAuth['loginPath']);
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_COOKIEJAR      => $heizstabCookieFile,
        CURLOPT_COOKIEFILE     => $heizstabCookieFile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if (!empty($heizstabAuth['insecureTls'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $logger->Error("Heizstab Login cURL Fehler: " . curl_error($ch) . " URL: $loginUrl");
        curl_close($ch);
        return false;
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($code, [200, 204, 302, 303], true)) { $logger->Error("Heizstab Login HTTP Fehler [$code] URL: $loginUrl"); return false; }
    return true;
}

// liest die data.jsn vom Heizstab und gibt sie als Array zurück
// liefert False bei einem Fehler
function getdata() {
    global $urlheizStab,$logger;
    $url=$urlheizStab."data.jsn";
    for ($i = 1; $i <= 10; $i++) {
      $content=curlRequest($url);
      if ($content === false) {
        //$logger->Error("!!! cURL getData Error:  url: $url"); 
        sleep(10); // Warte 10 sec
        continue;
      }
      $data = json_decode($content,true);
      if ($data === null) {
        //$logger->Error("!!! Fehler beim Parsen der JSON-Daten des Heizstabes  url $url");
        sleep(10); // Warte 10 sec
        continue;
      }
      return $data;
    }
    $logger->Error("!!! Fehler nach 10 maligen Aufruf url $url");
    return false;
}
// liest die setup.jsn vom Heizstab und gibt sie als Array zurück
// liefert False bei einem Fehler

function getsetup() {
    global $urlheizStab,$logger;
    $url=$urlheizStab."setup.jsn";
    for ($i = 1; $i <= 10; $i++) {
      $content=curlRequest($url);
      if ($content === false) {
        //$logger->Error("!!! cURL getsetup Error:  url: $url"); 
        sleep(10); // Warte 10 sec
        continue;
      }
      if ($content === false) {$logger->Error("!!! cURL Error: " . curl_error($ch)." url: $url"); return false;}
      $data = json_decode($content,true);
      if ($data === null) {
        //$logger->Error("!!! Fehler beim Parsen der JSON-Daten des Heizstabes url $url");
        sleep(10); // Warte 10 sec
        continue;
      }
      return $data;
    }
    $logger->Error("!!! Fehler nach 10 maligen Aufruf url $url");
    return false;
}

/*  liefert den wert vom Heizstab aus global $aktData,$setupData;
 *  
 */
function getHeizstabdata($name)
{
    global $aktData, $setupData;

    if (is_array($aktData) && array_key_exists($name, $aktData)) { return $aktData[$name]; }
    if (is_array($setupData) && array_key_exists($name, $setupData)) { return $setupData[$name]; }
    $aliases = [
        'ctrl'        => ['ctrlstate'],
        'maxpwr'      => ['power_nominal'],
        'power_elwa2' => ['power', 'power_act', 'power_actual'],
    ];
    foreach ($aliases[$name] ?? [] as $alias) {
        if (is_array($aktData) && array_key_exists($alias, $aktData)) { return $aktData[$alias]; }
        if (is_array($setupData) && array_key_exists($alias, $setupData)) { return $setupData[$alias]; }
    }
    return false;
}
// CURL-Request Funktion, um Redundanz zu vermeiden
function curlRequest($url, bool $retryAfterLogin = false)
{
    global $urlheizStab, $logger, $heizstabAuth, $heizstabCookieFile;

    $isHeizstab = str_starts_with($url, rtrim($urlheizStab, '/') . '/');
    $logger->debugMe("curlRequest isHeizstab $isHeizstab");
    if ($isHeizstab && !empty($heizstabAuth['enabled']) && !file_exists($heizstabCookieFile)) {
        if (!heizstabLogin()) { return false; }
    }
    $logger->debugMe("curlRequest beginn init url $url");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    if ($isHeizstab && !empty($heizstabAuth['enabled'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $heizstabCookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $heizstabCookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if (!empty($heizstabAuth['insecureTls'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
    }

    $content = curl_exec($ch);
    // cURL Fehler (Timeout / Host nicht erreichbar usw.)
    if ($content === false) {
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $logger->Error( "!!! cURL Fehler [$errno]: $error URL: $url" );
        curl_close($ch);
        return false;
    }
    // HTTP Status prüfen
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $logger->debugMe("curlRequest nach exec code $httpCode");
    if ($isHeizstab && !empty($heizstabAuth['enabled']) && in_array($httpCode, [301, 302, 303, 401, 403], true)) {
        $logger->debugMe("!!! Heizstab Session ungültig [$httpCode] URL: $url");
        curl_close($ch);

        if ($retryAfterLogin) { $logger->Error("!!! Heizstab Login nach Retry fehlgeschlagen [$httpCode] URL: $url retryAfterLogin $retryAfterLogin"); return false; }
        @unlink($heizstabCookieFile);
        if (!heizstabLogin()) {
            return false;
        }
        return curlRequest($url, true);
    }

    if ($httpCode >= 400) {
        $logger->Error( "!!! cURL HTTP Fehler [$httpCode] URL: $url" );
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    $logger->debugMe("curlRequest return ok ");
    return $content;
}
function timeToMinutes(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time));  // dient zum intervalvergleich
    return $h * 60 + $m;
}


/* startet oder stopt den Heizstab
 * 
 * $modus 0 Stopp heizstab
 * >0   in Steuerungseinstellung Modbus tcp Heizstab starten. Dabei wird aber die Heizstabeinstellung Warmwasser verwendet
 *      in Steuerungseinstellung http Heizstab starten.  Wert ist die eizustellende Powergröße
 *      Kommandos ctrl http
 *      /control.html?power=n n … Set power on the power stage, unlimited range of value The regulation is carried out by a higher-level control system.
 *      /control.html?pid_power=n The regulation is carried out by the pid-controller of AC ELWA 2
 *      /control.html?boost=1 activate Boost-Backup manually
 *      kommandos zum Umstellen ctrl
 *      http /setup.jsn?ctrl=1&ww1boost=700       auf 70 Grad aufheizen  ctrl 1 = http
 *      modtcp /setup.jsn?ctrl=2&tout=60      messintervall  ctrl 2 = modbus tcp
 */      
function heizen($modus) {
  global $logger;

  $logger->debugMe("Heizen Modus Heizstab $modus ueber Sicherstellung/Boost");
  return heizstabBoostSicherstellung($modus > 0);
}

function normalizeTemperatureValue($value): ?float
{
    if (!is_numeric($value)) { return null; }
    $temperature = (float)$value;
    if ($temperature <= 0) { return null; }
    return $temperature > 100 ? $temperature / 10 : $temperature;
}

function getTargetWaterTemp(): ?float
{
    global $aktData, $setupData;

    foreach (['ww1target', 'ww1boost'] as $field) {
        if (array_key_exists($field, $aktData)) {
            $temperature = normalizeTemperatureValue($aktData[$field]);
            if ($temperature !== null) { return $temperature; }
        }

        if (array_key_exists($field, $setupData)) {
            $temperature = normalizeTemperatureValue($setupData[$field]);
            if ($temperature !== null) {
                return $temperature;
            }
        }
    }

    return null;
}

/* 
 * entscheidung ob geheizt erden soll
 * Rückgabewerte
 * action = 1: Heizstab einschalten
 * action = 0: Heizstab ausschalten
 * action = null: Zustand nicht verändern
 * reason: Begründung für die Entscheidung
 * Eingabewerte
 * $isWithinInterval: Liegt die Uhrzeit in einem Heizintervall?
 * $boostAktiv: Ist der lokale Boost laut data.jsn aktiv?
 * $currentTemp: aktuelle Temperatur aus temperature
 * $targetTemp: Zieltemperatur aus targetTemperature
 * $stateBatterie: Akkustand aus batterySoc
 * $hysterese: aktuelle Wiedereinschaltsperre
 * $hystereseSoll: Freigabegrenze, derzeit 40 %
 * Prüfreihenfolge
 * Akku über 40 %
 * Bei mehr als 40 % wird die Wiedereinschaltsperre aufgehoben:
 * $hysterese = 0;
 * 
 * Außerhalb des Heizintervalls
 * Die Funktion ändert nichts:
 * action = null
 * Das Ausschalten am Intervallende erfolgt anschließend in der Hauptschleife, aber nur, wenn die Regelung den Heizstab selbst eingeschaltet hatte.
 * 
 * Temperatur fehlt
 * Ohne gültige Isttemperatur wird nichts geschaltet.
 * 
 * Zieltemperatur erreicht
 * Wenn currentTemp >= targetTemp gilt:
 * Heizstab läuft: action = 0
 * Heizstab ist bereits aus: action = null
 * 
 * Akku unter 20 %
 * Die Hysterese wird auf 40 gesetzt.
 * Heizstab läuft: ausschalten
 * Heizstab ist aus: nichts verändern
 * 
 * Heizstab läuft bereits
 * Wenn die Temperatur noch unter dem Ziel liegt und der Akku mindestens 20 % hat, darf er weiterheizen:
 * action = null
 * 
 * Akku über 40 % und Heizstab aus
 * Temperatur ist zu niedrig, daher:
 * action = 1
 * 
 * Akku zwischen 20 und 40 %, Hysterese noch frei
 * Wenn $hysterese === 0, darf einmal eingeschaltet werden. Gleichzeitig wird die Hysterese auf 40 gesetzt.
 * 
 * Hysterese aktiv
 * Der Heizstab bleibt aus, bis der Akku wieder über 40 % steigt.
 */

function decideHeizstabAction( bool $isWithinInterval, bool $boostAktiv, ?float $currentTemp, float $targetTemp, int $stateBatterie, int &$hysterese, int $hystereseSoll ): array {
    if ($stateBatterie > $hystereseSoll) { $hysterese = 0; }
    if (!$isWithinInterval) { return [ 'action' => null, 'reason' => 'außerhalb Intervall, Regelung pausiert',]; }
    if ($currentTemp === null) { return [ 'action' => null, 'reason' => 'keine gültige Temperatur', ]; }
    if ($currentTemp >= $targetTemp) {
        return [ 'action' => $boostAktiv ? 0 : null, 'reason' => "Temperatur erreicht ($currentTemp >= $targetTemp)",]; }
    if ($stateBatterie < 20) {
        $hysterese = $hystereseSoll;
        return [ 'action' => $boostAktiv ? 0 : null, 'reason' => "Akku unter 20% ($stateBatterie%)",];
    }
    if ($boostAktiv) { return [ 'action' => null, 'reason' => "Boost ist aktiv, Temperatur noch zu niedrig ($currentTemp < $targetTemp)", ]; }
    if ($stateBatterie > $hystereseSoll) { return [ 'action' => 1, 'reason' => "Temperatur zu niedrig und Akku über $hystereseSoll% ($stateBatterie%)", ];}
    if ($hysterese === 0 && $stateBatterie >= 20) {
        $hysterese = $hystereseSoll;
        return [ 'action' => 1, 'reason' => "Temperatur zu niedrig, Akku zwischen 20% und $hystereseSoll%, Hysterese startet", ];
    }
    return [ 'action' => null, 'reason' => "Hysterese aktiv, warte auf Akku über $hystereseSoll% ($stateBatterie%)",  ];
}

function getSleepUntilNextInterval(array $heizIntervalle, int $repeat): array
{
    $now = new DateTime();
    $today = $now->format('Y-m-d');
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    $nextStart = null;
    $nextInterval = null;

    foreach ($heizIntervalle as $interval) {
        if (empty($interval['an']) || empty($interval['aus'])) {
            continue;
        }

        $candidate = new DateTime($today . ' ' . $interval['an']);
        if ($candidate > $now && ($nextStart === null || $candidate < $nextStart)) {
            $nextStart = $candidate;
            $nextInterval = $interval;
        }
    }

    if ($nextStart === null) {
        foreach ($heizIntervalle as $interval) {
            if (empty($interval['an']) || empty($interval['aus'])) {
                continue;
            }

            $candidate = new DateTime($tomorrow . ' ' . $interval['an']);
            if ($nextStart === null || $candidate < $nextStart) {
                $nextStart = $candidate;
                $nextInterval = $interval;
            }
        }
    }

    if ($nextStart === null) {
        return [
            'seconds' => max(1, $repeat) * 60,
            'text' => 'kein nächstes Intervall gefunden',
        ];
    }

    return [
        'seconds' => max(1, $nextStart->getTimestamp() - $now->getTimestamp() + 10),
        'text' => $nextStart->format('d.m.Y H:i') . " bis " . $nextInterval['aus'],
    ];
}


if (strtolower((string)($argv[1] ?? '')) === 'modbus-test') {
    $testParamsFile = (string)($argv[2] ?? $paramsFile);
    if (!is_file($testParamsFile)) {
        fwrite(STDERR, "Parameterdatei fuer Modbustest fehlt: $testParamsFile" . PHP_EOL);
        exit(1);
    }

    $testParams = json_decode((string)file_get_contents($testParamsFile), true);
    if (!is_array($testParams)) {
        fwrite(STDERR, "Parameterdatei fuer Modbustest ist ungueltig: $testParamsFile" . PHP_EOL);
        exit(1);
    }

    if (isset($testParams['urlheizStab'])) {
        $urlheizStab = normalizeBaseUrl((string)$testParams['urlheizStab']);
    }
    configureIqBoxModbus($testParams);
    configureHeizstabAuth($testParams);
    configureHeizstabApi($testParams);
    $aktData = getdata();
    $setupData = getsetup();
    $testValues = getLocalRegulationValues();
    if (!is_array($testValues)) {
        fwrite(STDERR, "Lokaler Modbustest fehlgeschlagen." . PHP_EOL);
        exit(1);
    }

    echo json_encode($testValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$iteration = 0;

while (true) { //endlos Schleife wird mit break abgebrochen
  $iteration++;
  //Parameter lesen evtl. Stopp
  if (file_exists($paramsFile)) {
        $params = json_decode(file_get_contents($paramsFile), true);
        if (isset($params['logfile'])&&$params['logfile']!="") {
            $logfile = $params['logfile'] ?? null;
            $logfileHandle = null;
            if ($logfile) {
                $dir = dirname($logfile);
                // nur öffnen, wenn Verzeichnis existiert und beschreibbar ist
                if (is_dir($dir) && is_writable($dir)) {
                    $logfileHandle = @fopen($logfile, 'a');
                }
            } else {
              $logfile="";
              unset($logfileHandle);
            }
        }

        if (isset($params['stop']) && $params['stop'] === true) {
            $logger->Info("Task stopped by parameter.");            echo "Task stopped by parameter.";
            break;
        }
        if (isset($params['urlheizStab'])) {
            $urlheizStab=normalizeBaseUrl((string)$params['urlheizStab']);
        } 
        configureIqBoxModbus($params);
        configureHeizstabAuth($params);
        configureHeizstabApi($params);
        configureHeizstabControl($params);

        if (isset($params['repeat'])) {
            //writeLog("repeat  alle " . $params['repeat'] . "Min");
            $repeat=$params['repeat'];
        }                
        if (isset($params['Heizintervalle'])) {   //  in die Datenbank zu schreibenden werte
            $heizIntervalle = $params['Heizintervalle'];
            //writeLog("heizIntervale gelesen: ");
        }                
        if (isset($params['debug'])) {
            $debug=$params['debug'];
        }                
  } else {
    echo "kein paramsfile $paramsFile\n";
    exit;
  }


  // zuerst überprüfen, ob schon Boostmodus läuft.
  date_default_timezone_set('Europe/Berlin');
  $aktData = getdata();
  $setupData = getsetup();

  $ctrl = getHeizstabdata('ctrl');   // ansteuerungstyp 1 = http 2 = modbusdTCP s. Doku fußnote 1         

  // Lokalen Istzustand in jedem Durchlauf frisch aus data.jsn verwenden.
  // $heizstabDurchRegelungAktiv kennzeichnet nur, wer den Boost gestartet hat;
  // die Variable darf nicht als Nachweis gelten, dass der Heizstab noch laeuft.
  $boostAktiv = getHeizstabdata('boostactive');
  if ($boostAktiv === false) { $logger->Error("!!! Fehler beim Lesen von boostactive"); echo "Fehler beim Lesen von boostactive\n"; goto nextIteration;}
  $boostAktiv = ((int)$boostAktiv !== 0);
  $getMaxPwr = getHeizstabdata('maxpwr'); 
  $getAktPwr=getHeizstabdata('power_elwa2');

  // Die Regelung schaltet ausschliesslich den lokalen Boost. Eine Leistung > 0
  // kann auch von der IQbox angefordert werden und beweist daher nicht, dass
  // unser Boost noch aktiv ist. Fuer die Schaltentscheidung gilt nur der
  // lokale Istzustand boostactive.
  $logger->Info("Lokaler Heizstabstatus boostAktiv=" . ($boostAktiv ? 'ja' : 'nein') . " power_elwa2=" . formatLogValue($getAktPwr) . " durchRegelungAktiv=" . ($heizstabDurchRegelungAktiv ? 'ja' : 'nein'));
  if (!$boostAktiv) {
    $heizstabDurchRegelungAktiv=false;
  }
  $temp1=getHeizstabdata('temp1')/10;
  $temp2=getHeizstabdata('temp2')/10;

  $regulationValues = getLocalRegulationValues();
  $regulationValid = is_array($regulationValues);
  $socValid = $regulationValid;
  if (!$regulationValid) {
    $logger->Error("Lokale Regelungswerte konnten nicht gelesen werden. Dieser Durchlauf wird ohne Schaltaktion beendet.");
    $stateBatterie = 'unbekannt';
    $stateBatterieLog = 'unbekannt';
    $currentWaterTemp = null;
    $getMinTemp = 0.0;
    $wwTemp = '??';
    $temperatureTimestamp = null;
    $sleepTime = max(1, (int)$repeat) * 60;
    goto nextIteration;
  } else {
    $stateBatterie = (int)round($regulationValues['batterySoc']);
    $currentWaterTemp = (float)$regulationValues['temperature'];
    $getMinTemp = (float)$regulationValues['targetTemperature'];
    $wwTemp = $currentWaterTemp;
    $temperatureTimestamp = $regulationValues['temperatureTimestamp'];
  }
  $currentTime = date('d.m.Y H:i:s');
  //$logger->Info("currentTime $currentTime");
  $stateBatterieLog = $socValid ? $stateBatterie . ' %' : 'unbekannt';

  $logger->debugMe("currentTime $currentTime maxPower: $getMaxPwr % aktPwr: $getAktPwr W temp min: $getMinTemp C temp1akt: $temp1 C temp2akt: $temp2 C Lokale Isttemperatur $wwTemp C Lokale Zieltemperatur $getMinTemp C Batterie $stateBatterieLog");   // soweit wird geheizt
  // überprüfen ob die akt. Zeit innerhalb des Intervalls ist
  $pruefeHeizen=0;
  $cTime = date('H:i');    // zur Intervall Prüfung
  $cTimeMin = timeToMinutes($cTime);
  date_default_timezone_set('Europe/Berlin');
  
  foreach ($heizIntervalle as $intervallIndex=>$interval) {
    $intervalAnMin = timeToMinutes($interval['an']);
    $intervalAusMin = timeToMinutes($interval['aus']);
    $isWithinInterval = ($cTimeMin >= $intervalAnMin) && ($cTimeMin <= $intervalAusMin);
    if ($isWithinInterval) {
      $pruefeHeizen=1;
//      $logger->Info("Heizung prüfen im intervall [$intervallIndex] ok an: ".$interval['an']." aus: ".$interval['aus']."");
      break;
    }
  }
  $logger->debugMe("Heizintervall=" . ($pruefeHeizen ? 'ja' : 'nein') . " boostAktiv=" . ($boostAktiv ? 'ja' : 'nein') . " hysterese=$hysterese Batterie=$stateBatterieLog temp1=" . ($currentWaterTemp ?? '??'));

  $decision = decideHeizstabAction($pruefeHeizen > 0, $boostAktiv, $currentWaterTemp, (float)$getMinTemp, $stateBatterie, $hysterese, $hystereseSoll );   // hysterese auch Rueckgabeparameter
  $logger->Info("heizstab Entscheidung: ".$decision['reason']." SOC=$stateBatterieLog hysterese=$hysterese temp1=".($currentWaterTemp ?? '??')." ziel=$getMinTemp");


  if ($decision['action'] === 1) {
    $logger->debugMe("heizstab einschalten: ".$decision['reason']." SOC=$stateBatterieLog hysterese=$hysterese temp=".($currentWaterTemp ?? '??')." ziel=$getMinTemp");
    if (heizen(1)) { $heizstabDurchRegelungAktiv=true; }
  } elseif ($decision['action'] === 0) {
    $logger->Info("heizstab debugMe: ".$decision['reason']." SOC=$stateBatterieLog hysterese=$hysterese temp=".($currentWaterTemp ?? '??')." ziel=$getMinTemp");
    if (heizen(0)) {
      $heizstabDurchRegelungAktiv=false;
    }
  } else {
    $logger->debugMe("heizstab unverändert: ".$decision['reason']." SOC=$stateBatterie hysterese=$hysterese temp=".($currentWaterTemp ?? '??')." ziel=$getMinTemp");
  }

  if ($pruefeHeizen>0 ) { $sleepTime=$repeat*60;  
  } else { // Ende Untersuchung Heizen
    $logger->debugMe("currentTime $currentTime Außerhalb Intervall ");
    if ($boostAktiv && $heizstabDurchRegelungAktiv) {
      $logger->Info("heizstab ausschalten: Intervallende und Heizstab wurde durch Regelung eingeschaltet noch aktiv");
      if (heizen(0)) { $heizstabDurchRegelungAktiv=false;}
    }
    $nextSleep = getSleepUntilNextInterval($heizIntervalle, (int)$repeat);
    $sleepTime = $nextSleep['seconds'];
    $logger->Info("Nächstes Intervall: ".$nextSleep['text']);
  }
  nextIteration:
  $currentDateTime = new DateTime();
  $currentDateTime->add(new DateInterval('PT' . $sleepTime . 'S'));
  $w=$currentDateTime->format('Y-m-d H:i:s');
  $logger->Info(date('d.m.Y H:i:s')." it: $iteration sleep bis: $w Batt $stateBatterieLog hysterese $hysterese\n");
  if (isset($logfileHandle)) fclose($logfileHandle);
  $logfile="";
  unset($logfileHandle);

  sleep($sleepTime); // Warte Repeat Minuten pro Iteration
  
} //ende while

?>

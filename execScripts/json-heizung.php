<?php
// gedacht als endlosschleife die über Parameter aus einer dartei versorgt wird versorgt wird
// check ob geheizt werden soll
// Schreibt die wesentlichen Werte der Smartbox und des Heizstabes in die Datenbank   (derzeit noch nicht)

// start // php json-heizung.php &
// beenden mit ssh ende oder 
// ps aux | grep json-heizung
// kill (erste Zahl aus dem ergebnis

require_once __DIR__ . '/Logger.php';
$debug=true;
$logf="/home/peter/coh/logs/heizstabserver.log";
$logger = new Logger();
$logger->setLogfile ($logf);
$logger->setDebug($debug);
// als Globale Daten verwenden
$urlheizStab='http://192.168.178.46/';
$urlIQbox    = 'http://192.168.178.26';
$paramsFile = '/home/peter/scripts/coh/execScripts/task_heizstab_params.json';   // dateiname der Parameter
$cookieFile = '/home/peter/scripts/coh/cookies/heizung_iqbox_cookie.txt';        // speichern des auth zugriffs auf d
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
$logger->Info("Restart json-heizung Logfile $logf paramsFile $paramsFile");

$logfile="";
$logfileHandle;
$aktData = [];
$setupData = [];
$lastday = 0;      // zuletzt bearbeiteter Tag
$lastMon = 0;      // zuletzt bearbeiteter Monat

$hystereseSoll=40; // wenn heizen eingeschaltet wird, so muss der füllstand des Akkus mindestens
$hysterese=0;      // nach einem einschalten der Heizung wird erst wieder geheizt wenn die Hysterese des Akkus erreicht wird,
$heizstabDurchRegelungAktiv=false; // nur dann am Intervallende automatisch ausschalten
$repeat = 15;      // whileSchleife alle 15 Min
$repeat = 2;      // whileSchleife alle 15 Min

function normalizeBaseUrl(string $value, string $defaultScheme = 'http'): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('~^https?://~i', $value)) {
        $value = $defaultScheme . '://' . $value;
    }

    return rtrim($value, '/') . '/';
}

function buildUrl(string $baseUrl, string $path): string
{
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function buildDefaultCookieFile(string $baseUrl, string $cookieDir, string $prefix): string
{
    $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'unknown';
    $port = parse_url($baseUrl, PHP_URL_PORT);
    $cookieName = $prefix . '_' . sanitizeCookieName($host . ($port ? '_' . $port : '')) . '_cookie.txt';

    return rtrim($cookieDir, '/') . '/' . $cookieName;
}

function sanitizeCookieName(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value) ?? 'unknown';
    return trim($value, '_') ?: 'unknown';
}

function configureHeizstabAuth(array $params): void
{
    global $urlheizStab, $heizstabAuth, $heizstabCookieDir, $heizstabCookieFile;

    if (!isset($params['heizstabAuth']) || !is_array($params['heizstabAuth'])) {
        $heizstabAuth['enabled'] = false;
        return;
    }

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
    $heizstabCookieFile = !empty($cfg['cookieFile'])
        ? (string)$cfg['cookieFile']
        : buildDefaultCookieFile($urlheizStab, $heizstabCookieDir, 'heizstab');
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

function isHeizstabApiEnabled(): bool
{
    global $heizstabApi;

    return !empty($heizstabApi['enabled']);
}

function getHeizstabApiToken(): string
{
    global $heizstabApi;

    if ($heizstabApi['apiToken'] !== '') {
        return $heizstabApi['apiToken'];
    }

    $envToken = getenv($heizstabApi['apiTokenEnv']);
    return is_string($envToken) ? $envToken : '';
}

function buildHeizstabApiUrl(string $endpoint): string
{
    global $heizstabApi;

    return rtrim($heizstabApi['baseUrl'], '/') . '/device/' . rawurlencode($heizstabApi['serial']) . '/' . ltrim($endpoint, '/');
}

function heizstabApiRequest(string $method, string $endpoint, ?array $payload = null)
{
    global $heizstabApi, $logger;

    $token = getHeizstabApiToken();
    if ($heizstabApi['serial'] === '' || $token === '') {
        $logger->Error('my-PV API aktiv, aber serial oder apiToken fehlt');
        return false;
    }

    $url = buildHeizstabApiUrl($endpoint);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if (!empty($heizstabApi['insecureTls'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? [], JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $logger->Error('my-PV API cURL Fehler: ' . curl_error($ch) . " URL: $url");
        curl_close($ch);
        return false;
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $logger->Error("my-PV API HTTP Fehler [$code] URL: $url Antwort: " . trim((string)$response));
        return false;
    }

    return (string)$response;
}

function heizstabApiGetJson(string $endpoint)
{
    global $logger;

    $response = heizstabApiRequest('GET', $endpoint);
    if ($response === false) {
        return false;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        $logger->Error("my-PV API Antwort ist kein JSON-Array Endpoint: $endpoint Antwort: " . trim($response));
        return false;
    }

    return $data;
}

function heizstabApiSetPower(int $power): bool
{
    global $heizstabApi, $logger;

    $payload = [
        'power'                 => max(0, $power),
        'validForMinutes'       => $heizstabApi['validForMinutes'],
        'timeBoostOverride'     => $heizstabApi['timeBoostOverride'],
        'timeBoostValue'        => $heizstabApi['timeBoostValue'],
        'legionellaBoostBlock'  => $heizstabApi['legionellaBoostBlock'],
    ];

    $response = heizstabApiRequest('POST', $heizstabApi['powerEndpoint'], $payload);
    if ($response === false) {
        return false;
    }

    $logger->Info('my-PV API power gesetzt: ' . $payload['power'] . 'W fuer ' . $payload['validForMinutes'] . ' Minuten');
    return true;
}

function isHeizstabUrl(string $url): bool
{
    global $urlheizStab;

    return str_starts_with($url, rtrim($urlheizStab, '/') . '/');
}

/*
 * macht auth login für Heizstab
 */

function heizstabLogin(): bool
{
    global $urlheizStab, $heizstabAuth, $heizstabCookieFile, $logger;

    if (empty($heizstabAuth['enabled'])) {
        return true;
    }

    if ($heizstabAuth['password'] === '') {
        $logger->Error("Heizstab Login aktiv, aber password fehlt");
        return false;
    }

    $dir = dirname($heizstabCookieFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

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

    if (!in_array($code, [200, 204, 302, 303], true)) {
        $logger->Error("Heizstab Login HTTP Fehler [$code] URL: $loginUrl");
        return false;
    }

    return true;
}

function ampereLogin(string $urlIQbox): void
{
    global $cookieFile,$logger;

    $dir = dirname($cookieFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

// Altes Cookie löschen (WICHTIG)
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
    $username = "installer";
    $password = "sfjimorx"; 
    //writeLog ("ampereLogin urlIQbox $urlIQbox cookieFile $cookieFile");
    $ch = curl_init($urlIQbox . "/auth/login");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            "username" => $username,
            "password" => $password
        ]),
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $logger->Error ("response ampereLogin false urlIQbox $urlIQbox cookieFile $cookieFile");
        curl_close($ch);
        throw new RuntimeException("Login failed: " . curl_error($ch));
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($code, [200, 302, 303], true)) {
        $logger->Error  ("ampereLogin Login HTTP error $code");

        throw new RuntimeException("Login HTTP error $code");
    }
    //writeLog ("ampereLogin ok");
}
// base url enthält die IQBox/OpenHAB Basis-URL; gelesen wird nur der Item-State als Text.
function ampereRequest(string $baseUrl, string $path, bool $retry): array
{
    global $urlIQbox,$cookieFile,$logger;

    $chUrl=$baseUrl . '/rest/items/' . rawurlencode($path) . '/state';
    //writeLog ("ampereRequest  chUrl $chUrl cookieFile $cookieFile");
    $ch = curl_init($chUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Accept: text/plain']
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $logger->Error  ("ampereRequest  response false chUrl $chUrl");
        curl_close($ch);
        throw new RuntimeException("cURL error: " . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Session ungültig → EINMAL neu einloggen
    if (in_array($code, [301, 302, 303, 401, 403, 404], true)) {
        $logger->Error  ("ampereRequest  Session ungültig EINMAL neu einloggen chUrl $chUrl code $code");
        if ($retry) {
            $logger->Error("ampereRequest Session ungültig zweimal falsch Exception chUrl $chUrl code $code");
            throw new RuntimeException("Auth failed after retry (HTTP $code)");
        }
        ampereLogin($baseUrl);
        return ampereRequest($baseUrl, $path, true);
    }
    if ($code !== 200) {
        $logger->Error("ampereRequest code nicht 200 Exception chUrl $chUrl code $code response " . trim((string)$response));
        throw new RuntimeException("HTTP error $code");
    } 
    return [
        "ok"        => true,
        "http_code" => 200,
        "data"      => $response
    ];
}
// liefert die jsondaten der iq Box. macht evtl eine reauth
function ampereGet(string $baseUrl, string $path): array
{
    global $logger;
    //writeLog ("ampereGet  baseUrl $baseUrl path $path");
    try {
        $arr=ampereRequest($baseUrl, $path, false);
        //writeLog (" request ok");
        return $arr;
    } catch (Throwable $e) {
        $logger->Error (" ampereGet request failed baseUrl $baseUrl path $path " . $e->getMessage());
        return [
            "ok"        => false,
            "error"     => true,
            "http_code" => 500,
            "message"   => $e->getMessage()
        ];
    }
}
    
    

// liest die data.jsn vom Heizstab und gibt sie als Array zurück
// liefert False bei einem Fehler
function getdata() {
    global $urlheizStab,$logger;
    if (isHeizstabApiEnabled()) {
      global $heizstabApi;
      for ($i = 1; $i <= 10; $i++) {
        $data = heizstabApiGetJson($heizstabApi['dataEndpoint']);
        if ($data !== false) {
          return $data;
        }
        sleep(10);
      }
      $logger->Error("!!! Fehler nach 10 maligen my-PV API Aufruf data");
      return false;
    }

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
    if (isHeizstabApiEnabled()) {
      global $heizstabApi;
      for ($i = 1; $i <= 10; $i++) {
        $data = heizstabApiGetJson($heizstabApi['setupEndpoint']);
        if ($data !== false) {
          return $data;
        }
        sleep(10);
      }
      $logger->Error("!!! Fehler nach 10 maligen my-PV API Aufruf setup");
      return false;
    }

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
function getHeizstabdata ($data) {
  global $aktData,$setupData,$logger;
  if (isset($aktData[$data]) )  {  return $aktData[$data];}    
  else if (isset($setupData[$data]) )  {  return $setupData[$data];}    

  $aliases = [
    'boostactive' => ['bststrt'],
    'ctrl'        => ['ctrlstate'],
    'maxpwr'      => ['power_nominal'],
    'power_elwa2' => ['power', 'power_act', 'power_actual'],
  ];

  foreach ($aliases[$data] ?? [] as $alias) {
    if (isset($aktData[$alias])) { return $aktData[$alias]; }
    if (isset($setupData[$alias])) { return $setupData[$alias]; }
  }

  return 0;
}
/*
 * liest einen Status von der IQbox
 * der name ist der Name aus dem Link
 *
 */
function getfromIQbox ($path) {
  global $urlIQbox,$logger;
    $result = ampereGet($urlIQbox,$path);
    if ($result['ok']) {
      return trim((string)$result['data']);
    } else {
      $logger->Error("!!! Fehler beim Abrufen der Daten von: $urlIQbox var: $path");
      return false;
    }
}

// CURL-Request Funktion, um Redundanz zu vermeiden
function curlRequest($url, bool $retryAfterLogin = false)
{
    global $logger, $heizstabAuth, $heizstabCookieFile;

    $isHeizstab = isHeizstabUrl($url);
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
        $logger->Error(
            "!!! cURL Fehler [$errno]: $error URL: $url"
        );
        curl_close($ch);
        return false;
    }
    // HTTP Status prüfen
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $logger->debugMe("curlRequest nach exec code $httpCode");
    if ($isHeizstab && !empty($heizstabAuth['enabled']) && in_array($httpCode, [301, 302, 303, 401, 403], true)) {
        $logger->Error("!!! Heizstab Session ungültig [$httpCode] URL: $url");
        curl_close($ch);

        if ($retryAfterLogin) {
            $logger->Error("!!! Heizstab Login Retry fehlgeschlagen URL: $url retryAfterLogin $retryAfterLogin");
            return false;
        }

        @unlink($heizstabCookieFile);
        if (!heizstabLogin()) {
            return false;
        }

        return curlRequest($url, true);
    }

    if ($httpCode >= 400) {
        $logger->Error(
            "!!! cURL HTTP Fehler [$httpCode] URL: $url"
        );
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
  // steuerungseinstellung bestimmen wie
  // aktuell wird der Boost für Warmwassereinstellung verwendet
  //setup.jsn?bstmode=0
  global $urlheizStab,$ctrl,$logger,$heizstabApi;
  if (isHeizstabApiEnabled()) {
    $power = $modus > 0 ? (int)$heizstabApi['powerOn'] : 0;
    if ($modus > 0 && $power <= 0) {
        $power = 3000;
    }

    $logger->Info("Heizen Modus Heizstab $modus ueber my-PV API power=$power");
    return heizstabApiSetPower($power);
  }

  $steuerungseinstellung=$ctrl;    
  $url1 = $url2 = "";
  if ($steuerungseinstellung==1) {           // 1 http 2 ModbusTCP
    $logger->Info("Heizen Modus Heizstab $modus Protokoll $steuerungseinstellung bei http nichts tun");
    return false;
  } else if ($steuerungseinstellung==2) {           // modbustcp
    if ($modus > 0) {
      $url1=$urlheizStab.'setup.jsn?bstmode=1';
      $url2=$urlheizStab.'data.jsn?bststrt=1';
    } else {
      $url1=$urlheizStab.'setup.jsn?bstmode=0';
      $url2=$urlheizStab.'data.jsn?bststrt=0';
    }
  } else {
    $logger->Error("Heizen Fehler Modus Heizstab ctrl $steuerungseinstellung ");
    return false;
  }
  $logger->Info("Heizen Modus Heizstab $modus ");
  $logger->debugMe("Heizen Modus Heizstab ctrl $steuerungseinstellung url1: $url1 url2: $url2");
  //$response=@file_get_contents($urlheizStab.'data.jsn?bststrt=1');
  if ($url1 && $url2) {
        $response1 = curlRequest($url1);
        sleep(1); // Warte 1 sec
        $response2 = curlRequest($url2);
        if ($response1 === false || $response2 === false) {
            $logger->Error("!!! Fehler beim Heizen-Steuerungsbefehl: $modus");
            return false;
        }
  }
  return true;
}

// funktionen zur normierung des Status
function elwaPwrkWh($stat) {   // Power akt Heizstab
  $resArr['wert'] = round($stat/1000,2);
  $resArr['einheit']='kWh';
  return $resArr;
}
function elwaPwr($stat) {   // max Power in %
  $resArr['wert'] = $stat;
  $resArr['einheit']='%';
  return $resArr;
}
function elwaTemp($stat) {   // Power akt Heizstab
  $resArr['wert'] = round($stat/10,2);
  $resArr['einheit']='°C';
  return $resArr;
}

function elwaProt($stat) {   // Power akt Heizstab
  $resArr['wert'] = $stat;
  switch ($stat) {
    case 0: case 0: $v='Auto Detec';break;
    case 1: $v='HTTP';break; 
    case 2: $v='Modbus TCP';break; 
    case 3: $v='Fronius Auto';break; 
    case 4: $v='Fronius Manual';break; 
    case 5: $v='SMA Home Manager';break; 
    case 6: $v='Steca Auto';break; 
    case 7: $v='Varta Auto';break; 
    case 8: $v='Varta Manual';break; 
    case 12: $v='my-PV Meter Auto';break; 
    case 12: $v='my-PV Meter Manual';break; 
    case 14: $v='my-PV Power Meter Direct';break; 
    case 10: $v='RCT Power Manual';break; 
    case 15: $v='SMA Direct meter communication Auto';break; 
    case 16: $v='SMA Direct meter communication Manual';break; 
    case 19: $v='Digital Meter P1';break; 
    case 20: $v='Frequency';break; 
    case 100: $v='Fronius Sunspec Manual';break; 
    case 102: $v='Kostal PIKO IQ Plenticore plus Manual';break; 
    case 103: $v='Kostal Smart Energy Meter Manual';break; 
    case 104: $v='MEC electronics Manual';break; 
    case 105: $v='SolarEdge Manual';break; 
    case 106: $v='Victron Energy 1ph Manual';break; 
    case 107: $v='Victron Energy 3ph Manual';break; 
    case 108: $v='Huawei (Modbus TCP) Manual';break; 
    case 109: $v='Carlo Gavazzi EM24 Manual';break; 
    case 111: $v='Sungrow Manual';break; 
    case 112: $v='Fronius Gen24 Manual';break; 
    case 200: $v='Huawei (Modbus RTU)';break;   
    case 201: $v='Growatt (Modbus RTU)';break; 
    case 202: $v='Solax (Modbus RTU)';break; 
    case 203: $v='Qcells (Modbus RTU)';break; 
    case 204: $v='IME Conto D4 Modbus MID (Modbus RTU)';break; 
    default: $v='Protokoll undefinioert';break;
  }
  $resArr['einheit']=$v;
  return $resArr;
}
function IQSOC($stat) {   // Füllstand Betterie
  $statearr = explode(" ", $stat);
  $resArr['wert'] = $statearr[0];
  $resArr['einheit']='%';
  return $resArr;
}  

function IQkWh($stat) {   // Angabe kWh Wh, Ws
  $statearr = explode(" ", $stat);
  $v=strtolower($statearr[1]);
  if ($v == 'ws') {$value=round($statearr[0]/3600000,2);}
  elseif ($v == 'wh') {$value=round($statearr[0]/1000,2);}
  else $value=$statearr[0];
  $resArr['wert'] = $value;
  $resArr['einheit']='kWh';
  return $resArr;
}  
function IQkW($stat) {   // Angabe kW W
  $resArr=[];
  $valarr = explode("|",$stat);   // sieht der state so aus "1714050990000|4.0 W" dann ist das vor | die Uhrzeit
  if (count($valarr) > 1) {           // mit zeitangabe
    // liefere den zeitpunkt der messung in sec
    $unixzeit_ms=$valarr[0];
    $unixzeit_sec=$unixzeit_ms/1000;    // Umwandeln in Sekunden (durch 1000 teilen, da die Unixzeit in Millisekunden gegeben ist)
    $resArr['unixtime'] = $unixzeit_sec;
    $strWert=$valarr[1];              
  } else $strWert=$stat;

  $statearr = explode(" ", $strWert);
  $v=strtolower($statearr[1]);
  if ($v == 'w') {$value=round($statearr[0]/1000,2);}
  else $value=$statearr[0];
  $resArr['wert'] = $value;
  $resArr['einheit']='kW';
  return $resArr;
} 
 
function IQTemp($stat) {   // Temp z.b Batterie
  $statearr = explode(" ", $stat);
  $resArr['wert'] = $statearr[0];
  $resArr['einheit']='°C';
  return $resArr;
}
function writeLog($txt) {
  global $logger;
  $logger->debugMe($txt);
}

function getCurrentWaterTemp($wwTemp, float $temp1): ?float
{
    if (is_numeric($wwTemp)) {
        return (float)$wwTemp;
    }

    return is_numeric($temp1) ? (float)$temp1 : null;
}

function normalizeTemperatureValue($value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    $temperature = (float)$value;
    if ($temperature <= 0) {
        return null;
    }

    return $temperature > 100 ? $temperature / 10 : $temperature;
}

function getTargetWaterTemp(): float
{
    global $aktData, $setupData, $heizstabApi;

    foreach (['ww1target', 'ww1boost'] as $field) {
        if (array_key_exists($field, $aktData)) {
            $temperature = normalizeTemperatureValue($aktData[$field]);
            if ($temperature !== null) {
                return $temperature;
            }
        }

        if (array_key_exists($field, $setupData)) {
            $temperature = normalizeTemperatureValue($setupData[$field]);
            if ($temperature !== null) {
                return $temperature;
            }
        }
    }

    return (float)$heizstabApi['targetWaterTemp'];
}

function decideHeizstabAction(
    bool $isWithinInterval,
    bool $isHeating,
    ?float $currentTemp,
    float $targetTemp,
    int $stateBatterie,
    int &$hysterese,
    int $hystereseSoll
): array {
    if ($stateBatterie > $hystereseSoll) {
        $hysterese = 0;
    }

    if (!$isWithinInterval) {
        return [
            'action' => null,
            'reason' => 'außerhalb Intervall, Regelung pausiert',
        ];
    }

    if ($currentTemp === null) {
        return [
            'action' => null,
            'reason' => 'keine gültige Temperatur',
        ];
    }

    if ($currentTemp >= $targetTemp) {
        return [
            'action' => $isHeating ? 0 : null,
            'reason' => "Temperatur erreicht ($currentTemp >= $targetTemp)",
        ];
    }

    if ($stateBatterie < 20) {
        $hysterese = $hystereseSoll;
        return [
            'action' => $isHeating ? 0 : null,
            'reason' => "Akku unter 20% ($stateBatterie%)",
        ];
    }

    if ($isHeating) {
        return [
            'action' => null,
            'reason' => "heizt weiter, Temperatur zu niedrig ($currentTemp < $targetTemp)",
        ];
    }

    if ($stateBatterie > $hystereseSoll) {
        return [
            'action' => 1,
            'reason' => "Temperatur zu niedrig und Akku über $hystereseSoll% ($stateBatterie%)",
        ];
    }

    if ($hysterese === 0 && $stateBatterie >= 20) {
        $hysterese = $hystereseSoll;
        return [
            'action' => 1,
            'reason' => "Temperatur zu niedrig, Akku zwischen 20% und $hystereseSoll%, Hysterese startet",
        ];
    }

    return [
        'action' => null,
        'reason' => "Hysterese aktiv, warte auf Akku über $hystereseSoll% ($stateBatterie%)",
    ];
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
        if (isset($params['urlIQbox'])) {
            $urlIQbox=rtrim(normalizeBaseUrl((string)$params['urlIQbox']), '/');
        }                
        configureHeizstabAuth($params);
        configureHeizstabApi($params);

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

  $Booststat = getHeizstabdata('boostactive');  // musss evtl noch korrigiert werden, wenn http modus eingestellt ist
  if ($Booststat === false) { $logger->Error("!!! Fehler lesen Heizstab Booststat false"); echo "Fehler lesen Heizstab Booststat false\n"; goto nextIteration;}                                    
  $getMaxPwr = getHeizstabdata('maxpwr'); 
  $getAktPwr=getHeizstabdata('power_elwa2');
  $isHeating = ($Booststat != 0) || ((float)$getAktPwr > 0) || $heizstabDurchRegelungAktiv;
  if (!$isHeating) {
    $heizstabDurchRegelungAktiv=false;
  }
  $getMinTemp=getTargetWaterTemp();
  $temp1=getHeizstabdata('temp1')/10;
  $temp2=getHeizstabdata('temp2')/10;
  // Wassertemperatur aus dem Heizstab verwenden.
  // Das IQBox-Item mypv_acelwa_...actualTemperature liefert bei einigen Anlagen HTTP 400/asBigDecimal.
  $wwTemp="??";
  $currentWaterTemp = getCurrentWaterTemp($wwTemp, (float)$temp1);
  $SOCArr=explode(" ",getfromIQbox("sajhybrid_battery_94_HSR2103J2311E08738_battery_stateOfCharge")); // Abrufen des Inhalts der SOC  Batterie Füllstand   
  $stateBatterie = intval( $SOCArr[0]); // Fuellstand Batterie als int prozentwert
  $currentTime = date('d.m.Y H:i:s');
  //$logger->Info("currentTime $currentTime");

  $logger->debugMe("currentTime $currentTime maxPower: $getMaxPwr % aktPwr: $getAktPwr W temp min: $getMinTemp C temp1akt: $temp1 C temp2akt: $temp2 C temp von IQBox $wwTemp C  Batterie $stateBatterie %");   // soweit wird geheizt     
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
  $logger->debugMe("Intervall $pruefeHeizen Booststat $Booststat hysterese $hysterese Batterie $stateBatterie currentWaterTemp ".($currentWaterTemp ?? '??'));

  $decision = decideHeizstabAction(
      $pruefeHeizen > 0,
      $isHeating,
      $currentWaterTemp,
      (float)$getMinTemp,
      $stateBatterie,
      $hysterese,
      $hystereseSoll
  );

  if ($decision['action'] === 1) {
    $logger->Info("heizstab einschalten: ".$decision['reason']." SOC=$stateBatterie hysterese=$hysterese temp=".($currentWaterTemp ?? '??')." ziel=$getMinTemp");
    if (heizen(1)) {
      $heizstabDurchRegelungAktiv=true;
    }
  } elseif ($decision['action'] === 0) {
    $logger->Info("heizstab ausschalten: ".$decision['reason']." SOC=$stateBatterie hysterese=$hysterese temp=".($currentWaterTemp ?? '??')." ziel=$getMinTemp");
    if (heizen(0)) {
      $heizstabDurchRegelungAktiv=false;
    }
  } else {
    $logger->debugMe("heizstab unverändert: ".$decision['reason']." SOC=$stateBatterie hysterese=$hysterese temp=".($currentWaterTemp ?? '??')." ziel=$getMinTemp");
  }

  if ($pruefeHeizen>0 ) {
    $sleepTime=$repeat*60;  
  } else { // Ende Untersuchung Heizen
    $logger->debugMe("currentTime $currentTime Außerhalb Intervall ");
    if ($isHeating && $heizstabDurchRegelungAktiv) {
      $logger->Info("heizstab ausschalten: Intervallende und Heizstab wurde durch Regelung eingeschaltet");
      if (heizen(0)) {
        $heizstabDurchRegelungAktiv=false;
      }
    }
    $nextSleep = getSleepUntilNextInterval($heizIntervalle, (int)$repeat);
    $sleepTime = $nextSleep['seconds'];
    $logger->Info("Nächstes Intervall: ".$nextSleep['text']);
  }
  nextIteration:
  $currentDateTime = new DateTime();
  $currentDateTime->add(new DateInterval('PT' . $sleepTime . 'S'));
  $w=$currentDateTime->format('Y-m-d H:i:s');
  $logger->Info(date('d.m.Y H:i:s')." it: $iteration sleep bis: $w Batt $stateBatterie hysterese $hysterese\n");
  if (isset($logfileHandle)) fclose($logfileHandle);
  $logfile="";
  unset($logfileHandle);

  sleep($sleepTime); // Warte Repeat Minuten pro Iteration
  
} //ende while

?>

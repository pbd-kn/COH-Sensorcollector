<?php

// Interaktiver Leser fuer dieselbe EKD-Cloud-API wie die Ampere.IQ-App.
// Zugangsdaten und automatisch erneuerte Tokens liegen gemeinsam in
// execScripts/task_solar_params.json. Bei ungueltigen Tokens wird der
// Auth0-Weblogin einmal automatisch wiederholt.

declare(strict_types=1);

const AUTH0_BASE_URL = 'https://ekd-solar-prod.eu.auth0.com';
const AUTH0_CLIENT_ID = 'ZmEv1aRHlnkhRBHGzdXpig91kkZhNghK';
const AUTH0_AUDIENCE = 'de.ekd.iot-cloud.backend';
const AUTH0_SCOPE = 'openid email profile offline_access';
const AUTH0_REDIRECT_URI = 'de.ekdsolar.customerapp://ekd-solar-prod.eu.auth0.com/android/de.ekdsolar.customerapp/callback';
const API_BASE_URL = 'https://product.ekd-iot.de';
const APP_VERSION = '1.14.4';

date_default_timezone_set('Europe/Berlin');

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
$command = strtolower(trim((string)($argv[1] ?? 'loop')));
$tokenFile = getTokenFile();

try {
    migrateLegacyTokens($tokenFile);
    if ($command === 'logout' || $command === 'reset') {
        clearTokens($tokenFile);
        echo "Gespeicherte Ampere.IQ-Anmeldung geloescht." . PHP_EOL;
        exit(0);
    }

    if ($command === 'password-login') {
        passwordLogin($tokenFile, getLoginFile((string)($argv[2] ?? '')));
        $command = 'loop';
    }

    $context = createCloudContext($tokenFile, $command === 'login');
    $accessToken = $context['accessToken'];
    $installationId = $context['installationId'];

    if ($command === 'loop') {
        runCloudLoop($context);
        exit(0);
    }

    if ($command === 'discover' || $command === 'endpoints') {
        discoverReadEndpoints($installationId, $accessToken);
        exit(0);
    }

    if ($command === 'soc-history') {
        $path = '/api/v1/installation/' . rawurlencode($installationId) . '/history/stateOfCharge'
            . queryString(['date' => date(DATE_ATOM), 'period' => 'day', 'resolution' => '15m']);
        echo json_encode(apiGet($path, $accessToken), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $power = apiGet('/api/v1/installation/' . rawurlencode($installationId) . '/now/all/power', $accessToken);
    if (!array_key_exists('batterySoc', $power)) {
        echo json_encode($power, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        throw new RuntimeException('Das Feld batterySoc fehlt in der Live-Daten-Antwort.');
    }

    $result = [
        'installationId' => $installationId,
        'batterySoc' => $power['batterySoc'],
        'batteryPower' => $power['batteryPower'] ?? null,
        'pvPower' => $power['pvPower'] ?? null,
        'housePower' => $power['housePower'] ?? null,
        'gridPower' => $power['gridPower'] ?? null,
        'readAt' => date(DATE_ATOM),
    ];

    echo 'Akkufuellstand: ' . formatPercent($power['batterySoc']) . PHP_EOL;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
}

function createCloudContext(string $tokenFile, bool $forceBrowserLogin = false): array
{
    if ($forceBrowserLogin) {
        $tokens = interactiveLogin($tokenFile);
    } else {
        try {
            return createCloudContextAttempt($tokenFile, loadTokens($tokenFile));
        } catch (Throwable $e) {
            if (!isAuthenticationFailure($e)) {
                throw $e;
            }

            echo 'WARNUNG: Ampere.IQ-Anmeldung ist nicht mehr gueltig. Automatischer Login wird versucht.'
                . PHP_EOL;
            $tokens = passwordLogin($tokenFile, $tokenFile);
        }
    }

    return createCloudContextAttempt($tokenFile, $tokens);
}

function createCloudContextAttempt(string $tokenFile, ?array $tokens): array
{
    if ($tokens === null) {
        throw new RuntimeException('Keine Ampere.IQ-Tokens gespeichert. Automatischer Login erforderlich.');
    }

    $tokens = ensureAccessToken($tokens, $tokenFile);
    $accessToken = (string)($tokens['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Die Anmeldung lieferte keinen Access-Token.');
    }

    $installations = apiGet('/api/v1/installation', $accessToken);
    if (!array_is_list($installations) || $installations === []) {
        throw new RuntimeException('Im Ampere.IQ-Konto wurde keine Installation gefunden.');
    }

    $installation = $installations[0];
    $installationId = is_array($installation) ? (string)($installation['id'] ?? '') : '';
    if ($installationId === '') {
        throw new RuntimeException('Die Installations-ID fehlt in der Cloud-Antwort.');
    }

    return [
        'tokenFile' => $tokenFile,
        'tokens' => $tokens,
        'accessToken' => $accessToken,
        'installationId' => $installationId,
        'installations' => $installations,
    ];
}

function getTokenFile(): string
{
    $configured = trim((string)(getenv('AMPERE_IQ_TOKEN_FILE') ?: ''));
    if ($configured !== '') {
        return $configured;
    }

    return getLoginFile();
}

function getLoginFile(string $argument = ''): string
{
    $argument = trim($argument);
    if ($argument !== '') {
        return $argument;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . 'task_solar_params.json';
}

function passwordLogin(string $tokenFile, string $loginFile): array
{
    $credentials = loadLoginParameters($loginFile);
    $username = $credentials['username'];
    $password = $credentials['password'];

    $authorization = createAuthorizationRequest();
    $cookieFile = tempnam(sys_get_temp_dir(), 'ampere-auth-');
    if ($cookieFile === false) {
        throw new RuntimeException('Temporäre Cookie-Datei konnte nicht angelegt werden.');
    }

    try {
        $loginUrl = followAuth0ToHtmlPage($authorization['url'], $cookieFile);
        if (!str_contains($loginUrl, '/u/login?')) {
            throw new RuntimeException("Unerwartete Auth0-Anmeldeseite: $loginUrl");
        }

        $response = auth0HttpRequest($loginUrl, $cookieFile, [
            'state' => getQueryParameter($loginUrl, 'state'),
            'username' => $username,
            'password' => $password,
            'action' => 'default',
        ]);
        $callbackUrl = followAuth0ResponseToCallback($response, $cookieFile);
        return exchangeAuthorizationCallback(
            $callbackUrl,
            $authorization['verifier'],
            $authorization['state'],
            $tokenFile
        );
    } finally {
        @unlink($cookieFile);
    }
}

function loadLoginParameters(string $loginFile): array
{
    if (!is_file($loginFile)) {
        $directory = dirname($loginFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Login-Verzeichnis konnte nicht angelegt werden: $directory");
        }

        $template = [
            'ampereIq' => [
                'username' => 'ihre-mail@example.de',
                'password' => 'ihr-passwort',
            ],
        ];
        $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($loginFile, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Login-Vorlage konnte nicht angelegt werden: $loginFile");
        }
        @chmod($loginFile, 0600);
        throw new RuntimeException(
            "Login-Parameterdatei wurde angelegt. Bitte Zugangsdaten eintragen und erneut starten: $loginFile"
        );
    }

    $decoded = json_decode((string)file_get_contents($loginFile), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Ungueltige JSON-Parameterdatei: $loginFile");
    }

    $ampereIq = is_array($decoded['ampereIq'] ?? null) ? $decoded['ampereIq'] : $decoded;
    $username = trim((string)($ampereIq['username'] ?? ''));
    $password = (string)($ampereIq['password'] ?? '');
    if ($username === '' || $password === ''
        || $username === 'ihre-mail@example.de' || $password === 'ihr-passwort') {
        throw new RuntimeException("Bitte gueltige Zugangsdaten in $loginFile eintragen.");
    }

    @chmod($loginFile, 0600);
    return ['username' => $username, 'password' => $password];
}

function runCloudLoop(array $context): void
{
    echo 'Ampere.IQ Cloud: ' . API_BASE_URL . PHP_EOL;
    echo 'Installation: ' . $context['installationId'] . PHP_EOL;

    $rawData = loadCloudSnapshot($context['installationId'], $context['accessToken']);
    $items = flattenCloudData($rawData);
    echo 'Cloud-Werte geladen: ' . count($items) . PHP_EOL;
    printCloudHelp();

    while (true) {
        echo PHP_EOL . 'Filter> ';
        $line = fgets(STDIN);
        if ($line === false) {
            echo PHP_EOL;
            break;
        }

        $filter = trim($line);
        $lower = strtolower($filter);
        if (in_array($lower, ['q', 'quit', 'exit'], true)) {
            break;
        }
        if (in_array($lower, ['?', 'h', 'help', 'hilfe'], true)) {
            printCloudHelp();
            continue;
        }
        try {
            if (handleHeatingRodCommand($filter, $context['installationId'], $context['accessToken'])) {
                continue;
            }
        } catch (Throwable $e) {
            echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            continue;
        }
        if (in_array($lower, ['r', 'reload'], true)) {
            $context = createCloudContext($context['tokenFile']);
            $rawData = loadCloudSnapshot($context['installationId'], $context['accessToken']);
            $items = flattenCloudData($rawData);
            echo 'Cloud-Werte neu geladen: ' . count($items) . PHP_EOL;
            continue;
        }
        if (in_array($lower, ['login', 'relogin'], true)) {
            $context = createCloudContext($context['tokenFile'], true);
            $rawData = loadCloudSnapshot($context['installationId'], $context['accessToken']);
            $items = flattenCloudData($rawData);
            echo 'Login und Laden erledigt.' . PHP_EOL;
            continue;
        }
        if ($lower === 'password-login') {
            passwordLogin($context['tokenFile'], getLoginFile());
            $context = createCloudContext($context['tokenFile']);
            $rawData = loadCloudSnapshot($context['installationId'], $context['accessToken']);
            $items = flattenCloudData($rawData);
            echo 'Automatischer Login und Laden erledigt.' . PHP_EOL;
            continue;
        }
        if ($lower === 'soc') {
            printJson(loadSocHistory($context['installationId'], $context['accessToken']));
            continue;
        }
        if (in_array($lower, ['discover', 'endpoints', 'apis'], true)) {
            discoverReadEndpoints($context['installationId'], $context['accessToken']);
            continue;
        }
        try {
            $historyPath = resolveHistoryShortcut($filter, $context['installationId']);
        } catch (Throwable $e) {
            echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            continue;
        }
        if ($historyPath !== null) {
            try {
                $historyData = apiGet($historyPath['path'], $context['accessToken']);
                if ($historyPath['section'] !== null) {
                    $section = $historyPath['section'];
                    $historyData = [$section => $historyData[$section] ?? null];
                }
                printApiResponse($historyPath['path'], $historyData);
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }
        if (in_array($lower, ['flow', 'energiefluss'], true)) {
            $path = '/api/v1/installation/' . rawurlencode($context['installationId']) . '/now/all/power';
            printEnergyFlow(apiGet($path, $context['accessToken']));
            continue;
        }
        if ($lower === 'api') {
            printApiShortcutHelp();
            continue;
        }

        $apiPath = resolveApiShortcut($filter, $context['installationId']);
        if ($apiPath !== null) {
            try {
                printApiResponse($apiPath, apiGet($apiPath, $context['accessToken']));
            } catch (Throwable $e) {
                echo 'WARNUNG: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }
        if (in_array($lower, ['raw', 'json'], true)) {
            $file = writeCloudRawJson($rawData);
            echo "Raw JSON gespeichert: $file" . PHP_EOL;
            continue;
        }

        printCloudItems($items, $filter);
    }
}

function loadCloudSnapshot(string $installationId, string $accessToken): array
{
    $id = rawurlencode($installationId);
    $dayQuery = queryString(['period' => 'day', 'date' => date('Y-m-d')]);
    $paths = [
        'live' => "/api/v1/installation/$id/now/all/power",
        'today.work' => "/api/v1/installation/$id/total/common/work$dayQuery",
        'today.selfSufficiency' => "/api/v1/installation/$id/total/selfSufficiency$dayQuery",
        'today.selfConsumption' => "/api/v1/installation/$id/total/selfConsumption$dayQuery",
        'today.saving' => "/api/v2/installation/$id/saving$dayQuery",
        'settings.battery' => "/api/v1/installation/$id/hems/setting/battery",
        'settings.emergencyPower' => "/api/v1/installation/$id/hems_setting/emergency_power",
        'settings.energyTariff' => "/api/v1/installation/$id/hems/energyTariff",
        'settings.gridFeedCompensation' => "/api/v1/installation/$id/hems/gridFeedCompensationPrice",
        'devices' => "/api/v1/installation/$id/hems/device",
        'electricVehicles' => "/api/v1/installation/$id/hems/ev",
    ];

    $result = ['installationId' => $installationId, 'readAt' => date(DATE_ATOM)];
    foreach ($paths as $name => $path) {
        try {
            setNestedValue($result, explode('.', $name), apiGet($path, $accessToken));
        } catch (Throwable $e) {
            $result['_errors'][$name] = $e->getMessage();
        }
    }

    // The device list contains specification names, while current values are
    // returned by the detail endpoint for each installation device.
    foreach (($result['devices'] ?? []) as $index => $device) {
        if (!is_array($device)) {
            continue;
        }

        $deviceUuid = firstStringField($device, [
            'uuid',
            'installationDeviceUuid',
            'deviceUuid',
            'id',
        ]);
        if ($deviceUuid === null) {
            continue;
        }

        try {
            $details = apiGet(
                "/api/v1/installation/$id/hems/device/" . rawurlencode($deviceUuid),
                $accessToken
            );
            $result['devices'][$index]['details'] = $details;
            $result['devices'][$index]['values'] = specificationValuesByName($details);
        } catch (Throwable $e) {
            $result['_errors']["deviceDetails.$index"] = $e->getMessage();
        }
    }

    return $result;
}

function firstStringField(array $data, array $fieldNames): ?string
{
    foreach ($fieldNames as $fieldName) {
        $value = trim((string)($data[$fieldName] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function specificationValuesByName(array $device): array
{
    $values = [];
    foreach (($device['specifications'] ?? []) as $specification) {
        if (!is_array($specification)) {
            continue;
        }

        $name = trim((string)($specification['name'] ?? ''));
        if ($name !== '' && array_key_exists('value', $specification)) {
            $values[$name] = $specification['value'];
        }
    }

    return $values;
}

function setNestedValue(array &$target, array $parts, mixed $value): void
{
    $cursor =& $target;
    foreach ($parts as $part) {
        if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
            $cursor[$part] = [];
        }
        $cursor =& $cursor[$part];
    }
    $cursor = $value;
}

function flattenCloudData(array $data, string $prefix = ''): array
{
    $items = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($value)) {
            if ($value === []) {
                $items[] = ['path' => $path, 'value' => '[]'];
            } else {
                $items = array_merge($items, flattenCloudData($value, $path));
            }
            continue;
        }

        $items[] = [
            'path' => $path,
            'value' => $value === null ? 'NULL' : (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value),
        ];
    }

    return $items;
}

function printCloudItems(array $items, string $filter): void
{
    $matches = [];
    $regex = str_contains($filter, '%')
        ? '/^' . str_replace('%', '.*', preg_quote($filter, '/')) . '$/i'
        : null;

    foreach ($items as $item) {
        $text = $item['path'] . ' ' . $item['value'];
        $matched = $filter === ''
            || ($regex !== null && preg_match($regex, $text))
            || ($regex === null && stripos($text, $filter) !== false);
        if ($matched) {
            $matches[] = $item;
        }
    }

    echo PHP_EOL . 'Treffer: ' . count($matches) . PHP_EOL;
    echo str_repeat('=', 100) . PHP_EOL;
    foreach ($matches as $item) {
        echo str_pad($item['path'], 64) . ' = ' . $item['value'] . PHP_EOL;
    }
}

function printCloudHelp(): void
{
    echo PHP_EOL . 'Bedienung:' . PHP_EOL;
    echo '  leer          alle geladenen Werte anzeigen' . PHP_EOL;
    echo '  text          Pfad und Wert durchsuchen, z.B. battery, temperature oder pvPower' . PHP_EOL;
    echo '  %text%        LIKE-Suche mit % als Platzhalter' . PHP_EOL;
    echo '  r             Cloud-Daten neu laden' . PHP_EOL;
    echo '  flow          Energiefluss mit Richtung und verstaendlichen Bezeichnungen anzeigen' . PHP_EOL;
    echo '  day [Datum]   Leistungsverlauf, z.B. day 2026-07-14' . PHP_EOL;
    echo '  day-devices [Datum]  Tagesverlauf nach Haus, Wallbox, Waermepumpe und Heizstab' . PHP_EOL;
    echo '  day-house [Datum]       nur Hausverbrauch' . PHP_EOL;
    echo '  day-heatingrod [Datum]  nur Heizstab' . PHP_EOL;
    echo '  day-heatpump [Datum]    nur Waermepumpe' . PHP_EOL;
    echo '  day-wallbox [Datum]     nur Wallbox' . PHP_EOL;
    echo '  day-totals [Datum]   erzeugte, verbrauchte, gespeicherte und Netz-Energie' . PHP_EOL;
    echo '  heizstab temp             Ist-, Solltemperatur und Messzeit anzeigen' . PHP_EOL;
    echo '  heizstab values           alle aktuellen Heizstabwerte anzeigen' . PHP_EOL;
    echo '  heizstab value NAME       genau einen Heizstabwert anzeigen' . PHP_EOL;
    echo '  heizstab details          vollstaendige Geraeteantwort anzeigen' . PHP_EOL;
    echo '  heatingrod show          Heizstabmodus und Grenzwerte anzeigen' . PHP_EOL;
    echo '  heatingrod mode solar    auf Solarbasiert stellen (mit Bestaetigung)' . PHP_EOL;
    echo '  heatingrod mode manual   auf Nicht optimiert stellen (mit Bestaetigung)' . PHP_EOL;
    echo '  heatingrod min 500       Mindestueberschuss in Watt setzen' . PHP_EOL;
    echo '  heatingrod max 3500      maximal verwendeten Ueberschuss setzen' . PHP_EOL;
    echo '  soc           heutigen SOC-Verlauf in 15-Minuten-Schritten anzeigen' . PHP_EOL;
    echo '  discover      bekannte lesende App-Endpunkte pruefen' . PHP_EOL;
    echo '  api [name]    API-Kurzbefehl ausfuehren; nur api zeigt die API-Hilfe' . PHP_EOL;
    echo '  /api/...      vollstaendigen Cloud-Endpunkt direkt abrufen' . PHP_EOL;
    echo '  login         Browser-Anmeldung erneut ausfuehren' . PHP_EOL;
    echo '  password-login  Login mit execScripts/task_solar_params.json ausfuehren' . PHP_EOL;
    echo '                Zugangsdaten und erneuerte Tokens werden dort gemeinsam gespeichert' . PHP_EOL;
    echo '                Bei ungueltigem Token erfolgt automatisch ein Login-Wiederholungsversuch' . PHP_EOL;
    echo '  raw           komplette geladene JSON-Daten als Datei speichern' . PHP_EOL;
    echo '  ?             diese Hilfe anzeigen' . PHP_EOL;
    echo '  q             beenden' . PHP_EOL;
    printHeatingRodHelp();
    printApiShortcutHelp();
}

function writeCloudRawJson(array $rawData): string
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'ampere-iq-cloud-loop-raw.json';
    $json = json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($file, $json . PHP_EOL) === false) {
        throw new RuntimeException('Raw JSON konnte nicht gespeichert werden: ' . json_last_error_msg());
    }

    return $file;
}

function loadSocHistory(string $installationId, string $accessToken): array
{
    $path = '/api/v1/installation/' . rawurlencode($installationId) . '/history/stateOfCharge'
        . queryString(['date' => date(DATE_ATOM), 'period' => 'day', 'resolution' => '15m']);
    return apiGet($path, $accessToken);
}

function printJson(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function resolveApiShortcut(string $input, string $installationId): ?string
{
    $input = trim($input);
    $lower = strtolower($input);
    $id = rawurlencode($installationId);

    $aliases = [
        'installation' => '/api/v1/installation',
        'live' => "/api/v1/installation/$id/now/all/power",
        'devices' => "/api/v1/installation/$id/hems/device",
        'battery-settings' => "/api/v1/installation/$id/hems/setting/battery",
        'emergency-power' => "/api/v1/installation/$id/hems_setting/emergency_power",
        'energy-tariff' => "/api/v1/installation/$id/hems/energyTariff",
        'cars' => "/api/v1/installation/$id/hems/ev",
        'feature-flags' => '/api/v1/featureFlag',
    ];

    if (str_starts_with($lower, 'api ')) {
        $input = trim(substr($input, 4));
        $lower = strtolower($input);
    } elseif (!str_contains($input, '/') && !array_key_exists($lower, $aliases)) {
        return null;
    }

    if (isset($aliases[$lower])) {
        return $aliases[$lower];
    }
    if (str_starts_with($lower, '/api/')) {
        $slashAlias = substr($lower, 5);
        if (isset($aliases[$slashAlias])) {
            return $aliases[$slashAlias];
        }
    }

    $input = str_replace(
        ['{installationId}', '{installation-id}', '{id}', '<id>', ':id'],
        $id,
        $input
    );
    if (str_starts_with($input, '/api/')) {
        return $input;
    }
    if (str_starts_with($input, 'api/')) {
        return '/' . $input;
    }

    $relative = ltrim($input, '/');
    if (str_starts_with($relative, 'v1/') || str_starts_with($relative, 'v2/')) {
        return '/api/' . $relative;
    }
    if (str_starts_with($relative, 'installation/')) {
        return '/api/v1/' . $relative;
    }

    return "/api/v1/installation/$id/" . $relative;
}

function resolveHistoryShortcut(string $input, string $installationId): ?array
{
    $input = trim($input);
    if (!preg_match(
        '/^(day|verlauf|day-devices|device-history|geraeteverlauf|day-house|day-heatingrod|day-heizstab|day-heatpump|day-waermepumpe|day-wallbox|day-totals|tageswerte)(?:\s+(\d{4}-\d{2}-\d{2}))?$/i',
        $input,
        $matches
    )) {
        return null;
    }

    $command = strtolower($matches[1]);
    $date = $matches[2] ?? date('Y-m-d');
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($parsedDate === false || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        throw new RuntimeException("Ungueltiges Datum '$date'. Erwartet wird JJJJ-MM-TT.");
    }

    $id = rawurlencode($installationId);
    $query = queryString(['period' => 'day', 'date' => $date]);
    if (in_array($command, ['day-devices', 'device-history', 'geraeteverlauf'], true)) {
        return [
            'path' => "/api/v1/installation/$id/history/consumption/power$query",
            'section' => null,
        ];
    }
    $deviceSections = [
        'day-house' => 'house',
        'day-heatingrod' => 'heatingRod',
        'day-heizstab' => 'heatingRod',
        'day-heatpump' => 'heatPump',
        'day-waermepumpe' => 'heatPump',
        'day-wallbox' => 'wallbox',
    ];
    if (isset($deviceSections[$command])) {
        return [
            'path' => "/api/v1/installation/$id/history/consumption/power$query",
            'section' => $deviceSections[$command],
        ];
    }
    if (in_array($command, ['day-totals', 'tageswerte'], true)) {
        return [
            'path' => "/api/v1/installation/$id/total/common/work$query",
            'section' => null,
        ];
    }

    return [
        'path' => "/api/v1/installation/$id/history/common/power$query",
        'section' => null,
    ];
}

function printApiShortcutHelp(): void
{
    echo PHP_EOL . 'API-Kurzbefehle:' . PHP_EOL;
    echo '  api installation     eigene Installation anzeigen' . PHP_EOL;
    echo '  api live             aktuelle Leistungswerte anzeigen' . PHP_EOL;
    echo '  api devices          HEMS-Geraete anzeigen' . PHP_EOL;
    echo '  api battery-settings Batterie-Einstellungen anzeigen' . PHP_EOL;
    echo '  api emergency-power  Notstromreserve anzeigen' . PHP_EOL;
    echo '  api energy-tariff    Energietarif anzeigen' . PHP_EOL;
    echo '  api cars             Elektroautos anzeigen' . PHP_EOL;
    echo '  api feature-flags    freigeschaltete App-Funktionen anzeigen' . PHP_EOL;
    echo '  /api/live            Kurzbefehle funktionieren auch in dieser Schreibweise' . PHP_EOL;
    echo '  hems/device          wird automatisch um API-Praefix und Installations-ID ergaenzt' . PHP_EOL;
}

function printApiResponse(string $path, array $data): void
{
    $count = array_is_list($data) ? count($data) . ' Eintraege' : count($data) . ' Felder';
    echo PHP_EOL . "GET $path" . PHP_EOL;
    echo "JSON-Antwort ($count):" . PHP_EOL;
    echo str_repeat('=', 100) . PHP_EOL;
    printJson($data);
}

function printEnergyFlow(array $data): void
{
    $pv = powerValue($data['pvPower'] ?? null);
    $house = powerValue($data['housePower'] ?? null);
    $battery = powerValue($data['batteryPower'] ?? null);
    $grid = powerValue($data['gridPower'] ?? null);
    $heatingRod = powerValue($data['heatingRodPower'] ?? $data['heatrodPower'] ?? null);

    echo PHP_EOL . 'Aktueller Energiefluss:' . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    echo str_pad('Solarleistung', 24) . ' = ' . formatPowerValue($pv) . PHP_EOL;
    echo str_pad('Hausverbrauch', 24) . ' = ' . formatPowerValue($house === null ? null : abs($house)) . PHP_EOL;
    echo str_pad('Akku', 24) . ' = ' . formatDirectionalPower($battery, 'Entladen', 'Laden') . PHP_EOL;
    echo str_pad('Netz', 24) . ' = ' . formatDirectionalPower($grid, 'Netzbezug', 'Netzeinspeisung') . PHP_EOL;
    if ($heatingRod !== null) {
        echo str_pad('Heizstabverbrauch', 24) . ' = ' . formatPowerValue(abs($heatingRod)) . PHP_EOL;
    }
    if (array_key_exists('batterySoc', $data)) {
        echo str_pad('Akkufuellstand', 24) . ' = ' . formatPercent($data['batterySoc']) . PHP_EOL;
    }
    echo PHP_EOL . 'Hinweis: Die App zeigt die Betraege in kW und die Richtung grafisch an.' . PHP_EOL;
}

function handleHeatingRodCommand(string $input, string $installationId, string $accessToken): bool
{
    if (!preg_match('/^(?:heatingrod|heizstab)(?:\s+(.+))?$/i', trim($input), $matches)) {
        return false;
    }

    $action = trim((string)($matches[1] ?? 'show'));
    $actionLower = strtolower($action);
    $heatingRod = loadHeatingRodDevice($installationId, $accessToken);

    if (in_array($actionLower, ['temp', 'temperature', 'temperatur'], true)) {
        printHeatingRodTemperatures(specificationValuesByName($heatingRod['details']));
        return true;
    }
    if (in_array($actionLower, ['values', 'value', 'werte'], true)) {
        printJson(specificationValuesByName($heatingRod['details']));
        return true;
    }
    if (in_array($actionLower, ['details', 'detail', 'raw'], true)) {
        printJson($heatingRod['details']);
        return true;
    }
    if (preg_match('/^(?:value|wert)\s+([a-z0-9_.-]+)$/i', $action, $valueMatches)) {
        printHeatingRodValue(specificationValuesByName($heatingRod['details']), $valueMatches[1]);
        return true;
    }

    $settings = heatingRodOptimizationSettings($heatingRod);
    if ($actionLower === 'show') {
        printHeatingRodSettings($heatingRod['uuid'], $settings);
        return true;
    }
    if (!preg_match('/^(?:mode\s+(?:solar|pv|manual)|min\s+\d+|max\s+\d+)$/i', $action)) {
        throw new RuntimeException(
            "Unbekannter Heizstab-Befehl '$action'. Erlaubt sind show, temp, values, value NAME, details, mode, min und max."
        );
    }

    if (str_starts_with($actionLower, 'mode ')) {
        $requestedMode = substr($actionLower, 5);
        $field = 'strategy';
        $newValue = in_array($requestedMode, ['solar', 'pv'], true) ? 'pv' : 'manual';
    } else {
        [$fieldCommand, $number] = explode(' ', $actionLower, 2);
        $field = $fieldCommand === 'min' ? 'minPower' : 'maxPower';
        $newValue = (int)$number;
        validateHeatingRodPowerSetting($field, $newValue, $settings);
    }

    $oldValue = $settings[$field] ?? null;
    if ($oldValue === $newValue) {
        echo "Keine Aenderung erforderlich: $field ist bereits " . formatSettingValue($newValue) . '.' . PHP_EOL;
        return true;
    }

    echo PHP_EOL . 'Aenderung am Heizstab:' . PHP_EOL;
    echo "  $field: " . formatSettingValue($oldValue) . ' -> ' . formatSettingValue($newValue) . PHP_EOL;
    echo 'Zum Ausfuehren exakt JA eingeben: ';
    $confirmation = trim((string)fgets(STDIN));
    if ($confirmation !== 'JA') {
        echo 'Abgebrochen. Es wurde nichts geaendert.' . PHP_EOL;
        return true;
    }

    $id = rawurlencode($installationId);
    $uuid = rawurlencode($heatingRod['uuid']);
    $path = "/api/v1/installation/$id/hems/device/$uuid";
    apiPatch($path, [$field => $newValue], $accessToken);
    $updated = apiGet($path, $accessToken);
    printHeatingRodSettings($heatingRod['uuid'], heatingRodOptimizationSettings([
        'device' => $heatingRod['device'],
        'details' => $updated,
    ]));
    return true;
}

function loadHeatingRodDevice(string $installationId, string $accessToken): array
{
    $id = rawurlencode($installationId);
    $devices = apiGet("/api/v1/installation/$id/hems/device", $accessToken);
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }

        $uuid = firstStringField($device, ['uuid', 'installationDeviceUuid', 'deviceUuid', 'id']);
        if ($uuid === null) {
            continue;
        }

        $details = apiGet("/api/v1/installation/$id/hems/device/" . rawurlencode($uuid), $accessToken);
        $type = strtolower(trim((string)(
            $details['optimizationSettings']['type']
            ?? $device['optimizationSettings']['type']
            ?? $details['type']
            ?? $device['type']
            ?? $device['deviceType']
            ?? ''
        )));
        if (!in_array($type, ['heatingrod', 'heating_rod', 'heating-rod'], true)) {
            continue;
        }

        return ['uuid' => $uuid, 'device' => $device, 'details' => $details];
    }

    throw new RuntimeException('In der Ampere.IQ-Installation wurde kein Heizstab gefunden.');
}

function printHeatingRodTemperatures(array $values): void
{
    echo PHP_EOL . 'Heizstab-Temperaturen:' . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    echo str_pad('Isttemperatur', 28) . ' = ' . formatTemperature($values['temperature'] ?? null) . PHP_EOL;
    echo str_pad('Solltemperatur', 28) . ' = ' . formatTemperature($values['targetTemperature'] ?? null) . PHP_EOL;
    echo str_pad('Messzeitpunkt', 28) . ' = ' . ($values['temperatureTimestamp'] ?? 'nicht vorhanden') . PHP_EOL;
}

function printHeatingRodValue(array $values, string $requestedName): void
{
    foreach ($values as $name => $value) {
        if (strcasecmp($name, $requestedName) === 0) {
            echo $name . ' = ' . formatScalarValue($value) . PHP_EOL;
            return;
        }
    }

    throw new RuntimeException(
        "Heizstabwert '$requestedName' wurde nicht gefunden. Mit 'heizstab values' werden alle Namen angezeigt."
    );
}

function formatTemperature(mixed $value): string
{
    return is_numeric($value) ? number_format((float)$value, 1, ',', '.') . ' Grad C' : 'nicht vorhanden';
}

function formatScalarValue(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return (string)$value;
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '<nicht darstellbar>' : $json;
}

function heatingRodOptimizationSettings(array $heatingRod): array
{
    $details = is_array($heatingRod['details'] ?? null) ? $heatingRod['details'] : [];
    $device = is_array($heatingRod['device'] ?? null) ? $heatingRod['device'] : [];
    $settings = $details['optimizationSettings'] ?? $device['optimizationSettings'] ?? null;
    if (!is_array($settings)) {
        throw new RuntimeException('Der Heizstab liefert keine optimizationSettings.');
    }

    return $settings;
}

function validateHeatingRodPowerSetting(string $field, int $value, array $settings): void
{
    $limits = $field === 'minPower' ? [100, 3500] : [100, 6500];
    if ($value < $limits[0] || $value > $limits[1] || $value % 100 !== 0) {
        throw new RuntimeException(
            "$field muss zwischen {$limits[0]} und {$limits[1]} W liegen und durch 100 teilbar sein."
        );
    }

    $otherField = $field === 'minPower' ? 'maxPower' : 'minPower';
    $otherValue = $settings[$otherField] ?? null;
    if (is_numeric($otherValue)
        && (($field === 'minPower' && $value > (int)$otherValue)
            || ($field === 'maxPower' && $value < (int)$otherValue))) {
        throw new RuntimeException('minPower darf nicht groesser als maxPower sein.');
    }
}

function printHeatingRodSettings(string $uuid, array $settings): void
{
    $strategy = (string)($settings['strategy'] ?? 'unbekannt');
    $strategyLabel = match ($strategy) {
        'pv' => 'Solarbasiert',
        'manual' => 'Nicht optimiert',
        'priceBased' => 'Preisbasiert',
        default => 'Unbekannt',
    };

    echo PHP_EOL . 'Heizstab-Einstellungen:' . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    echo str_pad('Geraete-UUID', 28) . ' = ' . $uuid . PHP_EOL;
    echo str_pad('Lademodus', 28) . " = $strategyLabel ($strategy)" . PHP_EOL;
    echo str_pad('Mindestueberschuss', 28) . ' = ' . formatWattsSetting($settings['minPower'] ?? null) . PHP_EOL;
    echo str_pad('Maximaler Ueberschuss', 28) . ' = ' . formatWattsSetting($settings['maxPower'] ?? null) . PHP_EOL;
    echo PHP_EOL . 'Vollstaendige Cloud-Einstellungen:' . PHP_EOL;
    printJson($settings);
}

function printHeatingRodHelp(): void
{
    echo PHP_EOL . 'Heizstabwerte und -steuerung:' . PHP_EOL;
    echo '  heizstab temp      = Isttemperatur, Solltemperatur und Zeitstempel' . PHP_EOL;
    echo '  heizstab values    = kompakte Gruppe aller aktuellen Werte' . PHP_EOL;
    echo '  heizstab value temperature       = nur Isttemperatur' . PHP_EOL;
    echo '  heizstab value targetTemperature = nur Solltemperatur' . PHP_EOL;
    echo '  heizstab details   = vollstaendige rohe Geraeteantwort' . PHP_EOL;
    echo '  strategy pv       = Solarbasiert; Ampere.IQ regelt nach PV-Ueberschuss' . PHP_EOL;
    echo '  strategy manual   = Nicht optimiert; keine Ueberschussregelung durch Ampere.IQ' . PHP_EOL;
    echo '  minPower          = Mindestueberschuss, 100 bis 3500 W in 100-W-Schritten' . PHP_EOL;
    echo '  maxPower          = maximal verwendeter Ueberschuss, 100 bis 6500 W' . PHP_EOL;
    echo '  Vor jedem Schreibzugriff werden Alt- und Neuwert gezeigt und JA verlangt.' . PHP_EOL;
    echo '  Die Temperatur- und Sicherheitsregelung des AC ELWA bleibt aktiv.' . PHP_EOL;
}

function formatWattsSetting(mixed $value): string
{
    return is_numeric($value) ? number_format((float)$value, 0, ',', '.') . ' W' : 'nicht gesetzt';
}

function formatSettingValue(mixed $value): string
{
    if ($value === null) {
        return 'nicht gesetzt';
    }

    return is_numeric($value) ? formatWattsSetting($value) : (string)$value;
}

function powerValue(mixed $value): ?float
{
    if (is_array($value)) {
        $value = $value['value'] ?? null;
    }

    return is_numeric($value) ? (float)$value : null;
}

function formatPowerValue(?float $watts): string
{
    if ($watts === null) {
        return 'nicht verfuegbar';
    }

    return number_format($watts, 0, ',', '.') . ' W ('
        . number_format($watts / 1000, 1, ',', '.') . ' kW)';
}

function formatDirectionalPower(?float $watts, string $positiveLabel, string $negativeLabel): string
{
    if ($watts === null) {
        return 'nicht verfuegbar';
    }
    if (abs($watts) < 0.5) {
        return formatPowerValue(0.0) . ' - Stillstand';
    }

    $label = $watts > 0 ? $positiveLabel : $negativeLabel;
    return formatPowerValue(abs($watts)) . " - $label";
}

function interactiveLogin(string $tokenFile): array
{
    $authorization = createAuthorizationRequest();

    echo PHP_EOL;
    echo "Einmalige Ampere.IQ-Anmeldung:" . PHP_EOL;
    echo "1. Diese URL in einem Browser oeffnen und mit dem Ampere.IQ-Konto anmelden:" . PHP_EOL;
    echo $authorization['url'] . PHP_EOL . PHP_EOL;
    echo "2. Nach der Anmeldung versucht der Browser eine nicht installierte App zu oeffnen." . PHP_EOL;
    echo "   Die komplette Zieladresse aus der Adresszeile kopieren und hier einfuegen." . PHP_EOL;
    echo "Ruecksprung-URL> ";

    $callbackUrl = trim((string)fgets(STDIN));
    if ($callbackUrl === '') {
        throw new RuntimeException('Keine Ruecksprung-URL eingegeben.');
    }

    return exchangeAuthorizationCallback(
        $callbackUrl,
        $authorization['verifier'],
        $authorization['state'],
        $tokenFile
    );
}

function createAuthorizationRequest(): array
{
    $verifier = base64UrlEncode(random_bytes(64));
    $challenge = base64UrlEncode(hash('sha256', $verifier, true));
    $state = base64UrlEncode(random_bytes(24));
    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => AUTH0_CLIENT_ID,
        'redirect_uri' => AUTH0_REDIRECT_URI,
        'scope' => AUTH0_SCOPE,
        'audience' => AUTH0_AUDIENCE,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);

    return [
        'url' => AUTH0_BASE_URL . '/authorize?' . $query,
        'verifier' => $verifier,
        'state' => $state,
    ];
}

function exchangeAuthorizationCallback(
    string $callbackUrl,
    string $verifier,
    string $expectedState,
    string $tokenFile
): array {
    $parts = parse_url($callbackUrl);
    $params = [];
    parse_str((string)($parts['query'] ?? ''), $params);

    if (isset($params['error'])) {
        $description = (string)($params['error_description'] ?? $params['error']);
        throw new RuntimeException('Auth0-Anmeldung fehlgeschlagen: ' . $description);
    }
    if (!hash_equals($expectedState, (string)($params['state'] ?? ''))) {
        throw new RuntimeException('Der OAuth-State der Ruecksprung-URL stimmt nicht.');
    }

    $code = (string)($params['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('In der Ruecksprung-URL fehlt der Anmeldecode.');
    }

    $tokens = tokenRequest([
        'grant_type' => 'authorization_code',
        'client_id' => AUTH0_CLIENT_ID,
        'code' => $code,
        'code_verifier' => $verifier,
        'redirect_uri' => AUTH0_REDIRECT_URI,
    ]);
    $tokens['obtained_at'] = time();
    $tokens['expires_at'] = time() + (int)($tokens['expires_in'] ?? 0);
    saveTokens($tokenFile, $tokens);

    echo "Anmeldung gespeichert: $tokenFile" . PHP_EOL;
    return $tokens;
}

function followAuth0ToHtmlPage(string $url, string $cookieFile): string
{
    for ($i = 0; $i < 10; $i++) {
        $response = auth0HttpRequest($url, $cookieFile);
        if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
            $url = resolveAuth0Location($response['location']);
            continue;
        }
        if ($response['status'] === 200) {
            return $response['url'];
        }

        throw new RuntimeException("Auth0 lieferte HTTP {$response['status']} bei {$response['url']}");
    }

    throw new RuntimeException('Zu viele Weiterleitungen vor der Auth0-Anmeldeseite.');
}

function followAuth0ResponseToCallback(array $response, string $cookieFile): string
{
    for ($i = 0; $i < 12; $i++) {
        if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
            if (!preg_match('~^https?://~i', $response['location']) && !str_starts_with($response['location'], '/')) {
                return $response['location'];
            }

            $url = resolveAuth0Location($response['location']);
            $response = auth0HttpRequest($url, $cookieFile);
            continue;
        }

        $text = trim(strip_tags((string)$response['body']));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (strlen($text) > 300) {
            $text = substr($text, 0, 300) . '...';
        }
        throw new RuntimeException(
            "Automatischer Auth0-Login endete mit HTTP {$response['status']} bei {$response['url']}"
            . ($text !== '' ? ": $text" : '')
        );
    }

    throw new RuntimeException('Zu viele Weiterleitungen nach der Auth0-Anmeldung.');
}

function auth0HttpRequest(string $url, string $cookieFile, ?array $postFields = null): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 Ampere-IQ-CLI/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8'],
    ];
    if ($postFields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($postFields, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_HTTPHEADER][] = 'Origin: ' . AUTH0_BASE_URL;
        $options[CURLOPT_REFERER] = $url;
    }

    $caFile = resolveCaFile();
    if ($caFile !== null) {
        $options[CURLOPT_CAINFO] = $caFile;
    }
    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL-Fehler bei $url: $error");
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $headers = substr((string)$raw, 0, $headerSize);
    $body = substr((string)$raw, $headerSize);
    $location = '';
    if (preg_match('/^location:\s*(.+)$/mi', $headers, $matches)) {
        $location = trim($matches[1]);
    }

    return [
        'status' => $status,
        'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'location' => $location,
        'body' => $body,
    ];
}

function resolveAuth0Location(string $location): string
{
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }
    if (str_starts_with($location, '/')) {
        return AUTH0_BASE_URL . $location;
    }

    return $location;
}

function getQueryParameter(string $url, string $name): string
{
    $parameters = [];
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?: ''), $parameters);
    $value = (string)($parameters[$name] ?? '');
    if ($value === '') {
        throw new RuntimeException("Parameter $name fehlt in $url");
    }

    return $value;
}

function loadTokens(string $tokenFile): ?array
{
    if (!is_file($tokenFile)) {
        return null;
    }

    $decoded = json_decode((string)file_get_contents($tokenFile), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Ungueltige Token-Datei: $tokenFile");
    }

    if (isset($decoded['ampereIq']) && is_array($decoded['ampereIq'])) {
        $tokens = $decoded['ampereIq']['tokens'] ?? null;
        return is_array($tokens) ? $tokens : null;
    }

    return isset($decoded['access_token']) ? $decoded : null;
}

function isAuthenticationFailure(Throwable $error): bool
{
    $message = strtolower($error->getMessage());
    foreach ([
        'keine ampere.iq-tokens',
        'kein refresh-token',
        'http 400 bei ' . strtolower(AUTH0_BASE_URL) . '/oauth/token',
        'http 401 ',
        'http 403 ',
        'invalid_grant',
        'invalid refresh token',
    ] as $needle) {
        if (str_contains($message, $needle)) {
            return true;
        }
    }

    return false;
}

function ensureAccessToken(array $tokens, string $tokenFile): array
{
    $accessToken = (string)($tokens['access_token'] ?? '');
    $expiresAt = (int)($tokens['expires_at'] ?? jwtExpiresAt($accessToken));
    if ($accessToken !== '' && $expiresAt > time() + 60) {
        return $tokens;
    }

    $refreshToken = (string)($tokens['refresh_token'] ?? '');
    if ($refreshToken === '') {
        throw new RuntimeException("Kein Refresh-Token vorhanden. Bitte mit 'login' neu anmelden.");
    }

    $refreshed = tokenRequest([
        'grant_type' => 'refresh_token',
        'client_id' => AUTH0_CLIENT_ID,
        'refresh_token' => $refreshToken,
        'scope' => AUTH0_SCOPE,
    ]);

    // Auth0 kann Refresh-Tokens rotieren; fehlt ein neuer, bleibt der alte gueltig.
    if (empty($refreshed['refresh_token'])) {
        $refreshed['refresh_token'] = $refreshToken;
    }
    $refreshed['obtained_at'] = time();
    $refreshed['expires_at'] = time() + (int)($refreshed['expires_in'] ?? 0);
    saveTokens($tokenFile, $refreshed);

    return $refreshed;
}

function tokenRequest(array $fields): array
{
    return httpJson(AUTH0_BASE_URL . '/oauth/token', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
}

function apiGet(string $path, string $accessToken): array
{
    return httpJson(API_BASE_URL . $path, [
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
            'x-client-type: de.ekd.customerapp',
            'x-client-version: ' . APP_VERSION,
        ],
    ]);
}

function apiPatch(string $path, array $data, string $accessToken): array
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('PATCH-Daten konnten nicht als JSON erzeugt werden.');
    }

    return httpJson(API_BASE_URL . $path, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'x-client-type: de.ekd.customerapp',
            'x-client-version: ' . APP_VERSION,
        ],
    ]);
}

function discoverReadEndpoints(string $installationId, string $accessToken): void
{
    $id = rawurlencode($installationId);
    $date = date('Y-m-d');
    $dayQuery = queryString(['period' => 'day', 'date' => $date]);
    $from = date(DATE_ATOM, strtotime('today'));
    $to = date(DATE_ATOM, strtotime('tomorrow'));

    $endpoints = [
        'Feature-Flags' => '/api/v1/featureFlag',
        'Live-Leistung' => "/api/v1/installation/$id/now/all/power",
        'Wetter' => "/api/v1/installation/$id/weather" . queryString(['date' => $date]),
        'Arbeit/Ertrag gesamt' => "/api/v1/installation/$id/total/common/work$dayQuery",
        'Autarkie' => "/api/v1/installation/$id/total/selfSufficiency$dayQuery",
        'Eigenverbrauch' => "/api/v1/installation/$id/total/selfConsumption$dayQuery",
        'Leistungsverlauf' => "/api/v1/installation/$id/history/common/power$dayQuery",
        'Arbeitsverlauf' => "/api/v1/installation/$id/history/common/work$dayQuery",
        'Verbrauchsleistung' => "/api/v1/installation/$id/history/consumption/power$dayQuery",
        'Verbrauchsarbeit' => "/api/v1/installation/$id/history/consumption/work$dayQuery",
        'Netzbezug' => "/api/v1/installation/$id/history/gridDraw/work$dayQuery",
        'SOC-Verlauf' => "/api/v1/installation/$id/history/stateOfCharge" . queryString([
            'date' => date(DATE_ATOM),
            'period' => 'day',
            'resolution' => '15m',
        ]),
        'Ersparnis' => "/api/v2/installation/$id/saving$dayQuery",
        'Strompreise' => "/api/v1/installation/$id/electricityPrice" . queryString([
            'from' => $from,
            'to' => $to,
            'resolution' => '15m',
        ]),
        'Batterie-Einstellungen' => "/api/v1/installation/$id/hems/setting/battery",
        'Notstromreserve' => "/api/v1/installation/$id/hems_setting/emergency_power",
        'Energietarif' => "/api/v1/installation/$id/hems/energyTariff",
        'Aktuelles Netzentgelt' => "/api/v1/installation/$id/hems/energyTariff/currentGridFee",
        'Einspeiseverguetung' => "/api/v1/installation/$id/hems/gridFeedCompensationPrice",
        'Elektroautos' => "/api/v1/installation/$id/hems/ev",
        'HEMS-Geraete' => "/api/v1/installation/$id/hems/device",
        'Energievertrag' => "/api/v1/installation/$id/subscription/energy",
    ];

    echo "Nur lesende Ampere.IQ-Endpunkte fuer Installation $installationId" . PHP_EOL;
    echo "Zeitraum fuer Tageswerte: $date" . PHP_EOL . PHP_EOL;

    foreach ($endpoints as $label => $path) {
        echo "[$label] GET $path" . PHP_EOL;
        try {
            $data = apiGet($path, $accessToken);
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $json = '<JSON konnte nicht erzeugt werden>';
            }
            if (strlen($json) > 1200) {
                $json = substr($json, 0, 1200) . '... <gekuerzt>';
            }
            echo "  OK: $json" . PHP_EOL;
        } catch (Throwable $e) {
            echo '  NICHT VERFUEGBAR: ' . $e->getMessage() . PHP_EOL;
        }
        echo PHP_EOL;
    }
}

function queryString(array $parameters): string
{
    return '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

function httpJson(string $url, array $options): array
{
    $ch = curl_init($url);
    $curlOptions = $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ];

    $caFile = resolveCaFile();
    if ($caFile !== null) {
        $curlOptions[CURLOPT_CAINFO] = $caFile;
    }

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
        if (is_array($decoded)) {
            $messageValue = $decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? $decoded;
            $message = is_scalar($messageValue)
                ? (string)$messageValue
                : (string)json_encode($messageValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $message = trim((string)$body);
        }
        throw new RuntimeException("HTTP $status bei $url" . ($message !== '' ? ": $message" : ''));
    }
    if (trim((string)$body) === '') {
        return [];
    }
    if (!is_array($decoded)) {
        throw new RuntimeException("Ungueltige JSON-Antwort von $url: " . json_last_error_msg());
    }

    return $decoded;
}

function resolveCaFile(): ?string
{
    $configured = trim((string)(getenv('AMPERE_IQ_CA_FILE') ?: ''));
    if ($configured !== '') {
        if (!is_file($configured)) {
            throw new RuntimeException("AMPERE_IQ_CA_FILE wurde nicht gefunden: $configured");
        }

        return $configured;
    }

    foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
        $iniFile = trim((string)ini_get($setting));
        if ($iniFile !== '' && is_file($iniFile)) {
            return $iniFile;
        }
    }

    $bundled = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (is_file($bundled)) {
        return $bundled;
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        return null;
    }

    // Wampserver liefert oft kein curl.cainfo mit, PhpMyAdmin aber ein CA-Buendel.
    $wampBundles = glob('C:/wamp*/apps/phpmyadmin*/vendor/composer/ca-bundle/res/cacert.pem') ?: [];
    foreach ($wampBundles as $wampBundle) {
        if (is_file($wampBundle)) {
            return $wampBundle;
        }
    }

    throw new RuntimeException(
        'Kein CA-Zertifikatsbuendel gefunden. AMPERE_IQ_CA_FILE auf eine cacert.pem setzen.'
    );
}

function saveTokens(string $tokenFile, array $tokens): void
{
    $directory = dirname($tokenFile);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Token-Verzeichnis konnte nicht angelegt werden: $directory");
    }

    $data = [];
    if (is_file($tokenFile)) {
        $existing = json_decode((string)file_get_contents($tokenFile), true);
        if (is_array($existing)) {
            $data = $existing;
        }
    }

    if (isset($data['ampereIq']) && is_array($data['ampereIq'])) {
        $data['ampereIq']['tokens'] = $tokens;
    } elseif ($data !== [] && !isset($data['access_token'])) {
        $data['ampereIq'] = ['tokens' => $tokens];
    } else {
        $data = $tokens;
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tokenFile, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Token-Datei konnte nicht geschrieben werden: $tokenFile");
    }
    @chmod($tokenFile, 0600);
}

function clearTokens(string $tokenFile): void
{
    if (!is_file($tokenFile)) {
        return;
    }

    $decoded = json_decode((string)file_get_contents($tokenFile), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Ungueltige Parameterdatei: $tokenFile");
    }

    if (isset($decoded['ampereIq']) && is_array($decoded['ampereIq'])) {
        unset($decoded['ampereIq']['tokens']);
        $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tokenFile, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Parameterdatei konnte nicht geschrieben werden: $tokenFile");
        }
        @chmod($tokenFile, 0600);
        return;
    }

    if (!unlink($tokenFile)) {
        throw new RuntimeException("Token-Datei konnte nicht geloescht werden: $tokenFile");
    }
}

function migrateLegacyTokens(string $tokenFile): void
{
    if (loadTokens($tokenFile) !== null) {
        return;
    }

    $home = trim((string)(getenv('HOME') ?: getenv('USERPROFILE') ?: ''));
    if ($home === '') {
        return;
    }

    $legacyFile = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR
        . 'coh-sensorcollector' . DIRECTORY_SEPARATOR . 'ampere-iq-token.json';
    if ($legacyFile === $tokenFile || !is_file($legacyFile)) {
        return;
    }

    $legacyTokens = loadTokens($legacyFile);
    if ($legacyTokens === null) {
        return;
    }

    saveTokens($tokenFile, $legacyTokens);
    if (!unlink($legacyFile)) {
        throw new RuntimeException("Alte Token-Datei konnte nach der Migration nicht geloescht werden: $legacyFile");
    }
    echo "Vorhandene Ampere.IQ-Tokens wurden nach $tokenFile verschoben." . PHP_EOL;
}

function jwtExpiresAt(string $token): int
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return 0;
    }

    $payload = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/'), true), true);
    return is_array($payload) ? (int)($payload['exp'] ?? 0) : 0;
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function formatPercent(mixed $value): string
{
    if (!is_numeric($value)) {
        return (string)$value;
    }

    $number = (float)$value;
    if ($number >= 0.0 && $number <= 1.0) {
        $number *= 100.0;
    }

    return rtrim(rtrim(number_format($number, 1, ',', ''), '0'), ',') . ' %';
}

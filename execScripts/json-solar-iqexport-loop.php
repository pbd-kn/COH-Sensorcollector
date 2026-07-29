<?php
declare(strict_types=1);

/**
 * Exportiert die von der Ampere.IQ-App bekannten Lese- und Einstellwerte.
 *
 * Aufruf:
 *   php json-solar-iqexport-loop.php                         Interaktive Schleife
 *   php json-solar-iqexport-loop.php --once=live             Einen Bereich als JSON
 *   php json-solar-iqexport-loop.php --all                   Vollstaendigen Export erzeugen
 *   php json-solar-iqexport-loop.php --output=/var/www/html/json-solar-iqexport-loop.json
 *   php json-solar-iqexport-loop.php --stdout
 *
 * Der Export ist rein lesend. Passwoerter und OAuth-Tokens werden nie ausgegeben.
 */

require_once __DIR__ . '/TaskAccess.php';

const DEFAULT_OUTPUT_FILE = __DIR__ . '/json-solar-iqexport-loop.json';

date_default_timezone_set('Europe/Berlin');

try {
    $options = getopt('', ['output:', 'params:', 'once:', 'all', 'stdout', 'help']);
    if (isset($options['help'])) {
        printHelp();
        exit(0);
    }

    $paramsFile = absolutePath((string)($options['params'] ?? 'task_heizstab_params.json'));
    $outputFile = absolutePath((string)($options['output'] ?? DEFAULT_OUTPUT_FILE));
    $parameters = TaskAccess::loadParameters($paramsFile);
    $client = TaskAccess::ampereIq($parameters, __DIR__);

    $installationId = $client->installationId();
    $today = date('Y-m-d');
    $dayQuery = '?period=day&date=' . rawurlencode($today);
    $todayStart = (new DateTimeImmutable($today))->format(DATE_ATOM);
    $tomorrowStart = (new DateTimeImmutable($today))->modify('+1 day')->format(DATE_ATOM);

    $readEndpoints = [
        'live' => '/api/v1/installation/{installationId}/now/all/power',
        'today.work' => '/api/v1/installation/{installationId}/total/common/work' . $dayQuery,
        'today.selfSufficiency' => '/api/v1/installation/{installationId}/total/selfSufficiency' . $dayQuery,
        'today.selfConsumption' => '/api/v1/installation/{installationId}/total/selfConsumption' . $dayQuery,
        'today.saving' => '/api/v2/installation/{installationId}/saving' . $dayQuery,
        'history.common.power' => '/api/v1/installation/{installationId}/history/common/power' . $dayQuery,
        'history.common.work' => '/api/v1/installation/{installationId}/history/common/work' . $dayQuery,
        'history.consumption.power' => '/api/v1/installation/{installationId}/history/consumption/power' . $dayQuery,
        'history.consumption.work' => '/api/v1/installation/{installationId}/history/consumption/work' . $dayQuery,
        'history.gridDraw.work' => '/api/v1/installation/{installationId}/history/gridDraw/work' . $dayQuery,
        'history.batterySoc' => '/api/v1/installation/{installationId}/history/stateOfCharge'
            . '?date=' . rawurlencode(date(DATE_ATOM)) . '&period=day&resolution=15m',
        'history.electricityPrice' => '/api/v1/installation/{installationId}/electricityPrice'
            . '?from=' . rawurlencode($todayStart)
            . '&to=' . rawurlencode($tomorrowStart)
            . '&resolution=15m',
        'settings.battery' => '/api/v1/installation/{installationId}/hems/setting/battery',
        'settings.emergencyPower' => '/api/v1/installation/{installationId}/hems_setting/emergency_power',
        'settings.energyTariff' => '/api/v1/installation/{installationId}/hems/energyTariff',
        'settings.gridFeedCompensation' => '/api/v1/installation/{installationId}/hems/gridFeedCompensationPrice',
        'devices' => '/api/v1/installation/{installationId}/hems/device',
        'electricVehicles' => '/api/v1/installation/{installationId}/hems/ev',
    ];

    if (isset($options['once'])) {
        printSelectedJson($client, $readEndpoints, (string)$options['once']);
        exit(0);
    }

    $wantsFullExport = isset($options['all']) || isset($options['output']) || isset($options['stdout']);
    if (!$wantsFullExport) {
        runInteractiveLoop($client, $readEndpoints);
        exit(0);
    }

    $data = [];
    $errors = [];
    foreach ($readEndpoints as $name => $endpoint) {
        try {
            setPath($data, explode('.', $name), $client->get($endpoint));
        } catch (Throwable $error) {
            $errors[$name] = $error->getMessage();
        }
    }

    // Die Geraetedetails enthalten die aktuellen Spezifikationswerte, etwa
    // Temperatur, Zieltemperatur und Optimierungseinstellungen des Heizstabs.
    if (is_array($data['devices'] ?? null)) {
        foreach ($data['devices'] as $index => $device) {
            if (!is_array($device)) {
                continue;
            }
            $uuid = firstValue($device, ['uuid', 'installationDeviceUuid', 'deviceUuid', 'id']);
            if ($uuid === null) {
                continue;
            }
            try {
                $details = $client->get(
                    '/api/v1/installation/{installationId}/hems/device/' . rawurlencode($uuid)
                );
                $data['devices'][$index]['details'] = $details;
                $data['devices'][$index]['values'] = specificationValues($details);
            } catch (Throwable $error) {
                $errors['devices.' . $index . '.details'] = $error->getMessage();
            }
        }
    }

    $flatValues = flattenValues($data);
    $aliases = buildAliases($data);
    $export = [
        'ok' => $errors === [],
        'schemaVersion' => 1,
        'installationId' => $installationId,
        'generatedAt' => date(DATE_ATOM),
        'aliases' => $aliases,
        'values' => $flatValues,
        'data' => $data,
        'writable' => writableValues($data),
        'errors' => $errors,
    ];

    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON konnte nicht erzeugt werden: ' . json_last_error_msg());
    }
    $json .= PHP_EOL;

    if (isset($options['stdout'])) {
        echo $json;
        exit($errors === [] ? 0 : 2);
    }

    writeAtomically($outputFile, $json);
    echo "Ampere.IQ-Export geschrieben: $outputFile" . PHP_EOL;
    echo 'Werte: ' . count($flatValues) . ', Fehler: ' . count($errors) . PHP_EOL;
    exit($errors === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

function printHelp(): void
{
    echo 'Ampere.IQ-Werte als JSON exportieren' . PHP_EOL;
    echo '  ohne Option    Interaktive Auswahl in einer Schleife starten' . PHP_EOL;
    echo '  --once=NAME    Einen Bereich lesen und als JSON ausgeben' . PHP_EOL;
    echo '  --all          Alle Bereiche lesen und Exportdatei schreiben' . PHP_EOL;
    echo '  --output=DATEI  Zieldatei (Standard: execScripts/json-solar-iqexport-loop.json)' . PHP_EOL;
    echo '  --params=DATEI  Task-Parameter (Standard: execScripts/task_heizstab_params.json)' . PHP_EOL;
    echo '  --stdout        JSON nur auf STDOUT ausgeben' . PHP_EOL;
}

/**
 * Interaktive, ausschliesslich lesende Abfrage. Jeder Befehl holt nur den
 * ausgewaehlten Bereich frisch aus der Cloud.
 */
function runInteractiveLoop(AmpereIqHttpAccess $client, array $endpoints): void
{
    echo PHP_EOL . 'Ampere.IQ JSON-Auswahl' . PHP_EOL;
    printSelectionHelp($endpoints);

    while (true) {
        echo PHP_EOL . 'Auswahl (? fuer Hilfe, q zum Beenden): ';
        $input = fgets(STDIN);
        if ($input === false) {
            echo PHP_EOL;
            return;
        }

        $selection = ltrim(trim($input), "\xEF\xBB\xBF");
        $lower = strtolower($selection);
        if (in_array($lower, ['q', 'quit', 'ende', 'exit'], true)) {
            return;
        }
        if (in_array($lower, ['?', 'help', 'hilfe'], true)) {
            printSelectionHelp($endpoints);
            continue;
        }

        try {
            if ($lower === 'all' || $lower === 'alle') {
                $result = [];
                foreach ($endpoints as $name => $endpoint) {
                    try {
                        setPath($result, explode('.', $name), readSelection($client, $endpoints, $name));
                    } catch (Throwable $error) {
                        $result['_errors'][$name] = $error->getMessage();
                    }
                }
                printJsonValue($result);
                continue;
            }

            printJsonValue(readSelection($client, $endpoints, $selection));
        } catch (Throwable $error) {
            fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
        }
    }
}

function printSelectedJson(AmpereIqHttpAccess $client, array $endpoints, string $selection): void
{
    printJsonValue(readSelection($client, $endpoints, $selection));
}

function readSelection(AmpereIqHttpAccess $client, array $endpoints, string $selection): mixed
{
    // Bewusst dieselbe Klassenmethode wie spaeter in json-heizung.php nutzen.
    // Dadurch sind Auswahl, Rueckgabewert und Datentyp in beiden Oberflaechen identisch.
    return $client->getValue($selection);
}

function printSelectionHelp(array $endpoints): void
{
    echo PHP_EOL . 'Kurzbefehle:' . PHP_EOL;
    echo '  soc                  nur Batterie-SoC' . PHP_EOL;
    echo '  live                 aktueller Energiefluss und alle Livewerte' . PHP_EOL;
    echo '  live.power           alle aktuellen Leistungswerte' . PHP_EOL;
    echo '  live.power.batteryPower  nur aktuelle Batterieleistung' . PHP_EOL;
    echo '  today                alle heutigen Summen und Kennzahlen' . PHP_EOL;
    echo '  today-work           heutige Energiearbeit' . PHP_EOL;
    echo '  today.work.consumption   nur heutiger Verbrauch' . PHP_EOL;
    echo '  today-self-sufficiency  heutige Autarkie' . PHP_EOL;
    echo '  today-self-consumption  heutiger Eigenverbrauch' . PHP_EOL;
    echo '  today-saving         heutige Ersparnis' . PHP_EOL;
    echo '  today-saving-energy  heutige Energieaufteilung' . PHP_EOL;
    echo '  today-saving-pv-production  heutige PV-Produktion' . PHP_EOL;
    echo '  today-saving-grid-feed      heutige Netzeinspeisung' . PHP_EOL;
    echo '  today-saving-own-consumption  heutiger Eigenverbrauch' . PHP_EOL;
    echo '  today-saving-cost    heutige Kosten und Ersparnis' . PHP_EOL;
    echo '  today-saving-total   heutige Gesamtersparnis' . PHP_EOL;
    echo '  today-saving-grid-feed-compensation  Einspeiseverguetung' . PHP_EOL;
    echo '  today-saving-grid-feed-price  Preis der Einspeiseverguetung' . PHP_EOL;
    echo '  today-saving-electricity-prices  heutige Strompreise' . PHP_EOL;
    echo '  today-saving-evs     Werte der Elektrofahrzeuge' . PHP_EOL;
    echo '  today-saving-emissions       Emissionswerte' . PHP_EOL;
    echo '  today-saving-emissions-factor  Emissionsfaktor' . PHP_EOL;
    echo '  today-saving-emissions-total   gesamte Emissionseinsparung' . PHP_EOL;
    echo '  lifetime.pvProduction  gesamte PV-Erzeugung seit Anlagenbeginn' . PHP_EOL;
    echo '  lifetime.work          alle Gesamtenergien seit Anlagenbeginn' . PHP_EOL;
    echo '  lifetime 2025          Gesamtenergien eines bestimmten Jahres' . PHP_EOL;
    echo '  lifetime.work 2025     komplette Energiewerte eines Jahres' . PHP_EOL;
    echo '  lifetime.pvProduction 2025  PV-Erzeugung eines Jahres' . PHP_EOL;
    echo '  year.work JJJJ-MM-TT   Jahreswerte des angegebenen Jahres' . PHP_EOL;
    echo '  PFAD JJJJ-MM-TT     Tageswert eines bestimmten Datums,' . PHP_EOL;
    echo '                       z.B. today.saving.energy 2026-07-18' . PHP_EOL;
    echo '  soc-history          heutiger Batterie-SoC-Verlauf' . PHP_EOL;
    echo '  history.common.power       allgemeiner Leistungsverlauf' . PHP_EOL;
    echo '  history.common.work        allgemeiner Energieverlauf' . PHP_EOL;
    echo '  history.consumption.power  Leistungsverlauf der Verbraucher' . PHP_EOL;
    echo '  history.consumption.work   Energieverlauf der Verbraucher' . PHP_EOL;
    echo '  history.gridDraw.work      Verlauf des Netzbezugs' . PHP_EOL;
    echo '  history.electricityPrice   Strompreisverlauf in 15 Minuten' . PHP_EOL;
    echo '  battery-settings     Batterieeinstellungen' . PHP_EOL;
    echo '  devices              Geraeteliste' . PHP_EOL;
    echo '  device UUID          bestimmtes Geraet anhand einer beliebigen UUID' . PHP_EOL;
    echo '  devices UUID         gleichbedeutend mit device UUID' . PHP_EOL;
    echo '  all                  alle Bereiche als ein JSON anzeigen' . PHP_EOL;
    echo PHP_EOL . 'Alle Bereichsnamen:' . PHP_EOL;
    foreach (array_keys($endpoints) as $name) {
        echo '  ' . $name . PHP_EOL;
    }
}

function printJsonValue(mixed $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON konnte nicht erzeugt werden: ' . json_last_error_msg());
    }
    echo $json . PHP_EOL;
}

function absolutePath(string $path): string
{
    if ($path === '') {
        throw new InvalidArgumentException('Leerer Dateipfad.');
    }
    $isWindowsAbsolute = strlen($path) >= 3
        && ctype_alpha($path[0])
        && $path[1] === ':'
        && ($path[2] === '\\' || $path[2] === '/');
    if ($isWindowsAbsolute || str_starts_with($path, '/')) {
        return $path;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . $path;
}

function setPath(array &$target, array $parts, mixed $value): void
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

function firstValue(array $data, array $fields): ?string
{
    foreach ($fields as $field) {
        $value = trim((string)($data[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function specificationValues(array $device): array
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

/** @return array<string, mixed> */
function flattenValues(array $data, string $prefix = ''): array
{
    $flat = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($value)) {
            if ($value === []) {
                $flat[$path] = [];
            } else {
                $flat += flattenValues($value, $path);
            }
        } else {
            $flat[$path] = $value;
        }
    }
    return $flat;
}

function buildAliases(array $data): array
{
    $aliases = [];
    foreach (['batterySoc', 'batteryPower', 'gridPower', 'pvPower', 'consumptionPower'] as $name) {
        if (array_key_exists($name, $data['live'] ?? [])) {
            $aliases[$name] = $data['live'][$name];
        }
    }
    return $aliases;
}

function writableValues(array $data): array
{
    $result = [
        'notice' => 'Nur Beschreibung. Dieser Exporter schreibt keine Werte in die Cloud.',
        'battery' => [
            'endpoint' => '/api/v1/installation/{installationId}/hems/setting/battery',
            'currentValues' => $data['settings']['battery'] ?? null,
        ],
        'emergencyPower' => [
            'endpoint' => '/api/v1/installation/{installationId}/hems_setting/emergency_power',
            'currentValues' => $data['settings']['emergencyPower'] ?? null,
        ],
        'energyTariff' => [
            'endpoint' => '/api/v1/installation/{installationId}/hems/energyTariff',
            'currentValues' => $data['settings']['energyTariff'] ?? null,
        ],
        'gridFeedCompensation' => [
            'endpoint' => '/api/v1/installation/{installationId}/hems/gridFeedCompensationPrice',
            'currentValues' => $data['settings']['gridFeedCompensation'] ?? null,
        ],
    ];

    foreach (($data['devices'] ?? []) as $device) {
        if (!is_array($device)) {
            continue;
        }
        $settings = $device['details']['optimizationSettings'] ?? $device['optimizationSettings'] ?? null;
        $uuid = firstValue($device, ['uuid', 'installationDeviceUuid', 'deviceUuid', 'id']);
        if ($uuid !== null && is_array($settings)) {
            $result['devices'][$uuid] = [
                'endpoint' => '/api/v1/installation/{installationId}/hems/device/' . $uuid,
                'optimizationSettings' => $settings,
            ];
        }
    }
    return $result;
}

function writeAtomically(string $file, string $content): void
{
    $directory = dirname($file);
    if (!is_dir($directory)) {
        throw new RuntimeException("Zielverzeichnis existiert nicht: $directory");
    }
    $temporary = $file . '.tmp';
    if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $file)) {
        @unlink($temporary);
        throw new RuntimeException("Exportdatei konnte nicht geschrieben werden: $file");
    }
}

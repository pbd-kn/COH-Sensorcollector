<?php

declare(strict_types=1);

require_once __DIR__ . '/AmpereStorageProModbus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur fuer die Kommandozeile.\n");
}

$host = $argv[1] ?? 'ASP-HSR2103J2311E08738.local';
$port = isset($argv[2]) ? (int)$argv[2] : 502;
$unitId = isset($argv[3]) ? (int)$argv[3] : 1;
$timeout = 3.0;
$client = new AmpereStorageProModbus($host, $port, $unitId, $timeout);

echo "AMPERE.StoragePro - lokale Ampere.IQ-kompatible Modbus-Auswahl\n";
echo "Verbindung: $host:$port, Unit-ID $unitId\n";
echo "Nur Lesezugriffe (Modbus-Funktion 03).\n";
echo "Mit 'help' werden alle Befehle angezeigt.\n\n";

while (true) {
    $line = loopPrompt('storagepro> ');
    if ($line === null) {
        echo "\nBeendet.\n";
        break;
    }
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $parts = preg_split('/\s+/', $line, 2);
    $command = strtolower($parts[0]);
    $argument = $parts[1] ?? '';

    try {
        switch ($command) {
            case 'help': case 'hilfe': case '?':
                loopHelp();
                break;
            case 'all': case 'alle':
                loopJson(buildCompatibleData($client->readSnapshot()));
                break;
            case 'snapshot':
                loopSnapshot($client->readSnapshot());
                break;
            case 'json':
                loopJson($client->readSnapshot());
                break;
            case 'get': case 'wert':
                if ($argument === '') {
                    echo "Bitte Namen angeben, z. B. get battery.soc\n";
                } else {
                    loopValue($client, $argument);
                }
                break;
            case 'list': case 'liste': case 'catalog': case 'katalog':
                loopCatalog($argument);
                break;
            case 'raw':
                loopRaw($client, $argument);
                break;
            case 'open': case 'oeffnen':
                if ($argument !== '') {
                    $connection = preg_split('/\s+/', trim($argument));
                    if (!$connection || count($connection) > 4) {
                        throw new InvalidArgumentException(
                            'Aufruf: open HOST [PORT] [UNIT-ID] [TIMEOUT]'
                        );
                    }
                    $newHost = $connection[0];
                    $newPort = isset($connection[1]) ? (int)$connection[1] : 502;
                    $newUnitId = isset($connection[2]) ? (int)$connection[2] : 1;
                    $newTimeout = isset($connection[3]) ? (float)$connection[3] : 3.0;
                    $newClient = new AmpereStorageProModbus(
                        $newHost,
                        $newPort,
                        $newUnitId,
                        $newTimeout
                    );
                    $client->close();
                    $newClient->open();
                    $client = $newClient;
                    [$host, $port, $unitId, $timeout] = [$newHost, $newPort, $newUnitId, $newTimeout];
                } else {
                    $client->open();
                }
                echo "Modbus-Verbindung zu $host:$port, Unit-ID $unitId ist geoeffnet.\n";
                break;
            case 'close': case 'schliessen':
                $client->close();
                echo "Modbus-Verbindung ist geschlossen.\n";
                break;
            case 'reconnect': case 'neu':
                $client->close();
                echo "Verbindung geschlossen; der naechste Zugriff verbindet neu.\n";
                break;
            case 'quit': case 'exit': case 'q': case 'ende':
                $client->close();
                echo "Beendet.\n";
                exit(0);
            default:
                if (isset(AmpereStorageProModbus::catalog()[$line])) {
                    loopValue($client, $line);
                } else {
                    loopJson(readCompatibleSelection($client->readSnapshot(), $line));
                }
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
        fwrite(STDERR, "Die Testschleife laeuft weiter.\n");
    }
}

function loopHelp(): void
{
    echo <<<'HELP'

Cloud-kompatible Befehle, lokal per Modbus gelesen:
  soc                         Batterie-SoC
  live | live.power           Energiefluss und alle aktuellen Modbus-Werte
  live.power.batteryPower     einzelne aktuelle Leistung
  today                       heutige Werte
  today-work                  heutige Energiearbeit
  today.work.consumption      bilanzierter Hausverbrauch heute
  today-self-sufficiency      Autarkie heute
  today-self-consumption      PV-Eigenverbrauch heute
  today-saving-energy         lokale Energieaufteilung
  today-saving-pv-production  PV-Produktion heute
  today-saving-grid-feed      Netzeinspeisung heute
  today-saving-own-consumption direkt verbrauchte PV-Energie
  lifetime | lifetime.work    Gesamtzaehler seit Inbetriebnahme
  lifetime.pvProduction       gesamte PV-Erzeugung
  all                         live, today und lifetime als JSON
  modbus                      alle zusaetzlichen lokalen Modbus-Werte
  modbus.inverter.temperature einzelner Zusatzwert als JSON

Zusaetzliche Modbus-Befehle:
  snapshot                    alle Modbus-Werte formatiert
  json                        roher Modbus-Snapshot als JSON
  get NAME                    Einzelwert, z. B. get battery.soc
  NAME                        Einzelwert direkt, z. B. pv.power
  list [FILTER]               Registerkatalog
  raw ADRESSE [ANZAHL]        Rohregister lesen
  open                        aktuelle Modbus-Verbindung oeffnen
  open HOST [PORT] [UNIT] [TIMEOUT]  Verbindung zu einem anderen Geraet
  close                       Modbus-Verbindung schliessen
  reconnect                   Verbindung neu aufbauen
  quit | exit | q             Beenden

Nicht lokal verfuegbar sind Historien, fruehere Tage/Jahre, Strompreise,
Euro-Ersparnisse, Emissionen sowie Cloud-Geraete und -Einstellungen.

HELP;
}

function loopValue(AmpereStorageProModbus $client, string $name): void
{
    $catalog = AmpereStorageProModbus::catalog();
    if (!isset($catalog[$name])) {
        throw new InvalidArgumentException("Unbekannter Wert '$name'. 'list' zeigt alle Namen.");
    }
    $item = $catalog[$name];
    $value = $client->readValue($name);
    $unit = $item['unit'] === '' ? '' : ' ' . $item['unit'];
    printf("%s = %s%s\n", $name, loopNumber($value), $unit);
    printf("  Register %d/%s, %s, Faktor %s\n", $item['address'], $item['addressHex'], $item['type'], loopNumber($item['scale']));
    echo '  ' . $item['description'] . PHP_EOL;
}

function loopCatalog(string $filter): void
{
    $filter = strtolower(trim($filter));
    $found = 0;
    printf("%-36s %7s %-7s %-8s %6s  %s\n", 'Name', 'Adresse', 'Hex', 'Typ', 'Faktor', 'Einheit / Bedeutung');
    echo str_repeat('-', 110) . PHP_EOL;
    foreach (AmpereStorageProModbus::catalog() as $name => $item) {
        $haystack = strtolower($name . ' ' . $item['description'] . ' ' . $item['unit']);
        if ($filter !== '' && strpos($haystack, $filter) === false) {
            continue;
        }
        printf("%-36s %7d %-7s %-8s %6s  %-4s %s\n", $name, $item['address'], $item['addressHex'], $item['type'], loopNumber($item['scale']), $item['unit'], $item['description']);
        $found++;
    }
    echo "$found Wert(e) gefunden.\n";
}

function loopRaw(AmpereStorageProModbus $client, string $argument): void
{
    $parts = preg_split('/\s+/', trim($argument));
    if (!$parts || $parts[0] === '') {
        echo "Aufruf: raw ADRESSE [ANZAHL], z. B. raw 0x40A5 4\n";
        return;
    }
    $text = strtolower($parts[0]);
    if (str_starts_with($text, '0x')) {
        $hex = substr($text, 2);
        if ($hex === '' || !ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Ungueltige Hexadresse '{$parts[0]}'.");
        }
        $address = hexdec($hex);
    } else {
        if (!ctype_digit($text)) {
            throw new InvalidArgumentException("Ungueltige Dezimaladresse '{$parts[0]}'.");
        }
        $address = (int)$text;
    }
    $count = isset($parts[1]) ? (int)$parts[1] : 1;
    $registers = $client->readHoldingRegisters($address, $count);
    printf("%d Register ab %d (0x%04X):\n", count($registers), $address, $address);
    foreach ($registers as $offset => $raw) {
        $current = $address + $offset;
        $signed = AmpereStorageProModbus::decode([$raw], 0, 'int16');
        printf("  %5d  0x%04X  raw=%5d  hex=0x%04X  int16=%6d\n", $current, $current, $raw, $raw, $signed);
    }
}

/** @param array<string,mixed> $snapshot */
function loopSnapshot(array $snapshot): void
{
    printf("\n%s - %s:%d, Unit %d - %s\n", $snapshot['device'], $snapshot['host'], $snapshot['port'], $snapshot['unitId'], $snapshot['timestamp']);
    echo str_repeat('=', 76) . PHP_EOL;
    loopTree($snapshot['data']);
    echo PHP_EOL;
}

/** @param array<string,mixed> $values */
function loopTree(array $values, string $prefix = ''): void
{
    $catalog = AmpereStorageProModbus::catalog();
    foreach ($values as $name => $value) {
        $path = $prefix === '' ? $name : $prefix . '.' . $name;
        if (is_array($value)) {
            loopTree($value, $path);
            continue;
        }
        $unit = isset($catalog[$path]) && $catalog[$path]['unit'] !== '' ? ' ' . $catalog[$path]['unit'] : '';
        printf("%-42s %12s%s\n", $path, loopNumber($value), $unit);
    }
}

/** @param mixed $data */
function loopJson($data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('JSON-Fehler: ' . json_last_error_msg());
    }
    echo $json . PHP_EOL;
}

function loopPrompt(string $text): ?string
{
    if (function_exists('readline')) {
        $line = readline($text);
        if ($line === false) {
            return null;
        }
        if (trim($line) !== '') {
            readline_add_history($line);
        }
        return $line;
    }
    echo $text;
    $line = fgets(STDIN);
    return $line === false ? null : rtrim($line, "\r\n");
}

/** @param int|float $value */
function loopNumber($value): string
{
    return is_float($value) ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : (string)$value;
}
/** @return array<string,mixed> */
function buildCompatibleData(array $snapshot): array
{
    $data = $snapshot['data'];
    $energy = $data['energy'];
    $today = compatibleWork(
        $energy['inverter']['today'],
        $energy['house']['today'],
        $energy['battery']['chargeToday'],
        $energy['battery']['dischargeToday'],
        $energy['grid']['sumSellToday'] ?? $energy['grid']['sellToday'],
        $energy['grid']['sumFeedInToday'] ?? $energy['grid']['feedInToday']
    );
    $totalConsumption = calculatedConsumption(
        $energy['pv']['total'],
        $energy['grid']['feedInTotal'],
        $energy['battery']['dischargeTotal'],
        $energy['grid']['sellTotal'],
        $energy['battery']['chargeTotal']
    );
    $lifetime = compatibleWork(
        $energy['pv']['total'],
        $totalConsumption,
        $energy['battery']['chargeTotal'],
        $energy['battery']['dischargeTotal'],
        $energy['grid']['sellTotal'],
        $energy['grid']['feedInTotal']
    );
    $live = [
        'pvPower' => $data['pv']['power'],
        'housePower' => -abs($data['house']['power']),
        'gridPower' => $data['grid']['power'],
        'batteryPower' => $data['battery']['power'] == 0 ? 0 : -$data['battery']['power'],
        'heatingRodPower' => null,
        'batterySoc' => $data['battery']['soc'],
        'inverter' => $data['inverter'],
        'grid' => $data['grid'],
        'battery' => $data['battery'],
        'pv' => $data['pv'],
        'house' => $data['house'],
    ];
    $selfSufficiency = compatiblePercent(
        $today['consumption'] - $today['gridDraw'],
        $today['consumption']
    );
    $selfConsumption = compatiblePercent(
        $today['generation'] - $today['gridFeed'],
        $today['generation']
    );
    return [
        'live' => $live,
        'today' => [
            'work' => $today,
            'selfSufficiency' => ['value' => $selfSufficiency],
            'selfConsumption' => ['value' => $selfConsumption],
            'saving' => [
                'energy' => [
                    'pvProduction' => $today['generation'],
                    'gridFeed' => $today['gridFeed'],
                    'ownConsumption' => max(0.0, round($today['generation'] - $today['gridFeed'], 2)),
                ],
                '_notice' => 'Kosten, Preise, Fahrzeuge und Emissionen sind nicht per Modbus verfuegbar.',
            ],
        ],
        'lifetime' => [
            'pvProduction' => $lifetime['generation'],
            'work' => $lifetime + [
                'throughDate' => date('Y-m-d'),
                'source' => 'StoragePro-Modbus-Gesamtzaehler',
            ],
        ],
        'modbus' => $data,
        '_meta' => [
            'source' => 'AMPERE.StoragePro Modbus TCP',
            'timestamp' => $snapshot['timestamp'],
            'unit' => 'Wh',
        ],
    ];
}

/** @return array<string,float|string> */
function compatibleWork(
    float $generationKwh,
    float $consumptionKwh,
    float $batteryChargeKwh,
    float $batteryDischargeKwh,
    float $gridExportKwh,
    float $gridImportKwh
): array {
    return [
        'generation' => round($generationKwh * 1000, 2),
        'consumption' => round($consumptionKwh * 1000, 2),
        'batteryFeed' => round($batteryChargeKwh * 1000, 2),
        'batteryDraw' => round($batteryDischargeKwh * 1000, 2),
        'gridFeed' => round($gridExportKwh * 1000, 2),
        'gridDraw' => round($gridImportKwh * 1000, 2),
        'unit' => 'Wh',
    ];
}

function calculatedConsumption(
    float $pvKwh,
    float $gridImportKwh,
    float $batteryDischargeKwh,
    float $gridExportKwh,
    float $batteryChargeKwh
): float {
    return round(
        $pvKwh + $gridImportKwh + $batteryDischargeKwh - $gridExportKwh - $batteryChargeKwh,
        2
    );
}

function compatiblePercent(float $part, float $whole): ?float
{
    return $whole <= 0.0 ? null : round(max(0.0, min(100.0, $part / $whole * 100)), 2);
}

function readCompatibleSelection(array $snapshot, string $selection): mixed
{
    $selection = trim($selection);
    if (preg_match('/^(.+?)\s+(\d{4})$/', $selection)) {
        throw new InvalidArgumentException(
            'Modbus-Gesamtzaehler haben keine Aufteilung nach Jahren; verwende lifetime ohne Jahreszahl.'
        );
    }
    if (preg_match('/^(.+?)\s+(\d{4}-\d{2}-\d{2})$/', $selection, $matches)) {
        if ($matches[2] !== date('Y-m-d')) {
            throw new InvalidArgumentException('Modbus liefert nur die Tageszaehler des heutigen Tages.');
        }
        $selection = trim($matches[1]);
    }

    $aliases = [
        'soc' => 'live.batterySoc',
        'batterysoc' => 'live.batterySoc',
        'batterie-soc' => 'live.batterySoc',
        'flow' => 'live',
        'energiefluss' => 'live',
        'live.power' => 'live',
        'work' => 'today.work',
        'arbeit' => 'today.work',
        'today-work' => 'today.work',
        'today-self-sufficiency' => 'today.selfSufficiency',
        'today-autarkie' => 'today.selfSufficiency',
        'today-self-consumption' => 'today.selfConsumption',
        'today-eigenverbrauch' => 'today.selfConsumption',
        'today-saving' => 'today.saving',
        'today-ersparnis' => 'today.saving',
        'today-saving-energy' => 'today.saving.energy',
        'today-saving-pv-production' => 'today.saving.energy.pvProduction',
        'today-saving-grid-feed' => 'today.saving.energy.gridFeed',
        'today-saving-own-consumption' => 'today.saving.energy.ownConsumption',
        'pv-total' => 'lifetime.pvProduction',
        'pv-gesamt' => 'lifetime.pvProduction',
        'total-pv' => 'lifetime.pvProduction',
        'lifetime-pv' => 'lifetime.pvProduction',
        'lifetime' => 'lifetime.work',
    ];
    $selection = $aliases[strtolower($selection)] ?? $selection;
    if (str_starts_with(strtolower($selection), 'live.power.')) {
        $selection = 'live.' . substr($selection, strlen('live.power.'));
    }

    assertCompatibleSelectionAvailable($selection);
    return compatibleValueFromPath(buildCompatibleData($snapshot), $selection);
}

function assertCompatibleSelectionAvailable(string $selection): void
{
    $lower = strtolower($selection);
    $unavailable = [
        'history.', 'soc-history', 'year.', 'settings.', 'battery-settings',
        'devices', 'device ', 'electricvehicles', 'today.saving.cost',
        'today.saving.evs', 'today.saving.emissions', 'today-saving-cost',
        'today-saving-total', 'today-saving-grid-feed-compensation',
        'today-saving-grid-feed-price', 'today-saving-electricity-prices',
        'today-saving-evs', 'today-saving-emissions',
    ];
    foreach ($unavailable as $prefix) {
        if (str_starts_with($lower, $prefix)) {
            throw new InvalidArgumentException(
                "Auswahl '$selection' ist nicht per StoragePro-Modbus verfuegbar."
            );
        }
    }
}

function compatibleValueFromPath(array $data, string $path): mixed
{
    if (in_array(strtolower($path), ['today', 'heute'], true)) {
        return $data['today'];
    }
    $cursor = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($cursor)) {
            throw new InvalidArgumentException("Modbus-Auswahl '$path' ist kein Wertpfad.");
        }
        $actualKey = null;
        foreach (array_keys($cursor) as $key) {
            if (strcasecmp((string)$key, $part) === 0) {
                $actualKey = $key;
                break;
            }
        }
        if ($actualKey === null) {
            throw new InvalidArgumentException("Unbekannte lokale Auswahl '$path'.");
        }
        $cursor = $cursor[$actualKey];
    }
    return $cursor;
}

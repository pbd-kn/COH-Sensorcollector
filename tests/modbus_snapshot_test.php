<?php

declare(strict_types=1);

// Ausschließlich lokale Fixtures und TCP-Verbindungen auf 127.0.0.1, keine DB/Geräte.
$collectorDir = is_dir(__DIR__ . '/../sensorcollect') ? __DIR__ . '/../sensorcollect' : __DIR__ . '/../sensorCollect';
require_once $collectorDir . '/Modbus/bootstrap.php';
require_once $collectorDir . '/autoload.php';
require_once $collectorDir . '/Sensor/AmpereStorageProModbus.php';

use PbdKn\cohSensorcollector\Modbus\ModbusProfile;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotClient;
use PbdKn\cohSensorcollector\Modbus\ModbusTcpClient;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotReader;
use PbdKn\cohSensorcollector\Modbus\ModbusSensorValues;
use PbdKn\cohSensorcollector\Sensor\IQBoxSensorService;
use PbdKn\cohSensorcollector\Sensor\WechselrichterSensorService;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\SimpleHttpClient;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $callback, string $contains): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $contains), 'Unerwarteter Fehler: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Erwarteter Fehler fehlt: ' . $contains);
}

function exact($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Lokale Testanfrage unvollständig.');
        }
        $data .= $chunk;
    }
    return $data;
}

function runModbusLoop(array $arguments, string $input = '', string $script = 'json-inverter-modbus-loop.php'): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../execScripts/' . $script, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Loop-Testprozess konnte nicht starten.');
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $errors];
}

// Kindprozess: ausschließlich einen lokalen Modbus-Testserver bereitstellen.
if (($argv[1] ?? '') === '--server') {
    $mode = $argv[2];
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    check(is_resource($server), 'Testserver konnte nicht starten.');
    echo stream_socket_get_name($server, false) . PHP_EOL;
    flush();
    if ($mode === 'loop') {
        // Sechs interaktive Abfragen und --once, jeweils mit frischer Verbindung.
        $profile = ModbusProfile::load('saj-h2');
        $requestsPerSnapshot = count($profile['blocks']) + count($profile['optional']);
        for ($snapshot = 0; $snapshot < 7; ++$snapshot) {
            $socket = stream_socket_accept($server, 5);
            check(is_resource($socket), 'Loop-Testclient fehlt.');
            stream_set_timeout($socket, 5);
            for ($i = 0; $i < $requestsPerSnapshot; ++$i) {
                $request = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction/naddress/ncount', exact($socket, 12));
                check($request['function'] === 3 && $request['protocol'] === 0, 'Loop darf nur Register lesen.');
                $pdu = pack('CC', 3, $request['count'] * 2);
                for ($j = 0; $j < $request['count']; ++$j) {
                    $pdu .= pack('n', $request['address'] + $j);
                }
                fwrite($socket, pack('nnnC', $request['transaction'], 0, strlen($pdu) + 1, $request['unit']) . $pdu);
            }
            check(fread($socket, 1) === '', 'Loop muss nach jedem Snapshot die Verbindung schließen.');
            fclose($socket);
        }
        fclose($server);
        exit(0);
    }
    $socket = stream_socket_accept($server, 5);
    check(is_resource($socket), 'Testclient fehlt.');
    stream_set_timeout($socket, 5);
    for ($i = 0; $i < ($mode === 'reuse' ? 2 : 1); ++$i) {
        $request = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction/naddress/ncount', exact($socket, 12));
        check(in_array($request['function'], [3, 4], true), 'Schreibfunktion im Test!');
        check($request['protocol'] === 0 && $request['length'] === 6, 'Ungültige Anfrage.');
        $function = $request['function'];
        $pdu = pack('CC', $function, $request['count'] * 2);
        for ($j = 0; $j < $request['count']; ++$j) {
            $pdu .= pack('n', $request['address'] + $j);
        }
        if ($mode === 'exception') $pdu = pack('CC', $function | 0x80, 2);
        if ($mode === 'bad-exception') $pdu = pack('CC', ($function === 3 ? 4 : 3) | 0x80, 2);
        if ($mode === 'wrong-function') $pdu[0] = chr($function === 3 ? 4 : 3);
        if ($mode === 'wrong-count') $pdu[1] = chr(0);
        $reply = pack('nnnC',
            $request['transaction'] + ($mode === 'wrong-transaction' ? 1 : 0),
            $mode === 'wrong-protocol' ? 1 : 0,
            $mode === 'oversized' ? 65535 : strlen($pdu) + 1,
            $request['unit'] + ($mode === 'wrong-unit' ? 1 : 0)
        ) . $pdu;
        if ($mode === 'truncated') $reply = substr($reply, 0, 8);
        // Antwort in kleinen Fragmenten senden.
        foreach (str_split($reply, 3) as $chunk) {
            @fwrite($socket, $chunk);
        }
    }
    fclose($socket);
    fclose($server);
    exit(0);
}

/** Snapshot-Fixture mit denselben Adresswerten wie der TCP-Testserver. */
class FixtureModbusClient extends ModbusSnapshotClient
{
    public array $requests = [];
    public bool $closed = false;
    public bool $failOptional = false;
    public bool $failRequired = false;
    public int $snapshots = 0;

    public function readHoldingRegisters(int $address, int $count): array
    {
        $this->requests[] = [3, $address, $count];
        if (($this->failOptional && in_array($address, [0x4167, 0x416F], true)) || $this->failRequired) {
            throw new RuntimeException('Fixture-Register nicht verfügbar.');
        }
        return range($address, $address + $count - 1);
    }

    public function readInputRegisters(int $address, int $count): array
    {
        $this->requests[] = [4, $address, $count];
        return range($address, $address + $count - 1);
    }

    public function close(): void { $this->closed = true; }
    public function readSnapshot(): array { ++$this->snapshots; return parent::readSnapshot(); }
}

class SocFixtureModbusClient extends FixtureModbusClient
{
    public function readHoldingRegisters(int $address, int $count): array
    {
        $values = parent::readHoldingRegisters($address, $count);
        if ($address <= 0xA00C && $address + $count > 0xA00C) {
            $values[0xA00C - $address] = 4080;
        }
        return $values;
    }
}

try {
    $profile = ModbusProfile::load('saj-h2');
    check(count($profile['fields']) === 63, 'SAJ-Feldanzahl');
    $csv = fopen(__DIR__ . '/../execScripts/WechselRichter.csv', 'r');
    $headers = fgetcsv($csv, 0, ';');
    $typed = [];
    $untyped = 0;
    while (($row = fgetcsv($csv, 0, ';')) !== false) {
        $row = array_combine($headers, $row);
        if ($row['Datentyp_lokaler_Client'] === '') { ++$untyped; continue; }
        $typed[(int) $row['Register_Dezimal']] = $row;
    }
    fclose($csv);
    check(count($typed) === 63 && $untyped === 346, 'CSV vollständig eingelesen');
    foreach ($profile['fields'] as $name => $field) {
        $row = $typed[$field['address']] ?? null;
        check($row !== null, "CSV-Adresse fehlt: $name");
        check($row['Datentyp_lokaler_Client'] === $field['type'], "CSV-Datentyp: $name");
        check((float) $row['Faktor_lokaler_Client'] === (float) $field['scale'], "CSV-Faktor: $name");
        check($row['Einheit_lokaler_Client'] === $field['unit'], "CSV-Einheit: $name");
    }

    check(ModbusSnapshotClient::decode([0xFFFF], 0, 'int16') === -1, 'Int16-Vorzeichen');
    check(ModbusSnapshotClient::decode([0x8000], 0, 'int16') === -32768, 'Int16-Minimum');
    check(ModbusSnapshotClient::decode([0xFFFF, 0xFFFF], 0, 'uint32') === 4294967295, 'UInt32-Maximum');
    check(ModbusSnapshotClient::decode([0xFFFF, 0xFFFE], 0, 'int32') === -2, 'Int32-Vorzeichen');
    check(ModbusSnapshotClient::decode([2, 1], 0, 'uint32', 1.0, 'little') === 65538, 'Wortreihenfolge');
    check(ModbusSnapshotClient::decode([0x3FC0, 0], 0, 'float32') === 1.5, 'Float32');
    check(ModbusSnapshotClient::decode([0, 0x3FC0], 0, 'float32', 1.0, 'little') === 1.5, 'Float32 little');
    check(AmpereStorageProModbus::decode([1234], 0, 'uint16', 0.01) === 12.34, 'Alte Decode-Schnittstelle');
    fails(fn () => ModbusSnapshotClient::decode([1], 0, 'uint32'), 'Register');
    fails(fn () => ModbusSnapshotClient::decode([0x7FC0, 0], 0, 'float32'), 'endliche');
    fails(fn () => ModbusProfile::load('../saj-h2'), 'Profilname');
    fails(fn () => ModbusProfile::load('unknown'), 'Unbekanntes');
    $bad = $profile;
    $bad['fields']['bad'] = ['address' => 65535, 'type' => 'uint32'];
    fails(fn () => ModbusProfile::validate($bad), 'Registerdefinition');
    $bad = $profile;
    $bad['fields']['bad'] = ['address' => 10];
    fails(fn () => ModbusProfile::validate($bad), 'Datentyp');
    $bad = $profile;
    $bad['blocks'][] = [0x4167, 2];
    fails(fn () => ModbusProfile::validate($bad), 'Pflichtblöcke');

    $ampere = ModbusProfile::load('ampere-storagepro');
    $client = new FixtureModbusClient('fixture.invalid', $ampere);
    $snapshot = $client->readSnapshot();
    check(count($client->requests) === 10, 'Acht Blöcke plus zwei optionale Werte');
    check(array_keys($snapshot) === ['device', 'host', 'port', 'unitId', 'timestamp', 'data', 'aliases'], 'Snapshot-Hülle kompatibel');
    check($snapshot['device'] === 'AMPERE.StoragePro', 'Alter Gerätename');
    check(count($snapshot['aliases']) === 13, 'Alle bisherigen Aliase erhalten');
    check($snapshot['data']['energy']['house']['calculatedToday'] === $snapshot['data']['energy']['house']['today'], 'Alter Hauszählerpfad');
    foreach ($ampere['aliases'] as $alias => $path) {
        $value = $snapshot['data'];
        foreach (explode('.', $path) as $segment) $value = $value[$segment];
        check($snapshot['aliases'][$alias] === $value, "Alias $alias");
    }
    foreach (AmpereStorageProModbus::catalog() as $field) {
        check(array_keys($field) === ['address', 'type', 'scale', 'unit', 'description', 'addressHex'], 'Altes Katalogformat');
    }
    $client->failOptional = true;
    $snapshot = $client->readSnapshot();
    check(!isset($snapshot['data']['energy']['grid']['sumSellToday']), 'Optionale Werte fehlen bei Fehler');
    check(isset($snapshot['data']['pv']['power']), 'Pflichtwerte trotz optionalem Fehler');
    $client->failRequired = true;
    fails(fn () => $client->readSnapshot(), 'Fixture-Register');

    $generic = new FixtureModbusClient('fixture.invalid', [
        'device' => 'Other',
        'fields' => [
            'holding.value' => ['address' => 12, 'type' => 'uint16'],
            'input.value' => ['address' => 12, 'type' => 'uint16', 'function' => 4],
        ],
        'blocks' => [[12, 1, 3], [12, 1, 4]],
    ]);
    check($generic->readSnapshot()['data'] === ['holding' => ['value' => 12], 'input' => ['value' => 12]], 'FC03/04 getrennte Registerräume');
    check($generic->requests === [[3, 12, 1], [4, 12, 1]], 'Profilblöcke werden wiederverwendet');
    $single = new FixtureModbusClient('fixture.invalid', [
        'device' => 'Single',
        'fields' => ['counter' => ['address' => 20, 'type' => 'uint32', 'function' => 4]],
    ]);
    check($single->readSnapshot()['data']['counter'] === 20 * 65536 + 21, 'Profil ohne Blöcke');
    check($single->requests === [[4, 20, 2]], 'Keine unbekannten Registerlücken abfragen');

    $fixtures = [];
    $factory = function (string $host, array $definition) use (&$fixtures): FixtureModbusClient {
        $client = new FixtureModbusClient($host, $definition);
        $fixtures[] = $client;
        return $client;
    };
    $service = new ModbusSnapshotReader(Logger::getInstance(), $factory);
    $sensor = ['sensorID' => 'a', 'sensorSource' => 'wechselrichter', 'geraeteUrl' => 'fixture.invalid', 'sensorLokalId' => 'modbus.pv.power', 'sensorEinheit' => '', 'sensorValueType' => 'float'];
    check(!is_subclass_of(ModbusSnapshotReader::class, \PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface::class), 'Leser wird nicht als Sensorservice registriert');
    $results = $service->readSensors([
        $sensor,
        array_replace($sensor, ['sensorID' => 'b', 'sensorLokalId' => 'modbus.energy.pv.total']),
        array_replace($sensor, ['sensorID' => 'c', 'sensorSource' => 'modbus:saj-h2:wr2', 'geraeteUrl' => 'second.invalid', 'sensorLokalId' => 'modbus.pv']),
        array_replace($sensor, ['sensorID' => 'd', 'geraeteUrl' => '192.0.2.30']),
        array_replace($sensor, ['sensorID' => 'e', 'sensorLokalId' => 'modbus']),
    ], 'saj-h2');
    check(count($results) === 5 && count($fixtures) === 3, 'Snapshots je Verbindung gruppiert');
    foreach ($fixtures as $fixture) check($fixture->closed && $fixture->snapshots === 1, 'Verbindung freigegeben, ein Snapshot');
    check($results['a']['sensorEinheit'] === 'W' && $results['b']['sensorEinheit'] === 'kWh', 'Einheiten aus Profil');
    check(is_array($results['c']['sensorValue']) && is_array($results['e']['sensorValue']), 'JSON-Gruppen und Sammelsensor');
    check($results['b']['sensorSource'] === $sensor['sensorSource'], 'Quell-ID erhalten');
    // Nur die im Backend möglichen Adressen akzeptieren; keine URL-Optionen.
    ob_start();
    try {
        foreach (['', 'tcp://fixture.invalid:1502?unitId=2&timeout=1', 'https://', 'fixture.invalid:502'] as $address) {
            check($service->readSensors([array_replace($sensor, ['geraeteUrl' => $address])], 'saj-h2') === [], 'Ungültige Geräteadresse abgewiesen');
        }
    } finally {
        ob_end_clean();
    }
    check(count($fixtures) === 3, 'Bei ungültiger Adresse keine Verbindung öffnen');
    $failed = [];
    $failureService = new ModbusSnapshotReader(Logger::getInstance(),
        function (string $host, array $definition) use (&$failed): FixtureModbusClient {
            $client = new FixtureModbusClient($host, $definition);
            $client->failRequired = $host === 'broken.invalid';
            $failed[] = $client;
            return $client;
        }
    );
    ob_start();
    try {
        $afterFailure = $failureService->readSensors([
            array_replace($sensor, ['sensorID' => 'broken', 'geraeteUrl' => 'broken.invalid']),
            array_replace($sensor, ['sensorID' => 'missing', 'sensorLokalId' => 'modbus.unknown']),
            $sensor,
        ], 'saj-h2');
    } finally {
        ob_end_clean();
    }
    check(array_keys($afterFailure) === ['a'], 'Fehler eines Geräts oder Pfads isoliert');
    foreach ($failed as $fixture) check($fixture->closed, 'Auch fehlerhafte Verbindung freigegeben');
    check(count($service->readSensors([$sensor], 'saj-h2')) === 1 && count($fixtures) === 4, 'Neuer Poll-Lauf liest frisch');

    $iqFixtures = [];
    $iqbox = new IQBoxSensorService(new mysql_dialog(), Logger::getInstance(), new SimpleHttpClient(),
        function (string $host, array $definition) use (&$iqFixtures): FixtureModbusClient {
            check($definition['device'] === 'AMPERE.StoragePro', 'IQBox nutzt Kompatibilitätsprofil');
            $client = new FixtureModbusClient($host, $definition);
            $client->failRequired = $host === 'broken.invalid';
            $iqFixtures[] = $client;
            return $client;
        }
    );
    $iqSensor = array_replace($sensor, ['sensorSource' => 'iqbox']);
    ob_start();
    try {
        $iqValues = $iqbox->fetchArr([
            array_replace($iqSensor, ['sensorID' => 'iqPower', 'geraeteUrl' => 'https://fixture.invalid/old-api', 'sensorLokalId' => 'live.pvPower']),
            array_replace($iqSensor, ['sensorID' => 'iqSoc', 'sensorLokalId' => 'modbus.battery.soc']),
            array_replace($iqSensor, ['sensorID' => 'iqEnergy', 'sensorLokalId' => 'today.work.generation']),
            array_replace($iqSensor, ['sensorID' => 'iqAlias', 'geraeteUrl' => 'http://fixture.invalid/', 'sensorLokalId' => 'pvPower', 'sensorEinheit' => 'custom']),
            array_replace($iqSensor, ['sensorID' => 'iqLegacy', 'sensorLokalId' => 'old_photovoltaics_0_power']),
            array_replace($iqSensor, ['sensorID' => 'iqJson', 'sensorLokalId' => 'modbus']),
            array_replace($iqSensor, ['sensorID' => 'iqNativePower', 'sensorLokalId' => 'pv.power']),
            array_replace($iqSensor, ['sensorID' => 'iqPv', 'sensorLokalId' => 'pv']),
            array_replace($iqSensor, ['sensorID' => 'iqPvPrefixed', 'sensorLokalId' => 'modbus.pv']),
            array_replace($iqSensor, ['sensorID' => 'iqBattery', 'sensorLokalId' => 'battery']),
            array_replace($iqSensor, ['sensorID' => 'iqBatteryPrefixed', 'sensorLokalId' => 'modbus.battery']),
            array_replace($iqSensor, ['sensorID' => 'iqEnergyPv', 'sensorLokalId' => 'energy.pv']),
            array_replace($iqSensor, ['sensorID' => 'iqEnergyPvPrefixed', 'sensorLokalId' => 'modbus.energy.pv']),
            array_replace($iqSensor, ['sensorID' => 'iqSecond', 'geraeteUrl' => 'second.invalid']),
            array_replace($iqSensor, ['sensorID' => 'iqBroken', 'geraeteUrl' => 'broken.invalid']),
            array_replace($iqSensor, ['sensorID' => 'iqMissing', 'sensorLokalId' => 'modbus.unknown']),
            array_replace($iqSensor, ['sensorID' => 'iqNoAddress', 'geraeteUrl' => '']),
        ]);
    } finally {
        ob_end_clean();
    }
    check(count($iqFixtures) === 3 && count($iqValues) === 14, 'IQBox gruppiert Hosts und isoliert Fehler');
    foreach ($iqFixtures as $fixture) check($fixture->closed && $fixture->snapshots === 1, 'IQBox gibt alle Verbindungen frei');
    check($iqValues['iqPower']['sensorValue'] === $iqValues['iqAlias']['sensorValue'], 'IQBox Live- und Aliaswerte kompatibel');
    check($iqValues['iqLegacy']['sensorValue'] === $iqValues['iqPower']['sensorValue'], 'IQBox alte Suffixe kompatibel');
    check($iqValues['iqSoc']['sensorEinheit'] === '%', 'IQBox native Modbus-Einheit');
    check($iqValues['iqAlias']['sensorEinheit'] === 'custom', 'IQBox explizite Einheit erhalten');
    check($iqValues['iqEnergy']['sensorEinheit'] === 'Wh', 'IQBox alte Tageswerte in Wh');
    check(is_array($iqValues['iqJson']['sensorValue']) && $iqValues['iqJson']['sensorSource'] === 'iqbox', 'IQBox Sammelsensor und Quelle erhalten');
    check($iqValues['iqNativePower']['sensorValue'] === $iqValues['iqPower']['sensorValue'], 'IQBox nativer Einzelwert und Live-Ansicht stimmen überein');
    foreach (['iqPv' => 'iqPvPrefixed', 'iqBattery' => 'iqBatteryPrefixed', 'iqEnergyPv' => 'iqEnergyPvPrefixed'] as $plain => $prefixed) {
        check(is_array($iqValues[$plain]['sensorValue']) && $iqValues[$plain]['sensorValue'] === $iqValues[$prefixed]['sensorValue'], 'IQBox Gruppe mit und ohne Vorsatz: ' . $plain);
        check($iqValues[$plain]['sensorSource'] === 'iqbox' && $iqValues[$plain]['sensorEinheit'] === '', 'IQBox Gruppenmetadaten erhalten');
    }
    check($iqValues['iqPv']['sensorValue'] === $iqValues['iqJson']['sensorValue']['pv'], 'IQBox Gruppe entspricht dem JSON-Pfad');
    check(count($iqbox->fetchArr([$iqSensor])) === 1 && count($iqFixtures) === 4, 'IQBox neuer Poll-Lauf liest frisch');

    $inverterFixtures = [];
    $inverter = new WechselrichterSensorService(new mysql_dialog(), Logger::getInstance(), new SimpleHttpClient(),
        function (string $host, array $definition) use (&$inverterFixtures): SocFixtureModbusClient {
            check($definition['device'] === 'SAJ HS2/H2', 'Wechselrichter nutzt SAJ-Profil');
            $client = new SocFixtureModbusClient($host, $definition);
            $client->failRequired = $host === 'broken.invalid';
            $inverterFixtures[] = $client;
            return $client;
        }
    );
    $inverterSensor = array_replace($sensor, ['sensorSource' => 'wechselrichterModbus', 'sensorLokalId' => 'soc']);
    check($inverter->supports($inverterSensor), 'Gerät wechselrichterModbus erkannt');
    check($inverter->supports(['sensorSource' => ' WECHSELRICHTERMODBUS ']), 'Geräte-ID case-insensitive');
    foreach (['wechselrichter', 'inverter', 'modbus:saj-h2:wr1'] as $source) {
        check($inverter->supports(['sensorSource' => $source]), 'Bestehende Wechselrichterquelle erkannt: ' . $source);
    }
    check(!$inverter->supports(['sensorSource' => 'iqbox']) && !is_subclass_of(ModbusSnapshotReader::class, 'PbdKn\\cohSensorcollector\\Sensor\\SensorFetcherInterface'), 'Keine doppelte Fetcher-Zuordnung');
    ob_start();
    try {
        $inverterValues = $inverter->fetchArr([
            array_replace($inverterSensor, ['sensorID' => 'soc']),
            array_replace($inverterSensor, ['sensorID' => 'SOC', 'sensorSource' => 'WECHSELRICHTERMODBUS', 'sensorLokalId' => ' SOC ']),
            array_replace($inverterSensor, ['sensorID' => 'path', 'sensorLokalId' => 'MODBUS.BATTERY.SoC']),
            array_replace($inverterSensor, ['sensorID' => 'energy', 'sensorLokalId' => 'ENERGY.PV.TOTAL']),
            array_replace($inverterSensor, ['sensorID' => 'json', 'sensorLokalId' => 'MODBUS']),
            array_replace($inverterSensor, ['sensorID' => 'all', 'sensorLokalId' => ' ALL ']),
            array_replace($inverterSensor, ['sensorID' => 'group', 'sensorLokalId' => 'Battery']),
            array_replace($inverterSensor, ['sensorID' => 'other', 'geraeteUrl' => 'second.invalid', 'sensorEinheit' => 'custom']),
            array_replace($inverterSensor, ['sensorID' => 'broken', 'geraeteUrl' => 'broken.invalid']),
            array_replace($inverterSensor, ['sensorID' => 'missing', 'sensorLokalId' => 'unknown']),
            array_replace($inverterSensor, ['sensorID' => 'empty', 'sensorLokalId' => '']),
        ]);
    } finally {
        ob_end_clean();
    }
    check(count($inverterFixtures) === 3 && count($inverterValues) === 8, 'Wechselrichter gruppiert und isoliert Fehler');
    foreach (['soc', 'SOC', 'path'] as $id) {
        check($inverterValues[$id]['sensorValue'] === 40.8 && $inverterValues[$id]['sensorEinheit'] === '%', 'SOC liefert 40.8 Prozent: ' . $id);
    }
    check($inverterValues['SOC']['sensorSource'] === 'WECHSELRICHTERMODBUS', 'Originalquelle bleibt erhalten');
    check($inverterValues['energy']['sensorEinheit'] === 'kWh', 'Native Wechselrichterpfade case-insensitive');
    check(is_array($inverterValues['json']['sensorValue']) && $inverterValues['group']['sensorValue']['soc'] === 40.8, 'Wechselrichter JSON-Gruppen');
    check($inverterValues['all']['sensorValue'] === $inverterValues['json']['sensorValue'], 'ALL liefert denselben vollständigen JSON-Sammelwert wie modbus');
    check($inverterValues['other']['sensorEinheit'] === 'custom', 'Explizite Einheit erhalten');
    foreach ($inverterFixtures as $fixture) check($fixture->closed && $fixture->snapshots === 1, 'Wechselrichter gibt Verbindungen frei');
    check($inverter->fetch($inverterSensor)['sensorValue'] === 40.8 && count($inverterFixtures) === 4, 'Einzelsensor liest frisch');
    check($inverter->fetchArr([]) === [], 'Leere Sensorliste');

    // Gleiche Rohwerte müssen über beide Geräte-Services dieselben Ansichten liefern.
    $sharedClients = [];
    $sharedFactory = function (string $host, array $definition) use (&$sharedClients): SocFixtureModbusClient {
        $client = new SocFixtureModbusClient($host, $definition);
        $sharedClients[] = $client;
        return $client;
    };
    $sharedIq = new IQBoxSensorService(new mysql_dialog(), Logger::getInstance(), new SimpleHttpClient(), $sharedFactory);
    $sharedWr = new WechselrichterSensorService(new mysql_dialog(), Logger::getInstance(), new SimpleHttpClient(), $sharedFactory);
    $selections = ['live', 'live.power', 'live.batterySoc', 'live.housePower', 'today', 'today.work',
        'today.work.generation', 'today.selfSufficiency.value', 'today.selfConsumption.value',
        'today.saving.energy', 'lifetime', 'lifetime.work', 'lifetime.pvProduction', 'pv-total',
        'pvPower', 'batteryTemperature', 'houseConsumptionToday', 'battery.soc', 'soc', 'all'];
    $requests = array_map(fn (string $selection): array => array_replace($sensor, [
        'sensorID' => $selection, 'sensorLokalId' => $selection, 'sensorSource' => 'iqbox',
    ]), $selections);
    $iqGroups = $sharedIq->fetchArr($requests);
    $wrGroups = $sharedWr->fetchArr(array_map(fn (array $request): array => array_replace($request, [
        'sensorSource' => 'wechselrichterModbus',
    ]), $requests));
    check(count($iqGroups) === count($selections) && count($wrGroups) === count($selections), 'Beide Services liefern alle gemeinsamen Ansichten');
    foreach ($selections as $selection) {
        check($iqGroups[$selection]['sensorValue'] === $wrGroups[$selection]['sensorValue'], 'Identische Ansicht: ' . $selection);
        check($iqGroups[$selection]['sensorEinheit'] === $wrGroups[$selection]['sensorEinheit'], 'Identische Einheit: ' . $selection);
        check($iqGroups[$selection]['sensorSource'] === 'iqbox' && $wrGroups[$selection]['sensorSource'] === 'wechselrichterModbus', 'Gerätequelle bleibt erhalten');
    }
    check(count($sharedClients) === 2, 'Je Service nur ein Snapshot für alle Ansichten');
    foreach ($sharedClients as $client) check($client->closed && $client->snapshots === 1, 'Ansichten verwenden geschlossenen Snapshot');

    $knownData = [
        'pv' => ['power' => 2500], 'house' => ['power' => 1000], 'battery' => ['soc' => 40.8],
        'energy' => [
            'inverter' => ['today' => 4.5], 'pv' => ['today' => 3.0, 'total' => 100.0],
            'house' => ['today' => 4.0],
            'grid' => ['sumSellToday' => 0.8, 'sellToday' => 0.5, 'sumFeedInToday' => 1.0, 'feedInToday' => 0.6],
            'battery' => ['chargeToday' => 0.7, 'dischargeToday' => 0.3, 'chargeTotal' => 10.0, 'dischargeTotal' => 8.0],
        ],
    ];
    $views = new ModbusSensorValues($knownData, $profile);
    check($views->value('today.work.generation') === 4500.0 && $views->unit('today.work.generation') === 'Wh', 'Tagesansicht rechnet kWh nach Wh um');
    check($views->value('energy.pv.total') === 100.0 && $views->unit('energy.pv.total') === 'kWh', 'Native Zähler unverändert');
    check($views->value('lifetime.pvProduction') === 100000.0, 'Gesamtansicht in Wh');
    check($views->value('today.selfSufficiency.value') === 75.0, 'Autarkie aus Verbrauch und Netzbezug');
    check($views->value('today.selfConsumption.value') === 82.22, 'Eigenverbrauch aus Erzeugung und Einspeisung');
    check($views->value('today.saving.energy.ownConsumption') === 3700.0, 'Eigenverbrauch in Wh');
    check($views->value('live.housePower') === -1000.0, 'Bisherige Live-Vorzeichenkonvention');
    check(array_keys($views->value('live')) === ['pvPower', 'housePower', 'gridPower', 'batteryPower', 'heatingRodPower', 'batterySoc', 'inverter', 'grid', 'battery', 'pv', 'house'], 'Live enthält Energieflussfelder und bisherige native Detailgruppen');
    foreach (['saj-h2', 'ampere-storagepro'] as $profileName) {
        $batteryViews = new ModbusSensorValues(['battery' => ['soh' => 97.5, 'temperature' => 34.0]], ModbusProfile::load($profileName));
        $liveBattery = $batteryViews->value('live')['battery'] ?? [];
        check($liveBattery['soh'] === 97.5 && $liveBattery['temperature'] === 34.0, 'Live-Sammelwert erhält SOH und Temperatur: ' . $profileName);
        check($batteryViews->value('live.power')['battery'] === $liveBattery, 'Live-Alias erhält Batteriedetails');
    }
    check($views->value('live.heatingRodPower') === null, 'Nicht verfügbarer Heizstabwert bleibt unbekannt');
    check($views->value('live.battery.soc') === 40.8, 'Alter nativer Detailpfad bleibt trotz kompakter Live-Gruppe lesbar');
    check($views->unit('live') === '' && $views->unit('today.work') === '', 'JSON-Gruppen haben keine skalare Einheit');
    unset($knownData['energy']['grid']['sumSellToday'], $knownData['energy']['grid']['sumFeedInToday']);
    $fallback = new ModbusSensorValues($knownData, $profile);
    check($fallback->value('today.work.gridFeed') === 500.0 && $fallback->value('today.work.gridDraw') === 600.0, 'Optionale Summenzähler verwenden definierte Ersatzwerte');
    $missing = new ModbusSensorValues([], $profile);
    check($missing->value('live.batteryPower') === null && $missing->value('live.housePower') === null, 'Fehlende Live-Werte sind keine Nullmessung');
    check($missing->value('today.work.generation') === null && $missing->value('today.selfSufficiency.value') === null, 'Fehlende Tageswerte bleiben unbekannt');
    check($missing->value('lifetime.work.generation') === null && $missing->value('lifetime.work.gridFeed') === null, 'Fehlende oder unzuverlässige Gesamtwerte bleiben unbekannt');
    $zero = new ModbusSensorValues(['energy' => ['inverter' => ['today' => 0], 'grid' => ['sellToday' => 0]]], $profile);
    check($zero->value('today.work.generation') === 0.0 && $zero->value('today.selfConsumption.value') === null, 'Echte Nullmessung bleibt erhalten, keine Division durch null');

    $customProfile = $profile;
    $customProfile['groups']['testGroup'] = ['value' => ['from' => 'pv.power', 'unit' => 'W']];
    $customProfile['groupDescriptions']['testGroup'] = 'Gruppe aus Testprofil';
    $helpCatalog = ModbusSensorValues::catalog($customProfile);
    check($helpCatalog['testGroup']['kind'] === 'Gruppe' && $helpCatalog['testGroup']['description'] === 'Gruppe aus Testprofil', 'Gruppenhilfe stammt aus dem Profil');
    check($helpCatalog['testGroup.value']['unit'] === 'W', 'Unterwerte werden aus der Gruppendefinition aufgelistet');
    check($helpCatalog['battery.soc']['unit'] === '%', 'Native Unterwerte werden separat aufgelistet');
    foreach (['saj-h2', 'ampere-storagepro'] as $profileName) {
        [$exit, $output, $errors] = runModbusLoop(['--help', $profileName]);
        check($exit === 0 && !str_contains($errors, 'FEHLER:'), 'Loop-Hilfe ohne Gerätezugriff');
        foreach (ModbusProfile::load($profileName)['groupDescriptions'] as $path => $description) {
            check(str_contains($output, $path) && str_contains($output, $description), 'Profilgruppe in CLI-Hilfe: ' . $path);
        }
    }

    foreach (['json-inverter-modbus-loop.php' => 'saj-h2', 'json-solar-iqexport-loop.php' => 'ampere-storagepro'] as $script => $profileName) {
        [$exit, $output, $errors] = runModbusLoop(['--help'], '', $script);
        check($exit === 0 && str_contains($output, 'Profil: ' . ModbusProfile::load($profileName)['device']), 'Richtiges Standardprofil: ' . $script);
        $serverProcess = proc_open([PHP_BINARY, __FILE__, '--server', 'loop'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes);
        check(is_resource($serverProcess), 'Loop-Testserver konnte nicht starten.');
        try {
            fclose($serverPipes[0]);
            stream_set_timeout($serverPipes[1], 5);
            $address = trim((string) fgets($serverPipes[1]));
            check((bool) preg_match('/^127\.0\.0\.1:(\d+)$/D', $address, $match), 'Loop-Testserver-Adresse fehlt.');
            [$exit, $output, $errors] = runModbusLoop(['127.0.0.1', $match[1], '1'],
                "groups today\nlist live.batterySoc\npv\nlive\ntoday\nget live.batterySoc\nlifetime\ninfo live.batterySoc\nquit\n", $script);
            check($exit === 0 && !str_contains($errors, 'FEHLER:'), 'Loop liest Gruppen und Unterwerte ohne Fehler: ' . $script . ': ' . $errors);
            $sections = preg_split('/\n(?:inverter|iqbox)> /', $output);
            $jsonResults = array_slice($sections, 3, 6);
            check(count($jsonResults) === 6, 'Loop gibt sechs JSON-Auswahlen zurück');
            $definition = ModbusProfile::load($profileName);
            $fixture = new FixtureModbusClient('fixture.invalid', $definition);
            $expected = new ModbusSensorValues($fixture->readSnapshot()['data'], $definition);
            foreach (['pv', 'live', 'today', 'live.batterySoc', 'lifetime'] as $index => $selection) {
                $actual = json_decode(trim($jsonResults[$index]), true, 512, JSON_THROW_ON_ERROR);
                check($actual == $expected->value($selection), 'CLI gibt nur die Collector-Auswahl aus: ' . $script . ': ' . $selection);
            }
            $info = json_decode(trim($jsonResults[5]), true, 512, JSON_THROW_ON_ERROR);
            check($info['value'] == $expected->value('live.batterySoc') && $info['unit'] === '%', 'Metadaten nur bei info-Auswahl');
            [$exit, $output, $errors] = runModbusLoop(['127.0.0.1', $match[1], '1', '--once=pv'], '', $script);
            check($exit === 0 && !str_contains($errors, 'FEHLER:'), 'Einmalabfrage funktioniert: ' . $script);
            check(json_decode($output, true, 512, JSON_THROW_ON_ERROR) == $expected->value('pv'), 'Einmalabfrage liefert nur ausgewählten JSON-Wert');
            stream_get_contents($serverPipes[1]);
            stream_get_contents($serverPipes[2]);
        } finally {
            fclose($serverPipes[1]);
            fclose($serverPipes[2]);
            check(proc_close($serverProcess) === 0, 'Loop-Testserver fehlerhaft');
        }
    }

    // Echte Transportprüfung gegen lokalen Kindprozess, einschließlich Fragmentierung.
    foreach (['reuse', 'input', 'exception', 'bad-exception', 'wrong-transaction', 'wrong-protocol', 'wrong-unit', 'oversized', 'wrong-function', 'wrong-count', 'truncated'] as $mode) {
        $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, '--server', $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Testprozess konnte nicht starten.');
        try {
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 5);
            $address = trim((string) fgets($pipes[1]));
            check((bool) preg_match('/^127\.0\.0\.1:(\d+)$/D', $address, $match), 'Lokale Serveradresse fehlt.');
            $tcp = new ModbusTcpClient('127.0.0.1', (int) $match[1], 7, 1.0);
            try {
                if ($mode === 'reuse') {
                    check($tcp->readHoldingRegisters(20, 2) === [20, 21], 'TCP FC03');
                    check($tcp->readHoldingRegisters(30, 1) === [30], 'TCP-Verbindung wiederverwendet');
                } elseif ($mode === 'input') {
                    check($tcp->readInputRegisters(40, 2) === [40, 41], 'TCP FC04');
                } else {
                    $expected = match ($mode) {
                        'exception' => 'Registeradresse nicht erlaubt',
                        'bad-exception' => 'Exception-Antwort',
                        'wrong-transaction', 'wrong-protocol' => 'gehört nicht',
                        'wrong-unit', 'oversized' => 'Unit-ID oder Länge',
                        'wrong-function' => 'Modbus-Funktion',
                        'wrong-count' => 'Datenlänge',
                        'truncated' => 'unvollständig',
                    };
                    fails(fn () => $tcp->readHoldingRegisters(20, 2), $expected);
                }
            } finally {
                $tcp->close();
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            check(!str_contains($stdout . $stderr, 'Fatal error'), 'TCP-Testserverfehler');
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        }
        check($exitCode === 0, "Testserver $mode fehlgeschlagen");
    }
    echo "OK: CSV, Dekodierung, Profile, StoragePro-Kompatibilität, Collector-Gruppierung und 11 lokale TCP-Szenarien.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

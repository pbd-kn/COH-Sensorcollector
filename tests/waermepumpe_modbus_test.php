<?php

declare(strict_types=1);

// Nur Fixtures: keine Datenbank, keine TCP-Verbindung, keine Gerätezugriffe.
$root = is_dir(__DIR__ . '/../sensorcollect') ? __DIR__ . '/../sensorcollect' : __DIR__ . '/../sensorCollect';
require_once $root . '/autoload.php';
require_once $root . '/Modbus/bootstrap.php';
require_once $root . '/Sensor/WaermepumpeSensorServices.php';

use PbdKn\cohSensorcollector\Modbus\ModbusProfile;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotClient;
use PbdKn\cohSensorcollector\Sensor\WaermepumpeSensorServices;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\SimpleHttpClient;

function verify(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$profile = ModbusProfile::load('waermepumpe');
verify($profile['device'] === 'Waermepumpe' && $profile['connection']['unitId'] === 100, 'Gerät/Unit-ID');
verify($profile['controls'] === [], 'Keine ausführbaren Schreibbefehle');
foreach ($profile['fields'] as $path => $field) {
    verify(($field['access'] === 'R/W') === isset($profile['controlCatalog'][$path]), 'R/W-Katalog: ' . $path);
    if ($field['access'] === 'R/W') verify($profile['controlCatalog'][$path]['writable'] === false && $field['writeFunction'] === 16, 'FC16: ' . $path);
}
verify(!isset($profile['fields']['zones.zone5.curveStart'], $profile['fields']['zones.zone8.curveStart']), 'Keine erratenen CP21-Adressen');
verify(ModbusSnapshotClient::decode([0xab80], 0, 'uint8') === 128, 'Unteres Byte UINT8');
verify(ModbusSnapshotClient::decode([0x12ff], 0, 'int8') === -1, 'Vorzeichen INT8');
verify(ModbusSnapshotClient::decode([0xfe0c], 0, 'int16', 0.01) === -5.0, 'Negative Außentemperatur');
verify(ModbusSnapshotClient::decode([1, 2], 0, 'uint32') === 65538, 'Absoluter UINT32-Zähler');

$client = new class('fixture', $profile) extends ModbusSnapshotClient {
    public array $reads = [];
    public function readHoldingRegisters(int $address, int $count): array
    {
        $this->reads[] = $address;
        return match ($address) {
            400 => [3456], 403 => [3500], 409 => [0xab14], 419 => [1, 2],
            default => throw new RuntimeException('Ausstattung nicht vorhanden'),
        };
    }
};
$snapshot = $client->readSnapshot();
verify($client->reads[0] === 400, 'Erster Zugriff auf Pflichtwert');
verify($snapshot['data']['temperature']['flow'] === 34.56, 'Temperaturfaktor');
verify($snapshot['data']['system']['pressure'] === 2.0, 'UINT8 und Druckfaktor');
verify($snapshot['data']['counters']['starts'] === 65538, 'Zähler nicht differenzieren');
verify(!isset($snapshot['data']['energy']), 'Fehlende Ausstattung nicht als Null');

$calls = 0;
$closed = false;
$service = new WaermepumpeSensorServices(new mysql_dialog(), Logger::getInstance(), new SimpleHttpClient(),
    static function (string $host, array $definition) use (&$calls, &$closed, $snapshot) {
        ++$calls;
        verify($host === '192.168.178.99' && $definition['connection']['unitId'] === 100, 'Beispieladresse/Profil');
        return new class($snapshot, $closed) {
            public function __construct(private array $snapshot, public bool &$closed) {}
            public function readSnapshot(): array { return $this->snapshot; }
            public function close(): void { $this->closed = true; }
        };
    });
$sensor = ['sensorID' => '1', 'sensorSource' => 'Waermepumpe', 'geraeteUrl' => '192.168.178.99', 'sensorLokalId' => 'vorlauf', 'sensorEinheit' => '-'];
verify($service->supports($sensor) && !$service->supports(['sensorSource' => 'iqbox']), 'Quellentrennung');
$result = $service->fetchArr([$sensor, array_replace($sensor, ['sensorID' => '2', 'sensorLokalId' => 'modbus.system.pressure'])]);
verify($calls === 1 && $closed, 'Snapshot teilen und Verbindung schließen');
verify($result['1']['sensorValue'] === 35.0 && $result['1']['sensorEinheit'] === '°C', 'Alias/Einheit');
verify($result['2']['sensorValue'] === 2.0 && $result['2']['sensorEinheit'] === 'bar', 'Pfad/Einheit');
echo "OK: Waermepumpe Profil, Rechte, Dekodierung, fehlende Register und Service-Fixtures\n";

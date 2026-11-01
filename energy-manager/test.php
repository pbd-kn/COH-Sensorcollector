<?php

declare(strict_types=1);

require_once __DIR__ . '/EnergyManager.php';
require_once __DIR__ . '/LiveSimulation.php';
require_once dirname(__DIR__) . '/sensorCollect/Sensor/AmpereStorageProModbus.php';

use CohEnergy\EnergyManager;

$config = json_decode(file_get_contents(__DIR__ . '/config.example.json'), true, 512, JSON_THROW_ON_ERROR);
$config['mode'] = 'simulate';
$config['storagePro']['host'] = '127.0.0.1';
$config['storagePro']['gridImportSign'] = 1;
$config['storagePro']['batteryDischargeSign'] = 1;
$config['policy']['consumerPriority'] = ['heater'];
$config['policy']['minimumBatterySocPercent'] = 50;
$config['policy']['gridReserveWatts'] = 100;
$config['heater']['enabled'] = true;
$config['heater']['maximumPowerWatts'] = 3000;
EnergyManager::validate($config);
$sample = ['data' => ['grid' => ['power' => -1000], 'battery' => ['power' => 0, 'soc' => 80]]];
$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
};
$decide = static fn (array $s, float $age, ?float $load): array => EnergyManager::decide($config, $s, $age, $load);
$check($decide($sample, 1, 500)['powerWatts'] === 1400, 'Existing load must be added back');
$check($decide($sample, 21, 500)['powerWatts'] === 0, 'Stale samples');
$check($decide($sample, 1, null)['powerWatts'] === 0, 'Unknown load is not zero');
$sample['data']['battery']['power'] = 1200;
$check($decide($sample, 1, 500)['powerWatts'] === 200, 'Discharge must not finance heating');
$sample['data']['battery']['soc'] = 40;
$check($decide($sample, 1, 500)['powerWatts'] === 0, 'SOC reserve');
$config['mode'] = 'active';
try {
    EnergyManager::validate($config);
    throw new RuntimeException('Active mode accepted');
} catch (InvalidArgumentException) {
}
echo "OK: Bilanz, bestehende Last, fehlende/veraltete Werte, Batterie und Schreibsperre.\n";

$fake = new class {
    public bool $closed = false;
    public bool $fail = false;
    public array $requests = [];
    public function readHoldingRegisters(int $address, int $count): array
    {
        $this->requests[] = [$address, $count];
        $values = array_fill(0, 14, 0);
        $values[0] = 2000;
        $values[5] = 4000;
        $values[6] = 65536 - 500;
        $values[13] = 65536 - 1500;
        return $values;
    }
    public function readValue(string $name): float
    {
        $this->requests[] = $name;
        if ($this->fail) {
            throw new RuntimeException('Simulated timeout');
        }
        return 85.5;
    }
    public function close(): void { $this->closed = true; }
};
$live = \CohEnergy\LiveSimulation::read($fake);
$check($fake->closed, 'Connection must be closed before returning snapshot');
$check($fake->requests === [[0x40A0, 14], 'battery.soc'], 'Only required read requests');
$check($live['data']['grid']['power'] === -1500 && $live['data']['battery']['power'] === -500, 'Signed register decoding');
$check($live['data']['pv']['power'] === 4000 && $live['data']['house']['power'] === 2000, 'Register offsets');
$fake->fail = true;
$fake->closed = false;
try {
    \CohEnergy\LiveSimulation::read($fake);
    throw new LogicException('Expected simulated timeout');
} catch (RuntimeException) {
    $check($fake->closed, 'Connection must also close after read failure');
}
echo "OK: Live-Lesebloecke, Vorzeichendekodierung, Verbindungsschluss bei Erfolg und Fehler.\n";

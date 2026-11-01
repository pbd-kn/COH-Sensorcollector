<?php

declare(strict_types=1);

use CohEnergy\EnergyManager;
use CohEnergy\LiveSimulation;

require_once __DIR__ . '/EnergyManager.php';
require_once __DIR__ . '/LiveSimulation.php';
require_once dirname(__DIR__) . '/sensorCollect/Sensor/AmpereStorageProModbus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$options = getopt('', ['host:', 'port:', 'unit:', 'timeout:', 'once', 'loop', 'count:', 'interval:',
    'heater-watts:', 'grid-import-sign:', 'battery-discharge-sign:', 'min-soc:', 'reserve:', 'max-heater:', 'help']);
if (isset($options['help']) || $options === []) {
    echo <<<'HELP'
Lesender Live-Test, ohne DB und ohne Schaltbefehle.
php live-test.php --host=192.168.178.30
php live-test.php --host=192.168.178.30 --loop --interval=30
php live-test.php --host=192.168.178.30 --count=5 --interval=30

Standard: ein Lauf; --once explizit moeglich. --loop: bis Strg+C.
--count=N: N Versuche (inklusive Fehler), nicht zusammen mit --once/--loop.
--interval=30: Pause NACH geschlossener Verbindung (mindestens 5 Sekunden).
--port=502 --unit=1 --timeout=3

Optionale Simulation (Vorzeichen vorher am Geraet bestaetigen):
--grid-import-sign=1       +1: positiver Rohwert = Bezug; -1: umgekehrt
--battery-discharge-sign=1 +1: positiver Rohwert = Entladen; -1: umgekehrt
--heater-watts=0           Angenommene aktuelle Heizlast, KEIN Messwert!
--min-soc=50 --reserve=100 --max-heater=3000
Ohne beide Vorzeichen nur Rohwerte. Ohne Heizlastannahme kein Sollwert.
Diese Vorgaben sind Testparameter, keine bestaetigten Anlagenwerte.
Modbus-Verbindung schliesst nach zwei Leseanfragen, auch bei Fehlern.

HELP;
    exit(0);
}
$lock = null;
try {
    $number = static function (string $key, float $default, float $min, float $max) use ($options): float {
        $raw = $options[$key] ?? $default;
        if (is_array($raw) || !is_numeric($raw)) {
            throw new InvalidArgumentException('--' . $key . ' braucht eine Zahl.');
        }
        $value = (float) $raw;
        EnergyManager::number($value, '--' . $key, $min, $max);
        return $value;
    };
    $integer = static function (string $key, int $default, int $min, int $max) use ($number): int {
        $value = $number($key, $default, $min, $max);
        if (floor($value) !== $value) {
            throw new InvalidArgumentException('--' . $key . ' muss ganzzahlig sein.');
        }
        return (int) $value;
    };
    if (count(array_intersect(['once', 'loop', 'count'], array_keys($options))) > 1) {
        throw new InvalidArgumentException('--once, --loop und --count schliessen sich aus.');
    }
    $config = json_decode((string) file_get_contents(__DIR__ . '/config.example.json'), true, 512, JSON_THROW_ON_ERROR);
    $config['storagePro']['host'] = $options['host'] ?? null;
    $config['storagePro']['port'] = $integer('port', 502, 1, 65535);
    $config['storagePro']['unitId'] = $integer('unit', 1, 0, 255);
    $config['storagePro']['timeoutSeconds'] = $number('timeout', 3, 0.1, 30);
    $interval = $number('interval', 30, 5, 3600);
    $count = isset($options['loop']) ? PHP_INT_MAX : $integer('count', 1, 1, 1000000);
    $config['heater']['maximumPowerWatts'] = $number('max-heater', 3000, 0, 6500);
    $config['policy']['minimumBatterySocPercent'] = $number('min-soc', 50, 0, 100);
    $config['policy']['gridReserveWatts'] = $number('reserve', 100, 0, 100000);
    $heater = isset($options['heater-watts']) ? $number('heater-watts', 0, 0, $config['heater']['maximumPowerWatts']) : null;
    foreach (['grid-import-sign' => 'gridImportSign', 'battery-discharge-sign' => 'batteryDischargeSign'] as $option => $key) {
        if (isset($options[$option])) {
            $sign = $integer($option, 1, -1, 1);
            if ($sign === 0) {
                throw new InvalidArgumentException('--' . $option . ' muss -1 oder 1 sein.');
            }
            $config['storagePro'][$key] = $sign;
        }
    }
    if ($config['storagePro']['gridImportSign'] !== null && $config['storagePro']['batteryDischargeSign'] !== null) {
        $config['mode'] = 'simulate';
        $config['heater']['enabled'] = true;
        $config['policy']['consumerPriority'] = ['heater'];
    }
    EnergyManager::validate($config);
    date_default_timezone_set('Europe/Berlin');
    $lock = fopen(sys_get_temp_dir() . '/coh-energy-manager.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Ein Energiemanager/Live-Test laeuft bereits oder Sperre nicht verfuegbar.');
    }
    $running = true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $stop = static function () use (&$running): void { $running = false; };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
    echo "Nur Lesen/Simulation, keine DB, keine Schaltbefehle. Abbruch: Strg+C.\n";
    echo "Testparameter: SOC >= {$config['policy']['minimumBatterySocPercent']} %, Reserve {$config['policy']['gridReserveWatts']} W, Maximum {$config['heater']['maximumPowerWatts']} W\n";
    $failed = false;
    for ($round = 1; $running && $round <= $count; ++$round) {
        $device = $config['storagePro'];
        $client = new AmpereStorageProModbus($device['host'], $device['port'], $device['unitId'], $device['timeoutSeconds']);
        $start = microtime(true);
        try {
            $snapshot = LiveSimulation::read($client);
            $age = microtime(true) - $start;
            echo "\n" . date('d.m.Y H:i:s T') . " | Lauf $round | Verbindung geschlossen | " . round($age, 2) . " s\n";
            $data = $snapshot['data'];
            printf("PV: %s W | Haus: %s W | Netz roh: %s W | Batterie roh: %s W | SOC: %s %%\n",
                $data['pv']['power'], $data['house']['power'], $data['grid']['power'], $data['battery']['power'], $data['battery']['soc']);
            if ($config['mode'] === 'simulate') {
                $grid = $data['grid']['power'] * $device['gridImportSign'];
                $battery = $data['battery']['power'] * $device['batteryDischargeSign'];
                printf("Nach gewaehlten Vorzeichen: Netzbezug %s W | Einspeisung %s W | Batterieentladung %s W\n", max(0, $grid), max(0, -$grid), max(0, $battery));
                echo 'Heizlast-ANNAHME: ' . ($heater === null ? 'unbekannt' : $heater . ' W (konstant, nicht gemessen)') . "\n";
                $decision = EnergyManager::decide($config, $snapshot, $age, $heater);
                echo "Simulierter Sollwert: {$decision['powerWatts']} W | {$decision['reason']}\n";
            } else {
                echo "Nur Anzeige: Fuer Berechnung beide Vorzeichenparameter angeben.\n";
            }
        } catch (Throwable $error) {
            $failed = true;
            fwrite(STDERR, date('H:i:s') . " Lauf $round fehlgeschlagen (" . get_class($error) . "); Verbindung geschlossen, kein Befehl.\n");
        } finally {
            $client->close();
        }
        if ($round < $count && $running) {
            echo "Pause: $interval s, keine Modbus-Verbindung offen.\n";
            $deadline = microtime(true) + $interval;
            while ($running && microtime(true) < $deadline) {
                usleep(100000);
            }
        }
    }
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Abbruch: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/Km271/Km271Decoder.php';
$collector = is_dir(__DIR__ . '/../sensorCollect') ? 'sensorCollect' : 'sensorcollect';
require_once __DIR__ . '/../' . $collector . '/Sensor/Km271/Km271Decoder.php';
require_once __DIR__ . '/../' . $collector . '/Sensor/SensorFetcherInterface.php';
require_once __DIR__ . '/../' . $collector . '/Sensor/BuderusKm271SensorService.php';

foreach ([
    PbdKn\CohSensorcollector\Km271\Km271Decoder::class,
    PbdKn\cohSensorcollector\Sensor\Km271\Km271Decoder::class,
] as $class) {
    $decoder = new $class();
    foreach ([0x8001 => 'hc1SummerMode', 0x8113 => 'hc2SummerMode'] as $register => $key) {
        foreach ([0x00 => 0, 0x01 => 1, 0x02 => 0, 0x03 => 1, 0xFE => 0, 0xFF => 1] as $raw => $expected) {
            $updated = $decoder->consume(pack('CCC', $register >> 8, $register & 0xFF, $raw));
            if (($updated[$key]['value'] ?? null) !== $expected) {
                throw new RuntimeException("$class: falscher Sommerstatus fuer $key, Rohwert $raw");
            }
        }
    }
    $values = $decoder->values();
    $reflection = new ReflectionClass(PbdKn\cohSensorcollector\Sensor\BuderusKm271SensorService::class);
    $exported = $reflection->getConstant('EXPORTED_KEYS');
    foreach (['hc1SummerMode', 'hc2SummerMode'] as $key) {
        if (!in_array($key, $exported, true)) {
            throw new RuntimeException("$key fehlt im Collector-Export");
        }
    }
    $service = $reflection->newInstanceWithoutConstructor();
    $document = $reflection->getMethod('buildDocument')->invoke($service, $values);
    foreach (['HK1_Sommerbetrieb', 'HK2_Sommerbetrieb'] as $key) {
        if (($document['KM271'][$key]['Wert'] ?? null) !== 1) {
            throw new RuntimeException("$key fehlt im JSON-Export");
        }
    }
}
echo "OK: Sommerstatus beider Heizkreise, Bitmaskierung und Collector-Export.\n";

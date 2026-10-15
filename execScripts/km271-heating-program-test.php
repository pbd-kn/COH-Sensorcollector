<?php
declare(strict_types=1);

require_once __DIR__ . '/Km271/Km271Decoder.php';
require_once __DIR__ . '/../sensorCollect/Sensor/Km271/Km271Decoder.php';
require_once __DIR__ . '/../sensorCollect/Sensor/SensorFetcherInterface.php';
require_once __DIR__ . '/../sensorCollect/Sensor/BuderusKm271SensorService.php';

foreach ([
    PbdKn\CohSensorcollector\Km271\Km271Decoder::class,
    PbdKn\cohSensorcollector\Sensor\Km271\Km271Decoder::class,
] as $class) {
    $decoder = new $class();
    if ($decoder->consume("\x01\x69") !== []) {
        throw new RuntimeException("$class: unvollständiges Telegramm akzeptiert");
    }
    $decoder->consume("\x01\x00\x01\x65\x65\x00\x65\x65");
    $decoder->consume("\x01\x69\x00");
    $decoder->consume("\x00\x38\x00\x0D\x20\x28\x02");
    $decoder->consume("\x00\x00\x00\x0D\x1E\x26\x02\x1E");
    $values = $decoder->values();
    if ($values['hc1HeatingProgram']['value'] !== 'Familie' || $values['hc2HeatingProgram']['value'] !== 'Eigen') {
        throw new RuntimeException("$class: HK1/HK2-Programme falsch zugeordnet");
    }
    $reflection = new ReflectionClass(PbdKn\cohSensorcollector\Sensor\BuderusKm271SensorService::class);
    $exported = array_intersect_key($values, array_flip($reflection->getConstant('EXPORTED_KEYS')));
    $document = $reflection->getMethod('buildDocument')->invoke($reflection->newInstanceWithoutConstructor(), $exported);
    if (($document['KM271']['HK2_Heizprogramm']['Wert'] ?? null) !== 'Eigen') {
        throw new RuntimeException("$class: HK2 Heizprogramm fehlt im JSON-Export");
    }
    foreach (['HK2_Nachttemperatur' => 16, 'HK2_Tagtemperatur' => 20, 'HK2_Betriebsart' => 'Automatik'] as $key => $expected) {
        if (($document['KM271'][$key]['Wert'] ?? null) != $expected) {
            throw new RuntimeException("$class: falscher HK2-Konfigurationswert $key");
        }
    }
    if (($document['KM271']['Sommergrenze']['Wert'] ?? null) !== 13 || isset($document['KM271']['HK1_Sommergrenze'])) {
        throw new RuntimeException("$class: Sommergrenze unter falschem JSON-Schlüssel");
    }
    $exported['hc1SummerThreshold']['label'] = 'HK1 Sommergrenze';
    $legacy = $reflection->getMethod('buildDocument')->invoke($reflection->newInstanceWithoutConstructor(), $exported);
    if (($legacy['KM271']['Sommergrenze']['Name'] ?? null) !== 'Sommergrenze') {
        throw new RuntimeException("$class: alter Cache nicht normalisiert");
    }
}
echo "OK: Beide Decoder, getrennte HK1/HK2-Programme und HK2-JSON-Export.\n";

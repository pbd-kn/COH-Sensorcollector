<?php
declare(strict_types=1);

$autoload = is_file(__DIR__ . '/../sensorcollect/autoload.php')
    ? __DIR__ . '/../sensorcollect/autoload.php'
    : __DIR__ . '/../sensorCollect/autoload.php';
require_once $autoload;

use PbdKn\cohSensorcollector\Sensor\Km271\Km271WriteCommandEncoder;

$encoder = new Km271WriteCommandEncoder();
$cases = [
    ['HK1_Sommergrenze', 14, '07 00 65 0E 65 65 65 65'],
    ['HK1_Sommergrenze', 'Sommer', '07 00 65 09 65 65 65 65'],
    ['HK1_Betriebsart', 'Automatik', '07 00 65 65 65 65 02 65'],
    ['HK1_Tagtemperatur', '20,5', '07 00 65 65 65 29 65 65'],
    ['HK1_Nachttemperatur', 15, '07 00 65 65 1E 65 65 65'],
    ['HK1_Urlaubstemperatur', 17, '07 00 65 65 65 65 65 22'],
    ['HK1_Frostschutz_ab', -5, '07 31 65 65 65 65 65 FB'],
    ['HK1_Auslegungstemperatur', 75, '07 0E 65 65 65 65 4B 65'],
    ['HK1_Heizprogramm', 'Familie', '11 00 01 65 65 65 65 65'],
    ['HK1_Ferientage', 12, '11 00 65 65 65 0C 65 65'],
    ['Warmwasser_Betriebsart', 'Automatik', '0C 0E 02 65 65 65 65 65'],
    ['Warmwasser_eingestellte_Temperatur', 48, '0C 07 65 65 65 30 65 65'],
    ['Warmwasser_Zirkulation_Einstellung', '4 ×/h', '0C 0E 65 65 65 65 65 04'],
];

foreach ($cases as [$id, $value, $expected]) {
    $actual = $encoder->encode($id, $value)['Hex'];
    if ($actual !== $expected) {
        throw new RuntimeException("$id: erwartet $expected, erhalten $actual");
    }
}

$rejected = false;
try {
    $encoder->encode('HK1_Sommergrenze', 50);
} catch (InvalidArgumentException) {
    $rejected = true;
}
if (!$rejected) {
    throw new RuntimeException('Ungueltige Sommergrenze wurde nicht abgelehnt.');
}

echo 'OK: ' . count($cases) . " KM271-Schreibcodierungen und Wertepruefung erfolgreich.\n";

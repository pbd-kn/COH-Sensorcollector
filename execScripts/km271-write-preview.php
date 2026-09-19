<?php
declare(strict_types=1);

$autoload = is_file(__DIR__ . '/../sensorcollect/autoload.php')
    ? __DIR__ . '/../sensorcollect/autoload.php'
    : __DIR__ . '/../sensorCollect/autoload.php';
require_once $autoload;

use PbdKn\cohSensorcollector\Sensor\Km271\Km271WriteCommandEncoder;

$encoder = new Km271WriteCommandEncoder();
$localId = trim((string) ($argv[1] ?? ''));
$value = $argv[2] ?? null;

if ($localId === '' || $value === null) {
    echo "KM271-Schreibvorschau (sendet nichts an die Heizung)\n\n";
    echo "Aufruf:\n  php km271-write-preview.php LOKALE_ID WERT\n\n";
    echo "Moegliche Schreibauftraege:\n";
    foreach ($encoder->catalog() as $id => $definition) {
        echo '  ' . str_pad($id, 43) . $definition['Bezeichnung'] . "\n";
    }
    exit(0);
}

try {
    $preview = $encoder->encode($localId, $value);
    echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "\nNICHT GESENDET: Dies ist nur die validierte Codierungsvorschau.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

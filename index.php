
<?php

require_once 'FetcherRegistry.php';
require_once 'SensorManager.php';

// Autoloader für Sensor-Klassen
spl_autoload_register(function ($class) {
    $class = str_replace('Sensor\\', '', $class);
    $file = __DIR__ . '/Sensor/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Sensor\SensorFetcherInterface;

// Registry vorbereiten
$registry = new FetcherRegistry();

// Alle Sensor-Klassen im direktory Sensor laden
foreach (glob(__DIR__ . '/Sensor/*.php') as $file) {
    require_once $file;
}

// Alle geladenen Klassen nach passenden Fetchern durchsuchen
foreach (get_declared_classes() as $class) {
    if (is_subclass_of($class, SensorFetcherInterface::class)) {
        $fetcher = new $class();
        $registry->registerFetcher('sensor.fetcher', $fetcher);
    }
}

// SensorManager verwenden
$fetchers = $registry->getFetchersByTag('sensor.fetcher');
$manager = new SensorManager($fetchers);
$result = $manager->fetchAll(['sensor1', 'sensor2']);

echo "<pre>";
print_r($result);
echo "</pre>";

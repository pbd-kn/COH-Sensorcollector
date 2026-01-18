<?php
// COH-Sensorcollector/autoload.php

spl_autoload_register(function ($class) {
    // Nur Klassen aus unserem Namespace
    $prefix = 'PbdKn\\cohSensorcollector\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Relativer Pfad zur Klasse
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    } else {
        echo "Nicht gefunden: $file\n"; // Debug-Ausgabe bei Problemen
    }
});

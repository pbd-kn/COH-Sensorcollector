<?php

declare(strict_types=1);

// Linux-Installation, Checkout und optional eigenstaendige API-Installation.
foreach ([
    __DIR__ . '/Modbus/AmpereStorageProModbus.php',
    __DIR__ . '/../sensorcollect/Modbus/AmpereStorageProModbus.php',
    __DIR__ . '/../sensorCollect/Modbus/AmpereStorageProModbus.php',
    dirname(__DIR__, 5) . '/sensorCollect/Modbus/AmpereStorageProModbus.php',
    '/home/peter/scripts/coh/sensorcollect/Modbus/AmpereStorageProModbus.php',
    '/home/peter/coh/sensorCollect/Modbus/AmpereStorageProModbus.php',
] as $classFile) {
    if (is_file($classFile)) {
        require_once $classFile;
        return;
    }
}
throw new RuntimeException('Gemeinsamer Modbus-Leser fehlt: sensorcollect/Modbus installieren.');

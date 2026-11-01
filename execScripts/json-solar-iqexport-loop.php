<?php

declare(strict_types=1);

// Direkter IQBox-Modbus-Test, dieselben Gruppen und Befehle wie beim Wechselrichter.
// Aufruf: php json-solar-iqexport-loop.php [HOST] [PORT] [UNIT-ID]
require_once __DIR__ . '/ModbusLoop.php';
$host = 'ASP-HSR2103J2311E08738.local';
$paramsFile = __DIR__ . '/task_heizstab_params.json';
if (is_file($paramsFile)) {
    $parameters = json_decode((string) file_get_contents($paramsFile), true);
    $host = trim((string) ($parameters['iqBoxModbus']['host'] ?? $host));
}
exit(modbusLoopRun($argv, $host, 'ampere-storagepro', 'iqbox'));

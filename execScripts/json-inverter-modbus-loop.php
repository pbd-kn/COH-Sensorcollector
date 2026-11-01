<?php

declare(strict_types=1);

// Gemeinsame Konsole, Standardprofil für den Wechselrichter.
require_once __DIR__ . '/ModbusLoop.php';
exit(modbusLoopRun($argv, '192.168.178.30', 'saj-h2', 'inverter'));

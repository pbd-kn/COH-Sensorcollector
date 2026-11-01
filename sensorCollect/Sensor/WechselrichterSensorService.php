<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotReader;

require_once __DIR__ . '/../Modbus/bootstrap.php';

/** SAJ-Wechselrichter für die Geräte-ID wechselrichterModbus. */
final class WechselrichterSensorService implements SensorFetcherInterface
{
    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        mysql_dialog $connection,
        private Logger $logger,
        SimpleHttpClient $httpClient,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory;
    }

    public function supports($sensor): bool
    {
        $source = strtolower(trim((string) ($sensor['sensorSource'] ?? '')));
        return in_array($source, ['wechselrichtermodbus', 'wechselrichter', 'inverter'], true)
            || preg_match('/^modbus:saj-h2(?::[a-z0-9_-]+)?$/D', $source) === 1;
    }

    public function fetch($sensor): ?array
    {
        return $this->fetchArr([$sensor])[(string) ($sensor['sensorID'] ?? '')] ?? null;
    }

    public function fetchArr(array $sensors): ?array
    {
        $sensors = array_filter($sensors, fn (array $sensor): bool => $this->supports($sensor));
        $reader = new ModbusSnapshotReader($this->logger, $this->clientFactory);
        return $reader->readSensors($sensors, 'saj-h2');
    }
}

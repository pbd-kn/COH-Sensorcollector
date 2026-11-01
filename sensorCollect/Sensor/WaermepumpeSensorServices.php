<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotClient;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotReader;

require_once __DIR__ . '/../Modbus/bootstrap.php';

/** GTW-08 hinter einem Modbus-TCP/RTU-Gateway; keine Schreibzugriffe. */
final class WaermepumpeSensorServices implements SensorFetcherInterface
{
    private $clientFactory;

    public function __construct(
        mysql_dialog $connection,
        private Logger $logger,
        SimpleHttpClient $httpClient,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory ?? static fn (string $host, array $profile) =>
            new ModbusSnapshotClient($host, $profile, 502, $profile['connection']['unitId'], 3.0);
    }

    public function supports($sensor): bool
    {
        return strtolower(trim((string) ($sensor['sensorSource'] ?? ''))) === 'waermepumpe';
    }

    public function fetch($sensor): ?array
    {
        return $this->fetchArr([$sensor])[(string) ($sensor['sensorID'] ?? '')] ?? null;
    }

    public function fetchArr(array $sensors): ?array
    {
        $sensors = array_filter($sensors, fn (array $sensor): bool => $this->supports($sensor));
        return (new ModbusSnapshotReader($this->logger, $this->clientFactory))->readSensors($sensors, 'waermepumpe');
    }
}

<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotReader;

require_once __DIR__ . '/../Modbus/bootstrap.php';

final class IQBoxSensorService implements SensorFetcherInterface
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
        return strtolower(trim((string) ($sensor['sensorSource'] ?? ''))) === 'iqbox';
    }

    public function fetch($sensor): ?array
    {
        return $this->fetchArr([$sensor])[(string) ($sensor['sensorID'] ?? '')] ?? null;
    }

    public function fetchArr(array $sensors, ?string $date = null, array $fetchedValues = []): ?array
    {
        $sensors = array_filter($sensors, fn (array $sensor): bool => $this->supports($sensor));
        $reader = new ModbusSnapshotReader($this->logger, $this->clientFactory);
        return $reader->readSensors($sensors, 'ampere-storagepro');
    }
}

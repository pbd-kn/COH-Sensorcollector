<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Modbus;

use PbdKn\cohSensorcollector\Logger;

require_once __DIR__ . '/bootstrap.php';

/** Ein Snapshot je Verbindung und Profil pro Poll-Lauf. */
final class ModbusSnapshotReader
{
    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        private Logger $logger,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory;
    }

    public function readSensors(array $sensors, string $profileName): array
    {
        $profile = ModbusProfile::load($profileName);
        $groups = [];
        $result = [];
        foreach ($sensors as $sensor) {
            try {
                $host = strtolower(trim((string) ($sensor['geraeteUrl'] ?? '')));
                // HTTP-Adressen aus dem Backend liefern nur den Gerätehost.
                // Modbus verwendet weiterhin Port 502, Unit-ID 1 und 3 s Timeout.
                if (preg_match('~^https?://~', $host)) {
                    $host = trim((string) parse_url($host, PHP_URL_HOST), '[]');
                }
                if (!filter_var($host, FILTER_VALIDATE_IP)
                    && !preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/D', $host)) {
                    throw new \InvalidArgumentException('Geräteadresse muss eine IP-Adresse oder ein Hostname sein.');
                }
                $groups[$host][] = $sensor;
            } catch (\Throwable $error) {
                $this->logger->Error('ModbusSnapshotReader: Sensor ' . ($sensor['sensorID'] ?? '') . ': ' . $error->getMessage());
            }
        }

        foreach ($groups as $host => $group) {
            try {
                $client = $this->clientFactory !== null ? ($this->clientFactory)($host, $profile) : new ModbusSnapshotClient($host, $profile);
                try {
                    $snapshot = $client->readSnapshot();
                } finally {
                    // Vor Gruppenbildung und DB-Verarbeitung die Verbindung freigeben.
                    $client->close();
                }
                $values = new ModbusSensorValues($snapshot['data'], $profile);
            } catch (\Throwable $error) {
                $this->logger->Error(sprintf('ModbusSnapshotReader: %s, Profil %s: %s',
                    $host, $profileName, $error->getMessage()));
                continue;
            }

            foreach ($group as $sensor) {
                $sensorId = (string) ($sensor['sensorID'] ?? '');
                try {
                    $path = trim((string) ($sensor['sensorLokalId'] ?? ''));
                    if ($path === '') {
                        throw new \InvalidArgumentException('sensorLokalId fehlt.');
                    }
                    $value = $values->value($path);
                    $unit = trim((string) ($sensor['sensorEinheit'] ?? ''));
                    if ($unit === '' || $unit === '-') {
                        $unit = $values->unit($path);
                    }
                    $result[$sensorId] = [
                        'sensorID' => $sensorId,
                        'sensorValue' => $value,
                        'sensorEinheit' => $unit,
                        'sensorValueType' => (string) ($sensor['sensorValueType'] ?? ''),
                        'sensorSource' => (string) ($sensor['sensorSource'] ?? ''),
                    ];
                } catch (\Throwable $error) {
                    $this->logger->Error("ModbusSnapshotReader: Sensor $sensorId: " . $error->getMessage());
                }
            }
        }
        return $result;
    }

}

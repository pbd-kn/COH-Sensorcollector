<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Kompatibler Einstieg für vorhandene StoragePro-Aufrufer. */
final class AmpereStorageProModbus
{
    private \PbdKn\cohSensorcollector\Modbus\ModbusSnapshotClient $client;

    public function __construct(string $host, int $port = 502, int $unitId = 1, float $timeout = 3.0)
    {
        $this->client = new \PbdKn\cohSensorcollector\Modbus\ModbusSnapshotClient(
            $host, \PbdKn\cohSensorcollector\Modbus\ModbusProfile::load('ampere-storagepro'), $port, $unitId, $timeout
        );
    }

    public function open(): void { $this->client->open(); }
    public function close(): void { $this->client->close(); }
    public function readValue(string $name): int|float { return $this->client->readValue($name); }
    public function readSnapshot(): array { return $this->client->readSnapshot(); }
    public function readHoldingRegisters(int $address, int $count): array { return $this->client->readHoldingRegisters($address, $count); }

    public static function catalog(): array
    {
        $catalog = \PbdKn\cohSensorcollector\Modbus\ModbusProfile::load('ampere-storagepro')['fields'];
        foreach ($catalog as &$field) {
            unset($field['function'], $field['wordOrder']);
            $field['addressHex'] = sprintf('0x%04X', $field['address']);
        }
        return $catalog;
    }

    public static function decode(array $registers, int $offset, string $type, float $scale = 1.0): int|float
    {
        return \PbdKn\cohSensorcollector\Modbus\ModbusSnapshotClient::decode($registers, $offset, $type, $scale);
    }
}

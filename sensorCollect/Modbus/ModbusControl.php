<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Modbus;

/** Freigaben kommen ausschließlich aus dem installierten Geräteprofil. */
final class ModbusControl
{
    public function __construct(private ModbusTcpClient $client, private array $profile) {}

    public function read(string $setting): array
    {
        $definition = $this->profile['controls'][$setting] ?? null;
        if (!is_array($definition)) {
            throw new \InvalidArgumentException('Einstellung ist nicht zum Schreiben freigegeben.');
        }
        $current = $this->client->readHoldingRegisters($definition['address'], 1)[0];
        return $definition + ['value' => $current];
    }

    public function execute(string $setting, int $value, int $expected): array
    {
        $definition = $this->read($setting);
        if (!array_key_exists($value, $definition['options'])) {
            throw new \InvalidArgumentException('Dieser Auswahlwert ist nicht erlaubt.');
        }
        if ($definition['value'] !== $expected) {
            throw new \RuntimeException('Konflikt: Der Ausgangswert hat sich geändert. Bitte erneut prüfen.');
        }
        if (isset($definition['requires'])) {
            $requirement = $definition['requires'];
            if ($this->client->readHoldingRegisters($requirement['address'], 1)[0] !== $requirement['value']) {
                throw new \RuntimeException($requirement['message']);
            }
        }
        if ($value !== $expected) {
            $this->client->writeSingleRegister($definition['address'], $value);
        }
        $actual = $this->client->readHoldingRegisters($definition['address'], 1)[0];
        if ($actual !== $value) {
            throw new \RuntimeException('Rücklesen bestätigt den Zielwert nicht. Keine automatische Wiederholung; aktuellen Zustand neu prüfen.');
        }
        return ['previous' => $expected, 'value' => $actual, 'verified' => true];
    }
}

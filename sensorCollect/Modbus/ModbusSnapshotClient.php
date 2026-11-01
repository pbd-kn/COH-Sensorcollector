<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Modbus;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ModbusSnapshotClient extends ModbusTcpClient
{
    private array $profile;

    public function __construct(string $host, array $profile, int $port = 502, int $unitId = 1, float $timeout = 3.0)
    {
        $this->profile = ModbusProfile::validate($profile);
        parent::__construct($host, $port, $unitId, $timeout);
    }

    public function catalog(): array
    {
        $catalog = $this->profile['fields'];
        foreach ($catalog as &$field) {
            $field['addressHex'] = sprintf('0x%04X', $field['address']);
        }
        return $catalog;
    }

    public function readValue(string $name): int|float
    {
        $field = $this->profile['fields'][$name] ?? null;
        if ($field === null) {
            throw new InvalidArgumentException("Unbekannter Modbus-Wert '$name'.");
        }
        return self::decode(
            $this->readFieldRegisters($field['address'], ModbusProfile::registerCount($field['type']), $field['function']),
            0, $field['type'], (float) $field['scale'], $field['wordOrder']
        );
    }

    public function readSnapshot(): array
    {
        $raw = [];
        foreach ($this->profile['blocks'] as [$address, $count, $function]) {
            foreach ($this->readFieldRegisters($address, $count, $function) as $offset => $value) {
                $raw[$function][$address + $offset] = $value;
            }
        }
        $values = [];
        foreach ($this->profile['fields'] as $name => $field) {
            try {
                $registers = [];
                for ($offset = 0; $offset < ModbusProfile::registerCount($field['type']); ++$offset) {
                    if (!isset($raw[$field['function']][$field['address'] + $offset])) {
                        $registers = [];
                        break;
                    }
                    $registers[] = $raw[$field['function']][$field['address'] + $offset];
                }
                // Ohne Leseblock nur die definierten Register abfragen; keine
                // unbekannten Lücken automatisch zu großen Blöcken verbinden.
                $values[$name] = $registers === [] ? $this->readValue($name) : self::decode(
                    $registers, 0, $field['type'], (float) $field['scale'], $field['wordOrder']
                );
            } catch (Throwable $error) {
                if (!in_array($name, $this->profile['optional'], true)) {
                    throw $error;
                }
                // Firmwareabhängige Felder fehlen bei Lesefehlern im Snapshot.
            }
        }
        foreach ($this->profile['copies'] as $target => $source) {
            if (array_key_exists($source, $values)) {
                $values[$target] = $values[$source];
            }
        }
        $nested = [];
        foreach ($values as $path => $value) {
            self::setPath($nested, $path, $value);
        }
        $aliases = [];
        foreach ($this->profile['aliases'] as $alias => $path) {
            $aliases[$alias] = $values[$path] ?? null;
        }
        return [
            'device' => $this->profile['device'],
            'host' => $this->host, 'port' => $this->port, 'unitId' => $this->unitId,
            'timestamp' => date(DATE_ATOM), 'data' => $nested, 'aliases' => $aliases,
        ];
    }

    public static function decode(array $registers, int $offset, string $type, float $scale = 1.0, string $wordOrder = 'big'): int|float
    {
        $count = ModbusProfile::registerCount($type);
        if (!in_array($wordOrder, ['big', 'little'], true) || !is_finite($scale)) {
            throw new InvalidArgumentException('Ungültige Modbus-Dekodierung.');
        }
        $words = [];
        for ($i = 0; $i < $count; ++$i) {
            $word = $registers[$offset + $i] ?? null;
            if (!is_int($word) || $word < 0 || $word > 65535) {
                throw new InvalidArgumentException('Register für Dekodierung fehlt oder ist ungültig.');
            }
            $words[] = $word;
        }
        if ($wordOrder === 'little') {
            $words = array_reverse($words);
        }
        $value = $words[0];
        if ($count === 2) {
            $value = $words[0] * 65536 + $words[1];
        }
        if ($type === 'uint8' || $type === 'int8') {
            // GTW-08: 8-Bit-Daten stehen ausschließlich im unteren Byte.
            $value &= 0xff;
            if ($type === 'int8' && $value >= 0x80) {
                $value -= 0x100;
            }
        } elseif ($type === 'int16' && $value >= 0x8000) {
            $value -= 0x10000;
        } elseif ($type === 'int32' && $value >= 2147483648) {
            $value -= 4294967296;
        } elseif ($type === 'float32') {
            $value = unpack('Gvalue', pack('nn', ...$words))['value'];
        }
        $scaled = $value * $scale;
        if (!is_finite((float) $scaled)) {
            throw new RuntimeException('Modbus-Wert ist keine endliche Zahl.');
        }
        return $scale === 1.0 && $type !== 'float32' ? (int) $scaled : round($scaled, 3);
    }

    private function readFieldRegisters(int $address, int $count, int $function): array
    {
        return $function === 4 ? $this->readInputRegisters($address, $count) : $this->readHoldingRegisters($address, $count);
    }

    private static function setPath(array &$target, string $path, int|float $value): void
    {
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            $target[$part] ??= [];
            $target =& $target[$part];
        }
        $target[$last] = $value;
    }
}

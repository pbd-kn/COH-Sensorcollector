<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Modbus;

use InvalidArgumentException;

final class ModbusProfile
{
    public static function load(string $name): array
    {
        // Nur lokale, bewusst installierte Profile laden; keine Pfade aus URLs.
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)) {
            throw new InvalidArgumentException('Ungültiger Modbus-Profilname.');
        }
        $file = __DIR__ . '/Profiles/' . $name . '.php';
        if (!is_file($file)) {
            throw new InvalidArgumentException("Unbekanntes Modbus-Profil '$name'.");
        }
        return self::validate(require $file);
    }

    public static function validate(array $profile): array
    {
        if (!is_string($profile['device'] ?? null) || $profile['device'] === ''
            || !is_array($profile['fields'] ?? null) || $profile['fields'] === []) {
            throw new InvalidArgumentException('Modbus-Profil benötigt device und fields.');
        }
        foreach ($profile['fields'] as $name => &$field) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D', $name)
                || !is_array($field)) {
                throw new InvalidArgumentException('Ungültige Modbus-Felddefinition.');
            }
            $field += ['scale' => 1.0, 'unit' => '', 'description' => '', 'function' => 3, 'wordOrder' => 'big'];
            $count = self::registerCount($field['type'] ?? '');
            if (!is_int($field['address'] ?? null) || $field['address'] < 0 || $field['address'] + $count > 65536
                || !in_array($field['function'], [3, 4], true)
                || !in_array($field['wordOrder'], ['big', 'little'], true)
                || !is_numeric($field['scale']) || !is_finite((float) $field['scale'])) {
                throw new InvalidArgumentException("Ungültige Registerdefinition für '$name'.");
            }
            // Ein Pfad darf nicht zugleich Messwert und Gruppe sein.
            foreach (array_keys($profile['fields']) as $other) {
                if ($other !== $name && str_starts_with($name, $other . '.')) {
                    throw new InvalidArgumentException("Kollidierende Modbus-Feldpfade '$name' und '$other'.");
                }
            }
        }
        unset($field);
        $profile += ['blocks' => [], 'optional' => [], 'copies' => [], 'aliases' => [], 'groups' => []];
        foreach ($profile['controls'] ?? [] as $name => $control) {
            if (!is_string($name) || !is_array($control) || !is_int($control['address'] ?? null)
                || $control['address'] < 0 || $control['address'] > 65535
                || !is_string($control['label'] ?? null) || !is_array($control['options'] ?? null) || $control['options'] === []) {
                throw new InvalidArgumentException('Ungültige Modbus-Schreibfreigabe.');
            }
            foreach ($control['options'] as $value => $label) {
                if (!is_int($value) || $value < 0 || $value > 65535 || !is_string($label)) {
                    throw new InvalidArgumentException('Ungültiger Auswahlwert einer Modbus-Schreibfreigabe.');
                }
            }
            if (isset($control['requires'])) {
                $requirement = $control['requires'];
                if (!is_array($requirement) || !is_int($requirement['address'] ?? null)
                    || $requirement['address'] < 0 || $requirement['address'] > 65535
                    || !is_int($requirement['value'] ?? null) || $requirement['value'] < 0 || $requirement['value'] > 65535
                    || !is_string($requirement['message'] ?? null)) {
                    throw new InvalidArgumentException('Ungültige Voraussetzung einer Modbus-Schreibfreigabe.');
                }
            }
        }
        if (!is_array($profile['groups'])) {
            throw new InvalidArgumentException('Modbus-Profil benötigt ein Array für groups.');
        }
        foreach ($profile['blocks'] as &$block) {
            $block[2] ??= 3;
            if (!is_int($block[0] ?? null) || !is_int($block[1] ?? null)
                || $block[0] < 0 || $block[1] < 1 || $block[1] > 125 || $block[0] + $block[1] > 65536
                || !in_array($block[2], [3, 4], true)) {
                throw new InvalidArgumentException('Ungültiger Modbus-Leseblock.');
            }
        }
        unset($block);
        foreach ($profile['optional'] as $name) {
            if (!isset($profile['fields'][$name])) {
                throw new InvalidArgumentException("Unbekanntes optionales Modbus-Feld '$name'.");
            }
            $field = $profile['fields'][$name];
            foreach ($profile['blocks'] as [$address, $count, $function]) {
                if ($function === $field['function'] && $field['address'] < $address + $count
                    && $field['address'] + self::registerCount($field['type']) > $address) {
                    throw new InvalidArgumentException("Optionales Modbus-Feld '$name' muss außerhalb der Pflichtblöcke liegen.");
                }
            }
        }
        foreach ($profile['copies'] as $target => $source) {
            if (isset($profile['fields'][$target]) || !isset($profile['fields'][$source])) {
                throw new InvalidArgumentException('Ungültiger Modbus-Kompatibilitätspfad.');
            }
        }
        foreach ($profile['aliases'] as $source) {
            if (!isset($profile['fields'][$source]) && !isset($profile['copies'][$source])) {
                throw new InvalidArgumentException('Unbekanntes Modbus-Aliasziel.');
            }
        }
        return $profile;
    }

    public static function registerCount(string $type): int
    {
        return match ($type) {
            'uint8', 'int8', 'uint16', 'int16' => 1,
            'uint32', 'int32', 'float32' => 2,
            default => throw new InvalidArgumentException("Unbekannter Modbus-Datentyp '$type'."),
        };
    }
}

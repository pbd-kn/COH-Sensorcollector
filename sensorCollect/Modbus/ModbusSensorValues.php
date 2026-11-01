<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Modbus;

use InvalidArgumentException;

/** Gemeinsame Auswahl und Gruppenbildung für IQBox und Wechselrichter. */
final class ModbusSensorValues
{
    private array $groups;
    private array $groupValues = [];
    private array $units = [];

    private const LEGACY_SUFFIXES = [
        '_batteries_0_state_of_charge' => 'battery.soc',
        '_batteries_0_power' => 'battery.power',
        '_photovoltaics_0_power' => 'pv.power',
        '_consumption_power' => 'house.power',
        '_powermeter_power' => 'grid.power',
        '_powermeter_totalexport' => 'energy.grid.sellTotal',
        '_powermeter_totalimport' => 'energy.grid.feedInTotal',
        '_powermeter_realpower' => 'grid.power',
        '_powermeter_real_power_1' => 'grid.l1.power',
        '_powermeter_real_power_2' => 'grid.l2.power',
        '_powermeter_real_power_3' => 'grid.l3.power',
        '_metering_getproduction_p_active' => 'pv.power',
        '_metering_getconsumption_p_active' => 'house.power',
    ];

    private const SELECTION_ALIASES = [
        'soc' => 'battery.soc', 'all' => 'modbus',
        'pv-total' => 'lifetime.pvProduction', 'pv-gesamt' => 'lifetime.pvProduction',
        'total-pv' => 'lifetime.pvProduction', 'lifetime-pv' => 'lifetime.pvProduction',
        'lifetime' => 'lifetime.work',
        'heute' => 'today', 'work' => 'today.work', 'arbeit' => 'today.work', 'today-work' => 'today.work',
        'today-self-sufficiency' => 'today.selfSufficiency', 'today-autarkie' => 'today.selfSufficiency',
        'today-self-consumption' => 'today.selfConsumption', 'today-eigenverbrauch' => 'today.selfConsumption',
        'today-saving' => 'today.saving', 'today-ersparnis' => 'today.saving',
        'today-saving-energy' => 'today.saving.energy',
        'today-saving-pv-production' => 'today.saving.energy.pvProduction',
        'today-saving-grid-feed' => 'today.saving.energy.gridFeed',
        'today-saving-own-consumption' => 'today.saving.energy.ownConsumption',
        'today.work.consumation' => 'today.work.consumption',
        'live.power' => 'live',
    ];

    public function __construct(private array $data, private array $profile)
    {
        foreach ($profile['fields'] as $path => $field) {
            $this->units[strtolower($path)] = $field['unit'];
        }
        foreach ($profile['copies'] as $target => $source) {
            $this->units[strtolower($target)] = $this->units[strtolower($source)] ?? '';
        }
        $this->groups = $this->buildGroups($profile['groups'] ?? []);
    }

    /** Pro Snapshot einmal aufbauen; fehlende Messwerte bleiben null. */
    private function buildGroups(array $definitions, string $prefix = ''): array
    {
        $result = [];
        foreach ($definitions as $name => $definition) {
            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            if (!is_array($definition)) {
                $value = $definition;
            } elseif (isset($definition['from'])) {
                $value = $this->sourceValue((array) $definition['from']);
                if ($value !== null && isset($definition['factor'])) {
                    $value = round((float) $value * $definition['factor'], 2);
                }
                if ($value !== null && ($definition['transform'] ?? '') === 'negativeAbs') {
                    $value = -abs((float) $value);
                }
                $value ??= $definition['default'] ?? null;
            } elseif (isset($definition['calculation'])) {
                $value = $this->calculate($definition);
            } elseif (isset($definition['date'])) {
                $value = date($definition['date']);
            } else {
                $value = $this->buildGroups($definition, $path);
            }
            $result[$name] = $value;
            $this->groupValues[strtolower($path)] = $value;
            $isField = is_array($definition) && (isset($definition['from']) || isset($definition['calculation']));
            $this->units[strtolower($path)] = $isField ? ($definition['unit'] ?? '') : '';
        }
        return $result;
    }

    private function sourceValue(array $paths): mixed
    {
        foreach ($paths as $path) {
            try {
                $value = self::pathNode($this->data, $path);
                if ($value !== null) {
                    return $value;
                }
            } catch (InvalidArgumentException) {
                // Nur die im Profil genannten Ersatzpfade versuchen.
            }
        }
        return null;
    }

    private function calculate(array $definition): ?float
    {
        [$leftPath, $rightPath] = $definition['inputs'];
        $left = $this->groupValues[strtolower($leftPath)] ?? null;
        $right = $this->groupValues[strtolower($rightPath)] ?? null;
        if ($left === null || $right === null) {
            return null;
        }
        return match ($definition['calculation']) {
            'remainingPercent' => $left <= 0 ? null : round(max(0.0, min(100.0, ($left - $right) / $left * 100)), 2),
            'positiveDifference' => max(0.0, round($left - $right, 2)),
            default => throw new InvalidArgumentException('Unbekannte Gruppenberechnung.'),
        };
    }

    private function selectionPath(string $selection): string
    {
        $path = strtolower(trim($selection));
        if (str_starts_with($path, 'modbus.')) {
            return $path;
        }
        $path = self::SELECTION_ALIASES[$path] ?? $path;
        if (str_starts_with($path, 'live.power.')) {
            $path = 'live.' . substr($path, 11);
        }
        // Bisherige Detailpfade bleiben lesbar, ohne die sechs live-Werte zu erweitern.
        if (str_starts_with($path, 'live.') && !array_key_exists($path, $this->groupValues)) {
            $nativePath = substr($path, 5);
            if (array_key_exists(explode('.', $nativePath, 2)[0], $this->data)) {
                return $nativePath;
            }
        }
        // Die bisherigen einfachen Live-Namen behalten ihre Vorzeichenkonvention.
        if (array_key_exists('live.' . $path, $this->groupValues)
            && !is_array($this->groupValues['live.' . $path])) {
            return 'live.' . $path;
        }
        foreach ($this->profile['aliases'] as $alias => $target) {
            if (strtolower($alias) === $path) {
                return strtolower($target);
            }
        }
        foreach (self::LEGACY_SUFFIXES as $suffix => $target) {
            if (str_ends_with($path, $suffix)) {
                return $target;
            }
        }
        return $path;
    }

    public function value(string $selection): mixed
    {
        $path = $this->selectionPath($selection);
        if ($path === 'modbus') {
            return $this->data;
        }
        if (str_starts_with($path, 'modbus.')) {
            return self::pathNode($this->data, substr($path, 7));
        }
        if (str_contains($path, '_powermeter_')
            && (str_ends_with($path, 'harmonized_power_out') || str_ends_with($path, 'harmonized_power_in'))) {
            $power = $this->sourceValue(['grid.power']);
            return $power === null ? null : max(0.0, (str_ends_with($path, '_out') ? -1 : 1) * $power);
        }
        $root = explode('.', $path, 2)[0];
        return self::pathNode(isset($this->groups[$root]) ? $this->groups : $this->data, $path);
    }

    public function unit(string $selection): string
    {
        $path = $this->selectionPath($selection);
        if (str_starts_with($path, 'modbus.')) {
            $path = substr($path, 7);
        }
        if (str_ends_with($path, 'harmonized_power_out') || str_ends_with($path, 'harmonized_power_in')) {
            return 'W';
        }
        return $this->units[$path] ?? '';
    }

    /** Auswahlhilfe aus dem Profil erstellen, ohne Gerät oder Messwerte zu benötigen. */
    public static function catalog(array $profile): array
    {
        $catalog = [];
        foreach ($profile['fields'] as $path => $field) {
            $catalog[$path] = ['kind' => 'Wert', 'unit' => $field['unit'], 'description' => $field['description'], 'addressHex' => sprintf('0x%04X', $field['address'])];
            $parent = $path;
            while (($position = strrpos($parent, '.')) !== false) {
                $parent = substr($parent, 0, $position);
                $catalog[$parent] = ['kind' => 'Gruppe', 'unit' => '', 'description' => 'Native Registerwerte'];
            }
        }
        foreach ($profile['copies'] as $path => $source) {
            $catalog[$path] = $catalog[$source];
        }
        $nativeCatalog = $catalog;
        self::addGroupCatalog($catalog, $profile['groups'] ?? [], $nativeCatalog);
        foreach ($profile['aliases'] as $alias => $source) {
            $catalog[$alias] = $catalog[$source];
        }
        foreach ($profile['groupDescriptions'] ?? [] as $path => $description) {
            if (isset($catalog[$path])) {
                $catalog[$path]['description'] = $description;
            }
        }
        ksort($catalog, SORT_NATURAL | SORT_FLAG_CASE);
        return $catalog;
    }

    private static function addGroupCatalog(array &$catalog, array $definitions, array $nativeCatalog, string $prefix = ''): void
    {
        foreach ($definitions as $name => $definition) {
            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            $isField = !is_array($definition) || isset($definition['from']) || isset($definition['calculation']) || isset($definition['date']);
            $source = is_array($definition) ? ($definition['from'] ?? null) : null;
            $isNativeGroup = is_string($source) && ($nativeCatalog[$source]['kind'] ?? '') === 'Gruppe';
            $catalog[$path] = [
                'kind' => !$isField || $isNativeGroup ? 'Gruppe' : 'Wert',
                'unit' => $isField && is_array($definition) ? ($definition['unit'] ?? '') : '',
                'description' => is_array($definition) ? ($definition['description'] ?? '') : '',
            ];
            if (!$isField) {
                self::addGroupCatalog($catalog, $definition, $nativeCatalog, $path);
            } elseif ($isNativeGroup) {
                foreach ($nativeCatalog as $nativePath => $field) {
                    if (str_starts_with($nativePath, $source . '.')) {
                        $catalog[$path . substr($nativePath, strlen($source))] = $field;
                    }
                }
            }
        }
    }

    private static function pathNode(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value)) {
                throw new InvalidArgumentException("Sensorpfad '$path' ist nicht verfügbar.");
            }
            $keys = array_change_key_case($value, CASE_LOWER);
            if (!array_key_exists(strtolower($segment), $keys)) {
                throw new InvalidArgumentException("Sensorpfad '$path' ist nicht verfügbar.");
            }
            $value = $keys[strtolower($segment)];
        }
        return $value;
    }
}

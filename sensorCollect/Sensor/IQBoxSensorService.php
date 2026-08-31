<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\SimpleHttpClient;

final class IQBoxSensorService implements SensorFetcherInterface
{
    private const DEFAULT_HOST = '192.168.178.30';

    /** @var array<string,string> */
    private const LEGACY_SUFFIXES = [
        '_batteries_0_state_of_charge' => 'battery.soc',
        '_batteries_0_power' => 'battery.power',
        '_photovoltaics_0_power' => 'pv.power',
        '_consumption_power' => 'house.power',
        '_powermeter_power' => 'grid.power',
    ];

    public function __construct(
        private mysql_dialog $connection,
        private Logger $logger,
        private SimpleHttpClient $httpClient,
    ) {
    }

    public function supports($sensor): bool
    {
        return strtolower((string) ($sensor['sensorSource'] ?? '')) === 'iqbox';
    }

    public function fetch($sensor): ?array
    {
        return $this->fetchArr([$sensor])[(string) ($sensor['sensorID'] ?? '')] ?? null;
    }

    public function fetchArr(array $sensors, ?string $date = null, array $fetchedValues = []): ?array
    {
        $result = [];
        $snapshot = null;
        $settings = $this->settings();
        foreach ($sensors as $sensor) {
            $sensorId = (string) ($sensor['sensorID'] ?? '');
            $selection = trim((string) ($sensor['sensorLokalId'] ?? ''));
            if ($selection === '') {
                $this->logger->Error("IQBox StoragePro: sensorLokalId fehlt für Sensor $sensorId");
                continue;
            }
            try {
                // Der Collector laeuft im selben LAN und liest immer direkt per Modbus TCP.
                $snapshot ??= $this->modbus($settings)->readSnapshot();
                $result[$sensorId] = [
                    'sensorID' => $sensorId,
                    'sensorEinheit' => (string) ($sensor['sensorEinheit'] ?? ''),
                    'sensorValueType' => (string) ($sensor['sensorValueType'] ?? ''),
                    'sensorSource' => (string) ($sensor['sensorSource'] ?? ''),
                    'sensorValue' => $this->snapshotValue($snapshot, $selection),
                ];
            } catch (\Throwable $error) {
                $this->logger->Error($this->qualifiedErrorMessage($selection, $error));
            }
        }

        return $result;
    }

    private function qualifiedErrorMessage(string $selection, \Throwable $error): string
    {
        $message = $error->getMessage();
        if (str_contains($message, 'Modbus-Antwort unvollstÃ¤ndig: Verbindung beendet')) {
            return sprintf(
                "IQBox StoragePro: lokalId %s konnte nicht gelesen werden. Der StoragePro hat die Modbus-TCP-Verbindung beendet. Wahrscheinlich ist bereits ein anderer Modbus-Client verbunden (z. B. json-solar-modbus-loop.php). Bitte den anderen Client die Verbindung nach jedem Zugriff freigeben lassen. Technischer Fehler: %s",
                $selection,
                $message,
            );
        }
        if (str_contains($message, 'Timeout')) {
            return sprintf(
                'IQBox StoragePro: Timeout beim Lesen von lokalId %s. Host, Port, Erreichbarkeit und eingestellten Timeout prÃ¼fen. Technischer Fehler: %s',
                $selection,
                $message,
            );
        }
        if (str_contains($message, 'Modbus-Verbindung zu')) {
            return sprintf(
                'IQBox StoragePro: Verbindung fÃ¼r lokalId %s konnte nicht aufgebaut werden. Host, Port und Netzwerk prÃ¼fen. Technischer Fehler: %s',
                $selection,
                $message,
            );
        }

        return "IQBox StoragePro: Fehler bei lokalId $selection: $message";
    }

    private function settings(): array
    {
        return [
            'storageProHost' => self::DEFAULT_HOST,
            'storageProPort' => 502,
            'storageProUnitId' => 1,
            'storageProTimeout' => 3,
        ];
    }

    private function modbus(array $settings): object
    {
        $host = trim((string) ($settings['storageProHost'] ?? '')) ?: self::DEFAULT_HOST;
        $port = max(1, (int) ($settings['storageProPort'] ?? 502));
        $unitId = isset($settings['storageProUnitId']) ? (int) $settings['storageProUnitId'] : 1;
        $timeout = max(0.1, (float) ($settings['storageProTimeout'] ?? 3));

        return $this->newModbusClient($host, $port, $unitId, $timeout);
    }

    private function newModbusClient(string $host, int $port, int $unitId, float $timeout): object
    {
        require_once __DIR__ . '/AmpereStorageProModbus.php';
        return new \AmpereStorageProModbus($host, $port, $unitId, $timeout);
    }

    private function snapshotValue(array $snapshot, string $selection): mixed
    {
        $data = $snapshot['data'] ?? [];
        $live = $this->compatibleLiveData($data);
        $today = $this->compatibleTodayData($data);
        $lifetime = $this->compatibleLifetimeData($data);
        $normalized = strtolower(trim($selection));

        if ($normalized === 'modbus') return $data;
        if (str_starts_with($normalized, 'modbus.')) return $this->pathNode($data, substr(trim($selection), 7));

        $lifetimeAliases = ['pv-total' => 'lifetime.pvProduction', 'pv-gesamt' => 'lifetime.pvProduction', 'total-pv' => 'lifetime.pvProduction', 'lifetime-pv' => 'lifetime.pvProduction'];
        $lifetimeSelection = $lifetimeAliases[$normalized] ?? trim($selection);
        if (strcasecmp($lifetimeSelection, 'lifetime') === 0 || strcasecmp($lifetimeSelection, 'lifetime.work') === 0) return $lifetime['work'];
        if (str_starts_with(strtolower($lifetimeSelection), 'lifetime.')) return $this->pathNode($lifetime, substr($lifetimeSelection, 9));

        $todayAliases = [
            'heute' => 'today', 'work' => 'today.work', 'arbeit' => 'today.work', 'today-work' => 'today.work',
            'today-self-sufficiency' => 'today.selfSufficiency', 'today-autarkie' => 'today.selfSufficiency',
            'today-self-consumption' => 'today.selfConsumption', 'today-eigenverbrauch' => 'today.selfConsumption',
            'today-saving' => 'today.saving', 'today-ersparnis' => 'today.saving', 'today-saving-energy' => 'today.saving.energy',
            'today-saving-pv-production' => 'today.saving.energy.pvProduction', 'today-saving-grid-feed' => 'today.saving.energy.gridFeed',
            'today-saving-own-consumption' => 'today.saving.energy.ownConsumption',
        ];
        $todaySelection = $todayAliases[$normalized] ?? trim($selection);
        if (strcasecmp($todaySelection, 'today') === 0) return $today;
        if (str_starts_with(strtolower($todaySelection), 'today.')) {
            $path = substr($todaySelection, 6);
            if ($path === 'work.consumation') $path = 'work.consumption';
            return $this->pathNode($today, $path);
        }

        if ($normalized === 'live' || $normalized === 'live.power') return $live;
        if (str_starts_with($normalized, 'live.power.')) return $this->pathNode($live, substr($selection, 11));
        if (str_starts_with($normalized, 'live.')) return $this->pathNode($live, substr($selection, 5));
        if (array_key_exists($selection, $live) && !is_array($live[$selection])) return $live[$selection];
        if (array_key_exists($selection, $snapshot['aliases'] ?? [])) return $snapshot['aliases'][$selection];

        $path = $selection;
        foreach (self::LEGACY_SUFFIXES as $suffix => $modbusPath) {
            if (str_ends_with($selection, $suffix)) { $path = $modbusPath; break; }
        }
        if (str_contains($selection, '_powermeter_') && str_ends_with($selection, 'harmonized_power_out')) return max(0.0, -(float) $this->pathValue($data, 'grid.power'));
        if (str_contains($selection, '_powermeter_') && str_ends_with($selection, 'harmonized_power_in')) return max(0.0, (float) $this->pathValue($data, 'grid.power'));
        return $this->pathValue($data, $path);
    }

    private function compatibleLifetimeData(array $data): array
    {
        $e = $data['energy'] ?? [];
        $generation = (float) ($e['pv']['total'] ?? 0);
        $charge = (float) ($e['battery']['chargeTotal'] ?? 0);
        $discharge = (float) ($e['battery']['dischargeTotal'] ?? 0);

        return ['pvProduction' => round($generation * 1000, 2), 'work' => [
            'generation' => round($generation * 1000, 2),
            'consumption' => null,
            'batteryFeed' => round($charge * 1000, 2),
            'batteryDraw' => round($discharge * 1000, 2),
            'gridFeed' => null,
            'gridDraw' => null,
            'unit' => 'Wh',
            'throughDate' => date('Y-m-d'),
            'source' => 'StoragePro-Modbus-Teilzaehler',
            '_notice' => 'Netz-Gesamtwerte und Gesamtverbrauch sind per Modbus nicht verlaesslich; dafuer Tasmota verwenden.',
        ]];
    }
    private function compatibleTodayData(array $data): array
    {
        $e = $data['energy'] ?? [];
        $generation = round((float) ($e['inverter']['today'] ?? $e['pv']['today'] ?? 0) * 1000, 2);
        $consumption = round((float) ($e['house']['calculatedToday'] ?? 0) * 1000, 2);
        $charge = round((float) ($e['battery']['chargeToday'] ?? 0) * 1000, 2);
        $discharge = round((float) ($e['battery']['dischargeToday'] ?? 0) * 1000, 2);
        $sell = round((float) ($e['grid']['sumSellToday'] ?? $e['grid']['sellToday'] ?? 0) * 1000, 2);
        $import = round((float) ($e['grid']['sumFeedInToday'] ?? $e['grid']['feedInToday'] ?? 0) * 1000, 2);
        $autarky = $consumption <= 0 ? null : round(max(0.0, min(100.0, ($consumption - $import) / $consumption * 100)), 2);
        $own = $generation <= 0 ? null : round(max(0.0, min(100.0, ($generation - $sell) / $generation * 100)), 2);
        return [
            'work' => ['generation' => $generation, 'consumption' => $consumption, 'batteryFeed' => $charge, 'batteryDraw' => $discharge, 'gridFeed' => $sell, 'gridDraw' => $import, 'unit' => 'Wh'],
            'selfSufficiency' => ['value' => $autarky], 'selfConsumption' => ['value' => $own],
            'saving' => ['energy' => ['pvProduction' => $generation, 'gridFeed' => $sell, 'ownConsumption' => max(0.0, round($generation - $sell, 2))], '_notice' => 'Kosten, Preise, Fahrzeuge und Emissionen sind nicht per Modbus verfügbar.'],
        ];
    }

    private function compatibleLiveData(array $data): array
    {
        $batteryPower = (float) ($data['battery']['power'] ?? 0);
        return [
            'pvPower' => $data['pv']['power'] ?? null, 'housePower' => -abs((float) ($data['house']['power'] ?? 0)),
            'gridPower' => $data['grid']['power'] ?? null, 'batteryPower' => $batteryPower == 0.0 ? 0 : $batteryPower,
            'heatingRodPower' => null, 'batterySoc' => $data['battery']['soc'] ?? null,
            'inverter' => $data['inverter'] ?? [], 'grid' => $data['grid'] ?? [], 'battery' => $data['battery'] ?? [],
            'pv' => $data['pv'] ?? [], 'house' => $data['house'] ?? [],
        ];
    }

    private function pathValue(array $data, string $path): mixed
    {
        $value = $this->pathNode($data, $path);
        if (is_array($value)) throw new \InvalidArgumentException("Sensorpfad '$path' bezeichnet keinen einzelnen Messwert.");
        return $value;
    }

    private function pathNode(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) throw new \InvalidArgumentException("Sensorpfad '$path' ist lokal nicht verfügbar.");
            $value = $value[$segment];
        }
        return $value;
    }
}

<?php

declare(strict_types=1);

namespace CohEnergy;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class EnergyManager
{
    public static function validate(array $config): void
    {
        if (($config['schemaVersion'] ?? null) !== 1) {
            throw new InvalidArgumentException('schemaVersion muss 1 sein.');
        }
        if (!in_array($config['mode'] ?? '', ['observe', 'simulate'], true)) {
            throw new InvalidArgumentException('Nur observe und simulate sind freigegeben; aktive Geraetesteuerung ist noch nicht abgenommen.');
        }
        foreach (['pollSeconds', 'maxSampleAgeSeconds'] as $key) {
            self::number($config[$key] ?? null, $key, 1, 3600);
        }
        $device = $config['storagePro'] ?? [];
        if (!is_string($device['host'] ?? null) || !preg_match('/^[a-zA-Z0-9.:-]+$/D', $device['host'])) {
            throw new InvalidArgumentException('storagePro.host fehlt oder ist ungueltig.');
        }
        self::number($device['port'] ?? null, 'port', 1, 65535);
        self::number($device['unitId'] ?? null, 'unitId', 0, 255);
        self::number($device['timeoutSeconds'] ?? null, 'timeoutSeconds', 0.1, 30);
        if (($device['writesEnabled'] ?? false) !== false || ($config['heatPump']['enabled'] ?? false) !== false) {
            throw new InvalidArgumentException('Wechselrichter-Schreiben und Waermepumpe muessen deaktiviert bleiben.');
        }
        if ($config['mode'] === 'simulate') {
            foreach (['gridImportSign', 'batteryDischargeSign'] as $key) {
                if (!in_array($device[$key] ?? null, [-1, 1], true)) {
                    throw new InvalidArgumentException($key . ' muss nach Messvergleich -1 oder 1 sein.');
                }
            }
            self::number($config['policy']['minimumBatterySocPercent'] ?? null, 'minimumBatterySocPercent', 0, 100);
            self::number($config['policy']['gridReserveWatts'] ?? null, 'gridReserveWatts', 0, 100000);
            self::number($config['heater']['maximumPowerWatts'] ?? null, 'maximumPowerWatts', 0, 6500);
            if (($config['policy']['consumerPriority'] ?? []) !== ['heater']) {
                throw new InvalidArgumentException('Aktuell nur consumerPriority: ["heater"] unterstuetzt.');
            }
        }
    }

    /** Pure calculation: no network access and no device commands. */
    public static function decide(array $config, array $snapshot, float $ageSeconds, ?float $heaterWatts): array
    {
        $off = static fn (string $reason): array => ['powerWatts' => 0, 'reason' => $reason, 'executable' => false];
        if ($config['mode'] !== 'simulate' || !($config['heater']['enabled'] ?? false)) {
            return $off('Beobachtung oder Heizstab deaktiviert');
        }
        if (!is_finite($ageSeconds) || $ageSeconds < 0 || $ageSeconds > $config['maxSampleAgeSeconds']) {
            return $off('Messung veraltet');
        }
        $data = $snapshot['data'] ?? [];
        $grid = $data['grid']['power'] ?? null;
        $battery = $data['battery']['power'] ?? null;
        $soc = $data['battery']['soc'] ?? null;
        foreach ([$grid, $battery, $soc, $heaterWatts] as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                return $off('Unvollstaendige Bilanz: Netz, Batterie, SOC oder Heizstab-Istleistung fehlt');
            }
        }
        if ($soc < 0 || $soc > 100 || $heaterWatts < 0 || $heaterWatts > $config['heater']['maximumPowerWatts']) {
            return $off('Messwerte ausserhalb plausibler Grenzen');
        }
        if ($soc < $config['policy']['minimumBatterySocPercent']) {
            return $off('Batteriereserve unterschritten');
        }
        // Existing heater load is added back: otherwise the controller oscillates.
        // Battery charging is preserved; discharge must not finance heating by default.
        $import = $grid * $config['storagePro']['gridImportSign'];
        $discharge = $battery * $config['storagePro']['batteryDischargeSign'];
        $budget = $heaterWatts - $import - $config['policy']['gridReserveWatts'];
        if (!($config['policy']['batteryDischargeAllowedForHeating'] ?? false)) {
            $budget -= max(0, $discharge);
        }
        return [
            'powerWatts' => (int) floor(max(0, min($config['heater']['maximumPowerWatts'], $budget))),
            'reason' => 'Simulierter Sollwert aus Netzbilanz, bestehender Heizlast und Batteriereserve',
            'executable' => false,
        ];
    }

    public static function number(mixed $value, string $name, float $min, float $max): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($name . ' fehlt oder liegt ausserhalb des erlaubten Bereichs.');
        }
    }

    public static function database(): PDO
    {
        $dsn = getenv('COH_ENERGY_DSN');
        if (!$dsn || !str_starts_with($dsn, 'mysql:')) {
            throw new RuntimeException('COH_ENERGY_DSN muss auf eine eigene MySQL/MariaDB-Datenbank zeigen.');
        }
        $db = new PDO($dsn, getenv('COH_ENERGY_DB_USER') ?: '', getenv('COH_ENERGY_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec("SET time_zone = '+00:00'");
        return $db;
    }

    public static function save(PDO $db, array $config, array $snapshot, string $startedAt, float $age, array $decision): void
    {
        $json = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $db->beginTransaction();
        try {
            $statement = $db->prepare('INSERT INTO coh_energy_samples (sampled_at_utc, source, quality, snapshot_json) VALUES (?, ?, ?, ?)');
            $statement->execute([$startedAt, 'storagepro-modbus', $age <= $config['maxSampleAgeSeconds'] ? 'received' : 'stale', $json($snapshot)]);
            $sampleId = $db->lastInsertId();
            $configuration = $json($config);
            $previous = $db->query('SELECT revision, configuration_json FROM coh_energy_configuration ORDER BY revision DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!$previous || $previous['configuration_json'] !== $configuration) {
                $statement = $db->prepare('INSERT INTO coh_energy_configuration (created_at_utc, configuration_json) VALUES (UTC_TIMESTAMP(6), ?)');
                $statement->execute([$configuration]);
                $revision = $db->lastInsertId();
            } else {
                $revision = $previous['revision'];
            }
            $statement = $db->prepare('INSERT INTO coh_energy_actions (created_at_utc, expires_at_utc, configuration_revision, sample_id, device, mode, status, reason, requested_json) VALUES (UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$revision, $sampleId, 'heater', $config['mode'], 'not_executed', $decision['reason'], $json($decision)]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}

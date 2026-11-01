<?php

declare(strict_types=1);

use CohEnergy\EnergyManager;

require_once __DIR__ . '/EnergyManager.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$options = getopt('', ['config:', 'check', 'once', 'no-db', 'help']);
if (isset($options['help'])) {
    echo "php energy-manager.php --config=/private/config.json [--check|--once] [--no-db]\n";
    echo "Nur observe/simulate. Keine Geraetesteuerung. --check ohne Netzwerk/DB.\n";
    exit(0);
}
$lock = null;
try {
    $path = $options['config'] ?? null;
    if (!is_string($path) || !is_readable($path)) {
        throw new RuntimeException('Lesbare --config Datei angeben.');
    }
    $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new RuntimeException('Konfiguration muss ein JSON-Objekt sein.');
    }
    EnergyManager::validate($config);
    if (isset($options['check'])) {
        echo "Konfiguration gueltig; keine Verbindung aufgebaut.\n";
        exit(0);
    }
    if (($config['enabled'] ?? false) !== true) {
        throw new RuntimeException('Dienst ist deaktiviert (enabled=false).');
    }
    // Fixed lock per machine, independent of config path or working directory.
    $lock = fopen(sys_get_temp_dir() . '/coh-energy-manager.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Ein Energiemanager laeuft bereits oder Sperre nicht verfuegbar.');
    }
    require_once dirname(__DIR__) . '/sensorCollect/Sensor/AmpereStorageProModbus.php';
    $device = $config['storagePro'];
    $client = new AmpereStorageProModbus($device['host'], (int) $device['port'], (int) $device['unitId'], (float) $device['timeoutSeconds']);
    $running = true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $stop = static function () use (&$running): void { $running = false; };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
    do {
        $started = microtime(true);
        $startedAt = gmdate('Y-m-d H:i:s', (int) $started);
        try {
            try {
                $snapshot = $client->readSnapshot();
            } finally {
                $client->close();
            }
            $age = microtime(true) - $started;
            // Actual heater telemetry is not yet mapped; never invent zero load.
            $decision = EnergyManager::decide($config, $snapshot, $age, null);
            if (!isset($options['no-db'])) {
                $db = EnergyManager::database();
                EnergyManager::save($db, $config, $snapshot, $startedAt, $age, $decision);
                $db = null;
            }
            echo json_encode(['readAt' => gmdate(DATE_ATOM), 'snapshot' => $snapshot, 'decision' => $decision], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        } catch (Throwable $error) {
            // Avoid printing device exceptions: future drivers may include credentials.
            fwrite(STDERR, gmdate(DATE_ATOM) . " Runde fehlgeschlagen (" . get_class($error) . "); kein Befehl ausgefuehrt.\n");
            if (isset($options['once'])) {
                exit(1);
            }
        } finally {
            $client->close();
        }
        if (isset($options['once'])) {
            break;
        }
        $deadline = $started + $config['pollSeconds'];
        while ($running && microtime(true) < $deadline) {
            usleep(100000);
        }
    } while ($running);
} catch (Throwable $error) {
    fwrite(STDERR, 'Start abgebrochen: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

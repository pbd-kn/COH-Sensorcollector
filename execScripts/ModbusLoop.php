<?php

declare(strict_types=1);

use PbdKn\cohSensorcollector\Modbus\ModbusProfile;
use PbdKn\cohSensorcollector\Modbus\ModbusSensorValues;
use PbdKn\cohSensorcollector\Modbus\ModbusSnapshotClient;

/**
 * Gemeinsame Konsole für die beiden Modbus-Loop-Einstiege.
 * Der aufrufende Einstieg setzt Standardhost, Profil und Eingabeprompt.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur fuer die Kommandozeile.\n");
}

foreach ([__DIR__ . '/../sensorcollect/Modbus/bootstrap.php', __DIR__ . '/../sensorCollect/Modbus/bootstrap.php'] as $bootstrap) {
    if (is_file($bootstrap)) {
        require_once $bootstrap;
        break;
    }
}
if (!class_exists(ModbusSensorValues::class)) {
    fwrite(STDERR, "Gemeinsamer Modbus-Code fehlt: sensorcollect/Modbus vollstaendig installieren.\n");
    exit(1);
}

function modbusLoopRun(array $argv, string $defaultHost, string $defaultProfile, string $prompt): int
{
    $scriptName = basename($argv[0]);
    $once = null;
    $arguments = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--once=')) {
            $once = substr($argument, 7);
        } else {
            $arguments[] = $argument;
        }
    }
    $argv = array_merge([$argv[0]], $arguments);
    date_default_timezone_set('Europe/Berlin');
    $helpOnly = in_array($argv[1] ?? '', ['--help', '-h'], true);
    $profileName = $helpOnly ? ($argv[2] ?? $defaultProfile) : ($argv[4] ?? $defaultProfile);
    try {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '-') && !in_array($argument, ['--help', '-h'], true)) {
                throw new InvalidArgumentException('Unbekannte Option: ' . $argument . '. Verfügbare Optionen: --help, --once=NAME.');
            }
        }
        if ($once === '') {
            throw new InvalidArgumentException('Nach --once= einen Namen aus list angeben.');
        }
        $profile = ModbusProfile::load($profileName);
        if ($helpOnly) {
            echo "Aufruf: php $scriptName [HOST] [PORT] [UNIT-ID] [PROFIL]\n";
            echo "Hilfe:  php $scriptName --help [PROFIL]\n";
            echo "Einmal: php $scriptName [HOST] --once=NAME\n";
            echo "Standard: $defaultHost 502 1 $defaultProfile\n";
            modbusLoopHelp($profile, $prompt);
            return 0;
        }
        $host = trim($argv[1] ?? $defaultHost);
        if (preg_match('~^https?://~i', $host)) {
            $host = trim((string) parse_url($host, PHP_URL_HOST), '[]');
        }
        $port = filter_var($argv[2] ?? '502', FILTER_VALIDATE_INT);
        $unitId = filter_var($argv[3] ?? '1', FILTER_VALIDATE_INT);
        if ($port === false || $unitId === false) {
            throw new InvalidArgumentException('Port und Unit-ID muessen ganze Zahlen sein.');
        }
        $client = new ModbusSnapshotClient($host, $profile, $port, $unitId, 3.0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
        return 1;
    }
    
    if ($once !== null) {
        try {
            $snapshot = modbusLoopSnapshot($client);
            $values = new ModbusSensorValues($snapshot['data'], $profile);
            modbusLoopJson($values->value($once));
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
            return 1;
        }
    }
    echo "Modbus TCP: $host:$port, Unit-ID $unitId, Profil $profileName\n";
    modbusLoopHelp($profile, $prompt);
    while (true) {
        echo "\n$prompt> ";
        $line = fgets(STDIN);
        if ($line === false) {
            break;
        }
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (in_array(strtolower($line), ['q', 'quit', 'exit', 'ende'], true)) {
            break;
        }
        try {
            $parts = preg_split('/\s+/', $line, 2);
            $command = strtolower($parts[0]);
            $argument = trim($parts[1] ?? '');
            if (in_array($command, ['help', 'hilfe', '?'], true)) {
                modbusLoopHelp($profile, $prompt);
            } elseif (in_array($command, ['list', 'catalog', 'groups', 'gruppen'], true)) {
                modbusLoopCatalog($profile, $argument, in_array($command, ['groups', 'gruppen'], true));
            } elseif (in_array($command, ['json', 'snapshot', 'all'], true)) {
                modbusLoopJson(modbusLoopSnapshot($client));
            } else {
                $name = in_array($command, ['get', 'wert', 'info'], true) ? $argument : $line;
                if ($name === '') {
                    throw new InvalidArgumentException('Nach get einen Namen aus list eingeben.');
                }
                $snapshot = modbusLoopSnapshot($client);
                $values = new ModbusSensorValues($snapshot['data'], $profile);
                $value = $values->value($name);
                if ($command !== 'info') {
                    modbusLoopJson($value);
                    continue;
                }
                modbusLoopJson([
                    'host' => $host,
                    'port' => $port,
                    'unitId' => $unitId,
                    'timestamp' => $snapshot['timestamp'],
                    'name' => $name,
                    'value' => $value,
                    'unit' => $values->unit($name),
                ]);
            }
        } catch (Throwable $error) {
            fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
        }
    }
    $client->close();
    return 0;
}

function modbusLoopSnapshot(ModbusSnapshotClient $client): array
{
    try {
        return $client->readSnapshot();
    } finally {
        // Vor Aufbereitung und Eingabepause die Verbindung für andere Leser freigeben.
        $client->close();
    }
}

function modbusLoopJson(mixed $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}

function modbusLoopCatalog(array $profile, string $filter = '', bool $groupsOnly = false): void
{
    foreach (ModbusSensorValues::catalog($profile) as $name => $entry) {
        if (($groupsOnly && $entry['kind'] !== 'Gruppe') || ($filter !== '' && stripos($name, $filter) === false)) {
            continue;
        }
        printf("  %-40s %-7s %-4s %-6s %s\n", $name, $entry['kind'], $entry['unit'], $entry['addressHex'] ?? '', $entry['description']);
    }
}

function modbusLoopHelp(array $profile, string $prompt): void
{
    echo "\nProfil: " . $profile['device'] . "\n";
    echo <<<'HELP'

Befehle eingeben und Enter druecken:
  get NAME / NAME          Einzelwert oder ganze Gruppe wie im Collector lesen
  info NAME                Auswahl mit Host, Zeitstempel und Einheit ausgeben
  list [FILTER]            Namen, Gruppen, Einheiten und Beschreibungen aus dem Profil
  groups [FILTER]          Nur Gruppen aus dem Profil anzeigen
  json / snapshot / all    Nativen Snapshot mit Metadaten als JSON lesen
  get all / get modbus     Nur die nativen Daten wie beim Collector auswaehlen
  help / hilfe / ?         Diese Hilfe anzeigen
  q / quit / exit / ende   Programm beenden

Gruppen des ausgewaehlten Profils:
HELP;
    echo PHP_EOL;
    modbusLoopCatalog($profile, '', true);
    echo <<<'HELP'

Die angezeigten Namen direkt oder mit get eingeben; Unterwerte stehen in list.
Werte und Gruppen werden wie im Collector mit ihren Profileinheiten aufbereitet.
Native Energiezaehler bleiben in kWh, today/lifetime liefern Energiewerte in Wh.
Fehlende Werte bleiben null. help, groups und list greifen nicht auf das Geraet zu.
Jede Leseeingabe liest einen frischen Snapshot und gibt die Verbindung danach frei.
Keine automatische Dauerabfrage, keine Schreibbefehle, keine Cloud.

HELP;
}

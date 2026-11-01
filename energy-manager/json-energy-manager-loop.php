<?php

declare(strict_types=1);

use CohEnergy\EnergyManager;
use CohEnergy\LiveSimulation;

require_once __DIR__ . '/EnergyManager.php';
require_once __DIR__ . '/LiveSimulation.php';
require_once __DIR__ . '/StorageProWriter.php';
require_once dirname(__DIR__) . '/sensorCollect/Sensor/AmpereStorageProModbus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
date_default_timezone_set('Europe/Berlin');

function energyLoopHelp(): void
{
    echo <<<'HELP'

Befehle (ohne "energy>" eingeben):
  live                       Frische Leistungswerte + Simulation anzeigen
  snapshot                   Vollstaendigen Modbus-Snapshot holen, als JSON zeigen
  json                       Letzten Abruf als JSON zeigen (kein Netzwerk)
  get battery.soc            Einzelwert aus letztem Abruf (kein Netzwerk)
  simulate                   Letzten Abruf erneut berechnen (kein Netzwerk)
  loop [Anzahl] [Pause]      Live-Serie; Standard 5 Laeufe, 30 s Pause
  loop 0 30                 Unbegrenzt, Abbruch mit Strg+C beendet das Programm
  params                     Verbindung und Testannahmen anzeigen
  set host 192.168.178.30     Ziel aendern
  set port 502               Modbus-Port
  set unit 1                 Unit-ID
  set timeout 3              Verbindungs-/Lesetimeout in Sekunden
  set interval 30            Verbindungspause (mindestens 5 s)
  set grid-sign 1            +1: positiver Netzrohwert = Bezug; -1: umgekehrt
  set battery-sign 1         +1: positiver Batterierohwert = Entladen
  set heater 0               Konstante Heizlast-ANNAHME in W, kein Messwert
  set heater unknown         Heizlast wieder als unbekannt behandeln
  set min-soc 50             Batterie-SOC-Reserve in %
  set reserve 100            Leistungsreserve in W
  set max-heater 3000        Maximale simulierte Heizleistung in W
  demo                       Szenarien mit Kunstwerten, ohne Geraetezugriff
  write-load PFAD            Private Registerfreigaben laden (JSON)
  write-plan 6 ADRESSE WERT   FC06-Vorschau; liest nur, schreibt nichts
  write-plan 16 ADR W1,W2     FC16-Vorschau fuer zusammenhaengende Register
  write-confirm CODE         Einmalig bestaetigen, nach spaetestens 60 s verfallen
  write-cancel               Ausstehende Vorschau verwerfen
  help                       Diese Hilfe
  quit / exit                Beenden

Vorzeichen erst durch Vergleich mit der Anlage bestaetigen. Ohne beide
Vorzeichen und eine Heizlastannahme wird kein nutzbarer Sollwert berechnet.
Live/Simulation schreiben nichts. Schreiben nur ueber explizite write-Befehle
und private Registerfreigaben. Parameter gelten nur bis Programmende.
Modbus schliesst sofort nach jedem Abruf, vor Ausgabe und Pause.
HELP;
    echo "\n";
}

function energyLoopDecision(array $config, ?array $sample, ?float $started, ?float $heater, bool $demo = false): void
{
    if ($sample === null || $started === null) {
        echo "Noch keine Daten. Zuerst live oder snapshot eingeben.\n";
        return;
    }
    $age = max(0, microtime(true) - $started);
    $d = $sample['data'];
    printf("PV %s W | Haus %s W | Netz roh %s W | Batterie roh %s W | SOC %s %%\n",
        $d['pv']['power'] ?? '?', $d['house']['power'] ?? '?', $d['grid']['power'] ?? '?',
        $d['battery']['power'] ?? '?', $d['battery']['soc'] ?? '?');
    echo 'Heizlast-ANNAHME: ' . ($heater === null ? 'unbekannt' : "$heater W (nicht gemessen)") . "\n";
    if ($config['mode'] !== 'simulate') {
        echo "Berechnung gesperrt: grid-sign und battery-sign noch nicht beide gesetzt.\n";
        return;
    }
    $decision = EnergyManager::decide($config, $sample, $age, $heater);
    printf("%sSollwert: %d W | %s | Messalter %.1f s\n", $demo ? 'DEMO-' : 'Simulierter ', $decision['powerWatts'], $decision['reason'], $age);
}

$config = json_decode((string) file_get_contents(__DIR__ . '/config.example.json'), true, 512, JSON_THROW_ON_ERROR);
$config['storagePro']['host'] = $argv[1] ?? '192.168.178.30';
if (in_array($config['storagePro']['host'], ['--help', '-h'], true)) {
    energyLoopHelp();
    exit(0);
}
$config['heater']['enabled'] = true;
$config['heater']['maximumPowerWatts'] = 3000;
$config['policy']['minimumBatterySocPercent'] = 50;
$config['policy']['gridReserveWatts'] = 100;
$config['policy']['consumerPriority'] = ['heater'];
$interval = 30.0;
$heater = null;
$sample = null;
$started = null;
$sampleSource = null;
$lock = null;
$writePolicy = ['enabled' => false, 'registers' => []];
$writePlan = null;
try {
    EnergyManager::validate($config);
    $lock = fopen(sys_get_temp_dir() . '/coh-energy-manager.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Energiemanager/Live-Test laeuft bereits oder Sperre nicht verfuegbar.');
    }
    echo "COH Energie-Testdialog | Ziel {$config['storagePro']['host']}:502\n";
    echo "Start ohne Netzwerkzugriff. Schreiben standardmaessig gesperrt.\n";
    echo "Testvorgaben: Reserve 100 W, Mindest-SOC 50 %, Heizmaximum 3000 W.\n";
    energyLoopHelp();
    while (true) {
        echo "\nenergy> ";
        $line = fgets(STDIN);
        if ($line === false) {
            break;
        }
        $parts = preg_split('/\s+/', trim($line));
        $command = strtolower($parts[0]);
        if ($command === '') {
            continue;
        }
        try {
            if (in_array($command, ['quit', 'exit'], true)) {
                break;
            }
            if (in_array($command, ['help', 'hilfe', '?'], true)) {
                energyLoopHelp();
            } elseif ($command === 'params') {
                echo json_encode(['storagePro' => $config['storagePro'], 'policy' => $config['policy'],
                    'maxHeaterWatts' => $config['heater']['maximumPowerWatts'], 'heaterAssumptionWatts' => $heater,
                    'intervalSeconds' => $interval, 'maxSampleAgeSeconds' => $config['maxSampleAgeSeconds']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            } elseif ($command === 'write-load') {
                $path = trim(substr(trim($line), strlen('write-load')));
                $writePlan = null;
                $writePolicy = ['enabled' => false, 'registers' => []];
                $contents = $path === '' ? false : @file_get_contents($path);
                if ($contents === false) {
                    throw new InvalidArgumentException('Lesbare private JSON-Datei angeben.');
                }
                $loaded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($loaded) || !is_bool($loaded['enabled'] ?? null) || !is_array($loaded['registers'] ?? null)) {
                    throw new InvalidArgumentException('Policy braucht enabled (bool) und registers (Objekt).');
                }
                $writePolicy = \CohEnergy\StorageProWriter::normalizePolicy($loaded);
                echo 'Schreibfreigabe: ' . ($loaded['enabled'] ? 'AN' : 'AUS (nur Vorschau)') . ', Register: ' . count($loaded['registers']) . "\n";
            } elseif ($command === 'write-cancel') {
                $writePlan = null;
                echo "Vorschau verworfen.\n";
            } elseif ($command === 'write-plan') {
                $writePlan = null;
                if (count($parts) !== 4) {
                    throw new InvalidArgumentException('Syntax: write-plan 6|16 Adresse Rohwert[,Rohwert]');
                }
                $parse = static function (string $value): int {
                    if (!preg_match('/^(?:0x[0-9a-fA-F]{1,4}|[0-9]{1,5})$/D', $value)) {
                        throw new InvalidArgumentException('Adresse/Rohwerte als 0..65535 oder 0xHEX angeben.');
                    }
                    $n = str_starts_with($value, '0x') ? hexdec(substr($value, 2)) : (int) $value;
                    if ($n > 65535) {
                        throw new InvalidArgumentException('Maximal 65535.');
                    }
                    return (int) $n;
                };
                $writer = new \CohEnergy\StorageProWriter($config['storagePro'], $writePolicy);
                $writePlan = $writer->preview($parse($parts[1]), $parse($parts[2]), array_map($parse, explode(',', $parts[3])));
                echo json_encode($writePlan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                echo "Nur gelesen; Verbindung geschlossen. Bestaetigung innerhalb 60 s: write-confirm {$writePlan['confirmation']}\n";
            } elseif ($command === 'write-confirm') {
                $plan = $writePlan;
                $writePlan = null;
                if ($plan === null || count($parts) !== 2) {
                    throw new InvalidArgumentException('Keine Vorschau oder Bestaetigung fehlt.');
                }
                // Durable attempt record is mandatory before calling a writer.
                $audit = fopen(__DIR__ . '/write-events.jsonl', 'ab');
                if ($audit === false) {
                    throw new RuntimeException('Schreibprotokoll nicht verfuegbar; nichts geschrieben.');
                }
                $record = static function (string $status, array $details) use ($audit): void {
                    $text = json_encode(['time' => gmdate(DATE_ATOM), 'status' => $status, 'details' => $details], JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($audit, $text) !== strlen($text) || !fflush($audit) || (function_exists('fsync') && !fsync($audit))) {
                        throw new RuntimeException('Protokollierung fehlgeschlagen; Zustand pruefen.');
                    }
                };
                try {
                    $record('requested', $plan);
                    $writer = new \CohEnergy\StorageProWriter($config['storagePro'], $writePolicy);
                    $result = $writer->execute($plan, $parts[1]);
                    $record('verified', $result);
                    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\nVerbindung geschlossen.\n";
                } catch (Throwable $error) {
                    $record('failed_or_uncertain', ['error' => $error->getMessage()]);
                    throw $error;
                } finally {
                    fclose($audit);
                    $sample = null;
                    $started = null;
                }
            } elseif ($command === 'set') {
                $writePlan = null;
                if (count($parts) !== 3) {
                    throw new InvalidArgumentException('Syntax: set Parameter Wert');
                }
                [$unused, $key, $value] = $parts;
                $next = $config;
                $newHeater = $heater;
                $newInterval = $interval;
                if ($key === 'host') {
                    $next['storagePro']['host'] = $value;
                } elseif ($key === 'heater' && $value === 'unknown') {
                    $newHeater = null;
                } else {
                    if (!is_numeric($value) || !is_finite((float) $value)) {
                        throw new InvalidArgumentException('Numerischen Wert angeben.');
                    }
                    $n = (float) $value;
                    switch ($key) {
                        case 'grid-sign':
                        case 'battery-sign':
                            if (!in_array($n, [-1.0, 1.0], true)) {
                                throw new InvalidArgumentException('Vorzeichen muss -1 oder 1 sein.');
                            }
                            $next['storagePro'][$key === 'grid-sign' ? 'gridImportSign' : 'batteryDischargeSign'] = (int) $n;
                            break;
                        case 'port':
                        case 'unit':
                            EnergyManager::number($n, $key, $key === 'port' ? 1 : 0, $key === 'port' ? 65535 : 255);
                            if (floor($n) !== $n) {
                                throw new InvalidArgumentException('Ganzzahl erforderlich.');
                            }
                            $next['storagePro'][$key === 'port' ? 'port' : 'unitId'] = (int) $n;
                            break;
                        case 'timeout': $next['storagePro']['timeoutSeconds'] = $n; break;
                        case 'interval': EnergyManager::number($n, $key, 5, 3600); $newInterval = $n; break;
                        case 'heater': EnergyManager::number($n, $key, 0, $next['heater']['maximumPowerWatts']); $newHeater = $n; break;
                        case 'min-soc': EnergyManager::number($n, $key, 0, 100); $next['policy']['minimumBatterySocPercent'] = $n; break;
                        case 'reserve': EnergyManager::number($n, $key, 0, 100000); $next['policy']['gridReserveWatts'] = $n; break;
                        case 'max-heater': EnergyManager::number($n, $key, $heater ?? 0, 6500); $next['heater']['maximumPowerWatts'] = $n; break;
                        default: throw new InvalidArgumentException('Unbekannter Parameter; help zeigt die Auswahl.');
                    }
                }
                $next['mode'] = $next['storagePro']['gridImportSign'] !== null && $next['storagePro']['batteryDischargeSign'] !== null ? 'simulate' : 'observe';
                EnergyManager::validate($next);
                if ($next['storagePro'] !== $config['storagePro']) {
                    $sample = null;
                    $started = null;
                }
                $config = $next;
                $heater = $newHeater;
                $interval = $newInterval;
                echo "Gesetzt: $key = $value (nur fuer diese Sitzung).\n";
            } elseif ($command === 'json' || $command === 'get' || $command === 'simulate') {
                if ($sample === null) {
                    echo "Noch keine Daten. Zuerst live oder snapshot eingeben.\n";
                    continue;
                }
                echo "Letzter Abruf: $sampleSource | Alter " . round(microtime(true) - $started, 1) . " s, kein neuer Zugriff.\n";
                if ($command === 'json') {
                    echo json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                } elseif ($command === 'get') {
                    $value = $sample['data'];
                    foreach (explode('.', $parts[1] ?? '') as $segment) {
                        if (!is_array($value) || !array_key_exists($segment, $value)) {
                            throw new InvalidArgumentException('Pfad nicht im letzten Abruf enthalten. Fuer alle Felder snapshot verwenden.');
                        }
                        $value = $value[$segment];
                    }
                    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                } else {
                    energyLoopDecision($config, $sample, $started, $heater);
                }
            } elseif ($command === 'demo') {
                $demo = $config;
                $demo['mode'] = 'simulate';
                $demo['storagePro']['gridImportSign'] = 1;
                $demo['storagePro']['batteryDischargeSign'] = 1;
                echo "DEMO: kuenstliche Daten, Vorzeichen +1, Heizlastannahme 500 W. Live-Cache bleibt erhalten.\n";
                foreach (['Sonne' => [-2000, 0, 90], 'Wolke / Netzbezug' => [700, 0, 90],
                    'Batterieentladung' => [-500, 1000, 90], 'Niedriger SOC' => [-2000, 0, 10]] as $name => [$grid, $battery, $soc]) {
                    echo "\n--- $name ---\n";
                    energyLoopDecision($demo, ['data' => ['pv' => ['power' => 4000], 'house' => ['power' => 4000 + $grid + $battery],
                        'grid' => ['power' => $grid], 'battery' => ['power' => $battery, 'soc' => $soc]]], microtime(true), 500, true);
                }
            } elseif (in_array($command, ['live', 'snapshot', 'loop'], true)) {
                $count = $command === 'loop' ? ($parts[1] ?? '5') : '1';
                $pause = $parts[2] ?? (string) $interval;
                if (!ctype_digit($count) || (float) $count > 1000000 || !is_numeric($pause)) {
                    throw new InvalidArgumentException('Syntax: loop [0..1000000] [Pause in Sekunden]');
                }
                EnergyManager::number((float) $pause, 'Pause', 5, 3600);
                $limit = (int) $count === 0 ? PHP_INT_MAX : (int) $count;
                for ($round = 1; $round <= $limit; ++$round) {
                    $d = $config['storagePro'];
                    $client = new AmpereStorageProModbus($d['host'], $d['port'], $d['unitId'], $d['timeoutSeconds']);
                    $sample = null;
                    $started = null;
                    try {
                        $begin = microtime(true);
                        try {
                            $result = $command === 'snapshot' ? $client->readSnapshot() : LiveSimulation::read($client);
                        } finally {
                            $client->close();
                        }
                        $sample = $result;
                        $started = $begin;
                        $sampleSource = date('d.m.Y H:i:s T');
                        echo "\n$sampleSource | Lauf $round | Verbindung geschlossen\n";
                        if ($command === 'snapshot') {
                            echo json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                        }
                        energyLoopDecision($config, $sample, $started, $heater);
                    } catch (Throwable $error) {
                        echo 'Abruf fehlgeschlagen (' . get_class($error) . "). Verbindung geschlossen, Cache ungueltig.\n";
                    }
                    if ($round < $limit) {
                        echo "Pause $pause s, keine Verbindung offen. Strg+C beendet das Programm.\n";
                        $end = microtime(true) + (float) $pause;
                        while (microtime(true) < $end) {
                            usleep(100000);
                        }
                    }
                }
            } else {
                echo "Unbekannter Befehl. help zeigt die Auswahl.\n";
            }
        } catch (Throwable $error) {
            echo 'Eingabe/Berechnung abgebrochen: ' . $error->getMessage() . "\n";
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Start abgebrochen: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

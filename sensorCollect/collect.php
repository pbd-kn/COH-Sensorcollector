<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector;

// Autoloader
require_once __DIR__ . '/autoload.php';

use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\FetcherRegistry;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\SensorPararameter;
//use mysqli;

// ---------------------------------------------------------
// Setup
// ---------------------------------------------------------
date_default_timezone_set('Europe/Berlin');
$logger = Logger::getInstance('/home/peter/coh/logs/sensor-collect.log');
$SensorParameter = SensorParameter::getInstance();
// DB-Verbindung
$db = new mysql_dialog();
if (!$db->connect('localhost', 'peter', 'sql666sql', 'co5_solar')) { 
    $logger->Error("DB connect failed: " . $db->errors);
    exit(1);
}

// ---------------------------------------------------------
// Registry & Fetcher laden
// ---------------------------------------------------------
$registry = new FetcherRegistry();
foreach (glob(__DIR__ . '/Sensor/*.php') as $file) {
    require_once $file;
}

$httpClient = new SimpleHttpClient();
foreach (get_declared_classes() as $class) {
    if (is_subclass_of($class, SensorFetcherInterface::class)) {
        $fetcher = new $class($db, $logger, $httpClient);
        $registry->registerFetcher('sensor.fetcher', $fetcher);
    }
}

$fetchers = $registry->getFetchersByTag('sensor.fetcher');

// SensorManager
$manager = new SensorManager($db,$logger,$fetchers);

// ---------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------
/**
 * Hole das history-Flag für eine sensorID.
 */
function getHistoryFlag(mysql_dialog  $db, string $sensorID): int
{
    $sql = "SELECT history FROM tl_coh_sensors WHERE sensorID = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new \RuntimeException("prepare(getHistoryFlag) failed: " . $db->error);
    }
    $stmt->bind_param('s', $sensorID);
    $stmt->execute();
    $stmt->bind_result($history);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? (int)$history : 1; // Default: 1 (Historie sammeln), falls Sensor nicht gefunden
}

/**
 * Update der jüngsten Zeile für sensorID (history=0-Fall).
 * Legt eine Zeile an, wenn es noch keine gibt.
 */
function upsertCurrentValue( mysql_dialog $db, string $sensorID, int $tstamp, string $sensorValue, string $einheit,string $type, string $source, Logger $logger ): void {
    $db->begin_transaction();

    try {
        // ---------------------------------------------------
        // WERTE NORMALISIEREN
        // ---------------------------------------------------
        $sensorValue = trim($sensorValue);
        $einheit     = trim($einheit);
        $type        = trim($type);
        $source      = trim($source);

        if ($sensorValue === '') {
            $logger->debugMe("Skip current upsert: empty value for sensorID=$sensorID");
            $db->commit();
            return;
        }

        // ---------------------------------------------------
        // LETZTEN EINTRAG HOLEN (LOCK)
        // ---------------------------------------------------
        $sqlFind = "SELECT id
                      FROM tl_coh_sensorvalue
                     WHERE sensorID = ?
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE";

        $stmt = $db->prepare($sqlFind);
        if (!$stmt) {
            throw new \RuntimeException("prepare(select current) failed: " . $db->error);
        }

        $stmt->bind_param('s', $sensorID);
        $stmt->execute();
        $stmt->bind_result($id);
        $hasRow = $stmt->fetch();
        $stmt->close();

        // ---------------------------------------------------
        // UPDATE (IMMER ALLES SETZEN!)
        // ---------------------------------------------------
        if ($hasRow) {
            $upd = "UPDATE tl_coh_sensorvalue
                       SET tstamp = ?,
                           sensorValue = ?,
                           sensorEinheit = ?,
                           sensorValueType = ?,
                           sensorSource = ?
                     WHERE id = ?";

            $stmtU = $db->prepare($upd);
            if (!$stmtU) {
                throw new \RuntimeException("prepare(update current) failed: " . $db->error);
            }

            $stmtU->bind_param(
                'issssi',
                $tstamp,
                $sensorValue,
                $einheit,
                $type,
                $source,
                $id
            );

            if (!$stmtU->execute()) {
                throw new \RuntimeException("exec(update current) failed: " . $stmtU->error);
            }

            $stmtU->close();

            $logger->debugMe(
                "Update current: id=$id, sensorID=$sensorID, value=" .
                var_export($sensorValue, true) .
                ", einheit=$einheit"
            );

        } else {

            // ---------------------------------------------------
            // INSERT
            // ---------------------------------------------------
            $ins = "INSERT INTO tl_coh_sensorvalue
                       (tstamp, sensorID, sensorValue, sensorEinheit, sensorValueType, sensorSource)
                    VALUES (?, ?, ?, ?, ?, ?)";

            $stmtI = $db->prepare($ins);
            if (!$stmtI) {
                throw new \RuntimeException("prepare(insert current) failed: " . $db->error);
            }

            $stmtI->bind_param(
                'isssss',
                $tstamp,
                $sensorID,
                $sensorValue,
                $einheit,
                $type,
                $source
            );

            if (!$stmtI->execute()) {
                throw new \RuntimeException("exec(insert current) failed: " . $stmtI->error);
            }

            $stmtI->close();

            $logger->debugMe(
                "Insert current: sensorID=$sensorID, tstamp=$tstamp, value=" .
                var_export($sensorValue, true)
            );
        }

        $db->commit();

    } catch (\Throwable $e) {
        $db->rollback();
        $logger->Error($e->getMessage());
        throw $e;
    }
}
/**
 * Historie-Fall: wenn der letzte Wert gleich ist -> nur tstamp aktualisieren,
 * sonst neuen Datensatz anlegen.
 */
function insertOrTouchHistory( mysql_dialog $db, string $sensorID, int $tstamp, string $sensorValue, string $einheit, string $type, string $source, Logger $logger): void {
    $db->begin_transaction();

    try {
        // Letzten Eintrag holen & sperren
        $sqlLast = "SELECT id,
                           TRIM(sensorValue)       AS v,
                           TRIM(sensorEinheit)    AS e,
                           TRIM(sensorValueType)  AS t,
                           TRIM(sensorSource)     AS s
                      FROM tl_coh_sensorvalue
                     WHERE sensorID = ?
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE";

        $stmt = $db->prepare($sqlLast);
        if (!$stmt) {
            $logger->Error("Skip history insert: empty value for sensorID=$sensorID");
            $db->commit();
            return;
        }

        $stmt->bind_param('s', $sensorID);
        $stmt->execute();
        $stmt->bind_result($lastId, $lastVal, $lastEinheit, $lastType, $lastSource);
        $hasLast = $stmt->fetch();
        $stmt->close();

        $curVal      = trim($sensorValue);
        $curEinheit  = trim($einheit);
        $curType     = trim($type);
        $curSource   = trim($source);

        if ($curVal === '') {
            //$logger->debugMe("Skip history insert: empty value for sensorID=$sensorID");
            $logger->Info("Skip insert: empty value ('null' || 'NULL' || 'UNDEF') for sensorID=$sensorID Alter Wert $lastVal $lastEinheit bleibt bestehen");
            $db->commit();
            return;
        }

        // Vergleich: jetzt ALLES berücksichtigen
        $isSame =
            $hasLast &&
            $lastVal      === $curVal &&
            $lastEinheit  === $curEinheit &&
            $lastType     === $curType &&
            $lastSource   === $curSource;

        if ($isSame) {
            // nur Touch
            $upd = "UPDATE tl_coh_sensorvalue SET tstamp = ? WHERE id = ?";
            $stmtU = $db->prepare($upd);
            if (!$stmtU) {
                $logger->Error("prepare(update touch) failed: " . $db->error);
                $db->commit();
                return;
            }

            $stmtU->bind_param('ii', $tstamp, $lastId);

            if (!$stmtU->execute()) {
                $logger->Error("exec(update touch) failed: " . $stmtU->error);
                $db->commit();
                return;
            }

            $stmtU->close();
            //$logger->debugMe("Touch history: id=$lastId, sensorID=$sensorID, tstamp=$tstamp");
            $logger->debugMe("Touch insert: id=$lastId, sensorID=$sensorID, tstamp=$tstamp");
        } else {
            // INSERT (auch wenn nur Einheit geändert wurde!)
            $ins = "INSERT INTO tl_coh_sensorvalue
                       (tstamp, sensorID, sensorValue, sensorEinheit, sensorValueType, sensorSource)
                    VALUES (?, ?, ?, ?, ?, ?)";

            $stmtI = $db->prepare($ins);
            if (!$stmtI) {
                $logger->Error("prepare(insert hist) failed: " . $db->error);
                $db->commit();
                return;
            }

            $stmtI->bind_param('isssss', $tstamp, $sensorID, $sensorValue, $einheit, $type, $source);

            if (!$stmtI->execute()) {
                $logger->Error("exec(insert hist) failed: " . $stmtI->error);
                $db->commit();
                $stmtI->close();
                return;
            }

            $stmtI->close();

            $logger->debugMe(
                "Insert history: sensorID=$sensorID, tstamp=$tstamp, value=[" .
                var_export($sensorValue, true) . "], einheit=$einheit"
            );
        }

        $db->commit();

    } catch (\Throwable $e) {
        $db->rollback();
        $logger->Error($e->getMessage());
        throw $e;
    }
}

/**
 * Speicher die aufgesammelten sensorwerte in die datenbank
 */
 
function saveSensors(mysql_dialog $db, Logger $logger, array $arrResults ): int
{    
    //$logger->Info("saveSensors len " . count($arrResults));

    $now = time();
    $anz = 0;
    foreach ($arrResults as $result) {
        // Normalisieren
        $sensorID        = trim((string)($result['sensorID']        ?? ''));
        $sensorValue     = (string)($result['sensorValue']          ?? '');
        $sensorEinheit   = (string)($result['sensorEinheit']        ?? '');
        $sensorValueType = (string)($result['sensorValueType']      ?? '');
        $sensorSource    = (string)($result['sensorSource']         ?? '');
        $tstamp          = time();
        if ($sensorID === '') {
            $logger->Error("Leere sensorID in fetchAll()-Result – Eintrag wird übersprungen ($anz). sensorValue $sensorValue sensorSource $sensorSource");
            continue;
        }
        // History-Flag holen (kein JOIN mit tl_coh_sensorvalue!)
        try {
            $history = getHistoryFlag($db, $sensorID);
        } catch (\Throwable $e) {
            $logger->Error("history lookup failed for sensorID='$sensorID': " . $e->getMessage());
            continue;
        }

        // Logging für versteckte Unterschiede
        $logger->debugMe(sprintf( 'SID raw="%s" len=%d hex=%s', $sensorID, strlen($sensorID), bin2hex($sensorID)));

        try {
            if ($history === 0) {
                // genau eine aktuelle Zeile pflegen
                //upsertCurrentValue($db, $sensorID, $tstamp, $sensorValue, $sensorEinheit, $sensorValueType, $sensorSource, $logger);
                // geändert 28.04.2026 auch aktuelle werte werdn wenn sie identisch sind nur das datum geändert
                // aufsummierungen über parameter ausgbe im sensor
                insertOrTouchHistory($db, $sensorID, $tstamp, $sensorValue, $sensorEinheit, $sensorValueType, $sensorSource, $logger);
            } else {
                // Historie sammeln (aber bei identischem letzten Wert nur tstamp updaten)
                insertOrTouchHistory($db, $sensorID, $tstamp, $sensorValue, $sensorEinheit, $sensorValueType, $sensorSource, $logger);
            }
        } catch (\Throwable $e) {
            // Fehler pro Sensor protokollieren, weiter mit dem nächsten
            $logger->Error("processing failed for sensorID='$sensorID': " . $e->getMessage());
        }
        $anz++;
    }
    //$logger->Info("saveSensors geschrieben $anz " . count($arrResults));
    return $anz;
}

// ---------------------------------------------------------
// Main-Loop
// ---------------------------------------------------------

function loadCollectorConfiguration(mysql_dialog $db, Logger $logger, SensorParameter $SensorParameter): int
{
    $res = $db->query("SELECT * FROM tl_coh_cfgcollect");
    if (!$res) {
        throw new \RuntimeException("cfg query failed: " . $db->error);
    }

    $debug = false;
    $pollTime = 15;
    while ($cfg = $res->fetch_assoc()) {
        $logger->debugMe('cfgID: '.$cfg['cfgID'].' cfgType: '.$cfg['cfgType'].' cfgValue: '.$cfg['cfgValue']);
        if ($cfg['cfgType'] === 'debug') {
            $debug = ((int) $cfg['cfgValue'] !== 0);
        } elseif ($cfg['cfgType'] === 'pollTime') {
            $pollTime = max(1, (int) $cfg['cfgValue']);
        }
    }
    $res->free();
    $logger->setDebug($debug);
    $SensorParameter->setpollTime($pollTime);
    return $pollTime;
}

function printSensorResults(array $results): void
{
    if ($results === []) {
        echo "Der Sensor wurde gefunden, aber seine Quelle hat keinen Messwert geliefert.\n";
        echo "Bitte die unmittelbar davor ausgegebenen Fetcher-, Netzwerk- oder API-Fehler pruefen.\n";
        return;
    }
    echo "\nRequest-Ergebnis:\n";
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function printSensorDetails(array $details): void
{
    echo "\nSensor-Konfiguration:\n";
    echo json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function printSensorOverview(array $sensors): void
{
    if ($sensors === []) {
        echo "Keine passenden Sensoren gefunden.\n";
        return;
    }

    printf("%-28s %-45s %s\n", 'sensorID', 'Sensorname', 'Quelle');
    echo str_repeat('-', 100) . PHP_EOL;
    foreach ($sensors as $sensor) {
        printf(
            "%-28s %-45s %s\n",
            (string) $sensor['sensorID'],
            (string) $sensor['sensorTitle'],
            (string) $sensor['source']
        );
    }
    echo count($sensors) . " Sensor(en) gefunden.\n";
}

/**
 * Konsoleneingabe mit Befehlshistorie und Cursor-Unterstuetzung.
 * Ohne PHP-Readline-Erweiterung wird automatisch STDIN verwendet.
 */
function readConsoleCommand(string $prompt): string|false
{
    if (function_exists('readline')) {
        $line = readline($prompt);
        if ($line !== false && trim($line) !== '' && function_exists('readline_add_history')) {
            readline_add_history($line);
        }
        return $line;
    }

    echo $prompt;
    return fgets(STDIN);
}

function runConsoleTest(SensorManager $manager, mysql_dialog $db, Logger $logger): bool
{
    $writeToDb = false;
    echo PHP_EOL;
    echo "============================================================\n";
    echo " Sensor-Collector: Test- und Konsolenmodus\n";
    echo "============================================================\n";
    echo " Sicherheit: Das Schreiben in die Datenbank ist AUS.\n";
    echo "\n";
    echo " Verfuegbare Befehle:\n";
    echo "\n";
    echo "   list\n";
    echo "      Zeigt alle konfigurierten Sensoren an.\n";
    echo "\n";
    echo "   list <Quelle>\n";
    echo "      Zeigt nur Sensoren der angegebenen Quelle an.\n";
    echo "      Beispiel: list tasmota\n";
    echo "\n";
    echo "   sources\n";
    echo "      Zeigt alle vorhandenen Sensorquellen an.\n";
    echo "\n";
    echo "   sensor <Name oder ID>\n";
    echo "      Liest genau einen Sensor und zeigt das Ergebnis an.\n";
    echo "      Die Datenbank wird dabei niemals veraendert.\n";
    echo "\n";
    echo "   ids <ID1> <ID2> ...\n";
    echo "      Liest mehrere Sensoren nacheinander ein.\n";
    echo "      Ob gespeichert wird, bestimmt 'write on' bzw. 'write off'.\n";
    echo "\n";
    echo "   ids source <Quelle>\n";
    echo "      Liest alle Sensoren der angegebenen Quelle ein.\n";
    echo "      Beispiel: ids source IQbox\n";
    echo "\n";
    echo "   write on | write off\n";
    echo "      Schaltet das Speichern fuer den Befehl 'ids' ein oder aus.\n";
    echo "\n";
    echo "   debug on | debug off\n";
    echo "      Schaltet die ausfuehrlichen Debug-Ausgaben ein oder aus.\n";
    echo "\n";
    echo "   run\n";
    echo "      Startet den normalen Endlosbetrieb mit Poll- und Sleep-Zeit.\n";
    echo "\n";
    echo "   q\n";
    echo "      Beendet das Programm.\n";
    echo "\n";
    echo "   help\n";
    echo "      Zeigt die Kurzuebersicht der Befehle erneut an.\n";
    echo "============================================================\n";
    echo PHP_EOL;
    if (!function_exists('readline')) {
        echo "Hinweis: PHP-Readline ist nicht installiert. Pfeiltasten und Befehlshistorie sind daher nicht verfuegbar.\n";
        echo "Auf Raspberry Pi OS kann die Erweiterung in der Regel mit 'sudo apt install php-readline' installiert werden.\n\n";
    }

    $debugEnabled = $logger->isDebug();
    while (true) {
        $line = readConsoleCommand('collect> ');
        if ($line === false) {
            return false;
        }
        $parts = preg_split('/[\s,;]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            continue;
        }
        $command = strtolower((string) array_shift($parts));

        if ($command === 'q' || $command === 'quit' || $command === 'exit') {
            return false;
        }
        if ($command === 'run') {
            return true;
        }
        if ($command === 'help' || $command === '?') {
            echo "list [Quelle]      alle Sensoren oder Sensoren einer Quelle anzeigen\n";
            echo "sources            alle vorhandenen Quellen anzeigen\n";
            echo "sensor <Name/ID>   einen Sensor testen (immer ohne DB-Schreiben)\n";
            echo "ids <IDs...>       mehrere Sensoren abarbeiten\n";
            echo "ids source <Quelle> alle Sensoren einer Quelle abarbeiten\n";
            echo "write on|off       DB-Schreiben fuer 'ids' ein-/ausschalten\n";
            echo "debug on|off       ausfuehrliche Debug-Ausgaben ein-/ausschalten\n";
            echo "run                normalen Endlosbetrieb mit Sleep starten\n";
            echo "q                  Programm beenden\n";
            continue;
        }
        if ($command === 'list') {
            try {
                $source = $parts === [] ? null : implode(' ', $parts);
                printSensorOverview($manager->getSensorOverview($source));
            } catch (\Throwable $e) {
                $logger->Error('Console list failed: ' . $e->getMessage());
                echo 'Fehler: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }
        if ($command === 'sources') {
            try {
                $sources = [];
                foreach ($manager->getSensorOverview() as $sensor) {
                    $key = (string) $sensor['source'];
                    $sources[$key] = true;
                }
                foreach ($sources as $sourceId => $_present) {
                    echo $sourceId . PHP_EOL;
                }
                echo count($sources) . " Quelle(n) gefunden.\n";
            } catch (\Throwable $e) {
                $logger->Error('Console sources failed: ' . $e->getMessage());
                echo 'Fehler: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }
        if ($command === 'write') {
            $value = strtolower((string) ($parts[0] ?? ''));
            if (!in_array($value, ['on', 'off', '1', '0'], true)) {
                echo "Bitte 'write on' oder 'write off' verwenden.\n";
                continue;
            }
            $writeToDb = in_array($value, ['on', '1'], true);
            echo 'DB-Schreiben: ' . ($writeToDb ? 'EIN' : 'AUS') . PHP_EOL;
            continue;
        }
        if ($command === 'debug') {
            $value = strtolower((string) ($parts[0] ?? ''));
            if (!in_array($value, ['on', 'off', '1', '0'], true)) {
                echo "Bitte 'debug on' oder 'debug off' verwenden.\n";
                continue;
            }
            $debugEnabled = in_array($value, ['on', '1'], true);
            // Die Override-Einstellung bleibt auch dann aktiv, wenn ein Fetcher
            // intern seinen eigenen Debug-Status setzt.
            $logger->setDebugOverride($debugEnabled);
            $logger->setDebugToConsole($debugEnabled);
            echo 'Debug-Ausgaben: ' . ($debugEnabled ? 'EIN' : 'AUS') . PHP_EOL;
            continue;
        }
        if ($command === 'sensor' || $command === 'ids') {
            if ($parts === []) {
                echo "Mindestens einen Sensornamen bzw. eine sensorID angeben.\n";
                continue;
            }
            if ($command === 'ids' && strtolower((string) ($parts[0] ?? '')) === 'source') {
                array_shift($parts);
                $source = trim(implode(' ', $parts));
                if ($source === '') {
                    echo "Bitte eine Quelle angeben, zum Beispiel: ids source IQbox\n";
                    continue;
                }
                $sourceSensors = $manager->getSensorOverview($source);
                if ($sourceSensors === []) {
                    echo "Keine Sensoren fuer die Quelle '$source' gefunden.\n";
                    echo "Mit 'sources' werden alle vorhandenen Quellen angezeigt.\n";
                    continue;
                }
                $selection = array_map(
                    static fn (array $sensor): string => (string) $sensor['sensorID'],
                    $sourceSensors
                );
                echo count($selection) . " Sensor(en) der Quelle '$source' ausgewaehlt.\n";
            } else {
                $selection = $command === 'sensor' ? [implode(' ', $parts)] : $parts;
            }
            $allowWrite = $command === 'ids' && $writeToDb;
            try {
                $knownSensors = $manager->getSensorOverview();
                $knownNames = [];
                foreach ($knownSensors as $knownSensor) {
                    $knownNames[] = strtolower(trim((string) $knownSensor['sensorID']));
                    $knownNames[] = strtolower(trim((string) $knownSensor['sensorTitle']));
                }
                $unknown = array_values(array_filter(
                    $selection,
                    static fn ($selected): bool => !in_array(strtolower(trim((string) $selected)), $knownNames, true)
                ));
                if ($unknown !== []) {
                    echo "Unbekannte Sensor-Auswahl: " . implode(', ', $unknown) . PHP_EOL;
                    echo "Mit 'list' werden alle gueltigen Sensor-IDs angezeigt.\n";
                    continue;
                }

                if ($debugEnabled) {
                    printSensorDetails($manager->getSensorDetails($selection));
                }

                // Im Testmodus keine History-Zaehler veraendern.
                $results = $manager->fetchSensors($selection, false);
                printSensorResults($results);
                if ($allowWrite) {
                    $count = saveSensors($db, $logger, $results);
                    echo "$count Sensorwert(e) in die DB geschrieben.\n";
                } else {
                    echo "DB unveraendert.\n";
                }
            } catch (\Throwable $e) {
                $logger->Error('Console test failed: ' . $e->getMessage());
                echo 'Fehler: ' . $e->getMessage() . PHP_EOL;
            }
            continue;
        }
        echo "Unbekannter Befehl '$command'. Mit 'help' werden die Befehle angezeigt.\n";
    }
}

// Ein systemd-Service verwendet ebenfalls PHP_SAPI=cli, besitzt aber kein
// interaktives Terminal. Nur bei einem echten TTY wird der Dialog gestartet.
// Mit --console kann er bei Bedarf ausdruecklich erzwungen werden.
$isCli = PHP_SAPI === 'cli';
$forceConsole = $isCli && in_array('--console', $argv ?? [], true);
$hasInteractiveTerminal = false;
if ($isCli) {
    if (function_exists('stream_isatty')) {
        $hasInteractiveTerminal = stream_isatty(STDIN);
    } elseif (function_exists('posix_isatty')) {
        $hasInteractiveTerminal = posix_isatty(STDIN);
    }
}
$consoleMode = $isCli && ($hasInteractiveTerminal || $forceConsole);

if ($consoleMode) {
    try {
        loadCollectorConfiguration($db, $logger, $SensorParameter);
    } catch (\Throwable $e) {
        $logger->Error($e->getMessage());
        fwrite(STDERR, 'Konfiguration konnte nicht geladen werden: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
if ($consoleMode && !runConsoleTest($manager, $db, $logger)) {
    exit(0);
}

$iteration = 0;
$logger->Info("Restart: " . date('d.m.Y H:i:s'));

while (true) {
    $iteration++;
    // --- Konfiguration laden ---
    try {
        $pollTime = loadCollectorConfiguration($db, $logger, $SensorParameter);
    } catch (\Throwable $e) {
        $logger->Error($e->getMessage());
        // Fallback: 60 Sekunden warten, dann erneut versuchen
        sleep(60);
        continue;
    }

    // --- Werte abholen ---
    $arrResults = $manager->fetchAll();     // die db wird verwendet um alle senseren per querry zu lesen. sollte evtl in den Konstruktor von sensormanager rein 
    $anz = saveSensors($db, $logger, $arrResults );
    // Erst aufraeumen, wenn Werte aelter als 1 Jahr vorhanden sind.
    // Dann bis 10 Monate zurueck loeschen, damit der Cleanup nicht alle 10 Minuten kleine Mengen entfernt.
    $cleanupSql = "DELETE FROM tl_coh_sensorvalue
                    WHERE tstamp < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 10 MONTH))
                      AND EXISTS (
                          SELECT 1
                            FROM (
                                SELECT id
                                  FROM tl_coh_sensorvalue
                                 WHERE tstamp < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 YEAR))
                                 LIMIT 1
                            ) AS old_sensorvalues
                      )";
    if ($db->query($cleanupSql)) {
        $deleted = $db->affected();
        if ($deleted > 0) {
            $logger->Info("Cleanup: Es wurden in tl_coh_sensorvalue $deleted geloescht die aelter als 10 Monate sind, weil Werte aelter als 1 Jahr vorhanden waren.");
        }
    } else {
        $logger->Error("cleanup failed beim Loeschen alter Saetze");
    }
    // Sleep
    $newPoll=$SensorParameter->getpollTime();
    $sleepSeconds = max(1, $newPoll) * 60;
    $logger->Info("Iteration: $iteration " . date('d.m.Y H:i:s') . " anz. sensor ($anz) Sleep (Minuten): $pollTime");
    sleep($sleepSeconds);
}

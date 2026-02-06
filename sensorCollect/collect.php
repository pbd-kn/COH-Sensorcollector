<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector;

// Autoloader
require_once __DIR__ . '/autoload.php';

use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\FetcherRegistry;
use PbdKn\cohSensorcollector\Logger;
use mysqli;

// ---------------------------------------------------------
// Setup
// ---------------------------------------------------------
date_default_timezone_set('Europe/Berlin');
$logger = Logger::getInstance('/home/peter/coh/logs/sensor-collect.log');

// DB-Verbindung
$db = @new mysqli('localhost', 'peter', 'sql666sql', 'co5_solar');
if ($db->connect_errno) {
    $logger->Error("DB connect failed ({$db->connect_errno}): {$db->connect_error}");
    exit(1);
}
$db->set_charset('utf8mb4');

// ---------------------------------------------------------
// Registry & Fetcher laden
// ---------------------------------------------------------
$registry = new FetcherRegistry();
foreach (glob(__DIR__ . '/Sensor/*.php') as $file) {
    require_once $file;
}
foreach (get_declared_classes() as $class) {
    if (is_subclass_of($class, SensorFetcherInterface::class)) {
        $fetcher = new $class();
        $registry->registerFetcher('sensor.fetcher', $fetcher);
    }
}
$fetchers = $registry->getFetchersByTag('sensor.fetcher');

// SensorManager
$manager = new SensorManager($fetchers);

// ---------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------
/**
 * Hole das history-Flag für eine sensorID.
 */
function getHistoryFlag(mysqli $db, string $sensorID): int
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
function upsertCurrentValue(mysqli $db, string $sensorID, int $tstamp, string $sensorValue, string $einheit, string $type, string $source, Logger $logger): void
{
    // Transaktion starten (gegen Race Conditions)
    $db->begin_transaction();

    try {
        // Jüngste Zeile für diese sensorID sperren
        $sqlFind = "SELECT id FROM tl_coh_sensorvalue WHERE sensorID = ? ORDER BY id DESC LIMIT 1 FOR UPDATE";
        $stmt = $db->prepare($sqlFind);
        if (!$stmt) {
            throw new \RuntimeException("prepare(select current) failed: " . $db->error);
        }
        $stmt->bind_param('s', $sensorID);
        $stmt->execute();
        $stmt->bind_result($id);
        $hasRow = $stmt->fetch();
        $stmt->close();

        if ($hasRow) {
            $upd = "UPDATE tl_coh_sensorvalue
                       SET tstamp = ?, sensorValue = ?, sensorEinheit = ?, sensorValueType = ?, sensorSource = ?
                     WHERE id = ?";
            $stmtU = $db->prepare($upd);
            if (!$stmtU) {
                throw new \RuntimeException("prepare(update current) failed: " . $db->error);
            }
            $stmtU->bind_param('issssi', $tstamp, $sensorValue, $einheit, $type, $source, $id);
            if (!$stmtU->execute()) {
                throw new \RuntimeException("exec(update current) failed: " . $stmtU->error);
            }
            $stmtU->close();
            $logger->debugMe("Update current (history=0): id=$id, sensorID=$sensorID, tstamp=$tstamp");
        } else {
            $ins = "INSERT INTO tl_coh_sensorvalue
                       (tstamp, sensorID, sensorValue, sensorEinheit, sensorValueType, sensorSource)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmtI = $db->prepare($ins);
            if (!$stmtI) {
                throw new \RuntimeException("prepare(insert current) failed: " . $db->error);
            }
            $stmtI->bind_param('isssss', $tstamp, $sensorID, $sensorValue, $einheit, $type, $source);
            if (!$stmtI->execute()) {
                throw new \RuntimeException("exec(insert current) failed: " . $stmtI->error);
            }
            $stmtI->close();
            $logger->debugMe("Insert current (history=0): sensorID=$sensorID, tstamp=$tstamp");
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
function insertOrTouchHistory(mysqli $db, string $sensorID, int $tstamp, string $sensorValue, string $einheit, string $type, string $source, Logger $logger): void
{
    $db->begin_transaction();

    try {
        // Letzten Eintrag für diese sensorID holen & sperren
        $sqlLast = "SELECT id, TRIM(sensorValue) AS v
                      FROM tl_coh_sensorvalue
                     WHERE sensorID = ?
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE";
        $stmt = $db->prepare($sqlLast);
        if (!$stmt) {
            throw new \RuntimeException("prepare(select last hist) failed: " . $db->error);
        }
        $stmt->bind_param('s', $sensorID);
        $stmt->execute();
        $stmt->bind_result($lastId, $lastValTrim);
        $hasLast = $stmt->fetch();
        $stmt->close();

        $curValTrim = trim($sensorValue);
        if ($curValTrim === '') {
            $logger->debugMe("Skip history insert: empty value for sensorID=$sensorID");
            $db->commit();
            return;
        }        

        if ($hasLast && $lastValTrim === $curValTrim) {
            // gleicher Wert -> nur tstamp anheben
            $upd = "UPDATE tl_coh_sensorvalue SET tstamp = ? WHERE id = ?";
            $stmtU = $db->prepare($upd);
            if (!$stmtU) {
                throw new \RuntimeException("prepare(update touch) failed: " . $db->error);
            }
            $stmtU->bind_param('ii', $tstamp, $lastId);
            if (!$stmtU->execute()) {
                throw new \RuntimeException("exec(update touch) failed: " . $stmtU->error);
            }
            $stmtU->close();
            $logger->debugMe("Touch history: id=$lastId, sensorID=$sensorID, tstamp=$tstamp");
        } else {
            // neuer Wert -> INSERT

            $ins = "INSERT INTO tl_coh_sensorvalue
                       (tstamp, sensorID, sensorValue, sensorEinheit, sensorValueType, sensorSource)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmtI = $db->prepare($ins);
            if (!$stmtI) {
                throw new \RuntimeException("prepare(insert hist) failed: " . $db->error);
            }
            $stmtI->bind_param('isssss', $tstamp, $sensorID, $sensorValue, $einheit, $type, $source);
            if (!$stmtI->execute()) {
                throw new \RuntimeException("exec(insert hist) failed: " . $stmtI->error);
            }
            $stmtI->close();
            $logger->debugMe("Insert history: sensorID=$sensorID, tstamp=$tstamp, value=[" . var_export($sensorValue, true) . "]");
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        $logger->Error($e->getMessage());
        throw $e;
    }
}

// ---------------------------------------------------------
// Main-Loop
// ---------------------------------------------------------
$iteration = 0;
$logger->Info("Restart: " . date('d.m.Y H:i:s'));

while (true) {
    $iteration++;
    // --- Konfiguration laden ---
    $cfgSql = "SELECT * FROM tl_coh_cfgcollect";
    $res = $db->query($cfgSql);
    if (!$res) {
        $logger->Error("cfg query failed: " . $db->error);
        // Fallback: 60 Sekunden warten, dann erneut versuchen
        sleep(60);
        continue;
    }

    $arrCfg = [];
    while ($row = $res->fetch_assoc()) {
        $arrCfg[] = $row;
    }
    $res->free();

    $debug = false;
    $pollTime = 15; // Minuten Standard
    foreach ($arrCfg as $cfg) {
        $logger->debugMe('cfgID: '.$cfg['cfgID'].' cfgType: '.$cfg['cfgType'].' cfgValue: '.$cfg['cfgValue']);
        if ($cfg['cfgType'] === 'debug') {
            $debug = ((int)$cfg['cfgValue'] !== 0);
        } elseif ($cfg['cfgType'] === 'pollTime') {
            $pollTime = max(1, (int)$cfg['cfgValue']);
        }
    }
    $logger->setDebug($debug);

    // --- Werte abholen ---
    $arrResults = $manager->fetchAll($db);

    $now = time();
    foreach ($arrResults as $result) {
        // Normalisieren
        $sensorID        = trim((string)($result['sensorID']        ?? ''));
        $sensorValue     = (string)($result['sensorValue']          ?? '');
        $sensorEinheit   = (string)($result['sensorEinheit']        ?? '');
        $sensorValueType = (string)($result['sensorValueType']      ?? '');
        $sensorSource    = (string)($result['sensorSource']         ?? '');
        $tstamp          = time();

        if ($sensorID === '') {
            $logger->Error('Leere sensorID in fetchAll()-Result – Eintrag wird übersprungen.');
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
        $logger->debugMe(sprintf(
            'SID raw="%s" len=%d hex=%s',
            $sensorID,
            strlen($sensorID),
            bin2hex($sensorID)
        ));

        try {
            if ($history === 0) {
                // genau eine aktuelle Zeile pflegen
                upsertCurrentValue($db, $sensorID, $tstamp, $sensorValue, $sensorEinheit, $sensorValueType, $sensorSource, $logger);
            } else {
                // Historie sammeln (aber bei identischem letzten Wert nur tstamp updaten)
                insertOrTouchHistory($db, $sensorID, $tstamp, $sensorValue, $sensorEinheit, $sensorValueType, $sensorSource, $logger);
            }
        } catch (\Throwable $e) {
            // Fehler pro Sensor protokollieren, weiter mit dem nächsten
            $logger->Error("processing failed for sensorID='$sensorID': " . $e->getMessage());
        }
    }

    // Alte Daten (älter als 1 Jahr) aufräumen
    $cleanupSql = "DELETE FROM tl_coh_sensorvalue WHERE tstamp < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 YEAR))";
    if ($db->query($cleanupSql)) {
        $deleted = $db->affected_rows;
        if ($deleted > 0) {
            $logger->Info("Cleanup: Es wurden in tl_coh_sensorvalue $deleted gelöscht die älter als 1 Jahr sind.");
        }
    } else {
        $logger->Error("cleanup failed: " . $db->error);
    }

    // Sleep
    $sleepSeconds = max(1, $pollTime) * 60;
    $logger->Info("Iteration: $iteration " . date('d.m.Y H:i:s') . " – Sleep (Minuten): $pollTime");
    sleep($sleepSeconds);
}

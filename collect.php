<?php

namespace PbdKn\cohSensorcollector;

// autoloader einrichten
require_once __DIR__ . '/autoload.php';


use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\FetcherRegistry;
use PbdKn\cohSensorcollector\Logger;
use mysqli;

$logger= Logger::getInstance();    // globaler logger für alle instanzen
date_default_timezone_set('Europe/Berlin');


// Datenbankverbindung herstellen
//$db = new mysql_dialog();
//$con = $db->connect('localhost', 'root', '', 'co5_solar');
$db = new mysqli('localhost', 'peter', 'sql666sql', 'co5_solar');
// lese alle configdata incl. geraet
$sqlconfig = "SELECT * ";
$sqlconfig .= "FROM tl_coh_cfgcollect ";

// Registry vorbereiten
$registry = new FetcherRegistry();
foreach (glob(__DIR__ . '/Sensor/*.php') as $file) {  // alle fetchers in Sensor aktivieren
    require_once $file;
}
// Jetzt sind die Klassen bekannt – finde alle Fetcher und registriere sie
foreach (get_declared_classes() as $class) {
    if (is_subclass_of($class, SensorFetcherInterface::class)) {
        $fetcher = new $class();
        $registry->registerFetcher('sensor.fetcher', $fetcher);
    }
}


// Fetcher abrufen
$fetchers = $registry->getFetchersByTag('sensor.fetcher');


// SensorManager verwenden
$manager = new SensorManager($fetchers);

$iteration=0;

while (1) {
    $iteration++;
    $tstamp = time();
    $res=$db->query($sqlconfig);
    $anzahl = $res->num_rows;
    $logger->debugMe("anzahl Configsätze $anzahl");
    $arrCfg = [];
    while ($row = $res->fetch_assoc()) {
      $arrCfg[] = $row;
    }
    $debug=false;
    $pollTime=15;      // 15 Min
    foreach ($arrCfg as $cfg) {
        $logger->debugMe('cfgID: '.$cfg['cfgID'].' cfgType: '.$cfg['cfgType'].' cfgValue: '.$cfg['cfgValue']);
        if ($cfg['cfgType'] == 'debug') if ($cfg['cfgValue'] != 0) $debug=true; else $debug=false;
        if ($cfg['cfgType'] == 'pollTime') $pollTime = $cfg['cfgValue'];
    }


    $logger->setDebug($debug);  
    // Ergebniss holen und in db schreiben
    $arrResults = $manager->fetchAll($db);
    foreach ($arrResults as  $result) {
        $sensorID=$result['sensorID'];
        $tstamp = time(); // aktueller Zeitstempel

        // Werte aus dem Result-Array holen
        $sensorID        = $result['sensorID'];
        $sensorValue     = isset($result['sensorValue'])     ? $result['sensorValue']     : '';
        $sensorEinheit   = isset($result['sensorEinheit'])   ? $result['sensorEinheit']   : '';
        $sensorValueType = isset($result['sensorValueType']) ? $result['sensorValueType'] : '';
        $sensorSource    = isset($result['sensorSource'])    ? $result['sensorSource']    : '';

        // Schritt 1: Prüfen, ob ein Datensatz mit gleichem sensorID + sensorValue bereits existiert
        $escapedValue = $db->real_escape_string(trim((string)$sensorValue));

        $checkSql = "
            SELECT id 
            FROM tl_coh_sensorvalue 
            WHERE sensorID = '{$db->real_escape_string($sensorID)}' 
              AND TRIM(sensorValue) = '$escapedValue'
                ORDER BY tstamp DESC 
                LIMIT 1
        ";


        $resultCheck = $db->query($checkSql);
        if ($row = $resultCheck->fetch_assoc()) {
            // Datensatz existiert -> tstamp aktualisieren

            $updateId = $row['id'];
            $updateSql = "UPDATE tl_coh_sensorvalue SET tstamp = $tstamp WHERE id = $updateId";
//echo "datensatz zu $sensorID existiert updateId $updateId sql: $updateSql\n";
            $logger->debugMe("Update statt Insert: $updateSql");
            $updateResult = $db->query($updateSql);

            if (!$updateResult) {
                $logger->Error($db->makeerror());
            }
        } else {
            // Kein passender Datensatz -> INSERT
            $insertSql = "
                INSERT INTO tl_coh_sensorvalue 
                   (tstamp, sensorID, sensorValue, sensorEinheit, sensorValueType, sensorSource)
                VALUES 
                (
                    '$tstamp',
                    '{$db->real_escape_string($sensorID)}',
                    '{$db->real_escape_string($sensorValue)}',
                    '{$db->real_escape_string($sensorEinheit)}',
                    '{$db->real_escape_string($sensorValueType)}',
                    '{$db->real_escape_string($sensorSource)}'
                )   
            ";

            $logger->debugMe("Insert neuer Datensatz: $insertSql");
            $insertResult = $db->query($insertSql);

            if (!$insertResult) {
                $logger->Error($db->makeerror());
            }
        }
        $sl = $pollTime*60;
    }
    $db->query("DELETE FROM tl_coh_sensorvalue WHERE tstamp < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 YEAR))");
    $deletedCount = $db->affected_rows;
    if ($deletedCount !=0) echo "Info: Es wurden $deletedCount alte Datensätze gelöscht.\n";
    echo "Interval: $iteration ".date('d.m.Y H:i:s')." sleep Minuten $pollTime\n";

    sleep($sl);
}
?>  

<?php

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\mysql_dialog;

class IQBoxSensorService implements SensorFetcherInterface
{
    /** Gemeinsamer Cloud-Client fuer alle Zugriffe dieser Service-Instanz. */
    private ?\AmpereIqHttpAccess $cloud = null;


    public function __construct(
        private mysql_dialog $db,
        private Logger $logger,
        private SimpleHttpClient $httpClient
    ) {}

    public function supports($sensor): bool
    {
        return strtolower($sensor['sensorSource']) === 'iqbox';
    }

    public function fetch($sensor): ?array
    {
        try {
            $result = $this->fetchArr([$sensor]);
            if (!$result) return null;
            return $result[$sensor['sensorID']] ?? null;
        } catch (\Throwable $e) {
            $this->logger->Error("IQBox fetch Fehler: " . $e->getMessage());
            return null;
        }
    }

    // -------------------------------------------------
    // HAUPTLOGIK
    // -------------------------------------------------
    public function fetchArr(array $sensors): ?array
    {
        $res = [];

        try {
            if (count($sensors) === 0) return null;
            $cloud = $this->cloud();
            foreach ($sensors as $sensor) {
                $sensorLokalId = trim((string)($sensor['sensorLokalId'] ?? ''));
                $outputMode=$sensor['outputMode'];
                $sensorID=$sensor['sensorID'];
                $einheit = trim((string)($sensor['sensorEinheit'] ?? ''));

                if ($sensorLokalId === '') {
                    $this->logger->Error("IQBox Cloud: sensorLokalId fehlt fuer Sensor $sensorID");
                    continue;
                }

                try {
                    // Die Antwort wird absichtlich nicht gecacht: jeder Sensorwert
                    // wird frisch gelesen, aber immer ueber denselben Cloud-Client.
                    $cloudValue = $cloud->getValue($sensorLokalId);
                } catch (\Throwable $e) {
                    $this->logger->Error("IQBox Cloud: Fehler bei $sensorLokalId: " . $e->getMessage());
                    continue;
                }

                if (is_array($cloudValue) || is_object($cloudValue)) {
                    $value = json_encode($cloudValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($value === false) {
                        $this->logger->Error("IQBox Cloud: JSON fuer $sensorLokalId konnte nicht erzeugt werden");
                        continue;
                    }
                } else {
                    $rrArr = $this->IQStatreal($cloudValue, $sensorLokalId, $sensorID, $outputMode);
                    $value = $rrArr['wert'];
                }
                $this->logger->debugMe("Result 1: value $value einheit $einheit " . "sensorID " . $sensor['sensorID'] . " name $sensorLokalId ");
                $res[$sensor['sensorID']] = [
                    'sensorID'        => $sensor['sensorID'],
                    'sensorValue'     => $value,
                    'sensorEinheit'   => $einheit,
                    'sensorValueType' => $sensor['sensorValueType'],
                    'sensorSource'    => $sensor['sensorSource'],
                ];
            }

            return $res;
        } catch (\Throwable $e) {
            $this->logger->Error("IQBox Fehler: " . $e->getMessage());
            return null;
        }
    }

    private function cloud(): \AmpereIqHttpAccess
    {
        if ($this->cloud !== null) {
            return $this->cloud;
        }

        $execScriptsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'execScripts';
        require_once $execScriptsDir . DIRECTORY_SEPARATOR . 'TaskAccess.php';

        $this->cloud = new \AmpereIqHttpAccess(
            $execScriptsDir . DIRECTORY_SEPARATOR . 'task_solar_params.json',
            3,                  // anzahl Versuche
            10,                 // wartezeit zwischen den Verwsuchn
            \TaskAccess::loggerAdapter($this->logger)   //logger übergabe
        );

        return $this->cloud;
    }
    // -------------------------------------------------
    // TRANSFORMS
    // -------------------------------------------------
    private function normalizeUnit(string $unit): string
    {
        $unit = trim($unit);
        $unit = @iconv('UTF-8', 'UTF-8//IGNORE', $unit);  // kaputte UTF-8 Sequenzen entfernen (wichtig!)
        $unit = str_replace(['Â','Ã','â'], '', $unit);    // typische Störzeichen entfernen (nur bekannte Fehler!)
        return strtolower($unit);
    }
    private function detectUnit(string $unitRaw): string {
        $u = $this->normalizeUnit($unitRaw);
        // zuerst längere / spezifische Einheiten!
        if (strpos($u, 'kwh') !== false) return 'kWh';
        if (strpos($u, 'wh') !== false) return 'Wh';
        if (strpos($u, 'ws') !== false) return 'Ws';
        if (strpos($u, 'kw') !== false) return 'kW';
        if ($u === 'w') return 'W';
        if (strpos($u, '°c') !== false) return '°C';
        if ($u === 'c') return '°C';
        if (strpos($u, '%') !== false) return '%';
        return $unitRaw;
    } 
    /*
     * liefert den ersten werte ab den startdatum
     */
    private function getValueFromStartday ($sensorID,$startOfDay) {

        //$sensorID=$sensor['sensorID'];
        
        $conn = $this->db->getConnection();
        // erster Wert ab $startOfDay
        $sqlFirst = "
            SELECT sensorValue
            FROM tl_coh_sensorvalue
            WHERE sensorID = '".$conn->real_escape_string($sensorID)."'
            AND tstamp >= $startOfDay
            ORDER BY tstamp ASC
            LIMIT 1
        ";
$this->logger->debugMe("IQbox getValueFromStartday sql $sqlFirst");    
        $resFirst = $conn->query($sqlFirst);
        if (!$resFirst || !$rowFirst = $resFirst->fetch_assoc()) {
            return 0;
        }
        $firstValue = (float)$rowFirst['sensorValue'];   
        
        return $firstValue;
    }    
           
    private function IQStatreal($stat, $sensorLokalId, $sensorID,$outputMode) {
        //$this->logger->debugMe("IQStatreal name $sensorLokalId stat $stat");
        // -----------------------------------
        // 1. Wert extrahieren
        // -----------------------------------
        $valarr = explode("|", (string)($stat ?? ''));
        $strWert = count($valarr) > 1 ? $valarr[1] : $stat;
        // -----------------------------------
        // 2. Wert + Einheit parsen (inkl. E+9)
        // -----------------------------------
        if (preg_match('/^\s*([+-]?[0-9\.,]+(?:[eE][+-]?[0-9]+)?)\s*([^\s]+)?/u', $strWert, $m)) {
            $value   = $m[1];
            $unitRaw = $m[2] ?? '';
        } else {
            // KEINE ZAHL → STRING zurückgeben (z.B. CHARGING)
            return ['wert' => trim($strWert), 'einheit' => ''];
        }
        // -----------------------------------
        // 3. Wert normalisieren
        // -----------------------------------
        $valueRaw = trim($value);
        if (is_numeric($valueRaw)) { $value = (float)$valueRaw;
        } elseif (preg_match('/^[+-]?[0-9]+,[0-9]+$/', $valueRaw)) { $value = (float) str_replace(',', '.', $valueRaw);
        } else { $value = $valueRaw;
        }
        // -----------------------------------
        // 4. Einheit erkennen
        // -----------------------------------
        $unit = $this->detectUnit($unitRaw);
        // -----------------------------------
        // 5. Logik
        // -----------------------------------
        switch ($unit) {
            case 'kWh':
                if (is_numeric($value)) {  if (abs($value) >= 0.01) { $value = round($value, 2);} }
                $unitOut = 'kWh';
                break;
            case 'Wh':
                if (is_numeric($value)) { $value = $value / 1000; if (abs($value) >= 0.01) { $value = round($value, 2);} }
                $unitOut = 'kWh';
                break;
            case 'Ws':
                if (is_numeric($value)) { $value = $value / 3600000; if (abs($value) >= 0.01) { $value = round($value, 2); } }
                $unitOut = 'kWh';
                break;
            case 'kW':
                if (is_numeric($value)) { if (abs($value) >= 0.01) { $value = round($value, 2); } }
                $unitOut = 'kW';
                break;
            case 'W':
                if (is_numeric($value)) { $value = $value / 1000; if (abs($value) >= 0.01) { $value = round($value, 2); }}
                $unitOut = 'kW';
                break;
            case '°C':
                if (is_numeric($value)) { if (abs($value) >= 0.01) { $value = round($value, 1); } }
                $unitOut = '°C';
            break;
            default:
                $unitOut = $unit;
                break;
        }
        // 6. Value korrektur wenn outputMode nicht absulut ist
        $this->logger->debugMe("IQbox getfromIQbox state ok variable $sensorLokalId value $value outputMode $outputMode ");    
        $dt = new \DateTime('today midnight');            
        switch ($outputMode) {
            case 'daily':   $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                //$unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'woche':   $dt->modify('-7 days'); $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                //$unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'monat':   $dt->modify('-30 days'); $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                //$unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'jahr':    $dt->modify('-365 days'); $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                //$unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'absolute':
            default: 
                break;
        }        
        return ['wert' => $value, 'einheit' => $unitOut];
    }
}

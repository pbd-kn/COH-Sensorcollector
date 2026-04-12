<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\mysql_dialog;

class ComponentService implements SensorFetcherInterface
{

    private ?array $aktData  = null;
    private ?array $setupData  = null;

    public function __construct( private mysql_dialog $db, private Logger $logger, private SimpleHttpClient $httpClient) {}   
     
    public function supports( $sensor): bool
    {
        if (strtolower($sensor['sensorSource']) === 'zcomponent') $this->logger->debugMe( "zcomponent supports " . $sensor['sensorSource']);
        return (strtolower($sensor['sensorSource']) === 'zcomponent');
    }
    public function fetch($sensor): array
    {
        return ['Component' => 'Fetched Component data'];
    }
    public function fetchArr(array $sensors): ?array // neue Methode
    { 
 //$this->logger->setDebug(true);
        $res=array();
        if (count($sensors) == 0) { 
            $this->logger->debugMe('Component Sensorservice len sensors:'.count($sensors)); 
            return $res;
        }   
        $this->logger->debugMe( "ComponentService fetchArr anzahl componentSensoren len ".count($sensors));
        foreach ($sensors as $sensor) {   // schleife über alle componentSensors
            try {
                $lokalAccess = $sensor['sensorID'] ?? '';    // name des componentSensors index für die rückmeldung des ergebnisses
$this->logger->debugMe( "ComponentService bearbeiteten Sensor lokalAccess $lokalAccess");
                if ($lokalAccess === '') {
                    $this->logger->debugMe('ComponentService: sensorID fehlt'); // sensor hat keinen Namen  kann eigentlich nicht sein, da obligat bei kreation des sensors
                    continue;
                }
                // prüfen ob Komponente
                if (($sensor['isComponent'] ?? '') !== '1') {
                    $this->logger->debugMe('ComponentService: kein Komponenten-Sensor: '.$lokalAccess);
                    continue;
                }
                $componentSensors = $this->deserialize($sensor['componentSensors'] ?? null, true);
                if (empty($componentSensors)) {
                    $this->logger->debugMe('ComponentService: keinen  sensoren zu bearbeiten componentSensors leer bei '.$lokalAccess);
                    continue;
                }
                // alle Sensoren aus der componente aufsammeln
                $selectedSensors = [];
                foreach ($componentSensors as $row) {
                    if (!empty($row['sensor'])) { $selectedSensors[] = $row['sensor']; }
                }
                $selectedSensors = array_unique($selectedSensors);
                if (empty($selectedSensors)) {
                    $this->logger->debugMe('ComponentService: keine gültigen Komponenten-Sensoren bei '.$lokalAccess);
                    continue;
                }
//$this->logger->debugMe( "ComponentService bearbeiteten lokalAccess $lokalAccess pruefungen ok");
                $conn = $this->db->getConnection();
                $escaped = array_map([$conn,'real_escape_string'],$selectedSensors);
                $inList  = "'" . implode("','",$escaped) . "'";  // alle sensoren der componente in einen kommagetrennten String
                $this->logger->debugMe("ComponentService: verwendete sensoren in componente inList $inList");

                $formula = html_entity_decode((string)($sensor['componentFormula'] ?? ''), ENT_QUOTES | ENT_HTML5);  // wegn daily(  mit kalmmer auf und klammer zu)
                $formula = trim($formula);
                $this->logger->debugMe("ComponentService: formula=".$formula);
                $sql = "
                    SELECT s1.*, s3.*
                    FROM tl_coh_sensorvalue s1
                    JOIN (
                        SELECT sensorID, MAX(tstamp) AS max_ts
                        FROM tl_coh_sensorvalue
                        WHERE sensorID IN ($inList)
                        GROUP BY sensorID
                    ) m
                    ON s1.sensorID = m.sensorID AND s1.tstamp = m.max_ts
                    LEFT JOIN tl_coh_sensors s3
                        ON s1.sensorID = s3.sensorID
                    ORDER BY FIELD(s1.sensorID, $inList)
                ";
                $result = $conn->query($sql);
                $cSensors = [];

                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $cSensors[$row['sensorID']] = $row;
                        //$this->logger->debugMe( "ComponentService: DB sensor=".$row['sensorID']. " value=".$row['sensorValue']);
                    }
                }
                $vars = [];
                $aliasToSensor = [];
                foreach ($componentSensors as $row) {
                    if (!is_array($row)) { continue; }
                    $alias    = $row['alias'] ?? '';
                    $sensorId = trim((string)($row['sensor'] ?? ''));
                    $factor   = $row['factor'] ?? 1;
                    if ($alias === '' || $sensorId === '') { continue; }
                    $value = $cSensors[$sensorId]['sensorValue'] ?? 0;
                    if ($factor !== '' && $factor != 1) { $value *= (float)$factor; }
                    $vars[$alias] = (float)$value;
                    $aliasToSensor[$alias] = $sensorId;
                    $this->logger->debugMe( "ComponentService: alias=$alias sensor=$sensorId value=".$vars[$alias] );
                }
//$this->logger->debugMe("ComponentService: formula $formula");
                $value = $this->computeFormula($formula, $vars, $aliasToSensor);
                $this->logger->debugMe("ComponentService: formula= $formula result=$value");                             
                if ($value === null) { $this->logger->debugMe('Compute Sensorservice keinen wert für sensorID: '.$sensor['sensorID']);
                } else { 
$this->logger->debugMe("ComponentService: set result fuer " . $sensor['sensorID']);                  
                    $res[$sensor['sensorID']] = [
                        'sensorID'        => $sensor['sensorID'],
                        'sensorValue'     => $value,
                        'sensorEinheit'   => $sensor['sensorEinheit'],
                        'sensorValueType' => $sensor['sensorValueType'],
                        'sensorSource'    => $sensor['sensorSource'],
                    ];

/*
                    $this->connection->update('tl_coh_sensors', [
                        'lastUpdated' => time(),
                        'lastValue' => $value,
                        'lastError' => '',
                        ], ['id' => $sensor['id]);
                    $this->logger->debugMe( "Compute Sensorservice update sensorID: '.$sensor['sensorID'].' lastUpdated id: '.$sensor['id']);    
*/
                }
            

        
            } catch (\Throwable $e) {
                $message = "Component: Fehler bei : " . $e->getMessage();
                $this->logger->debugMe( $message);    
//$this->logger->setDebug(false);

/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id]);
*/
            return null;
            }
        }

        //$this->logger->debugMe("ComponentService: anzahl result " . count($res));
        //$this->logger->setDebug(false);
        return $res;

    }
    private function deserialize($value, $forceArray = false)
{
    if ($value === null || $value === '') {
        return $forceArray ? [] : null;
    }

    // prüfen ob serialisiert
    if (is_string($value)) {
        $un = @unserialize($value);
        if ($un !== false || $value === 'b:0;') {
            return $un;
        }

        // JSON versuchen
        $json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }
    }

    return $forceArray ? (array)$value : $value;
}
    private function computeValue($sensor) { 
        try {
            $v1=null;
            if ($v1 === null) {
              return null;             
            }
        } catch (\Throwable $e) {
            $this->logger->Error( " Catch WasserLeckage: Fehler bei getDataFromDevice : ".$e->getMessage());
            return null;
        }
        return "OK";
        
    }

private function computeFormula(string $formula, array $vars, array $aliasToSensor): ?float
{
    $formula = html_entity_decode($formula, ENT_QUOTES | ENT_HTML5);
    $formula = trim($formula);

    if ($formula === '') {
        return null;
    }

    // daily(alias) zuerst behandeln, BEVOR Variablen ersetzt werden
    $formula = preg_replace_callback('/daily\((.*?)\)/', function ($m) use ($aliasToSensor) {
        $alias = trim($m[1]);

        if ($alias === '') {
            return '0';
        }

        if (!isset($aliasToSensor[$alias])) {
            $this->logger->debugMe("ComponentService: daily alias not found: $alias");
            return '0';
        }

        $sensorId = $aliasToSensor[$alias];
        return (string)$this->getDailyValue($sensorId);
    }, $formula);

    // sum()
    $formula = preg_replace_callback('/sum\((.*?)\)/', function ($m) use ($vars) {
        $parts = explode(',', $m[1]);
        $sum = 0.0;

        foreach ($parts as $p) {
            $p = trim($p);

            if (isset($vars[$p])) {
                $sum += (float)$vars[$p];
            } else {
                $sum += (float)$p;
            }
        }

        return (string)$sum;
    }, $formula);

    // min()
    $formula = preg_replace_callback('/min\((.*?)\)/', function ($m) use ($vars) {
        $parts = explode(',', $m[1]);
        $values = [];

        foreach ($parts as $p) {
            $p = trim($p);
            $values[] = isset($vars[$p]) ? (float)$vars[$p] : (float)$p;
        }

        return (string)min($values);
    }, $formula);

    // max()
    $formula = preg_replace_callback('/max\((.*?)\)/', function ($m) use ($vars) {
        $parts = explode(',', $m[1]);
        $values = [];

        foreach ($parts as $p) {
            $p = trim($p);
            $values[] = isset($vars[$p]) ? (float)$vars[$p] : (float)$p;
        }

        return (string)max($values);
    }, $formula);

    // abs()
    $formula = preg_replace_callback('/abs\((.*?)\)/', function ($m) use ($vars) {
        $p = trim($m[1]);
        $value = isset($vars[$p]) ? (float)$vars[$p] : (float)$p;
        return (string)abs($value);
    }, $formula);

    // round()
    $formula = preg_replace_callback('/round\((.*?),(.*?)\)/', function ($m) use ($vars) {
        $p1 = trim($m[1]);
        $p2 = trim($m[2]);

        $value = isset($vars[$p1]) ? (float)$vars[$p1] : (float)$p1;
        $precision = (int)$p2;

        return (string)round($value, $precision);
    }, $formula);

    // jetzt normale Variablen ersetzen
    foreach ($vars as $name => $value) {
        $formula = preg_replace('/\b'.preg_quote($name, '/').'\b/', (string)$value, $formula);
    }

    $this->logger->debugMe("ComponentService: eval formula = $formula");

    if (!preg_match('/^[0-9+\-*\/()., ]+$/', $formula)) {
        $this->logger->debugMe("ComponentService: unsafe formula $formula");
        return null;
    }

    try {
        $result = eval("return $formula;");

        if (!is_numeric($result)) {
            return null;
        }

        return (float)$result;
    } catch (\Throwable $e) {
        $this->logger->debugMe("ComponentService: eval error ".$e->getMessage());
        return null;
    }
}
private function getDailyValue(string $sensorID): float
{
    try {

        $conn = $this->db->getConnection();

        $startOfDay = strtotime('today midnight');
        /*
         * 7 tage zurück
         * $dt = new DateTime('today midnight');
         * $dt->modify('-7 days');
         * $ts = $dt->getTimestamp();
         */

        // erster Wert des Tages
        $sqlFirst = "
            SELECT sensorValue
            FROM tl_coh_sensorvalue
            WHERE sensorID = '".$conn->real_escape_string($sensorID)."'
            AND tstamp >= $startOfDay
            ORDER BY tstamp ASC
            LIMIT 1
        ";

        $resFirst = $conn->query($sqlFirst);

        if (!$resFirst || !$rowFirst = $resFirst->fetch_assoc()) {
            return 0;
        }

        $firstValue = (float)$rowFirst['sensorValue'];

        // letzter Wert
        $sqlLast = "
            SELECT sensorValue
            FROM tl_coh_sensorvalue
            WHERE sensorID = '".$conn->real_escape_string($sensorID)."'
            ORDER BY tstamp DESC
            LIMIT 1
        ";

        $resLast = $conn->query($sqlLast);

        if (!$resLast || !$rowLast = $resLast->fetch_assoc()) {
            return 0;
        }

        $lastValue = (float)$rowLast['sensorValue'];

        // Reset-Schutz (z.B. Zähler reset)
        if ($lastValue >= $firstValue) {
            return round($lastValue - $firstValue, 2);
        }

        return round($lastValue, 2);

    } catch (\Throwable $e) {

        $this->logger->debugMe(
            "ComponentService daily error: ".$e->getMessage()
        );

        return 0;
    }
}    
}
?>

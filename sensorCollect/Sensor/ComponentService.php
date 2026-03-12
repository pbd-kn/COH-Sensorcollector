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
        $this->logger->setDebug(true);
        $this->logger->debugMe( "ComponentService fetchArr len ".count($sensors));
        foreach ($sensors as $sensor) {
        $res=array();
        try {
            if (count($sensors) > 0) { $this->logger->debugMe('Component Sensorservice len sensors:'.count($sensors)); }   
            else { return $res; } 
            $lokalAccess = $sensor['sensorID'] ?? '';

            if ($lokalAccess === '') {
                $this->logger->debugMe('ComponentService: sensorID fehlt'); // sensor hat keinen Namen  kann eigentlich nicht sein, da obligat bei kreation des sensors
                continue;
            }
            // prüfen ob Komponente
            if (($sensor['isComponent'] ?? '') !== '1') {
                $this->logger->debugMe('ComponentService: kein Komponenten-Sensor: '.$lokalAccess);
                continue;
            }
            // componentSensors direkt aus dem vorhandenen Datensatz lesen
            //$componentSensors = StringUtil::deserialize($sensor['componentSensors'] ?? null, true);
            $componentSensors = $this->deserialize($sensor['componentSensors'] ?? null, true);
            if (empty($componentSensors)) {
                $this->logger->debugMe('ComponentService: componentSensors leer bei '.$lokalAccess);
                continue;
            }
            // enthaltene Sensor-IDs einsammeln
            $selectedSensors = [];
            foreach ($componentSensors as $row) {
                if (!empty($row['sensor'])) {
                    $selectedSensors[] = $row['sensor'];
                }
            }
            $selectedSensors = array_unique($selectedSensors);
            if (empty($selectedSensors)) {
                $this->logger->debugMe('ComponentService: keine gültigen Komponenten-Sensoren bei '.$lokalAccess);
                continue;
            }
            $conn = $this->db->getConnection();
        
            $escaped = array_map([$conn,'real_escape_string'],$selectedSensors);
            $inList  = "'" . implode("','",$escaped) . "'";
            $sql = "
                SELECT s1.*, s3.sensorTitle, s3.outputMode
                FROM tl_coh_sensorvalue s1
                JOIN (
                    SELECT sensorID, MAX(tstamp) AS max_ts
                    FROM tl_coh_sensorvalue
                    WHERE sensorID IN ($inList)
                    GROUP BY sensorID
                ) m
                ON  s1.sensorID = m.sensorID AND s1.tstamp   = m.max_ts
                LEFT JOIN
                    tl_coh_sensors s3
                    ON s1.sensorID = s3.sensorID
                ORDER BY FIELD(s1.sensorID, $inList)
                ";
$this->logger->debugMe("ComponentService: sql $sql");

            $result = $conn->query($sql);
            $rows = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
$this->logger->debugMe("ComponentService: Variable von component ".$row['sensorID']);
                    $rows[$row['sensorID']] = $row;
                }
            } 
            $vars = [];

            foreach ($componentSensors as $row) {
                $alias  = $row['alias'];
                $sensor = $row['sensor'];
                $factor = $row['factor'] ?? 1;
                $value = $rows[$sensor]['sensorValue'] ?? 0;

                if ($factor !== '' && $factor != 1) {
                    $value *= (float)$factor;
                }
                $vars[$alias] = (float)$value;
                $this->logger->debugMe("ComponentService: alias=$alias sensor=$sensor value=".$vars[$alias]);
            }  
$formula = $sensor['componentFormula'] ?? '';
$this->logger->debugMe("ComponentService: formula $formula");

$value = $this->computeFormula($formula, $vars);

$this->logger->debugMe("ComponentService: formula=$formula result=$value");                             
$this->logger->setDebug(false);
return $res;
            if (!empty($sensor['sensorLokalId'])) $lokalAccess=$sensor['sensorLokalId'];
                $value = $this->computeValue($lokalAccess);
                $einheit=$sensor['sensorEinheit'];  
                if (!empty($sensor['transFormProcedur'])) {
                 if (method_exists($this, $sensor['transFormProcedur'])) {
                        $arr = $this->{$sensor['transFormProcedur']}($value);
                        $einheit=$arr['einheit'];                    
                        $value=$arr['wert'];
                    } else {
                        $this->logger->Error( "Component transFormProcedur ".$sensor['transFormProcedur']." für SensorID  ".$sensor['sensorID']." existiert nicht");  
                    }                 
                }                   
                $this->logger->debugMe( "Component Sensorservice SensorID  ".$sensor['sensorID']." lokalAccess $lokalAccess value $value Einheit $einheit");  
                if ($value === null) {
//                    $this->logger->debugMe('Compute Sensorservice keinen wert für sensorID: '.$sensor['sensorID']);
                } else {    
                    $res[$sensor['sensorID']] = [
                        'sensorID'        => $sensor['sensorID'],
                        'sensorValue'     => $value,
                        'sensorEinheit'   => $einheit,
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
            

            return $res;
        
        } catch (\Throwable $e) {
            $message = "Component: Fehler bei : " . $e->getMessage();
            $this->logger->debugMe( $message);    
$this->logger->setDebug(false);

/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id]);
*/
            return null;
        }
        //$this->logger->setDebug(false);
        }

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
    private function computeFormula(string $formula, array $vars): ?float {
        // Variablen ersetzen
        foreach ($vars as $name => $value) {
            $formula = preg_replace('/\b'.$name.'\b/', $value, $formula);
        }

        // sum()
    $formula = preg_replace_callback('/sum\((.*?)\)/', function ($m) {
        $parts = explode(',', $m[1]);
        $sum = 0;
        foreach ($parts as $p) {
            $sum += (float)$p;
        }
        return $sum;
    }, $formula);

    // min()
    $formula = preg_replace_callback('/min\((.*?)\)/', function ($m) {
        $parts = array_map('floatval', explode(',', $m[1]));
        return min($parts);
    }, $formula);

    // max()
    $formula = preg_replace_callback('/max\((.*?)\)/', function ($m) {
        $parts = array_map('floatval', explode(',', $m[1]));
        return max($parts);
    }, $formula);

    // daily()
    $formula = preg_replace_callback('/daily\((.*?)\)/', function ($m) {

        $value = (float)$m[1];

        // hier kannst du später deine Tagesberechnung machen
        return $value;

    }, $formula);

    // Sicherheitsprüfung
    if (!preg_match('/^[0-9+\-*\/(). ]+$/', $formula)) {
        return null;
    }

    try {
        return eval("return $formula;");
    } catch (\Throwable $e) {
        return null;
    }
}
    
}
?>

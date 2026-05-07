<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\mysql_dialog;

class WasserLeckageService implements SensorFetcherInterface
{


    public function __construct( private mysql_dialog $db, private Logger $logger, private SimpleHttpClient $httpClient) {}   
     
    public function supports( $sensor): bool
    {
//return false;                          // keine daten von SYR
        if (strtolower($sensor['sensorSource']) === 'wasserleckage') {
            $this->logger->debugMe( "WasserLeckageService supports " . $sensor['sensorSource']);
            $now = new \DateTime();                 // aktuelle Serverzeit
            $time = $now->format('H:i');          // z.B. 02:15
            if ($time >= '00:00' && $time <= '04:30') { return false;   // on de zeit zwischen die sensoren nicht abholen. wegen evtl laufendem Mikroleckagetest 
            } else { return true; }
        } else { return false; }
    }
    public function fetch($sensor): ?array
    {
        $res=[];
        return res;
    }
    public function fetchArr(array $sensors): ?array // neue Methode
    { 
        $debugval=false;
        $this->logger->setDebug($debugval);

        $res=[];
        try {
            if (count($sensors) > 0) {
                $url=$sensors[0]['geraeteUrl'];
                $this->logger->debugMe('WasserLeckage Sensorservice  url '.$url.' len sensors:'.count($sensors));    
                if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                    $url = 'http://' . $url;
                }
            } 
            if (empty($url)) {
                $message = "WasserLeckage: keine url  Sensor {$sensors[0]->sensorID}";
/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']);
*/
                $this->logger->Error( $message);
if ($debugval) $this->logger->setDebug(false);
                return null;
            }
            $this->dataFromDevice=$this->getDataFromDevice($url,"all");
            if ( $this->dataFromDevice === null || count($this->dataFromDevice) == 0 ) {
                $this->logger->Info( "WasserLeckage: Fehler bei Lesen aller daten vom WasserLeckage");  
                return null;
            }
            // diese werte kommen bei als falsch zurück
            $arr=$this->getDataFromDevice($url,"alm");
            if ( $arr === null  || count($this->dataFromDevice) == 0) {
                $this->logger->Info( "WasserLeckage: Fehler bei Lesen von alm WasserLeckage");  
                return null;
            }
            $this->dataFromDevice = array_merge($this->dataFromDevice, $arr);
            $arr=$this->getDataFromDevice($url,"alw");
            if ( $arr === null || count($this->dataFromDevice) == 0 ) {
                $this->logger->Info( "WasserLeckage: Fehler bei Lesen von alw WasserLeckage");  
                return null;
            }
            $this->dataFromDevice = array_merge($this->dataFromDevice, $arr);
            $arr=$this->getDataFromDevice($url,"aln");
            if ( $arr === null || count($this->dataFromDevice) == 0) {
                $this->logger->Info( "WasserLeckage: Fehler bei Lesen von aln WasserLeckage");  
                return null;
            }
            $this->dataFromDevice = array_merge($this->dataFromDevice, $arr);
            // Zugriff auf Werte, z.B.:
            foreach ($sensors as $sensor) {
                $sensorID=$sensor['sensorID'];
                $outputMode=strtolower($sensor['outputMode']);   // derzeit absolut, da kann evtl dayly 7Tage 30 tage oder 365 tage sehten muss nioch gemacht werden
                $SensorlokalId=$sensor['sensorLokalId'];    
                if (empty($sensor['sensorLokalId'])) {
                    $this->logger->Info("keine Werte bei sensorID $sensorID Soll z.b vol");   
                    continue;         
                }
                $resultVal = $this->getWasserLeckagedata($sensor);
                $einheit=$resultVal['sensorEinheit'];  
                $value=$resultVal['sensorValue'];  
                
                $this->logger->debugMe( "WasserLeckage Sensorservice SensorID  ".$sensor['sensorID']." SensorlokalId $SensorlokalId outputMode $outputMode value $value Einheit  $einheit type " . $resultVal['sensorValueType']);  
                if ($value === null) {
                    $this->logger->Info('WasserLeckage Sensorservice keinen wert für sensorID: ' . $sensor['sensorID'] . ' sensorLokalId: ' . $sensor['sensorLokalId']);
                } else {    
//                    $this->logger->debugMe('WasserLeckage Sensorservice wert für sensorID: '.$sensor['sensorID'] ." " . $value);
                    $res[$sensor['sensorID']] = [
                        'sensorID'          => $sensorID,
                        'sensorValue'       => $value,
                        'sensorEinheit'     => $einheit,
                        'sensorValueType'   => $resultVal['sensorValueType'],
                        'sensorSource'      => strtolower($sensor['sensorSource']),
                        'outputMode'        => strtolower($sensor['outputMode'])
                    ];
/*
                    $this->connection->update('tl_coh_sensors', [
                        'lastUpdated' => time(),
                        'lastValue' => $jsonvalue,
                        'lastError' => '',
                        ], ['id' => $sensor['id]);
                    $this->logger->debugMe( "WasserLeckage Sensorservice update sensorID: '.$sensor['sensorID'].' lastUpdated id: '.$sensor['id']);    
*/
                }
            }
            if ($debugval) $this->logger->setDebug(false);
                return $res;
        } catch (\Throwable $e) {
            $message = "WasserLeckage: Fehler bei : " . $e->getMessage();
            $this->logger->Info( $message); 
if ($debugval) $this->logger->setDebug(false);   
/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id]);
*/
            return null;
        }
if ($debugval) $this->logger->setDebug(false);
        return $res;
    }
    private function getDataFromDevice(string $url,$cmd): ?array { 
        $baseGet=$url . ":5333/trio/get/";
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 10]]);
            $xCMD = $baseGet . $cmd;
//$this->logger->debugMe("getDataFromDevice xCMD $xCMD");
            $json = @file_get_contents($baseGet . $cmd, false, $ctx);
            if (!$json) {
            $this->logger->Error( " Catch WasserLeckage: Fehler bei getDataFromDevice : no json Get $baseGet" . "$cmd");
                return [];
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
            $this->logger->Error( " Catch WasserLeckage: Fehler bei decode ");
                return [];
            }
        } catch (\Throwable $e) {
            $this->logger->Error( " Catch WasserLeckage: Fehler bei getDataFromDevice : url $url cmd $smd ".$e->getMessage());
            return null;
        }     
        return $data;
    }
    /*
     * liefert den ersten werte ab den startdatum
     */
    private function getValueFromStartday ($sensorID,$startOfDay) {
        
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
        $resFirst = $conn->query($sqlFirst);
        if (!$resFirst || !$rowFirst = $resFirst->fetch_assoc()) {
            return 0;
        }
        $firstValue = (float)$rowFirst['sensorValue'];   
        return $firstValue;
    }
    /*  liefert den wert vom WasserLeckage aus 
                            'sensorID'        => $sensor['sensorID'],
                        'sensorValue'     => $value,
                        'sensorEinheit'   => $einheit,
                        'sensorValueType' => $sensor['sensorValueType'],
     *  
     */
    private function getWasserLeckagedata ($sensor): ?array {
        $name=$sensor['sensorLokalId'];
        $outputMode=$sensor['outputMode'];
        $sensorID=$sensor['sensorID'];
        $res=[];
        $name = strtoupper($name);
        $syrName= "get".$name;      // wertbezeichnng aus Syr
            
        if (isset($this->dataFromDevice[$syrName]) )  { 
            $aV =  $this->dataFromDevice[$syrName];
        } elseif (isset($this->dataFromDevice[$w]) ) {
            $aV =  $this->dataFromDevice[$syrName];
        } else {
            $this->logger->Info("getWasserLeckagedata name $name  syrName $syrName undefined");
            $aV=0;                     // kein wert verhanden
        }
        $aE = $aT = "";
        switch ($name) {
            case "BAT":
                $aV = ($aV ?? 0) / 100;$aE = 'V';$aT = 'float';
                break;
            case "CEL":
                $aV = isset($aV) ? ($aV / 10) : 0;$aE = ' °C';$aT = 'float';
                break;
            case "BAR":
                $aV = isset($aV) ? ($aV / 1000) : 0;$aE = 'Bar';$aT = 'float';
                break;
            case "VOL":
                $aV = isset($aV) ? $aV : 0;$aE = 'l';$aT = 'float';
                break;
            case "CEL":
                $aV = isset($aV) ? ($aV / 10) : 0;$aE = '°C';$aT = 'float';
                break;
            case "VLV":
                $aT = 'int';
                break;
            default:
                break;
        }
        $dt = new \DateTime('today midnight');            
        switch ($outputMode) {
            case 'daily':   $startOfDay = $dt->getTimestamp();
                            //$firstValue=$this->getValueFromStartday ($sensorID,$startOfDay);
                            $aV= $aV - $this->getValueFromStartday ($sensorID,$startOfDay);
                            $aT = date('d.m.Y H:i:s', $startOfDay);
                            break;
            case 'woche':   $dt->modify('-7 days'); $startOfDay = $dt->getTimestamp();
                            $aV= $aV - $this->getValueFromStartday ($sensorID,$startOfDay);
                            $aT = date('d.m.Y H:i:s', $startOfDay);
                            break;
            case 'monat':   $dt->modify('-30 days'); $startOfDay = $dt->getTimestamp();
                            $aV= $aV - $this->getValueFromStartday ($sensorID,$startOfDay);
                            $aT = date('d.m.Y H:i:s', $startOfDay);
                            break;
            case 'jahr':    $dt->modify('-365 days'); $startOfDay = $dt->getTimestamp();
                            $aV= $aV - $this->getValueFromStartday ($sensorID,$startOfDay);
                            $aT = date('d.m.Y H:i:s', $startOfDay);
                            break;
            case 'absolute':
            default: break;
        }
        $res['sensorValue']=$aV;
        $res['sensorEinheit']=$aE;
        $res['sensorValueType']=$aT;
        return $res;
    } 
}     
?>

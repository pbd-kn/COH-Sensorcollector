<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\mysql_dialog;

class WasserLeckageService implements SensorFetcherInterface
{
    private const SMALL_KEYS = [
        'VLV','BAT','FLO','BAR','CEL','PRF','SRN','VER','WIP','WGW','MAC1','EIP','EGW','MAC2','WFS','WFR',
        'ALA','WRN','NOT','ALM','ALW','ALN','VOL','CND','WTI','CEN','DSV','DRP','DTT','DTC','DOM','DST','DMA',
        'MM','DBD','DBT','DPL','DCM','AMA','ALD','SLP','SLE','SLV','SLT','SLF','SOF','SLO','SMF',
    ];


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
                $SensorlokalId=$sensor['sensorLokalId'];    
                if (empty($sensor['sensorLokalId'])) {
                    $this->logger->Info("keine Werte bei sensorID $sensorID Soll z.b vol");   
                    continue;         
                }
                $resultVal = $this->getWasserLeckagedata($sensor);
                $einheit=$resultVal['sensorEinheit'];  
                $value=$resultVal['sensorValue'];  
                
                $this->logger->debugMe( "WasserLeckage Sensorservice SensorID  ".$sensor['sensorID']." SensorlokalId $SensorlokalId value $value Einheit $einheit type " . $resultVal['sensorValueType']);
                if ($value === null) {
                    $this->logger->Info('WasserLeckage Sensorservice keinen wert für sensorID: ' . $sensor['sensorID'] . ' sensorLokalId: ' . $sensor['sensorLokalId']);
                } else {    
//                    $this->logger->debugMe('WasserLeckage Sensorservice wert für sensorID: '.$sensor['sensorID'] ." " . $value);
                    $res[$sensor['sensorID']] = [
                        'sensorID'          => $sensorID,
                        'sensorValue'       => $value,
                        'sensorEinheit'     => $einheit,
                        'sensorValueType'   => $resultVal['sensorValueType'],
                        'sensorSource'      => strtolower($sensor['sensorSource'])
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
                return $res;
        } catch (\Throwable $e) {
            $message = "WasserLeckage: Fehler bei : " . $e->getMessage();
            $this->logger->Info( $message); 
/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id]);
*/
            return null;
        }
        return $res;
    }
    private function getDataFromDevice(string $url,$cmd): ?array { 
        $baseGet=$url . ":5333/trio/get/";
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 10]]);
            $xCMD = $baseGet . $cmd;
//$this->logger->debugMe("getDataFromDevice xCMD $xCMD");
            $json = @file_get_contents($baseGet . $cmd, false, $ctx);
            $respHeaders = $http_response_header ?? [];
            $status = $respHeaders[0] ?? 'kein HTTP Status';
            if ($json === false) {
                $err = error_get_last();
                $this->logger->Error( " Catch WasserLeckage: Fehler " . $err['message'] ?? 'unbekannt' . " Http Status $status bei getDataFromDevice : no json Get $baseGet" . "$cmd");
                return [];
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                $this->logger->Error( " Catch WasserLeckage: Fehler bei decode json array json $json");
                return [];
            }
        } catch (\Throwable $e) {
            $this->logger->Error( " Catch WasserLeckage: Fehler bei getDataFromDevice : url $url cmd $smd ".$e->getMessage());
            return null;
        }     
        return $data;
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
        $res=[];
        $name = strtoupper($name);
        if ($name === 'ALL') {
            return ['sensorValue' => $this->dataFromDevice, 'sensorEinheit' => 'json', 'sensorValueType' => 'json'];
        }
        if ($name === 'SMALL') {
            $selection = [];
            foreach (self::SMALL_KEYS as $key) {
                $payloadKey = 'get' . $key;
                if (array_key_exists($payloadKey, $this->dataFromDevice)) {
                    $selection[$key] = $this->dataFromDevice[$payloadKey];
                }
            }
            return ['sensorValue' => $selection, 'sensorEinheit' => 'json', 'sensorValueType' => 'json'];
        }
        $syrName= "get".$name;      // wertbezeichnng aus Syr
            
        if (isset($this->dataFromDevice[$syrName]) )  { 
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
        $res['sensorValue']=$aV;
        $res['sensorEinheit']=$aE;
        $res['sensorValueType']=$aT;
        return $res;
    } 
}     
?>

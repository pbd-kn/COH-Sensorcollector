<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;

class HeizstabSensorService implements SensorFetcherInterface
{

    private ?array $aktData  = null;
    private ?array $setupData  = null;
    private ?Logger $logger = null;
    private ?SimpleHttpClient $httpClient = null;

    public function __construct()
    {
        $this->httpClient = new SimpleHttpClient();
        //$this->logger = new Logger(debug: true);
        $this->logger = Logger::getInstance();
    }
    
    public function supports( $sensor): bool
    {
        return (strtolower($sensor['sensorSource']) === 'heizstab');
    }
    public function fetch($sensor): array
    {
        return ['Heizstab' => 'Fetched Heizstab data'];
    }
    public function fetchArr(array $sensors): ?array // neue Methode
    { 
        $this->logger->debugMe( "HeizstabSensorService fetchArr len ".count($sensors));
        $res=array();
        try {
            if (count($sensors) > 0) {
                $url=$sensors[0]['geraeteUrl'];
                $this->logger->debugMe('Heizstab Sensorservice  url '.$url.' len sensors:'.count($sensors));    
                if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                    $url = 'http://' . $url;
                }
            } 
        
            if (empty($url)) {
                $message = "Heizstab: keine url  Sensor {$sensors[0]->sensorID}";
/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']);
*/
                $this->logger->Error( $message);
                return null;
            }
            $this->logger->debugMe("HeizstabSensorService url $url");
            if ( $this->getDataFromDevice($url) === null) {
                $this->logger->Error( "getDataFromDevice null");
                return null;
            }
            // Zugriff auf Werte, z.B.:
            foreach ($sensors as $sensor) {
                $lokalAccess=$sensor['sensorID'];
                if (!empty($sensor['sensorLokalId'])) $lokalAccess=$sensor['sensorLokalId'];
                $value = $this->getHeizstabdata($lokalAccess);
                $einheit=$sensor['sensorEinheit'];  
                if (!empty($sensor['transFormProcedur'])) {
                 if (method_exists($this, $sensor['transFormProcedur'])) {
                        $arr = $this->{$sensor['transFormProcedur']}($value);
                        $einheit=$arr['einheit'];                    
                        $value=$arr['wert'];
                    } else {
                        $this->logger->Error( "Heizstab transFormProcedur ".$sensor['transFormProcedur']." für SensorID  ".$sensor['sensorID']." existiert nicht");  
                    }                 
                }                   
                $this->logger->debugMe( "Heizstab Sensorservice SensorID  ".$sensor['sensorID']." lokalAccess $lokalAccess value $value Einheit $einheit");  
                if ($value === null) {
//                    $this->logger->debugMe('Heizstab Sensorservice keinen wert für sensorID: '.$sensor['sensorID']);
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
                    $this->logger->debugMe( "Heizstab Sensorservice update sensorID: '.$sensor['sensorID'].' lastUpdated id: '.$sensor['id']);    
*/
                }
            }

            return $res;
        
        } catch (\Throwable $e) {
            $message = "Heizstab: Fehler bei : " . $e->getMessage();
            $this->logger->debugMe( $message);    
/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id]);
*/
            return null;
        }
        return $res;

    }
    private function getDataFromDevice(string $url) { 
        try {
            $v1=$this->getdata($url);
            $v2=$this->getsetup($url);
            if ($v1 === null || $v2 === null) {
              $this->logger->debugMe( "Heizstab: Fehler bei Lesen der daten vom Heizstab");  
              return null;             
            }
        } catch (\Throwable $e) {
            $this->logger->Error( "Heizstab: Fehler bei getDataFromDevice : ".$e->getMessage());
            return null;
        }
        return "OK";
        
    }
    // liest die data.jsn vom Heizstab und gibt sie als Array zurück
    // liefert False bei einem Fehler
    private function getdata($url) {
        $url=$url."/data.jsn";
        $this->logger->debugMe( "getdata from $url");
        $data = $this->httpClient->getJson($url);      
        if ($data === null) {
            $this->logger->Error( "Fehler keine daten bei $url");
            return false;
        }
        $this->aktData=$data;
        return $data;
    }
    // liest die setup.jsn vom Heizstab und gibt sie als Array zurück
    // liefert False bei einem Fehler

    private function getsetup($url) {
        $url=$url."/data.jsn";
        $data = $this->httpClient->getJson($url);
        $this->setupData=$data;
        return $data;
    }
    /*  liefert den wert vom Heizstab aus global $aktData,$setupData;
     *  
     */
    private function getHeizstabdata ($sensorID) {
        if (isset($this->aktData[$sensorID]) )  {  
          return $this->aktData[$sensorID];
        } else if (isset($this->setupData[$sensorID]) )  {  
          return $this->setupData[$sensorID];
        } else {
          return null;
        }
    } 
    /*
     *  Routinen zur Anpassung des Values wird in der Konfig des Servers unter transFormProcedur angegheben
     */
     
    private function elwaPwrkWh($stat) {   // Power akt Heizstab
        $resArr['wert'] = round($stat/1000,2);
        $resArr['einheit']='kWh';
        return $resArr;
    }
    private function elwaPwr($stat) {   // max Power in %
        $resArr['wert'] = $stat;
        $resArr['einheit']='%';
        return $resArr;
    }
    private function elwaTemp($stat) {   // temperatur
        $resArr['wert'] = round($stat/10,2);
        $resArr['einheit']='°C';
        return $resArr;
    }

    private function elwaProt($stat) {   // Protokoll
        $resArr['wert'] = $stat;
        switch ($stat) {
            case 0: case 0: $v='Auto Detec';break;
            case 1: $v='HTTP';break; 
            case 2: $v='Modbus TCP';break; 
            default: $v='Protokoll undefinioert';break;
/*
    case 3: $v='Fronius Auto';break; 
    case 4: $v='Fronius Manual';break; 
    case 5: $v='SMA Home Manager';break; 
    case 6: $v='Steca Auto';break; 
    case 7: $v='Varta Auto';break; 
    case 8: $v='Varta Manual';break; 
    case 12: $v='my-PV Meter Auto';break; 
    case 12: $v='my-PV Meter Manual';break; 
    case 14: $v='my-PV Power Meter Direct';break; 
    case 10: $v='RCT Power Manual';break; 
    case 15: $v='SMA Direct meter communication Auto';break; 
    case 16: $v='SMA Direct meter communication Manual';break; 
    case 19: $v='Digital Meter P1';break; 
    case 20: $v='Frequency';break; 
    case 100: $v='Fronius Sunspec Manual';break; 
    case 102: $v='Kostal PIKO IQ Plenticore plus Manual';break; 
    case 103: $v='Kostal Smart Energy Meter Manual';break; 
    case 104: $v='MEC electronics Manual';break; 
    case 105: $v='SolarEdge Manual';break; 
    case 106: $v='Victron Energy 1ph Manual';break; 
    case 107: $v='Victron Energy 3ph Manual';break; 
    case 108: $v='Huawei (Modbus TCP) Manual';break; 
    case 109: $v='Carlo Gavazzi EM24 Manual';break; 
    case 111: $v='Sungrow Manual';break; 
    case 112: $v='Fronius Gen24 Manual';break; 
    case 200: $v='Huawei (Modbus RTU)';break;   
    case 201: $v='Growatt (Modbus RTU)';break; 
    case 202: $v='Solax (Modbus RTU)';break; 
    case 203: $v='Qcells (Modbus RTU)';break; 
    case 204: $v='IME Conto D4 Modbus MID (Modbus RTU)';break; 
*/
        }
        $resArr['einheit']=$v;
        return $resArr;
    }
        
    
    
}
?>

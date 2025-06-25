<?php
namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;

class TasmotaSensorService implements SensorFetcherInterface
{

    private ?Logger $logger = null;
    private ?SimpleHttpClient $httpClient = null;
    private ?array $dataFromDevice  = null;

    public function __construct()
    {
        $this->httpClient = new SimpleHttpClient();
        $this->logger = Logger::getInstance();
    }

    public function supports( $sensor): bool
    {
        return strtolower($sensor['sensorSource']) === 'tasmota';
    }
    public function fetch( $sensor): ?array
    {
        $res=array();
        try {
            $url=$sensor['geraeteUrl'];
            if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'http://' . $url;
            }
 
            $this->logger->debugMe('Tasmota Sensorservice sensorID: '.$sensor['sensorID'].' url '.$url);    

            if (!$url) {
                $message = "Tasmota: geraeteUrl fehlt bei Sensor {$sensor['sensorID']}";
/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']]);
*/
                return null;
            }
            if ( $this->getDataFromDevice($url) === null) {
                return null;
            }

            $value = $data['StatusSNS']['ENERGY']['Power'] ?? null;

            if ($value === null) {
                $message = "Tasmota: Kein Power-Wert gefunden für Sensor {$sensor['sensorID']}";
                $this->logger->debugMe($message);    
/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']]);
*/
                return null;
            }

            // ? Erfolg: Log + Datenbank-Update
            $this->logger->debugMe("Tasmota: Sensor {$sensor['sensorID']} liefert {$value} W");    
/*
            $this->connection->update('tl_coh_sensors', [
                'lastUpdated' => time(),
                'lastValue' => $value,
                'lastError' => '',
            ], ['id' => $sensor['id]']]);
*/
            $res[]= [
                'sensorID'        => $sensor['sensorID'],
                'sensorValue'     => $value,
                'sensorEinheit'   => $sensor['sensorEinheit'],
                'sensorValueType' => $sensor['sensorValueType'],
                'sensorSource'    => $sensor['sensorSource'],
            ];
        } catch (\Throwable $e) {
            $message = "Tasmota: Fehler bei {$sensor['sensorID']}: " . $e->getMessage();
            $this->logger->debugMe($message);    
/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id']]);
*/
            return null;
        }
        return $res;
    }
    public function fetchArr(array $sensors): ?array // neue Methode
    {   $res=array();
        try {
            if (count($sensors) > 0) {
                $url=$sensors[0]['geraeteUrl'];
                if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                    $url = 'http://' . $url;
                }
            } 
            $this->logger->debugMe('Tasmota Sensorservice  url '.$url.' len sensors:'.count($sensors));    

            if (!$url) {
                $message = "Tasmota: keine url  Sensor {$sensors[0]->sensorID}";
/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']]);
*/
                return null;
            }
            if ( $this->getDataFromDevice($url) === null) {
                return null;
            }
            $this->logger->debugMe('Tasmota Sensorservice vor schleife count:  '.count($sensors));    
            // Zugriff auf Werte, z.B.:
            foreach ($sensors as $sensor) {
                $this->logger->debugMe('Tasmota Sensorservice  lese  '.$sensor['sensorID']);
                $lokalAccess=$sensor['sensorID'];
                if (!empty($sensor['sensorLokalId'])) $lokalAccess=$sensor['sensorLokalId'];
    
                if (isset($this->dataFromDevice['StatusSNS']['M60'][$lokalAccess])) {
                    $value = $this->dataFromDevice['StatusSNS']['M60'][$lokalAccess];
                    $einheit=$sensor['sensorEinheit'];  
                    if (!empty($sensor['transFormProcedur'])) {
                        if (method_exists($this, $sensor['transFormProcedur'])) {
                            $arr = $this->{$sensor['transFormProcedur']}($value);
                            $einheit=$arr['einheit'];                    
                            $value=$arr['wert'];
                        } else {
                            $this->logger->Error("Tasmota transFormProcedur ".$sensor['transFormProcedur'].' für SensorID  '.$sensor['sensorID'].' existiert nicht');  
                        }                 
                    }                   
                    $this->logger->debugMe('Tasmota Sensorservice SensorID  '.$sensor['sensorID']." lokalAccess $lokalAccess value $value Einheit $einheit");  
                    $res[$sensor['sensorID']] = [
                        'sensorID'        => $sensor['sensorID'],
                        'sensorValue'     => $value,
                        'sensorEinheit'   => $einheit,
                        'sensorValueType' => $sensor['sensorValueType'],
                        'sensorSource'    => $sensor['sensorSource']
                    ];
/*
                    $this->connection->update('tl_coh_sensors', [
                        'lastUpdated' => time(),
                        'lastValue' => $value,
                        'lastError' => '',
                        ], ['id' => $sensor['id']]);
*/                    
                    $this->logger->debugMe("sensorID: $value");
                } else {
                    $this->logger->debugMe('Tasmota Sensorservice keinen wert für sensorID: '.$sensor['sensorID']);    
                }
            }
            return $res;
        } catch (\Throwable $e) {
            $message = "Tasmota: Fehler bei : " . $e->getMessage();
            $this->logger->debugMe($message);    
/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id']]);
*/
            return null;
        }
        return $res;
    }
    private function getDataFromDevice(string $url) { 


        try {
            $url = $url.'/cm?cmnd=Status%2010';    //Request um die daten zu holen 
            $data = $this->httpClient->getJson($url);
            $this->logger->debugMe("Antwort: " . json_encode($data)); // ? sicher logbar
/*
            liefert das Array
                data[StatusSNS][Time]:2025-04-05T16:41:03
                data[StatusSNS][M60]:
                data[StatusSNS][M60][TS_E_in_108]:549.61    TS_E_in108   kWh ist als parameter beim tasmota von mir so konfiguriert
                data[StatusSNS][M60][TS_E_out_208]:3067.49  dito         kWh
                data[StatusSNS][M60][TS_Power]:-3345                     W
                data[StatusSNS][M60][TS_Power_L1]:-1088                  W
                data[StatusSNS][M60][TS_Power_L2]:-1102                  W
                data[StatusSNS][M60][TS_Power_L3]:-1155                  W
*/
            $this->dataFromDevice=$data;
            foreach ($data as $k=>$v) {
                $this->logger->debugMe("dataFromDevice[$k]:"); // ? sicher logbar

                foreach ($v as $k1=>$v1) {
                    if (is_array($v1)) {
                        $this->logger->debugMe("dataFromDevice[$k][$k1]:"); // ? sicher logbar
                        foreach ($v1 as $k2=>$v2) {
                            $this->logger->debugMe("dataFromDevice[$k][$k1][$k2]: $v2"); // ? sicher logbar
                        }
                    }   else {
                            $this->logger->debugMe("dataFromDevice[$k][$k1]: $v1"); // ? sicher logbar
                    }
                }
            }            
        } catch (\Throwable $e) {
            $this->logger->debugMe("Tasmota: Fehler bei getDataFromDevice : " . $e->getMessage());
            return null;
        }
        return $this->dataFromDevice=$data;
        
    }
    // funktionen zur normierung des Status
    private function tskWh($stat) {   // tasmota Wert in kwh
        $resArr['wert'] = $stat;
        $resArr['einheit']='kWh';
        return $resArr;
    }
    private function tsWatt($stat) {   // tasmota Wert in W
        $resArr['wert'] = $stat;
        $resArr['einheit']='W';
        return $resArr;
    }
}
?>

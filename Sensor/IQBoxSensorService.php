<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;

class IQBoxSensorService implements SensorFetcherInterface
{
    private ?Logger $logger = null;
    private ?SimpleHttpClient $httpClient = null;

    public function __construct()
    {
        $this->httpClient = new SimpleHttpClient();
        $this->logger = Logger::getInstance();
    }

    public function supports( $sensor): bool
    {
        return strtolower($sensor['sensorSource']) === 'iqbox';
    }
    public function fetch( $sensor): ?array
    {
        try {
            $url = $sensor['sensorReferenz'];

            if (!$url) {
                $message = "SolarAPI: geraeteUrl fehlt bei Sensor {$sensor['sensorID']}";
                $this->logger->debugMe($message);
/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']]);
*/
                return null;
            }

            $response = $this->httpClient->request('GET', $url);
            $data = $response->toArray();

            $key = $this->mapTransform($sensor['transFormProcedur'], $data);

            if ($key === null || !isset($data[$key])) {
                $message = "IQbox: Kein passender Wert für '{$sensor['transFormProcedur']}' bei Sensor {$sensor['sensorID']}";
                $this->logger->debugMe($message);
/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']]);
*/
                return null;
            }

            $value = $data[$key];

            $this->logger->debugMe("IQbox: Sensor {$sensor['sensorID']} liefert {$value} W");    
/*
            $this->connection->update('tl_coh_sensors', [
                'lastUpdated' => time(),
                'lastValue'   => $value,
                'lastError'   => '',
            ], ['id' => $sensor['id']]);
*/
            return [
                'sensorID'        => $sensor['sensorID'],
                'sensorValue'     => $value,
                'sensorEinheit'   => $sensor['sensorEinheit'],
                'sensorValueType' => $sensor['sensorValueType'],
                'sensorSource'    => $sensor['sensorSource'],
            ];
        } catch (\Throwable $e) {
            $message = "IQbox: Fehler bei Sensor {$sensor['sensorID']}: " . $e->getMessage();
           $this->logger->debugMe($message);
/*
            $this->connection->update('tl_coh_sensors', [
                'lastError' => $e->getMessage()
            ], ['id' => $sensor['id']]);
*/
            return null;
        }
        
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
            $this->logger->debugMe('IQbox Sensorservice  url '.$url.' len sensors:'.count($sensors));    

            if (!$url) {
                $message = "IQbox: keine url  Sensor {$sensors[0]->sensorID}";


/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']]);
*/
                return null;
            }
            $this->logger->debugMe('IQbox Sensorservice vor schleife count:  '.count($sensors));    
            // Zugriff auf Werte, z.B.:
            foreach ($sensors as $sensor) {
//                $this->logger->debugMe('IQbox Sensorservice  lese  '.$sensor['sensorID']);
                $lokalAccess=$sensor['sensorID'];
                if (!empty($sensor['sensorLokalId'])) $lokalAccess=$sensor['sensorLokalId'];
                $value = $this->getfromIQbox($url,$lokalAccess);
                $einheit=$sensor['sensorEinheit'];  
                if (!empty($sensor['transFormProcedur'])) {
                    if (method_exists($this, $sensor['transFormProcedur'])) {
                        $arr = $this->{$sensor['transFormProcedur']}($value);
                        $einheit=$arr['einheit'];                    
                        $value= $arr['wert'];
                        if ($sensor['transFormProcedur'] == 'IQSOC') {
                        }
                    } else {
                        $this->logger->Error('IQbox transFormProcedur '.$sensor['transFormProcedur'].' für SensorID  '.$sensor['sensorID'].' existiert nicht');  
                    }                 
                }                   
//                $this->logger->debugMe('IQbox Sensorservice SensorID  '.$sensor['sensorID']." lokalAccess $lokalAccess value $value Einheit $einheit");  
                $res[$sensor['sensorID']] = [
                    'sensorID'        => $sensor['sensorID'],
                    'sensorValue'     => $value,
                    'sensorEinheit'   => $einheit,
                    'sensorValueType' => $sensor['sensorValueType'],
                    'sensorSource'    => $sensor['sensorSource'],
                ];
                //$this->logger->debugMe("sensorID ".$sensor['sensorID']." value: $value");
/*
                $this->connection->update('tl_coh_sensors', [
                        'lastUpdated' => time(),
                        'lastValue' => $value,
                        'lastError' => '',
                        ], ['id' => $sensor['id']]);
*/
            }
            $this->logger->debugMe('IQbox Sensorservice nach schleife res count:  '.count($res));    
            return $res;
        } catch (\Throwable $e) {
            $message = "IQbox: Fehler bei : " . $e->getMessage();
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
    /*
     * liest einen Status von der IQbox
     * der name ist der Name aus dem Link
     *
     */
    private function getfromIQbox ($url,$param) {

        $urlItem=$url.'/rest/items/'.$param;
        $data = $this->httpClient->getJson($urlItem);
        $this->logger->debugMe("Antwort: " . json_encode($data)); // ? sicher logbar
        
        $state = is_array($data) && isset($data['state']) ? $data['state'] : '';
        return $state;
    }
    private function IQSOC($stat) {   // Füllstand Betterie
        $statearr = explode(" ", $stat);
        $resArr['wert'] = $statearr[0];
        $resArr['einheit']='%';
        return $resArr;
    }  

private function IQkWh($stat) {
    $statearr = explode(" ", $stat ?? '');
    $rawValue = $statearr[0] ?? '';
    $unit = strtolower($statearr[1] ?? '');

    if (!is_numeric($rawValue)) {
        $resArr['wert'] = 0;  // oder null, oder eine Fehlermeldung
        $resArr['einheit'] = 'kWh';
        return $resArr;
    }

    $value = match ($unit) {
        'ws' => round($rawValue / 3600000, 2),
        'wh' => round($rawValue / 1000, 2),
        default => (float)$rawValue
    };

    $resArr['wert'] = $value;
    $resArr['einheit'] = 'kWh';
    return $resArr;
}

    private function IQkW($stat) {   // Angabe kW W
        $resArr=[];
        $valarr = explode("|", $stat ?? '');
        if (count($valarr) > 1) {           // mit zeitangabe
            // liefere den zeitpunkt der messung in sec
            $unixzeit_ms=$valarr[0];
            $unixzeit_sec=$unixzeit_ms/1000;    // Umwandeln in Sekunden (durch 1000 teilen, da die Unixzeit in Millisekunden gegeben ist)
            $resArr['unixtime'] = $unixzeit_sec;
            $strWert=$valarr[1];              
        } else $strWert=$stat;

        $statearr = explode(" ", $strWert ?? '');
        $v = strtolower($statearr[1] ?? '');
        if ($v == 'w') {$value=round($statearr[0]/1000,2);}
        else $value=$statearr[0];
        $resArr['wert'] = $value;
        $resArr['einheit']='kW';
        return $resArr;
    } 
 
    private function IQTemp($stat) {   // Temp z.b Batterie
        $statearr = explode(" ", $stat);
        $resArr['wert'] = $statearr[0];
        $resArr['einheit']='°C';
        return $resArr;
    }
    
}
?>
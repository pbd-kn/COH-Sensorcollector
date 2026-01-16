<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;

class IQBoxSensorService implements SensorFetcherInterface
{
    private ?Logger $logger = null;
    private ?SimpleHttpClient $httpClient = null;
    private string $cookieFile = '/home/peter/scripts/coh/cookies/iqbox_cookie.txt';
    private string $baseUrl    = 'http://192.168.178.26';

    public function __construct()
    {
        $this->logger = Logger::getInstance();

        // Login EINMAL beim Start
    }

    public function supports( $sensor): bool
    {
        $res =strtolower($sensor['sensorSource']) === 'iqbox';
        if ($res) { 
            $this->baserUrl= $sensor['geraeteUrl'];
//            $this->logger->debugMe("IQBoxSensorService supports url " . $this->baserUrl . " " . $sensor['geraeteUrl']);  
//var_dump($sensor);die();
        }  
        return $res;
    }
private function ampereLogin(): void
{

    $username = "installer";
    $password = "sfjimorx"; 
    //writeLog ("ampereLogin urlIQbox $urlIQbox cookieFile $cookieFile");
    $ch = curl_init($this->baseUrl . "/auth/login");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            "username" => $username,
            "password" => $password
        ]),
        CURLOPT_COOKIEJAR      => $this->cookieFile,
        CURLOPT_COOKIEFILE     => $this->cookieFile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $this->logger->Error("IQBoxSensorService ampereLogin url " . $this->baserUrl . " cookieFile " .$this->cookieFile);
        throw new RuntimeException("Login failed: " . curl_error($ch));
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($code, [200, 302, 303], true)) {
        $this->logger->Error("IQBoxSensorService ampereLogin responeCode $code url " . $this->baserUrl . " cookieFile " . $this->cookieFile);
        throw new RuntimeException("Login HTTP error $code");
    }
}


private function ampereRequest(string $path, bool $retry): array
{
    //writeLog ("ampereRequest  baseUrl $baseUrl path $path");
    $requestUrl = $this->baseUrl . "/rest/items/" . $path;
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $this->cookieFile,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $this->logger->Error("IQBoxSensorService ampereRequest  response false requestUrl $requestUrl cookieFile " . $this->cookieFile);
        throw new RuntimeException("cURL error: " . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    //$this->logger->debugMe("IQBoxSensorService ampereRequest  code  $code requestUrl $requestUrl");
    if (in_array($code, [301, 302, 303, 401, 403, 404], true)) {
        // Session ungültig → EINMAL neu einloggen
        $this->logger->debugMe("IQBoxSensorService ampereRequest  Session ungültig EINMAL neu einloggen baseUrl requestUrl $requestUrl cookieFile code $code");
        if ($retry) {
            $this->logger->Error("IQBoxSensorService ampereRequest Session ungültig 2.Login fehlerhaft response false requestUrl $requestUrl cookieFile " . $this->cookieFile);
            return [
                "ok"        => false,
                "http_code" => $code,
                "data"      => "IQBoxSensorService ampereRequest Session ungültig 2.Login fehlerhaft response false requestUrl $requestUrl cookieFile " . $this->cookieFile
            ];
            //throw new RuntimeException("Auth failed after retry (HTTP $code)");
        }
        ampereLogin();
        return $this->ampereRequest($path, true);
    }
    if ($code !== 200) {
        $this->logger->Error("IQBoxSensorService ampereRequest returnCode != 200 code $code requestUrl $requestUrl cookieFile " . $this->cookieFile);
        return [
                "ok"        => false,
                "http_code" => $code,
                "data"      => "IQBoxSensorService ampereRequest returnCode != 200 $code requestUrl $requestUrl cookieFile " . $this->cookieFile
        ];
        //throw new RuntimeException("HTTP error $code");
    } 
    $json = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->Error("IQBoxSensorService ampereRequest Invalid JSON response $json");
        return [
                "ok"        => false,
                "http_code" => $code,
                "data"      => "IQBoxSensorService ampereRequest Invalid JSON response $json"
        ];
        //throw new RuntimeException("Invalid JSON response");
    }
    return [
        "ok"        => true,
        "http_code" => 200,
        "data"      => $response
    ];
}

    public function fetch( $sensor): ?array
    {
        try {
            $url = $sensor['geraeteUrl'];

            if (!$url) {
                $message = "SolarAPI: geraeteUrl fehlt bei Sensor {$sensor['sensorID']}";
                $this->logger->debugMe($message);
                return null;
            }
            $this->baseUrl=$url; 
            $this->ampereLogin();  
            $this->logger->Info("IQBoxSensorService login ok");  

            $response = $this->httpClient->request('GET', $url);
            $data = $response->toArray();

            $key = $this->mapTransform($sensor['transFormProcedur'], $data);

            if ($key === null || !isset($data[$key])) {
                $message = "IQbox: Kein passender Wert für '{$sensor['transFormProcedur']}' bei Sensor {$sensor['sensorID']}";
                $this->logger->debugMe($message);
                return null;
            }

            $value = $data[$key];

            $this->logger->debugMe("IQbox: Sensor {$sensor['sensorID']} liefert {$value} W");    
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
            return null;
        }
        
    }
    
    public function fetchArr(array $sensors): ?array // neue Methode
    {   
        $res=array();
        try {
            if (count($sensors) > 0) {
                $url=$sensors[0]['geraeteUrl'];
                if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                    $url = 'http://' . $url;
                }
            }
            if (!$url) {
                $message = "IQbox: geraeteUrl fehlt bei Sensor {$sensor['sensorID']}";
                $this->logger->Error($message);
                return null;
            }
            
            $this->baseUrl=$url; 
            $this->ampereLogin();  
            $this->logger->debugMe('IQbox Sensorservice  url '.$url.' len sensors:'.count($sensors));    

            $this->logger->debugMe('IQbox Sensorservice vor schleife count:  '.count($sensors));    
            // Zugriff auf Werte, z.B.:
            foreach ($sensors as $sensor) {
//                $this->logger->debugMe('IQbox Sensorservice  lese  '.$sensor['sensorID']);
                $lokalAccess=$sensor['sensorID'];
                if (!empty($sensor['sensorLokalId'])) $lokalAccess=$sensor['sensorLokalId'];
                $value = $this->getfromIQbox($lokalAccess);
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
    private function getfromIQbox ($variable) {

    //writeLog ("ampereGet  baseUrl $baseUrl path $path");
        try {
            $result=$this->ampereRequest($variable, false);
            if ($result['ok']) {

                $data = json_decode((string)$result['data'],true);
                $state=$data['state'];  
                $this->logger->debugMe("IQbox getfromIQbox state ok variable $variable state $state  ");    
            } else {
                $state=$result['data'];  
                $this->logger->Error("IQbox getfromIQbox state error variable $variable state $state  ");  
            }
        } catch (Throwable $e) {
            $state=" ampereGet request failed variable $variable";  
        }
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
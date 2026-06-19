<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\mysql_dialog;

class HeizstabSensorService implements SensorFetcherInterface
{

    private ?array $aktData  = null;
    private ?array $setupData  = null;
    private $heizstabCookieFile = '/home/peter/scripts/coh/cookies/heizstab_cookie.txt';
    private $loginPath = (string)'/auth.jsn';       // pfad zum auth request
    private $password = '14881488';                 // passwort für auth login
    private $passwordField = 'pw';                  // feld für den auth request
    private $insecureTls = false;                   // TLS-/HTTPS-Zertifikatsprüfung ausschalten ja/nein.


    public function __construct( private mysql_dialog $db, private Logger $logger, private SimpleHttpClient $httpClient) {}        
    
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
        $debugval=false;
        $this->logger->setDebug($debugval);
        $this->logger->debugMe( "HeizstabSensorService fetchArr len ".count($sensors));
        $res=array();
        try {
            if (count($sensors) > 0) {
                $url=$sensors[0]['geraeteUrl'];
                $this->logger->debugMe('Heizstab Sensorservice  url '.$url.' len sensors:'.count($sensors));    
                if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                    $url = 'https://' . $url;
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
if ($debugval) $this->logger->setDebug(false);
                return null;
            }
            $this->logger->debugMe("HeizstabSensorService url $url");
            if ( $this->getDataFromDevice($url) === null) {
                $this->logger->Error( "getDataFromDevice null");
if ($debugval) $this->logger->setDebug(false);
                return null;
            }
            // Zugriff auf Werte, z.B.:
            foreach ($sensors as $sensor) {
                $lokalAccess=$sensor['sensorID'];
                if (!empty($sensor['sensorLokalId'])) $lokalAccess=$sensor['sensorLokalId'];
                $value = $this->getHeizstabdata($lokalAccess);
                $einheit=$sensor['sensorEinheit'];  
                $this->logger->debugMe( "Heizstab Sensorservice SensorID  ".$sensor['sensorID']." lokalAccess $lokalAccess value $value Einheit $einheit");  
                if (!empty($sensor['transFormProcedur'])) {
                 $this->logger->debugMe( "Heizstab Sensorservice SensorID  ".$sensor['sensorID']." transFormProcedur ". $sensor['transFormProcedur']);  
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
                    $this->logger->Info('Heizstab Sensorservice keinen wert für sensorID: '.$sensor['sensorID'] . "lokalAccess $lokalAccess");
                    $res[$sensor['sensorID']] = [
                        'sensorID'        => $sensor['sensorID'],
                        'sensorValue'     => 0,
                        'sensorEinheit'   => $einheit,
                        'sensorValueType' => $sensor['sensorValueType'],
                        'sensorSource'    => $sensor['sensorSource'],
                    ];
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

if ($debugval) $this->logger->setDebug(false);
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
            $this->ensureElwaLogin           $url, '/auth.jsn', $this->passwordField, $this->password, $this->heizstabCookieFile, $this->insecureTls);
            $v1=fetchJsonWithRelogin($url, '/data.jsn', $this->passwordField, $this->password, $this->heizstabCookieFile, $this->insecureTls);
            if ($v1 == null) {
            } else {
                $this->aktData=$v1;
            }
            $v2=fetchJsonWithRelogin($url, '/setup.jsn', $this->passwordField, $this->password, $this->heizstabCookieFile, $this->insecureTls);
            if ($v2 == null) {
            } else {
                $this->setupData=$v2;
            }
            
            //$v1=$this->getdata($url);
            //$v2=$this->getsetup($url);
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
    private function fetchJsonWithRelogin( string &$baseUrl, string $path, string $loginPath, string $passwordField, string $password, string $cookieFile, bool &$insecureTls): array
    {
        $url = $this->buildUrl($baseUrl, $path);
        $result = curlGet($url, $cookieFile, $insecureTls);

        if (shouldSwitchToHttps($baseUrl, $result['http_code'], $result['body'])) {
            $baseUrl = switchBaseUrlToHttps($baseUrl);
            echo "$path HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl\n";
            @unlink($cookieFile);
            ensureElwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);

            $url = $this->buildUrl($baseUrl, $path);
            $result = curlGet($url, $cookieFile, $insecureTls);
        }

        if (in_array($result['http_code'], [301, 302, 303, 401, 403], true)) {
            echo "$path Session abgelaufen oder Login erforderlich (HTTP {$result['http_code']}). Re-Login ...\n";
            @unlink($cookieFile);
            $loginResponse = elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
            echo "Re-Login OK\n";
            echo "Login Antwort:\n";
            echo trim($loginResponse) . "\n\n";

            $result = curlGet($url, $cookieFile, $insecureTls);
        }

        if ($result['http_code'] !== 200) {
            throw new RuntimeException("GET HTTP Fehler {$result['http_code']} fuer $url Antwort: {$result['body']}");
        }

        $response = $result['body'];
        $decoded = json_decode($response, true);

        echo "$path HTTP OK\n";

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Antwort von $path ist kein JSON: " . json_last_error_msg() . " Antwort: " . trim($response)
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Antwort von $path ist JSON, aber kein Array");
        }

        return $decoded;
    }
    private function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }        
/* obsolet
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
        $url=$url."/setup.jsn";
        $this->logger->debugMe( "getsetup from $url");
        $data = $this->httpClient->getJson($url);
        $this->setupData=$data;
        return $data;
    }
*/
    /*  liefert den wert vom Heizstab aus global $aktData,$setupData;
     *  
     */
    private function getHeizstabdata ($sensorID) {
        if (isset($this->aktData[$sensorID]) )  {  
$this->logger->debugMe (" getHeizstabdata return $sensorID " . $this->aktData[$sensorID]);
          return $this->aktData[$sensorID];
        } else if (isset($this->setupData[$sensorID]) )  {  
$this->logger->debugMe (" getHeizstabdata return $sensorID " . $this->setupData[$sensorID]);
          return $this->setupData[$sensorID];
        } else {
            $this->logger->Error( "Heizstab: Fehler bei getHeizstabdata : $sensorID $sensorID weder in aktData (data.jsn) noch setupData (data.jsn)");          
            return null;
        }
    } 
    /*  
     * https Login funktionen
     */
    private function ensureElwaLogin(string &$baseUrl,string $loginPath,string $passwordField,string $password,string $cookieFile,bool &$insecureTls): void {
        if (file_exists($cookieFile) && filesize($cookieFile) > 0) {
            echo "Cookie vorhanden, Login wird wiederverwendet.\n";
            return;
        }

        echo "Kein Cookie vorhanden, Login wird ausgeführt.\n";
        $loginResponse = elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
        echo "Login OK\n";
        echo "Login Antwort:\n";
        echo trim($loginResponse) . "\n\n";
    }

    private function elwaLogin(string &$baseUrl,string $loginPath,string $passwordField,string $password,string $cookieFile,bool &$insecureTls): string {
        $url = buildUrl($baseUrl, $loginPath);
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([$passwordField => $password]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        applyTlsOptions($ch, $insecureTls);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            if (!$insecureTls && isSelfSignedCertificateError($error)) {
                $insecureTls = true;
                echo "Selbstsigniertes Zertifikat erkannt, TLS-Prüfung wird für diesen Test deaktiviert.\n";
                return elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
            }

            throw new RuntimeException('ELWA Login fehlgeschlagen: ' . $error);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (shouldSwitchToHttps($baseUrl, $code, (string)$response)) {
            $baseUrl = switchBaseUrlToHttps($baseUrl);
            echo "HTTP->HTTPS Redirect erkannt, neuer Base URL: $baseUrl\n";
            return elwaLogin($baseUrl, $loginPath, $passwordField, $password, $cookieFile, $insecureTls);
        }

        if (!in_array($code, [200, 204, 302, 303], true)) {
            throw new RuntimeException('ELWA Login HTTP Fehler: ' . $code . ' Antwort: ' . $response);
        }

        return (string)$response;
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

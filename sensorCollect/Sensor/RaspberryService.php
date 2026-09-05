<?php

namespace PbdKn\cohSensorcollector\Sensor;



use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\mysql_dialog;

class RaspberryService implements SensorFetcherInterface
{

    public function __construct( private mysql_dialog $db, private Logger $logger, private SimpleHttpClient $httpClient) {}   
     
    public function supports( $sensor): bool
    {
        return strtolower($sensor['sensorSource']) === 'raspberry';
    }
    public function fetch( $sensor): ?array
    {
        try {
            $this->logger->debugMe("raspberry: Sensor {$sensor['sensorID']} liefert {$value} W");    
/*
            $this->connection->update('tl_coh_sensors', [
                'lastUpdated' => time(),
                'lastValue'   => $value,
                'lastError'   => '',
            ], ['id' => $sensor['id']]);
*/
            return null;    // fetch wird nicht untestützt
        } catch (\Throwable $e) {
            $message = "raspberry: Fehler bei Sensor {$sensor['sensorID']}: " . $e->getMessage();
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
    {   
        $res=array();
        try {
            if (count($sensors) > 0) {
                $url=$sensors[0]['geraeteUrl'];
                if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                    $url = 'http://' . $url;
                }
            } 
            $this->logger->debugMe('raspberry Sensorservice  url '.$url.' len sensors:'.count($sensors));    

            if (!$url) {
                $message = "raspberry: keine url  Sensor {$sensors[0]->sensorID}";


/*
                $this->connection->update('tl_coh_sensors', [
                    'lastError' => $message
                ], ['id' => $sensor['id']]);
*/
                return null;
            }
            $this->logger->debugMe('raspberry Sensorservice vor schleife count:  '.count($sensors));    
            // Zugriff auf Werte, z.B.:
            foreach ($sensors as $sensor) {
                $this->logger->debugMe('raspberry Sensorservice  lese  '.$sensor['sensorID']);
                $lokalAccess=$sensor['sensorID'];
                if (empty($sensor['sensorLokalId'])) {
                    $this->logger->Error('raspberry transFormProcedur für SensorID '.$sensor['sensorID'].' darf nicht leer sein.Kommando für Raspberrry');  
                } else {
                    $lokalAccess=$sensor['sensorLokalId'];
                }

                if (strcasecmp((string) $lokalAccess, 'raspberry.all') === 0) {
                    $snapshot = $this->getStatusSnapshot();
                    $value = $snapshot['values'] ?? null;
                } else {
                    $value = $this->raspBerryCmd($url,$lokalAccess);
                }
                if ($value === '' || $value === null) {
                    $this->logger->Error('raspberry raspBerryCmd für SensorID '.$sensor['sensorID']." lokaladress $lokalAccess value empty");  
                }
                $einheit=$sensor['sensorEinheit'];
                $valueType = $sensor['sensorValueType'];
                if (is_array($value) || is_object($value)) {
                    $einheit = 'json';
                    $valueType = 'json';
                }
                if (!empty($sensor['transFormProcedur']) && $sensor['transFormProcedur'] !== '-') {
                    if (method_exists($this, $sensor['transFormProcedur'])) {
                        $arr = $this->{$sensor['transFormProcedur']}($value);
                        $einheit=$arr['einheit'];                    
                        $value= $arr['wert'];
                    } else {
                        $this->logger->Error('raspberry transFormProcedur '.$sensor['transFormProcedur'].' für SensorID  '.$sensor['sensorID'].' existiert nicht');  
                    }                 
                }                   
                $logValue = is_array($value) || is_object($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $value;
                $this->logger->debugMe('raspberry Sensorservice SensorID  '.$sensor['sensorID']." lokalAccess $lokalAccess value $logValue Einheit $einheit");
                $res[$sensor['sensorID']] = [
                    'sensorID'        => $sensor['sensorID'],
                    'sensorValue'     => $value,
                    'sensorEinheit'   => $einheit,
                    'sensorValueType' => $valueType,
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
            $this->logger->debugMe('raspberry Sensorservice nach schleife res count:  '.count($res));    
            return $res;
        } catch (\Throwable $e) {
            $message = "raspberry: Fehler bei : " . $e->getMessage();
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

    private function getStatusSnapshot(): ?array
    {
        $url = 'http://127.0.0.1/api/coh/raspberry-status.php?token=' . rawurlencode('COH_CODE');
        $payload = $this->httpClient->getJson($url, 15);
        if (!is_array($payload) || empty($payload['ok']) || !is_array($payload['values'] ?? null)) {
            $this->logger->Error('Raspberry: lokale Status-API lieferte keine gueltigen values.');
            return null;
        }

        return $payload;
    }
    private function raspBerryCmd($url,$cmd) {
        // Whitelist für erlaubte Kommandos
        $whitelist = ['checkPhpHeizungserver.sh', 
            'logfile_protokoll.sh',
            'heizung-server-configjson.php',
            'myOtherCommand',
            'checkSensorCollectorServer.sh',
            'check_disk_usage.sh'];  
            
        // Zerlege den String anhand der Doppelpunkte
        $parts = explode(':', $cmd);

        // Erwartet mindestens 3 Teile: [exec, command, Befehl]
        if (count($parts) >= 3) {
            $action = $parts[0];   // z. B. 'exec'
            $type   = $parts[1];   // z. B. 'command'
            $value  = $parts[2];   // z. B. 'checkPhpHeizungserver'
            if ($type === 'command') {
                if (!in_array($value, $whitelist, true)) {
                    $this->logger->Error("raspberry Nicht erlaubter Befehl: $value");
                    return null;  
                }
                $this->logger->debugMe("raspberry action: $action");
                if ($action === 'exec' ) {
                    // exec Command ausführen (Pfad optional anpassen)
                        $output = shell_exec("bash ".__DIR__."/RaspberryExecScripts/$value");
//echo "Action $action" . "bash ".__DIR__."/RaspberryExecScripts/$value"." Result: $output\n ";
//                        $output = shell_exec("bash /etc/coh/scripts/execScripts/$value");
                        $this->logger->debugMe("raspberry Result: $output");
                        return $output;
                }
                if ($action === 'php' ) {
                    // php Command ausführen (Pfad optional anpassen)
                    // php:command:heizung-server-configjson.php:json:Heizintervalle
                        $retval = shell_exec("/usr/bin/php ".__DIR__."/RaspberryExecScripts/$value");
                        if (count($parts) >= 3) {   // konvertierung nach evtl: json
                            if ($parts[3] == 'json') {
                                $this->logger->debugMe("raspberry read php script: $value");
                                //$this->logger->debugMe("raspberry read: $retval");    
                                $retval = json_decode($retval, true);   // json als array
                                if (count($parts) >= 4) {                                
                                    $retval=$retval[$parts[4]];    // hole wert aus json
                                    if (is_array($retval)) {
                                        $retval = json_encode($retval);
                                    }
                                }
                                $this->logger->debugMe("raspberry Json Result: $retval");
                            }
                        }
                        return $retval;
                }
            } else {
                $this->logger->Error("raspberry Nicht vom Typ exec:command: $value");
                return null;  
            }
        } else {
            $this->logger->Error("raspberry ungültiges Format: url $url  $cmd");
            return null;  
        }
    }
}
?>

<?php

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\mysql_dialog;

class IQBoxSensorService implements SensorFetcherInterface
{
    private string $cookieFile = '/home/peter/scripts/coh/cookies/iqbox_cookie.txt';
    private string $baseUrl    = 'http://192.168.178.26';
    private bool $loggedIn     = false;
    private ?array $items  = null;


    public function __construct(
        private mysql_dialog $db,
        private Logger $logger,
        private SimpleHttpClient $httpClient
    ) {}

    public function supports($sensor): bool
    {
        $res = strtolower($sensor['sensorSource']) === 'iqbox';
        if ($res) {
            $this->baseUrl = $sensor['geraeteUrl'];
        }
        return $res;
    }

    public function fetch($sensor): ?array
    {
        try {
            $result = $this->fetchArr([$sensor]);
            if (!$result) return null;
            return $result[$sensor['sensorID']] ?? null;
        } catch (\Throwable $e) {
            $this->logger->Error("IQBox fetch Fehler: " . $e->getMessage());
            return null;
        }
    }

    // -------------------------------------------------
    // LOGIN (nur einmal)
    // -------------------------------------------------
    private function ensureLogin(): void
    {
        if ($this->loggedIn) return;
        if (!file_exists($this->cookieFile)) { $this->ampereLogin(); }
        $this->loggedIn = true;
    }

    private function ampereLogin(): void
    {
        $username = "installer";
        $password = "sfjimorx";
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
            $this->logger->Info("Login failed: " . curl_error($ch));
            throw new \RuntimeException("Login failed: " . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!in_array($code, [200, 302, 303], true)) {
            $this->logger->Info("Login failed: " . curl_error($ch));
            throw new \RuntimeException("Login HTTP error $code");
        }
        $this->logger->debugMe("ampereLogin Login OK");
    }
    // -------------------------------------------------
    // REQUEST (mit Schutz gegen 503)
    // -------------------------------------------------
    private function ampereRequest(?string $path = null, bool $retry = false): array
    {
        $this->ensureLogin();
        usleep(200000); // 200ms Schutz gegen Überlast
        $requestUrl = $this->baseUrl . "/rest/items" . ($path ? "/" . $path : "");
        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ["Connection: close"]
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new \RuntimeException("cURL error: " . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // 503 → Retry
        if ($code === 503 && !$retry) {
            $this->logger->debugMe("IQBox 503 retry");
            usleep(500000);
            return $this->ampereRequest($path, true);
        }
        // 👉 NEU: sauberer Abbruch beim zweiten Versuch
        if ($code === 503 && $retry) {
            $this->logger->Error("IQBox 503 dauerhaft Abbruch");
            return [
                "ok" => false,
                "data" => "503 Service Unavailable"
            ];
        }
        // Session verloren
        if (in_array($code, [401, 403], true) && !$retry) {
            $this->logger->debugMe("Session verloren Login neu");
            $this->loggedIn = false;
            $this->ampereLogin();
            return $this->ampereRequest($path, true);
        }
        if ($code !== 200) {
            $this->logger->Error("IQBox HTTP Fehler $code URL $requestUrl");
            return ["ok" => false];
        }
        return ["ok" => true, "data" => $response];
    }
    // -------------------------------------------------
    // HAUPTLOGIK
    // -------------------------------------------------
    public function fetchArr(array $sensors): ?array
    {
        $debug=false;
        $this->logger->setDebug($debug);
        $res = [];

        try {
            if (count($sensors) === 0) return null;
            $url = $sensors[0]['geraeteUrl'];
            if ($url && !str_starts_with($url, 'http')) {
                $url = 'http://' . $url;
            }
            $this->baseUrl = $url;
            try {
                $this->items = $this->getDataFromDevice();
            } catch (\Throwable $e) {
                $this->logger->Error("IQBox Fehler: fehler beim lesen gettall" . $e->getMessage());
            }
            foreach ($sensors as $sensor) {
                $sensorLokalId = !empty($sensor['sensorLokalId']) ? $sensor['sensorLokalId'] : $sensor['sensorID'];
                $outputMode=$sensor['outputMode'];
                $sensorID=$sensor['sensorID'];

                //$this->logger->debugMe("name: $sensorLokalId value $value einheit $einheit ");
                $rrArr = [];
                if (isset($this->items[$sensorLokalId])) {
                    $rrArr = $this->IQStatreal($this->items[$sensorLokalId],$sensorLokalId, $sensorID, $outputMode);
                    $value = $rrArr['wert'];
                    $einheit = $rrArr['einheit'];
                } 
                else {
                    $this->logger->Error("fetchArr this->items[$sensorLokalId] existiert nicht in items");
                    continue;
                }
                $this->logger->debugMe("Result 1: value $value einheit $einheit " . "sensorID " . $sensor['sensorID'] . " name $sensorLokalId ");
                $res[$sensor['sensorID']] = [
                    'sensorID'        => $sensor['sensorID'],
                    'sensorValue'     => $value,
                    'sensorEinheit'   => $einheit,
                    'sensorValueType' => $sensor['sensorValueType'],
                    'sensorSource'    => $sensor['sensorSource'],
                ];
            }
            if ($debug) $this->logger->setDebug(false);

            return $res;
        } catch (\Throwable $e) {
            $this->logger->Error("IQBox Fehler: " . $e->getMessage());
                        if ($debug) $this->logger->setDebug(false);

            return null;
        }
    }
    // -------------------------------------------------
    // TEST: ALLE ITEMS IN EINEM REQUEST HOLEN
    // -------------------------------------------------
    private function getDataFromDevice() : ?array {
        try {
            $result = $this->ampereRequest(); // 🔥 kein eigener curl mehr
            if (!$result['ok']) { $this->logger->Error("getDataFromDevice Fehler"); return null;
        }
        $data = json_decode($result['data'], true);
        if (!is_array($data)) { $this->logger->Error("getDataFromDevice JSON Fehler"); return null; }
        // falls {items:[...]}
        if (isset($data['items'])) { $data = $data['items']; }
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            $name  = $item['name']  ?? null;
            $state = $item['state'] ?? null;
            if (!$name) continue;
            $items[$name] = ($state === null || $state === 'NULL' || $state === 'UNDEF') ? null : $state;
        }
        $this->logger->debugMe("getDataFromDevice OK: " . count($items));
        return $items;
        } catch (\Throwable $e) {
            $this->logger->Error("getDataFromDevice Exception: " . $e->getMessage());
            return null;
        }
    }    
    // -------------------------------------------------
    // TRANSFORMS
    // -------------------------------------------------
    private function normalizeUnit(string $unit): string
    {
        $unit = trim($unit);
        $unit = @iconv('UTF-8', 'UTF-8//IGNORE', $unit);  // kaputte UTF-8 Sequenzen entfernen (wichtig!)
        $unit = str_replace(['Â','Ã','â'], '', $unit);    // typische Störzeichen entfernen (nur bekannte Fehler!)
        return strtolower($unit);
    }
    private function detectUnit(string $unitRaw): string {
        $u = $this->normalizeUnit($unitRaw);
        // zuerst längere / spezifische Einheiten!
        if (strpos($u, 'kwh') !== false) return 'kWh';
        if (strpos($u, 'wh') !== false) return 'Wh';
        if (strpos($u, 'ws') !== false) return 'Ws';
        if (strpos($u, 'kw') !== false) return 'kW';
        if ($u === 'w') return 'W';
        if (strpos($u, '°c') !== false) return '°C';
        if ($u === 'c') return '°C';
        if (strpos($u, '%') !== false) return '%';
        return $unitRaw;
    } 
    /*
     * liefert den ersten werte ab den startdatum
     */
    private function getValueFromStartday ($sensorID,$startOfDay) {

        //$sensorID=$sensor['sensorID'];
        
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
$this->logger->debugMe("IQbox getValueFromStartday sql $sqlFirst");    
        $resFirst = $conn->query($sqlFirst);
        if (!$resFirst || !$rowFirst = $resFirst->fetch_assoc()) {
            return 0;
        }
        $firstValue = (float)$rowFirst['sensorValue'];   
        
        return $firstValue;
    }    
           
    private function IQStatreal($stat, $sensorLokalId, $sensorID,$outputMode) {
        //$this->logger->debugMe("IQStatreal name $sensorLokalId stat $stat");
        // -----------------------------------
        // 1. Wert extrahieren
        // -----------------------------------
        $valarr = explode("|", $stat ?? '');
        $strWert = count($valarr) > 1 ? $valarr[1] : $stat;
        // -----------------------------------
        // 2. Wert + Einheit parsen (inkl. E+9)
        // -----------------------------------
        if (preg_match('/^\s*([+-]?[0-9\.,]+(?:[eE][+-]?[0-9]+)?)\s*([^\s]+)?/u', $strWert, $m)) {
            $value   = $m[1];
            $unitRaw = $m[2] ?? '';
        } else {
            // KEINE ZAHL → STRING zurückgeben (z.B. CHARGING)
            return ['wert' => trim($strWert), 'einheit' => ''];
        }
        // -----------------------------------
        // 3. Wert normalisieren
        // -----------------------------------
        $valueRaw = trim($value);
        if (is_numeric($valueRaw)) { $value = (float)$valueRaw;
        } elseif (preg_match('/^[+-]?[0-9]+,[0-9]+$/', $valueRaw)) { $value = (float) str_replace(',', '.', $valueRaw);
        } else { $value = $valueRaw;
        }
        // -----------------------------------
        // 4. Einheit erkennen
        // -----------------------------------
        $unit = $this->detectUnit($unitRaw);
        // -----------------------------------
        // 5. Logik
        // -----------------------------------
        switch ($unit) {
            case 'kWh':
                if (is_numeric($value)) {  if (abs($value) >= 0.01) { $value = round($value, 2);} }
                $unitOut = 'kWh';
                break;
            case 'Wh':
                if (is_numeric($value)) { $value = $value / 1000; if (abs($value) >= 0.01) { $value = round($value, 2);} }
                $unitOut = 'kWh';
                break;
            case 'Ws':
                if (is_numeric($value)) { $value = $value / 3600000; if (abs($value) >= 0.01) { $value = round($value, 2); } }
                $unitOut = 'kWh';
                break;
            case 'kW':
                if (is_numeric($value)) { if (abs($value) >= 0.01) { $value = round($value, 2); } }
                $unitOut = 'kW';
                break;
            case 'W':
                if (is_numeric($value)) { $value = $value / 1000; if (abs($value) >= 0.01) { $value = round($value, 2); }}
                $unitOut = 'kW';
                break;
            case '°C':
                if (is_numeric($value)) { if (abs($value) >= 0.01) { $value = round($value, 1); } }
                $unitOut = '°C';
            break;
            default:
                $unitOut = $unit;
                break;
        }
        // 6. Value korrektur wenn outputMode nicht absulut ist
        $this->logger->debugMe("IQbox getfromIQbox state ok variable $sensorLokalId value $value outputMode $outputMode ");    
        $dt = new \DateTime('today midnight');            
        switch ($outputMode) {
            case 'daily':   $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                $unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'woche':   $dt->modify('-7 days'); $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                $unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'monat':   $dt->modify('-30 days'); $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                $unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'jahr':    $dt->modify('-365 days'); $startOfDay = $dt->getTimestamp();
                $value= $value - $this->getValueFromStartday ($sensorID,$startOfDay);
                if (abs($value) >= 0.01) { $value = round($value, 2); }
                $unitOut = date('d.m.Y H:i:s', $startOfDay);
                break;
            case 'absolute':
            default: 
                break;
        }        
        return ['wert' => $value, 'einheit' => $unitOut];
    }
}
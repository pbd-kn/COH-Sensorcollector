<?php
// gedacht als endlosschleife die über Parameter aus einer dartei versorgt wird versorgt wird
// check ob geheizt werden soll
// Schreibt die wesentlichen Werte der Smartbox und des Heizstabes in die Datenbank   (derzeit noch nicht)

// start // php json-heizung.php &
// beenden mit ssh ende oder 
// ps aux | grep json-heizung
// kill (erste Zahl aus dem ergebnis



// als Globale Daten verwenden
//include('mysql_dialog.php');
$urlheizStab='http://192.168.178.46/';
$urlIQbox    = 'http://192.168.178.26';
//$urlSOC=$urlIQbox."sajhybrid_battery_94_HSR2103J2311E08738_battery_stateOfCharge";
$paramsFile = 'task_heizstab_params.json';   // dateiname der Parameter
$cookieFile = '/home/peter/coh/cookies/iqbox_cookie.txt';                   // speichern des auth zugriffs auf d
//$cookieFile = __DIR__ . "/ampere_cookie.txt";
echo "cookieFile $cookieFile\n";
$logfile="";
$logfileHandle;
$aktData = getdata();
$setupData = getsetup();
$lastday = 0;      // zuletzt bearbeiteter Tag
$lastMon = 0;      // zuletzt bearbeiteter Monat

$hystereseSoll=40; // wenn heizen eingeschaltet wird, so muss der füllstand des Akkus mindestens
$hysterese=0;      // nach einem einschalten der Heizung wird erst wieder geheizt wenn die Hysterese des Akkus erreicht wird,
$repeat = 15;      // whileSchleife alle 15 Min
$repeat = 2;      // whileSchleife alle 15 Min


function ampereLogin(string $urlIQbox): void
{
    global $cookieFile;

    $username = "installer";
    $password = "sfjimorx"; 
    //writeLog ("ampereLogin urlIQbox $urlIQbox cookieFile $cookieFile");
    $ch = curl_init($urlIQbox . "/auth/login");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            "username" => $username,
            "password" => $password
        ]),
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        writeLog ("!!!!!!!!!!!!!!!!!  response falseampereLogin urlIQbox $urlIQbox cookieFile $cookieFile");
        throw new RuntimeException("Login failed: " . curl_error($ch));
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($code, [200, 302, 303], true)) {
        throw new RuntimeException("Login HTTP error $code");
    }
}
// base url enthält /rest/items
function ampereRequest(string $baseUrl, string $path, bool $retry): array
{
    global $urlIQbox,$cookieFile;
    //writeLog ("ampereRequest  baseUrl $baseUrl path $path");

    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        writeLog ("ampereRequest  response false baseUrl $baseUrl path $path");
        throw new RuntimeException("cURL error: " . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    //writeLog ("ampereRequest  code  $code baseUrl $baseUrl path $path");
    // Session ungültig → EINMAL neu einloggen
    if (in_array($code, [301, 302, 303, 401, 403, 404], true)) {
        writeLog ("ampereRequest  Session ungültig EINMAL neu einloggen baseUrl $baseUrl path $path code $code");
        if ($retry) {
            throw new RuntimeException("Auth failed after retry (HTTP $code)");
        }
        ampereLogin($urlIQbox);
        return ampereRequest($baseUrl, $path, true);
    }
    if ($code !== 200) {
        throw new RuntimeException("HTTP error $code");
    } 
    $json = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid JSON response");
    }
    return [
        "ok"        => true,
        "http_code" => 200,
        "data"      => $response
    ];
}
// liefert die jsondaten der iq Box. macht evtl eine reauth
function ampereGet(string $baseUrl, string $path): array
{
    //writeLog ("ampereGet  baseUrl $baseUrl path $path");
    try {
        $arr=ampereRequest($baseUrl, $path, false);
        //writeLog (" request ok");
        return $arr;
    } catch (Throwable $e) {
        writeLog (" ampereGet request failed");
        return [
            "ok"        => false,
            "error"     => true,
            "http_code" => 500,
            "message"   => $e->getMessage()
        ];
    }
}
    
    

// liest die data.jsn vom Heizstab und gibt sie als Array zurück
// liefert False bei einem Fehler
function getdata() {
    global $urlheizStab;
    $url=$urlheizStab."data.jsn";
    for ($i = 1; $i <= 10; $i++) {
      $content=curlRequest($url);
      if ($content === false) {
        writeLog("!!! cURL Error: " . curl_error($ch)." url: $url"); 
        sleep(10); // Warte 10 sec
        continue;
      }
      $data = json_decode($content,true);
      if ($data === null) {
        writeLog("!!! Fehler beim Parsen der JSON-Daten des Heizstabes  url $url");
        sleep(10); // Warte 10 sec
        continue;
      }
      return $data;
    }
    writeLog("!!! Fehler nach 10 maligen Aufruf");
    return false;
}
// liest die setup.jsn vom Heizstab und gibt sie als Array zurück
// liefert False bei einem Fehler

function getsetup() {
    global $urlheizStab;
    $url=$urlheizStab."setup.jsn";
    for ($i = 1; $i <= 10; $i++) {
      $content=curlRequest($url);
      if ($content === false) {
        writeLog("!!! cURL Error: " . curl_error($ch)." url: $url"); 
        sleep(10); // Warte 10 sec
        continue;
      }
      if ($content === false) {writeLog("!!! cURL Error: " . curl_error($ch)." url: $url"); return false;}
      $data = json_decode($content,true);
      if ($data === null) {
        writeLog("!!! Fehler beim Parsen der JSON-Daten des Heizstabes url $url");
        sleep(10); // Warte 10 sec
        continue;
      }
      return $data;
    }
    writeLog("!!! Fehler nach 10 maligen Aufruf");
    return false;
}

/*  liefert den wert vom Heizstab aus global $aktData,$setupData;
 *  
 */
function getHeizstabdata ($data) {
  global $aktData,$setupData;
  if (isset($aktData[$data]) )  {  return $aktData[$data];}    
  else if (isset($setupData[$data]) )  {  return $setupData[$data];}    
  else return false;
}
/*
 * liest einen Status von der IQbox
 * der name ist der Name aus dem Link
 *
 */
function getfromIQbox ($path) {
  global $urlIQbox;
    $result = ampereGet($urlIQbox,$path);
    if ($result['ok']) {
      $data = json_decode((string)$result['data'],true);
      $state=$data['state'];  
      return $state;
    } else {
      writeLog ("!!! Fehler beim Abrufen der Daten von: $urlIQbox var: $path");
      return false;
    }
}

// CURL-Request Funktion, um Redundanz zu vermeiden
function curlRequest($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $content = curl_exec($ch);
    if ($content === false) {
        writeLog("!!! cURL Error: " . curl_error($ch) . " url: $url");
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $content;
}

/* startet oder stopt den Heizstab
 * 
 * $modus 0 Stopp heizstab
 * >0   in Steuerungseinstellung Modbus tcp Heizstab starten. Dabei wird aber die Heizstabeinstellung Warmwasser verwendet
 *      in Steuerungseinstellung http Heizstab starten.  Wert ist die eizustellende Powergröße
 *      Kommandos ctrl http
 *      /control.html?power=n n … Set power on the power stage, unlimited range of value The regulation is carried out by a higher-level control system.
 *      /control.html?pid_power=n The regulation is carried out by the pid-controller of AC ELWA 2
 *      /control.html?boost=1 activate Boost-Backup manually
 *      kommandos zum Umstellen ctrl
 *      http /setup.jsn?ctrl=1&ww1boost=700       auf 70 Grad aufheizen  ctrl 1 = http
 *      modtcp /setup.jsn?ctrl=2&tout=60      messintervall  ctrl 2 = modbus tcp
 */      
function heizen($modus) {
  // steuerungseinstellung bestimmen wie
  // aktuell wird der Boost für Warmwassereinstellung verwendet
  //setup.jsn?bstmode=0
  global $urlheizStab,$ctrl;
  $steuerungseinstellung=$ctrl;    
    $url1 = $url2 = "";
  if ($steuerungseinstellung==1) {           // http
    return false;
  } else if ($steuerungseinstellung==2) {           // modbustcp
    if ($modus > 0) {
      $url1=$urlheizStab.'setup.jsn?bstmode=1';
      $url2=$urlheizStab.'data.jsn?bststrt=1';
    } else {
      $url1=$urlheizStab.'setup.jsn?bstmode=0';
      $url2=$urlheizStab.'data.jsn?bststrt=0';
    }
  }
  writeLog("Heizen Modus Heizstab $modus Protokoll url1: $url1 url2: $url2");
  //$response=@file_get_contents($urlheizStab.'data.jsn?bststrt=1');
  if ($url1 && $url2) {
        $response1 = curlRequest($url1);
        sleep(1); // Warte 1 sec
        $response2 = curlRequest($url2);
        if ($response1 === false || $response2 === false) {
            writeLog("!!! Fehler beim Heizen-Steuerungsbefehl: $modus");
            return false;
        }
  }
  return true;
}

// funktionen zur normierung des Status
function elwaPwrkWh($stat) {   // Power akt Heizstab
  $resArr['wert'] = round($stat/1000,2);
  $resArr['einheit']='kWh';
  return $resArr;
}
function elwaPwr($stat) {   // max Power in %
  $resArr['wert'] = $stat;
  $resArr['einheit']='%';
  return $resArr;
}
function elwaTemp($stat) {   // Power akt Heizstab
  $resArr['wert'] = round($stat/10,2);
  $resArr['einheit']='°C';
  return $resArr;
}

function elwaProt($stat) {   // Power akt Heizstab
  $resArr['wert'] = $stat;
  switch ($stat) {
    case 0: case 0: $v='Auto Detec';break;
    case 1: $v='HTTP';break; 
    case 2: $v='Modbus TCP';break; 
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
    default: $v='Protokoll undefinioert';break;
  }
  $resArr['einheit']=$v;
  return $resArr;
}
function IQSOC($stat) {   // Füllstand Betterie
  $statearr = explode(" ", $stat);
  $resArr['wert'] = $statearr[0];
  $resArr['einheit']='%';
  return $resArr;
}  

function IQkWh($stat) {   // Angabe kWh Wh, Ws
  $statearr = explode(" ", $stat);
  $v=strtolower($statearr[1]);
  if ($v == 'ws') {$value=round($statearr[0]/3600000,2);}
  elseif ($v == 'wh') {$value=round($statearr[0]/1000,2);}
  else $value=$statearr[0];
  $resArr['wert'] = $value;
  $resArr['einheit']='kWh';
  return $resArr;
}  
function IQkW($stat) {   // Angabe kW W
  $resArr=[];
  $valarr = explode("|",$stat);   // sieht der state so aus "1714050990000|4.0 W" dann ist das vor | die Uhrzeit
  if (count($valarr) > 1) {           // mit zeitangabe
    // liefere den zeitpunkt der messung in sec
    $unixzeit_ms=$valarr[0];
    $unixzeit_sec=$unixzeit_ms/1000;    // Umwandeln in Sekunden (durch 1000 teilen, da die Unixzeit in Millisekunden gegeben ist)
    $resArr['unixtime'] = $unixzeit_sec;
    $strWert=$valarr[1];              
  } else $strWert=$stat;

  $statearr = explode(" ", $strWert);
  $v=strtolower($statearr[1]);
  if ($v == 'w') {$value=round($statearr[0]/1000,2);}
  else $value=$statearr[0];
  $resArr['wert'] = $value;
  $resArr['einheit']='kW';
  return $resArr;
} 
 
function IQTemp($stat) {   // Temp z.b Batterie
  $statearr = explode(" ", $stat);
  $resArr['wert'] = $statearr[0];
  $resArr['einheit']='°C';
  return $resArr;
}
function writeLog($txt) {
  global $logfileHandle;
  if (isset($logfileHandle)) fwrite($logfileHandle, $txt."\n");
  else echo($txt."\n");
}


/*
$fileHandle = fopen($paramsFile, 'a');   append an file
if ($fileHandle) {
    fwrite($fileHandle, json_encode($params));
    fclose($fileHandle);
} 
*/
$iteration = 0;

while (true) { //endlos Schleife wird mit break abgebrochen
  ampereLogin($urlIQbox);    // auth login

  $iteration++;
  //Parameter lesen evtl. Stopp
  if (file_exists($paramsFile)) {
        $params = json_decode(file_get_contents($paramsFile), true);
        if (isset($params['logfile'])&&$params['logfile']!="") {
            $logfile = $params['logfile'] ?? null;
            $logfileHandle = null;
            if ($logfile) {
                $dir = dirname($logfile);
                // nur öffnen, wenn Verzeichnis existiert und beschreibbar ist
                if (is_dir($dir) && is_writable($dir)) {
                    $logfileHandle = @fopen($logfile, 'a');
                }
            } else {
              $logfile="";
              unset($logfileHandle);
            }
        }

        if (isset($params['stop']) && $params['stop'] === true) {
            writeLog("Task stopped by parameter.");
            echo "Task stopped by parameter.";
            break;
        }
        if (isset($params['urlheizStab'])) {
//            writeLog("urlheizStab  " . $params['urlheizStab'] . "");
            $urlheizStab="http://".$params['urlheizStab'].'/';
        } 
        if (isset($params['urlIQbox'])) {
//            writeLog("urlIQbox  " . $params['urlIQbox'] . "");
            $urlIQbox="http://".$params['urlIQbox'].'/rest/items/';
//            $urlSOC=$urlIQbox."sajhybrid_battery_94_HSR2103J2311E08738_battery_stateOfCharge";             // Füllstand Batterie
        }                
        if (isset($params['repeat'])) {
            //writeLog("repeat  alle " . $params['repeat'] . "Min");
            $repeat=$params['repeat'];
        }                
        if (isset($params['Heizintervalle'])) {   //  in die Datenbank zu schreibenden werte
            $heizIntervalle = $params['Heizintervalle'];
            //writeLog("heizIntervale gelesen: ");
        }                
  } else {
    echo "kein paramsfiel $paramsFile\n";
    exit;
  }


  // zuerst überprüfen, ob schon Boostmodus läuft.
  $aktData = getdata();
  $setupData = getsetup();

  $ctrl = getHeizstabdata('ctrl');   // ansteuerungstyp 1 = http 2 = modbusdTCP s. Doku fußnote 1         

  $Booststat = getHeizstabdata('boostactive');  // musss evtl noch korrigiert werden, wenn http modus eingestellt ist
  if ($Booststat === false) { writeLog("!!! Fehler lesen Heizstab Booststat false"); echo "Fehler lesen Heizstab Booststat false\n"; goto nextIteration;}                                    
  $getMaxPwr = getHeizstabdata('maxpwr'); 
  $getAktPwr=getHeizstabdata('power_elwa2');
  $getMinTemp=getHeizstabdata('ww1boost')/10;
  $temp1=getHeizstabdata('temp1')/10;
  $temp2=getHeizstabdata('temp2')/10;
  // lese Wasser Temperatur von IQBox
  
  //writeLog("lese temperatur");
  $tempstate=getfromIQbox("mypv_acelwa_2_1601502403220274_deviceState_actualTemperature");
  if ($tempstate === false) $wwTemp="??";
  else $wwTemp = explode(" ", $tempstate)[0];  // wird  mit Grad Celius geliefert
  //writeLog("gelesen temperatur $wwTemp");
  $SOCArr=explode(" ",getfromIQbox("sajhybrid_battery_94_HSR2103J2311E08738_battery_stateOfCharge")); // Abrufen des Inhalts der SOC  Batterie Füllstand   
  $stateBatterie = intval( $SOCArr[0]); // Fuellstand Batterie als int prozentwert
  date_default_timezone_set('Europe/Berlin');
  $currentTime = date('d.m.Y H:i:s');
  writeLog("currentTime $currentTime");

  writeLog("maxPower: $getMaxPwr % aktPwr: $getAktPwr W temp min: $getMinTemp C temp1akt: $temp1 C temp2akt: $temp2 C temp von IQBox $wwTemp C  Batterie $stateBatterie %");   // soweit wird geheizt     
  // überprüfen ob die akt. Zeit innerhalb des Intervalls ist
  $pruefeHeizen=0;
  $cTime = date('H:i');    // zur Intervall Prüfung
  date_default_timezone_set('Europe/Berlin');
  
  foreach ($heizIntervalle as $intervallIndex=>$interval) {
    $isWithinInterval = ($cTime >= $interval['an']) && ($cTime <= $interval['aus']);
    if ($isWithinInterval) {
      $pruefeHeizen=1;
      writeLog("Heizung prüfen im intervall [$intervallIndex] ok an: ".$interval['an']." aus: ".$interval['aus']."");
      break;
    }
  }
  writeLog("Intervall $pruefeHeizen Booststat $Booststat hysterese $hysterese Batterie $stateBatterie");

  if ( $stateBatterie < 10 ) { $hysterese=$hystereseSoll; }
  if (($hysterese >0) && ($stateBatterie > $hystereseSoll)) $hysterese=0;    // zurücksetzen
  if ($Booststat != 0) {  // heizstab an
//      writeLog("Heizstab heizt schon Booststat $Booststat hysterese $hysterese test auf ausschalten"); 
    if ($hysterese != 0 && ($stateBatterie < 10 || $stateBatterie < $hystereseSoll)) {  // Hysterese Modus warten bis hystereseSoll erreicht 
      heizen(0) ; 
      writeLog("heizstab ausschalten SOC = $stateBatterie hysterese $hysterese  warten auf hysterese"); 
      goto nextIteration;      
    }
  } 
  if ($pruefeHeizen>0 ) {       
    if ($Booststat == 0) {      // innerhalb intervall und heizt noch nicht
    // Untersuchung auf Heizen
        if ($stateBatterie > 20) {      
          writeLog("Füllstand Batterie $stateBatterie Größer 20 % evtl. Heizen hysterese $hysterese");    
          if ($hysterese == 0 || $stateBatterie > $hysterese) {    // starten wenn nicht auf hysterese warten oder hysterese erreicht 
            writeLog("heizstab einschalten SOC = $stateBatterie hysterese $hysterese");
            heizen(1);
            if ($stateBatterie < $hystereseSoll) {   // hysterese einschalten
              $hysterese= $hystereseSoll;     // ab jetzt warten bis Füllstand Batterie $hystereseSoll erreicht
              writeLog("Hysterese eingeschaltet ");
            }
            else  $hysterese= 0;             // alles ok Batterie ist voll genug
          } else writeLog("Auf Hysterese warten hysterese $hysterese ");
        } else writeLog("Auf Hysterese warten hysterese $hysterese stateBatterie $stateBatterie"); 
    } else {   // heizt schon überprüfen ob heizen stop wegen Batterie
      if ($stateBatterie < 10) {
          $hysterese= $hystereseSoll;     // ab jetzt warten bis Füllstand Batterie $hystereseSoll erreicht
          heizen(0) ; 
          writeLog("heizstab ausschalten SOC = $stateBatterie hysterese $hysterese  Batterie<10%");
      }
    }
    $sleepTime=$repeat*60;  
  } else { // Ende Untersuchung Heizen
    writeLog("Außerhalb Intervall");
    // ausserhalb intervall
    if ($Booststat != 0) {  // heizt noch
        heizen(0) ; 
        writeLog("heizstab ausschalten Intervall Ende");
        echo "heizen aus Intervall Ende\n";
    }
    // check nächstes Intervall Bestimmung sleepTime
    $nextAn="";
//echo "cTime $cTime\n";
    foreach ($heizIntervalle as $intervallIndex=>$interval) {
//echo "interval['an'] ".$interval['an']."\n";

      $isWithinInterval = ($interval['an'] >= $cTime) || ($cTime >= $interval['an']);   // entweder heute noch oder morgen
      if ($isWithinInterval) {
        $nextAn=$interval['an'];
        writeLog("Nächstes Intervall Beginn an: ".$interval['an']);
        break;
      }
    }
//echo "nextAn $nextAn\n";
    if ($nextAn!="") {
      $arrTime=explode(':',$nextAn);
      $zielStunde = $arrTime[0];
      $zielMinute = $arrTime[1];
      $jetzt = new DateTime();
      $zielZeit = new DateTime();
      $zielZeit->setTime($zielStunde, $zielMinute);
      // Wenn die Zielzeit schon vorbei ist, einen Tag hinzufügen
      if ($jetzt > $zielZeit) {
        $zielZeit->modify('+1 day');
//        echo "neu zielZeit: ".$zielZeit->format('Y-m-d H:i:s')."\n";
      }
    // Zeitdifferenz in Sekunden berechnen
      $diffInSekunden = $zielZeit->getTimestamp() - $jetzt->getTimestamp();
      // Schlafen, bis die Zielzeit erreicht ist maximal 1 Stunde(aber nur, wenn die Differenz positiv ist)
//echo "diffsec $$diffInSekunden\n";
      if ($diffInSekunden > 0) {
        if ($diffInSekunden > 3600)$sleepTime=3600;  // 1 stunde warten
        else $sleepTime=$diffInSekunden+10;          // 10 sec länger, damit sicherr im Intervall
      } else {
        $sleepTime=$repeat*60;
      }
    } else {
      $sleepTime=$repeat*60;
    }
  }
  nextIteration:
  $currentDateTime = new DateTime();
  $currentDateTime->add(new DateInterval('PT' . $sleepTime . 'S'));
  $w=$currentDateTime->format('Y-m-d H:i:s');
  writeLog(date('d.m.Y H:i:s')." iteration: $iteration sleepTime $sleepTime sec sleep bis: $w sec Batterie $stateBatterie hysterese $hysterese\n");
  if (isset($logfileHandle)) fclose($logfileHandle);
  $logfile="";
  unset($logfileHandle);
  echo date('d.m.Y H:i:s')." iteration: $iteration sleepTime $sleepTime sec sleep bis: $w sec Batterie $stateBatterie hysterese $hysterese\n";

  sleep($sleepTime); // Warte Repeat Minuten pro Iteration
  
} //ende while

?>

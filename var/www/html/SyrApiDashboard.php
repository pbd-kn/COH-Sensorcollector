<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------------------------------------------------
// API Basis
// ---------------------------------------------------
$url = "http://192.168.178.65:5333";
$baseGet = $url . "/trio/get/";
$baseSet = $url . "/trio/set/";
$dH = 7.9;   // geschätzte Wasserhärte

// ---------------------------------------------------
// API GET (für Ventil-Wait + Fallback)
// ---------------------------------------------------
function syrGet($cmd, $baseGet)
{
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $json = @file_get_contents($baseGet . strtolower($cmd), false, $ctx);
    if (!$json) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) {
        return null;
    }
    return array_values($data)[0] ?? null;
}

// ---------------------------------------------------
// API GET ALL
// ---------------------------------------------------
function syrGetAll($baseGet)
{
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($baseGet . "all", false, $ctx);
    if (!$json) {
        return [];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

// ---------------------------------------------------
// PROFIL SETZEN
// ---------------------------------------------------
if (isset($_POST['setProfile'])) {
    $p = (int)($_POST['profile'] ?? 1);
    if ($p >= 1 && $p <= 8) {
        @file_get_contents($baseSet . "pa" . $p . "/true"); // Profil aktivieren
        @file_get_contents($baseSet . "prf/" . $p);         // danach setzen
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ---------------------------------------------------
// LECKAGEWERT / MIKROLECKAGE / TEST SETZEN
// ---------------------------------------------------
if (isset($_POST['setType'])) {
    $type = $_POST['setType'] ?? '';
    $valueRaw = $_POST['value'] ?? '';
    $profile = (int)($_POST['profile'] ?? 1);

    if ($profile >= 1 && $profile <= 8) {
        // -----------------------------------
        // Profilbezogene Leckagewerte
        // -----------------------------------
        if (in_array($type, ['pv', 'pt', 'pf'], true)) { // Volumen time durchfluß grenzuen einstellen
            $value = (int)$valueRaw;
            @file_get_contents($baseSet . $type . $profile . "/" . $value);
        }

        if ($type === 'pm') {
            $value = ((int)$valueRaw === 1) ? 'true' : 'false'; // test aktivierern
            @file_get_contents($baseSet . "pm" . $profile . "/" . $value);
        }

        // -----------------------------------
        // Mikroleckage Test
        // -----------------------------------
        if ($type === 'drp') { // test ntervall
            $value = (int)$valueRaw;
            if ($value >= 1 && $value <= 3) {
                @file_get_contents($baseSet . "drp/" . $value);
                usleep(300000);
            }
        }

        if ($type === 'dtt') { // Uhrzeit
            $value = trim((string)$valueRaw);
            if (preg_match('/^\d{2}:\d{2}$/', $value)) {
                @file_get_contents($baseSet . "dtt/" . $value);
                usleep(300000);
            }
        }

        if ($type === 'dex') {
            $statusMsg = 'Mikroleckage Test cmd dex ';
            $value = (int)$valueRaw;

            if ($value === 1) {
                $ctx = stream_context_create([
                    'http' => ['timeout' => 5]
                ]);

                $res = @file_get_contents($baseSet . "dex", false, $ctx);

                // FIX: false = Fehler, sonst ok
                if ($res === false) {
                    $err = error_get_last();
                    $statusMsg .= $err['message'] ?? 'Fehler';
                } else {
                    $statusMsg .= 'ok';
                }
            } else {
                $statusMsg .= "Kein Start value $value";
            }

            header("Location: " . $_SERVER['PHP_SELF'] . "?Postmsg=" . urlencode($statusMsg));
            exit;
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ---------------------------------------------------
// VENTIL STEUERUNG
// ---------------------------------------------------
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $target = null;
    $cmd = '';

    if ($action === 'syrcloseventil') {
        $cmd = "ab/true";
        $target = 10; // ZU
    }
    if ($action === 'syropenventil') {
        $cmd = "ab/false";
        $target = 20; // OFFEN
    }

    if ($cmd !== '') {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);
        $res = @file_get_contents($baseSet . $cmd, false, $ctx);

        // ---------------------------------
        // Fehler beim Senden
        // ---------------------------------
        if ($res === false) {
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?Postmsg=apierror");
            exit;
        }

        // ---------------------------------
        // max 50 Sekunden warten
        // ---------------------------------
        $maxSeconds = 50;
        $start = time();
        $success = false;

        while (true) {
            sleep(1);
            $vlvNow = syrGet("vlv", $baseGet);

            if ($vlvNow == $target) {
                $success = true;
                break;
            }
            if ($vlvNow === null) {
                break;
            }
            if ((time() - $start) >= $maxSeconds) {
                break;
            }
        }

        // ---------------------------------
        // Redirect mit Status
        // ---------------------------------
        if ($success) {
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?Postmsg=ok");
        } else {
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?Postmsg=timeout");
        }
        exit;
    }
}

// ---------------------------------------------------
// DATEN von Syr holen - über /get/all
// ---------------------------------------------------
$dataRaw = syrGetAll($baseGet);

// Falls /get/all gar nichts liefert, optionaler Fallback
$useFallback = empty($dataRaw);

// Basis-Mapping
$data = [
    "VLV"  => $dataRaw["getVLV"]  ?? null,
    "BAT"  => $dataRaw["getBAT"]  ?? null,
    "FLO"  => $dataRaw["getFLO"]  ?? null,
    "BAR"  => $dataRaw["getBAR"]  ?? null,
    "CEL"  => $dataRaw["getCEL"]  ?? null,
    "PRF"  => $dataRaw["getPRF"]  ?? 1,
    "SRN"  => $dataRaw["getSRN"]  ?? null,
    "VER"  => $dataRaw["getVER"]  ?? null,
    "WIP"  => $dataRaw["getWIP"]  ?? null,
    "WGW"  => $dataRaw["getWGW"]  ?? null,
    "MAC1" => $dataRaw["getMAC1"] ?? null,
    "EIP"  => $dataRaw["getEIP"]  ?? null,
    "EGW"  => $dataRaw["getEGW"]  ?? null,
    "MAC2" => $dataRaw["getMAC2"] ?? null,
    "WFS"  => $dataRaw["getWFS"]  ?? null,
    "WFR"  => $dataRaw["getWFR"]  ?? null,
    "ALA"  => $dataRaw["getALA"]  ?? null,
    "WRN"  => $dataRaw["getWRN"]  ?? null,
    "NOT"  => $dataRaw["getNOT"]  ?? null,
    "ALM"  => $dataRaw["getALM"]  ?? "",
    "ALW"  => $dataRaw["getALW"]  ?? "",
    "ALN"  => $dataRaw["getALN"]  ?? "",
    "VOL"  => $dataRaw["getVOL"]  ?? 0,
    "CND"  => $dataRaw["getCND"]  ?? 0,
    "WTI"  => $dataRaw["getWTI"]  ?? 0,   // cloud intervall
    "CEN"  => $dataRaw["getCEN"]  ?? 0,   // cloud aktive
    "DSV"  => $dataRaw["getDSV"]  ?? 0,   // Mikroleckage  Status
    // 0 Test nicht Aktiv
    // 1 Test Aktiv
    // 2 Abgebrochen durch Druckabfall
    // 3 Test übersprungen
    "DRP"  => $dataRaw["getDRP"]  ?? 0,   // Mikroleckage Intervall  1-3
    //  GET /trio/get/drp
    //  SET /trio/set/drp/X
    //  1 täglich
    //  2 wöchentlich
    //  3 monatlich
    "DTT"  => $dataRaw["getDTT"]  ?? "04:00", // Mikroleckage Uhrzeit lesen "HH:MM"
    //  /trio/get/dtt
    //  /trio/set/dtt/03:00
    "DTC"  => $dataRaw["getDTC"]  ?? "04:00", // Mikroleckage Wiederholungen Wie oft nachgemessen wird.
    //  1 einmal
    //  2 zweimal
    //  3 dreimal
    "DOM"  => $dataRaw["getDOM"]  ?? "04:00", // Mikroleckage Beobachtungsdauer Wie lange Druck beobachtet wird. in sec
    "DST"  => $dataRaw["getDST"]  ?? "04:00", // Mikroleckage Stabilisierung Zeit nach Ventilschluss bevor Messung beginnt. in sec
    "DMA"  => $dataRaw["getDMA"]  ?? 0,   // Mikroleckage Reaktion bei Mikroleckage / Alarm
    //  0 Nur Warnung, Ventil bleibt offen
    //  1 Warnung + verzögertes Eingreifen
    //  2 Sofort / automatisch schließen
    "MM"  => $dataRaw["getMM"]  ?? 0,   // Microleak Mode
    //  GET /trio/get/mm
    //  SET /trio/set/mm/X
    //  0 Aus
    //  1 tolerant
    //  2 normal
    //  3 streng
    "DBD"  => $dataRaw["getDBD"]  ?? 0,   // Microleak Grenzen für Druckverlust. Je kleiner, desto schneller Alarm.
    "DBT"  => $dataRaw["getDBT"]  ?? 0,   // Microleak Drucktest Microleak Grenzen für Druckverlust. Je kleiner, desto schneller Alarm.
    "DPL"  => $dataRaw["getDPL"]  ?? 0,   // Microleak Grenzen für Druckverlust. Je kleiner, desto schneller Alarm.
    "DCM"  => $dataRaw["getDCM"]  ?? 0,   // Interner Bewertungsmodus ??
    
    "AMA"  => $dataRaw["getAMA"]  ?? 0,   // Alarm Hauptmodus  ???? Wie Alarme generell behandelt werden.
    //  0 Nur Meldung
    //  1 Normalbetrieb
    //  2 Strenger Modus
    "ALD"  => $dataRaw["getALD"]  ?? 0,   // Alarm Delay  wartezeit in sec
    //  GET /trio/get/ald
    //  SET /trio/set/ald/X
    "SLP"  => $dataRaw["getSLP"]  ?? 0,   // SELBSTLERNUNG / KI Sensitivity Learning Profile Wie aggressiv das Gerät aus Nutzungsdaten lernt.
    //  0 niedrig tolerant ??
    //  1 hoch strenger ??
    "SLE"  => $dataRaw["getSLE"]  ?? 0,   // SELBSTLERNUNG / KI Sensitivity Lernzähler Profile Wie viele Lernvorgänge / Datensätze gesammelt wurden.
    "SLV"  => $dataRaw["getSLV"]  ?? 0,   // SELBSTLERNUNG / KI Sensitivity Learned Volume Typische gelernte Wassermenge.
    "SLT"  => $dataRaw["getSLT"]  ?? 0,   // SELBSTLERNUNG / KI Sensitivity Learned Time Typische gelernte Nutzungsdauer.
    "SLF"  => $dataRaw["getSLT"]  ?? 0,   // SELBSTLERNUNG / KI Sensitivity Learned Flow Typische gelernte Durchfluss.
    "SOF"  => $dataRaw["getSOF"]  ?? 0,   // SELBSTLERNUNG / KI Shutoff Factor ?? Wie schnell aus Lernwerten abgesperrt wird.
    "SLO"  => $dataRaw["getSLO"]  ?? 0,   // SELBSTLERNUNG / Interner Offset  ??
    "SMF"  => $dataRaw["getSMF"]  ?? 0,   // SELBSTLERNUNG / Interner Flow-Grenzwert ??
];

// diese werte kommen bei all falsch zurück
$data["ALM"] = syrGet("ALM", $baseGet);
$data["ALW"] = syrGet("ALW", $baseGet);
$data["ALN"] = syrGet("ALN", $baseGet);

// Fallback auf alte Einzel-Requests nur wenn /get/all komplett leer ist
if ($useFallback) {
    $keys = [
        "vlv", "bat", "flo", "bar", "cel", "prf", "srn", "ver",
        "wip", "wgw", "mac1", "eip", "egw", "mac2", "wfs", "wfr",
        "ala", "wrn", "not", "alm", "alw", "aln", "vol", "cnd", "wti", "cen", "dsv", "drp", "dtt"
    ];

    $data = [];
    foreach ($keys as $k) {
        $data[strtoupper($k)] = syrGet($k, $baseGet);
    }
}

// ---------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------
/*
funktion liefert htmlwert zurück htmlspecialchars/helper/html)
parameter $v
    null    null           return ''
    bool    true, false    return 'true' oder 'false'
    string  "Hallo"        return htmlspecialchars
    int     123            return htmlspecialchars
    float   12.5           return htmlspecialchars
    array   ['a'=>1]       liefert lesbares json
*/
function h($v): string
{
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_array($v)) return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return htmlspecialchars((string)$v);
}

// gv liefert den wert aus den data werten von str.
// der key wird auf Großbuchstaben gestzt, da in dem Felde diese groß sind
// existiert der wert nicht, so wir der defaultwert zurückgeliefert
// eigentilich $data[$key]
function gv(array $data, string $key, $default = null)
{
    $full = 'get' . strtoupper($key);
    return $data[$full] ?? $data[$key] ?? $default;
}

// ---------------------------------------------------
// Profilwerte für aktuelles Profil laden
// ---------------------------------------------------
$aktprf = (int)($data["PRF"] ?? 1);
if ($aktprf < 1 || $aktprf > 8) {
    $aktprf = 1;
}

if (!$useFallback) {
    $data["PN$aktprf"] = $dataRaw["getPN$aktprf"] ?? 0;
    $data["PV$aktprf"] = $dataRaw["getPV$aktprf"] ?? 0;
    $data["PT$aktprf"] = $dataRaw["getPT$aktprf"] ?? 0;
    $data["PF$aktprf"] = $dataRaw["getPF$aktprf"] ?? 0;
    $data["PM$aktprf"] = $dataRaw["getPM$aktprf"] ?? 0;
    $data["PW$aktprf"] = $dataRaw["getPW$aktprf"] ?? 0;
    $data["PB$aktprf"] = $dataRaw["getPB$aktprf"] ?? 0;
    $data["PR$aktprf"] = $dataRaw["getPR$aktprf"] ?? 0;
    $data["PA$aktprf"] = $dataRaw["getPA$aktprf"] ?? 0;
} else {
    foreach (["pn", "pv", "pt", "pf", "pm", "pw", "pb", "pr", "pa"] as $k) {
        $data[strtoupper($k) . $aktprf] = syrGet($k . $aktprf, $baseGet);
    }
}

$pn = $data["PN$aktprf"] ?? 0;     // Profil Name
$pv = $data["PV$aktprf"] ?? 0;     // Profil zulässiges Volumen in litern. Bei 0 ist die Volumenleckage deaktiviert
$pt = $data["PT$aktprf"] ?? 0;     // Profil zulässige Zeit in min. Bei 0 ist die Zeitleckage deaktiviert
$pf = $data["PF$aktprf"] ?? 0;     // Profil zulässiger Durchfluss in l/h. Bei 0 ist die Durchflussleckage deaktiviert
$pm = $data["PM$aktprf"] ?? 0;     // Mikroleckage Test Aktivieren/Deaktivieren
$pw = $data["PW$aktprf"] ?? 0;     // Leckagewarnung Aktivieren/Deaktivieren
$pb = $data["PB$aktprf"] ?? 0;     // Buzzer Aktivieren/Deaktivieren
$pr = $data["PR$aktprf"] ?? 0;     // Rückkehrzeit zu Profil Anwesend(Profil1) in Stunden. Bei 0 bleibt das Profil dauerhaft aktiv
$pa = $data["PA$aktprf"] ?? 0;     // bool Profil Verfügbarkeit

$leak = detectLeakType($data);

// ---------------------------------------------------
// BERECHNUNG
// ---------------------------------------------------
$batVolt = ($data["BAT"] ?? 0) / 100;
$batPercent = max(0, min(100, round(($batVolt - 4.9) / (6.2 - 4.9) * 100)));   // 6.2 V voll 4.2V leer
$temp = isset($data["CEL"]) ? ($data["CEL"] / 10) : 0;
$pressure = isset($data["BAR"]) ? ($data["BAR"] / 1000) : 0;
$flow = $data["FLO"] ?? 0;
$gesVol = $data["VOL"] ?? 0;
$leitWert = $data["CND"] ?? 0;
$dH = round($leitWert/30,2);

$valveMap = [
    10 => "ZU",
    11 => "am Schließen",
    20 => "OFFEN",
    21 => "am Öffnen"
];

$vlv = $data["VLV"] ?? 0;
$valve = $valveMap[$vlv] ?? "UNBEKANNT";

// Button-Zustände
$disableOpen = ($vlv == 20 || $vlv == 21);
$disableClose = ($vlv == 10 || $vlv == 11);


// leckage test
function detectLeakType(array $data): array
{
    // Werte sauber holen (fallbacks)
    $flow   = isset($data['getFLO']) ? (float)$data['getFLO'] : 0;
    $volume = isset($data['getVOL']) ? (float)$data['getVOL'] : 0;
    $alarm  = isset($data['getALA']) ? (int)$data['getALA'] : 0;

    // robust: manche payloads liefern getWRN statt getWAR
    $warn = 0;
    if (isset($data['getWAR'])) $warn = (int)$data['getWAR'];
    if (isset($data['getWRN'])) $warn = (int)$data['getWRN'];
    if (isset($data['WRN']))    $warn = (int)$data['WRN'];

    $valve  = isset($data['getVLV']) ? (int)$data['getVLV'] : null;
    if ($valve === null && isset($data['VLV'])) $valve = (int)$data['VLV'];

    // kein Alarm → alles ok
    if ($alarm === 0 && $warn === 0) {
        return [
            'type' => 'none',
            'text' => '✅ Kein Leck erkannt ',
            'severity' => 'ok'
        ];
    }

    // 🔴 Rohrbruch / hoher Durchfluss
    if ($flow > 1000) {
        return [
            'type' => 'flow',
            'text' => '🔴 Hoher Durchfluss (Rohrbruch möglich)',
            'severity' => 'critical'
        ];
    }

    // 🟠 Volumenleckage (viel Wasser aber kein hoher Flow)
    if ($volume > 50 && $flow < 1000) {
        return [
            'type' => 'volume',
            'text' => '🟠 Ungewöhnlich hoher Wasserverbrauch',
            'severity' => 'warning'
        ];
    }

    // 🟡 Mikroleckage (kleiner Flow, aber Alarm/Warnung)
    if ($flow > 0 && $flow < 50) {
        return [
            'type' => 'micro',
            'text' => '🟡 Mikroleckage (kleiner Dauerfluss erkannt)',
            'severity' => 'warning'
        ];
    }

    // 🟠 Ventil zu ohne klaren Grund → meist Mikroleckage oder Zeit
    if ($valve === 10) {
        return [
            'type' => 'auto_closed',
            'text' => '🟠 Ventil wurde automatisch geschlossen',
            'severity' => 'critical'
        ];
    }

    // 🟣 Fallback
    return [
        'type' => 'unknown',
        'text' => '🟣 Unklare Leckage',
        'severity' => 'warning'
    ];
}

// ---------------------------------------------------
// WASSERHÄRTE PUNKTE
// ---------------------------------------------------
function hardnessDots($dH)
{
    if ($dH < 8) return "🟢⚪⚪";
    if ($dH < 14) return "🟢🟡⚪";
    return "🟢🟡🔴";
}

// ---------------------------------------------------
// LISTEN werte extrahieren
// ---------------------------------------------------
function decodeList($str)
{
    if (!$str) return [];
    return array_filter(array_map('trim', explode(",", $str)));
}

// ---------------------------------------------------
// ALARM
// ---------------------------------------------------
function decodeAlarm($hex)
{
    if (!$hex || strtolower($hex) == "ff") return ["(FF) Kein aktueller Alarm", "ok"];
    $hex = strtoupper(trim((string)$hex));
    if (strpos($hex, "0X") !== 0) $hex = "0x" . $hex;

    $map = [
        "0xFF" => ["(FF) Kein Alarm", "ok"],
        "0xA1" => ["(A1) Endschalter Problem", "alarm"],
        "0xA2" => ["(A2) Motorstrom zu hoch", "alarm"],
        "0xA3" => ["(A3) Volumenleckage", "alarm"],
        "0xA4" => ["(A4) Zeitleckage", "alarm"],
        "0xA5" => ["(A5) Durchflussleckage", "alarm"],
        "0xA6" => ["(A6) Mikroleckage", "warn"],
        "0xA7" => ["(A7) Bodensensor-Leckage", "alarm"],
        "0xA8" => ["(A8) Durchflusssensor defekt", "alarm"],
        "0xA9" => ["(A9) Drucksensor defekt", "alarm"],
        "0xAA" => ["(AA) Temperatursensor defekt", "alarm"],
        "0xAB" => ["(AB) Leitwertsensor defekt", "alarm"],
        "0xAC" => ["(AC) Leitwertsensor Fehler", "alarm"],
        "0xAD" => ["(AD) Wasserhärte zu hoch", "warn"],
        "0x0D" => ["(0D) Salz leer", "warn"],
        "0x0E" => ["(0E) Ventilposition falsch", "alarm"],
        "0x1A" => ["(1A) Das Gerät hatte zu lange keine aktive Internetverbindung. Die Systemzeit ist verloren gegangen","warn"],
    ];
    return $map[$hex] ?? ["Unbekannt ($hex)", "warn"];
}

// ---------------------------------------------------
// WARNUNG
// ---------------------------------------------------
function decodeWarning($hex)
{
    if (!$hex || strtolower($hex) == "ff") return ["(FF) Keine aktuelle Warnung", "ok"];
    $hex = strtoupper(trim((string)$hex));
    if (strpos($hex, "0X") !== 0) $hex = "0x" . $hex;

    $map = [
        "0x01" => ["(01) Stromunterbrechung", "warn"],
        "0x07" => ["(07) Leckagewarnung Volumen", "warn"],
        "0x08" => ["(08) Batterie leer", "warn"],
        "0x02" => ["(02) Salz niedrig", "warn"],
        "0x09" => ["(09) Erstbefüllung erforderlich", "warn"],
        "0x10" => ["(10) Volumenleck erkannt", "warn"],
        "0x11" => ["(11) Zeitleck erkannt", "warn"],
        "0x1A" => ["(1A) Das Gerät hatte zu lange keine aktive Internetverbindung. Die Systemzeit ist verloren gegangen","warn"],
    ];
    return $map[$hex] ?? ["Warnung ($hex)", "warn"];
}

// ---------------------------------------------------
// NOTIFICATION
// ---------------------------------------------------
function decodeNotification($hex)
{
    if (!$hex || strtolower($hex) == "ff") return ["(FF) Keine aktuelle Meldung", "ok"];
    $hex = strtoupper(trim((string)$hex));
    if (strpos($hex, "0X") !== 0) $hex = "0x" . $hex;

    $map = [
        "0x01" => ["(01) Softwareupdate verfügbar", "warn"],
        "0x04" => ["(04) Softwareupdate installiert", "ok"],
        "0x02" => ["(02) Halbjährliche Wartung", "warn"],
        "0x03" => ["(03) Jährliche Wartung", "warn"],
    ];
    return $map[$hex] ?? ["Info ($hex)", "warn"];
}

function decodeMicroStatus($val)
{
    return match ((int)$val) {
        0 => ["Nicht aktiv ($val)", "secondary"],
        1 => ["Test aktiv ($val)", "warning"],
        2 => ["Abgebrochen (Druckabfall) ($val)", "danger"],
        3 => ["Übersprungen ($val)", "info"],
        default => ["MLT Status ($val) Unbekannt", "dark"]
    };
}

// ---------------------------------------------------
// STATUS
// ---------------------------------------------------
$alarmStatus = decodeAlarm($data["ALA"] ?? null);
$state = $alarmStatus[1];
switch ($state) {
    case 'ok':
        $status = 'OK';
        break;
    case 'warn':
        $status = 'WARNUNG';
        break;
    case 'alarm':
        $status = 'ALARM';
        break;
    default:
        $status = 'UNBEKANNT';
}

// ---------------------------------------------------
// WLAN Status
// ---------------------------------------------------
function decodeWifiStatus($wfs)
{
    switch ((string)$wfs) {
        case '0':
            return "Nicht verbunden";
        case '1':
            return "Verbinden...";
        case '2':
            return "Verbunden";
        default:
            return "-";
    }
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SYR SafeTech Dashboard</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6f8;
            color: #31475b;
        }
        h1 {
            margin: 0 0 10px;
        }
        small {
            color: #666;
        }
        /* PANEL */
        .coh-panel {
            max-width: 1600px;
            margin: auto;
        }
        /* CARD */
        .coh-card,
        details {
            background: #fff;
            border: 1px solid #d9e3ec;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
            margin-bottom: 14px;
        }
        /* TOP GRID */
        .coh-card {
            padding: 14px;
        }
        .coh-mini-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 5px;
            width: 100%;
        }
        .coh-mini-box {
            background: #f7fafc;
            border: 1px solid #e4edf3;
            border-radius: 10px;
            padding: 12px;
        }
        .big {
            font-size: 28px;
            font-weight: bold;
            margin: 4px 0;
        }
        /* BUTTONS */
        .btn {
            display: inline-block;
            flex: 1;
            text-align: center;
            padding: 8px;
            border-radius: 8px;
            color: #fff;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-open {
            background: #2e7d32;
        }
        .btn-close {
            background: #c62828;
        }
        .btn-disabled {
            opacity: .45;
            pointer-events: none;
        }
        .waiting-note {
            margin-top: 8px;
            font-size: 12px;
            color: #666;
        }
        /* DETAILS */
        details {
            padding: 10px 14px;
        }
        summary {
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
        }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 8px 0;
            border-bottom: 1px solid #eef2f6;
        }
        .row:last-child {
            border-bottom: none;
        }
        .edit-btn {
            cursor: pointer;
            margin-left: 8px;
        }
        /* BADGES  klasse wird bals returnwertz bei decode eingestellt */
        .badge-ok { color: #178a2f; font-weight: bold; }
        .badge-warn { color: #c97a00; font-weight: bold; }
        .badge-alarm { color: #c62828;font-weight: bold; }
        .badge-danger { color: #c62828; font-weight: bold; }
        .badge-warning { color: #c97a00; font-weight: bold; }
        .badge-info { color: #1d4ed8; font-weight: bold;}
        .badge-secondary { color: #666; }
        .badge-dark { color: #111; }
        /* POPUP */
        #editBox {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: min(420px, 95vw);
            background: #fff;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
            display: none;
            z-index: 999;
        }
        #editBox input,
        #editBox select,
        #editBox button {
            width: 100%;
            padding: 10px;
            margin-top: 8px;
        }
        pre {
            white-space: pre-wrap;
            word-break: break-word;
        }
        /* MOBILE */
        @media (max-width:700px) {
            body {
                padding: 10px;
            }
            .coh-mini-grid {
                grid-template-columns: 1fr;
            }
            .row {
                flex-direction: column;
                gap: 4px;
            }
        }
    </style>
</head>

<body>
    <h1>💧 SYR SafeTech Dashboard</h1>
    <small><?= htmlspecialchars($url) ?></small>
    <?php
    $msg = trim($_GET['Postmsg'] ?? '');
    if ($msg !== '') {
        echo '<div style="margin:10px 0;padding:10px;background:#fff3cd;border-radius:10px;">'
            . htmlspecialchars($msg) .
            '</div>';
    }
    ?>
    <div class="coh-panel">
    <!-- 
    =====================================================
     TOP GRID
    ===================================================== 
    -->
        <div class="coh-card">
            <div class="coh-mini-grid">
                <div class="coh-mini-box">
                    <div>🚰 Ventil</div>
                    <div class="big"><?= htmlspecialchars($valve) ?></div>
                    <div style="display:flex;gap:6px;margin-top:10px;">
                        <a href="<?= $disableOpen ? '#' : '?action=syropenventil' ?>" class="btn btn-open action-btn <?= $disableOpen ? 'btn-disabled' : '' ?>"
                            onclick="<?= $disableOpen ? 'return false;' : "return handleAction(this,'Ventil öffnen?');" ?>">Öffnen
                        </a>
                        <a href="<?= $disableClose ? '#' : '?action=syrcloseventil' ?>" class="btn btn-close action-btn <?= $disableClose ? 'btn-disabled' : '' ?>"
                            onclick="<?= $disableClose ? 'return false;' : "return handleAction(this,'Ventil schließen?');" ?>">Schließen
                        </a>
                    </div>
                    <div id="waitingNote" class="waiting-note"></div>
                </div>
                <div class="coh-mini-box">
                    <div>🚨 Alarmstatus</div>
                    <div class="big"><?= htmlspecialchars($status) ?></div>
                    <div><?= $leak['text'] ?></div>
                </div>
                <div class="coh-mini-box">
                    <div>🔋 Batterie</div>
                    <div class="big"><?= (int)$batPercent ?> %</div>
                    <div><?= number_format($batVolt, 2) ?> V</div>
                </div>
                <div class="coh-mini-box">
                    <div>💧 Durchfluss</div>
                    <div class="big"><?= htmlspecialchars((string)$flow) ?> l/h</div>
                </div>
                <div class="coh-mini-box">
                    <div>📊 Gesamtverbrauch</div>
                    <div class="big"><?= htmlspecialchars((string)$gesVol) ?> l</div>
                </div>
                <div class="coh-mini-box">
                    <div>🧭 Druck</div>
                    <div class="big"><?= number_format($pressure, 2) ?> bar</div>
                </div>
                <div class="coh-mini-box">
                    <div>🌡 Temperatur</div>
                    <div class="big"><?= htmlspecialchars((string)$temp) ?> °C</div>
                </div>
                <div class="coh-mini-box">
                    <div>⚙️ Profil</div>
                    <div class="big"><?= (int)$aktprf ?></div>
                    <form method="post">
                        <select name="profile">
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == $aktprf ? 'selected' : '' ?>><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" name="setProfile">Profil setzen</button>
                    </form>
                </div>
                <div class="coh-mini-box">
                    <div>🧪 Wasserhärte</div>
                    <div class="big"><?= hardnessDots($dH) ?></div>
                    <div><?= $dH ?> °dH<br>aus</div>
                    <div>Leitwert <?= $leitWert ?> µS/cm / 30</div>
                </div>
                <div class="coh-mini-box">
                    <div>☁️ Cloud</div>
                    <div>
                        aktiv: <?= !empty($data['CEN']) ? 'ja' : 'nein' ?><br>
                        alle <?= (int)($data['WTI'] ?? 0) ?> Sek
                    </div>
                </div>
            </div>
        </div>
        <!-- LECKAGESCHUTZ -->
        <details>
            <summary>Leckageschutz</summary>
            <div class="row">
                <span>Volumenleckage</span>
                <span>
                    <?= htmlspecialchars((string)$pv) ?> L
                    <span class="edit-btn" onclick="openEdit('pv', <?= (int)$pv ?>)">✏️</span>
                </span>
            </div>
            <div class="row">
                <span>Zeitleckage Zeit in der das Volumenleckage überprüft wird</span>
                <span>
                    <?= htmlspecialchars((string)$pt) ?> min
                    <span class="edit-btn" onclick="openEdit('pt', <?= (int)$pt ?>)">✏️</span>
                </span>
            </div>
            <div class="row">
                <span>Durchflussleckage</span>
                <span>
                    <?= htmlspecialchars((string)$pf) ?> l/h
                    <span class="edit-btn" onclick="openEdit('pf', <?= (int)$pf ?>)">✏️</span>
                </span>
            </div>
            <div class="row">
                <span>Mikroleckage</span>
                <span>
                    <?= $pm ? "An" : "Aus" ?>
                    <span class="edit-btn" onclick="openEdit('pm', <?= $pm ? 1 : 0 ?>)">✏️</span>
                </span>
            </div>
        </details>
        <!-- MIKROLECKAGE Tropftest -->
        <details>
            <summary>💧 Mikro-Leckage / Tropftest Test</summary>
            <?php $ms = decodeMicroStatus($data["DSV"] ?? 0); ?>         <!-- MIKROLECKAGE TEST Status -->
            <div class="row">
                <span>(DSV) Status MLT</span>
                <span class="badge-<?= htmlspecialchars($ms[1]) ?>"><?= htmlspecialchars($ms[0]) ?></span>
            </div>
            <div class="row">
                <span>(DRP) Intervall</span>
                <span>
                    <?php
                    $drpText = match ((int)($data["DRP"] ?? 0)) {
                        1 => "Täglich",
                        2 => "Wöchentlich",
                        3 => "Monatlich",
                        default => "-"
                    };
                    echo htmlspecialchars($drpText);
                    ?>
                    <span class="edit-btn" onclick="openEdit('drp', '<?= (int)($data["DRP"] ?? 1) ?>')">✏️</span>
                </span>
            </div>
            <div class="row">
                <span>(DTT) Uhrzeit</span>
                <span>
                    <?= htmlspecialchars((string)($data["DTT"] ?? '-')) ?>
                    <span class="edit-btn" onclick="openEdit('dtt', '<?= htmlspecialchars((string)($data["DTT"] ?? '04:00'), ENT_QUOTES) ?>')">✏️</span>
                </span>
            </div>
            <div class="row"><span>(DEX) Mikro-Test</span><span>Starten<span class="edit-btn" onclick="openEdit('dex', '1')">▶️</span></span></div>
        </details>
        <!-- MIKROLECKAGE Diagnosewerte -->
        <details>
            <summary> Mikroleckage Diagnosewerte</summary>
            <div class="row"><span>(DBD) Mikroleckage Microleak Grenzen für Druckverlust. Je kleiner, desto schneller Alarm.</span><span><?= htmlspecialchars((string)($data["DBD"] ?? '-')) ?></span></div>
            <div class="row"><span>(DPL) Mikroleckage Microleak Grenzen für Druckverlust. Je kleiner, desto schneller Alarm.</span><span><?= htmlspecialchars((string)($data["DPL"] ?? '-')) ?></span></div>
            <div class="row"><span>(DST) Mikroleckage Zeit ohne Impuls / noPulse-Zeit in Min</span><span><?= htmlspecialchars((string)($data["DST"] ?? '-')) ?></span></div>
            <div class="row"><span>(DOM) Mikroleckage Messzeit Wie lange Druck beobachtet wird. in sec</span><span><?= htmlspecialchars((string)($data["DOM"] ?? '-')) ?></span></div>
            <div class="row"><span>(DBT) Mikroleckage Empfindlichkeit Druckabfall Microleak Drucktest / Zeitwert</span><span><?= htmlspecialchars((string)($data["DBT"] ?? '-')) ?></span></div>
            <div class="row"><span>(DCM) Mikroleckage Interner Bewertungsmodus ????</span><span><?= htmlspecialchars((string)($data["DCM"] ?? '-')) ?></span></div>
            <div class="row"><span>(DTC) Mikroleckage Wiederholungen Wie oft nachgemessen wird Anzahl</span><span><?= htmlspecialchars((string)($data["DTC"] ?? '-')) ?></span></div>
            <div class="row"><span>(DMA) Mikroleckage Reaktion bei Mikroleckage / Alarm<br>0 Nur Warnung Ventil bleibt offen<br>1 Warnung + verzögertes Eingreifen<br> 2 Sofort / automatisch schließen</span><span><?= htmlspecialchars((string)($data["DMA"] ?? '-')) ?></span></div>
            <div class="row"><span>(MM) Microleak Mode<br>0 aus<br>1 tolerant<br> 2 normal<br>3 streng</span><span><?= htmlspecialchars((string)($data["MM"] ?? '-')) ?></span></div>
        </details>
        <!-- Profildetails -->
        <details>
            <summary>Profildetails</summary>
            <div class="row"><span>Aktives Profil (Nummer)</span><span><?= htmlspecialchars((string)($aktprf ?? '-')) ?></span></div>
            <div class="row"><span>Profil Name </span><span><?= htmlspecialchars((string)($pn ?? '-')) ?></span></div>
            <div class="row"><span>Profil Maximales Volumen </span><span><?= htmlspecialchars((string)($pv ?? '-')) ?></span></div>
            <div class="row"><span>Profil Maximale Laufzeit in Minuten </span><span><?= htmlspecialchars((string)($pt ?? '-')) ?></span></div>
            <div class="row"><span>Profil Maximaler Durchfluss in l/h </span><span><?= htmlspecialchars((string)($pf ?? '-')) ?></span></div>
            <div class="row"><span>Profil Mikroleckage-Test aktiv</span><span><?= $pm ? "An" : "Aus" ?></span></div>
            <div class="row"><span>Profil Leckagewarnung aktiv</span><span><?= $pw ? "An" : "Aus" ?></span></div>
            <div class="row"><span>Profil Buzzer (Piepston) aktiv</span><span><?= $pb ? "An" : "Aus" ?></span></div>
            <div class="row"><span>Profil Rückkehrzeit zu Profil 1 in Stunden</span><span><?= htmlspecialchars((string)($pr ?? '-')) ?></span></div>
        </details>
        <!-- Service / Lernwerte intern -->
        <details>
            <summary>Service / Lernwerte intern</summary>
            <div class="row"><span>(SLP) Interner Lern-/Leckageparameter</span><span><?= htmlspecialchars((string)($data["SLP"] ?? '-')) ?></span></div>
            <div class="row"><span>(SLO) Interner Offset ??</span><span><?= htmlspecialchars((string)($data["SLO"] ?? '-')) ?></span></div>
            <div class="row"><span>(SOF) Interner Faktor ??</span><span><?= htmlspecialchars((string)($data["SOF"] ?? '-')) ?></span></div>
            <div class="row"><span>(SMF) Interner Flow-Grenzwert ??</span><span><?= htmlspecialchars((string)($data["SMF"] ?? '-')) ?></span></div>
            <div class="row"><span>(SLE) Lern-/Ereigniszähler ??</span><span><?= htmlspecialchars((string)($data["SLE"] ?? '-')) ?></span></div>
            <div class="row"><span>(SLV) Gelernter Volumenwert</span><span><?= htmlspecialchars((string)($data["SLV"] ?? '-')) ?></span></div>
            <div class="row"><span>(SLT) Gelernter Zeitwert</span><span><?= htmlspecialchars((string)($data["SLT"] ?? '-')) ?></span></div>
            <div class="row"><span>(SLF) Gelernter Durchflusswert</span><span><?= htmlspecialchars((string)($data["SLF"] ?? '-')) ?></span></div>
            <div class="row"><span>(AMA) Interner Alarmmodus ??</span><span><?= htmlspecialchars((string)($data["AMA"] ?? '-')) ?></span></div>
            <div class="row"><span>(MM) Interner Modus ??</span><span><?= htmlspecialchars((string)($data["MM"] ?? '-')) ?></span></div>
            <div class="row"><span>(ALD) Alarmdauer / Verzögerung ??</span><span><?= htmlspecialchars((string)($data["ALD"] ?? '-')) ?></span></div>
        </details>

        <!-- GERÄTEINFO -->
        <details>
            <summary>Geräteinformationen</summary>
            <div class="row"><span>Seriennummer</span><span><?= htmlspecialchars((string)($data["SRN"] ?? "-")) ?></span></div>
            <div class="row"><span>Firmware</span><span><?= htmlspecialchars((string)($data["VER"] ?? "-")) ?></span></div>
        </details>
        <!-- NETZWERK -->
        <details>
            <summary>Netzwerk</summary>
            <div class="row"><span>WLAN Status</span><span><?= htmlspecialchars(decodeWifiStatus($data["WFS"] ?? null)) ?></span></div>
            <div class="row"><span>Signal</span><span><?= htmlspecialchars((string)($data["WFR"] ?? "-")) ?> %</span></div>
            <div class="row"><span>IP</span><span><?= htmlspecialchars((string)($data["WIP"] ?? "-")) ?></span></div>
            <div class="row"><span>Gateway</span><span><?= htmlspecialchars((string)($data["WGW"] ?? "-")) ?></span></div>
            <div class="row"><span>MAC</span><span><?= htmlspecialchars((string)($data["MAC1"] ?? "-")) ?></span></div>
            <div class="row"><span>LAN IP</span><span><?= htmlspecialchars((string)($data["EIP"] ?? "-")) ?></span></div>
            <div class="row"><span>LAN Gateway</span><span><?= htmlspecialchars((string)($data["EGW"] ?? "-")) ?></span></div>
            <div class="row"><span>LAN MAC</span><span><?= htmlspecialchars((string)($data["MAC2"] ?? "-")) ?></span></div>
        </details>
        <!-- EVENTS -->
        <details open>
            <summary>Alarme / Warnungen / Meldungen</summary>
            <?php $a = decodeAlarm($data["ALA"] ?? null); ?>
            <div class="row"><span><strong>Alarm</strong></span><span class="badge-<?= htmlspecialchars($a[1]) ?>"><?= htmlspecialchars($a[0]) ?></span></div>
            <details>
                <summary>Letzte 8 Alarme</summary>
                <?php foreach (decodeList($data["ALM"] ?? "") as $v): $d = decodeAlarm($v); ?>
                    <div class="row"><span><strong>Alarm</strong></span><span class="badge-<?= htmlspecialchars($d[1]) ?>"><?= htmlspecialchars($d[0]) ?></span></div>
                <?php endforeach; ?>
            </details>
            <hr>
            <?php $w = decodeWarning($data["WRN"] ?? null); ?>
            <div class="row"><span><strong>Warnungen</strong></span><span class="badge-<?= htmlspecialchars($w[1]) ?>"><?= htmlspecialchars($w[0]) ?></span></div>
            <details>
                <summary>Letzte 8 Warnungen</summary>
                <?php foreach (decodeList($data["ALW"] ?? "") as $v): $d = decodeWarning($v); ?>
                    <div class="row"><span>Warnung</span><span class="badge-<?= htmlspecialchars($d[1]) ?>"><?= htmlspecialchars($d[0]) ?></span></div>
                <?php endforeach; ?>
            </details>
            <hr>
            <?php $n = decodeNotification($data["NOT"] ?? null); ?>
            <div class="row"><span><strong>Meldungen</strong></span><span class="badge-<?= htmlspecialchars($n[1]) ?>"><?= htmlspecialchars($n[0]) ?></span></div>
            <details>
                <summary>Letzte 8 Meldungen</summary>
                <?php foreach (decodeList($data["ALN"] ?? "") as $v): $d = decodeNotification($v); ?>
                    <div class="row"><span>Meldung</span><span class="badge-<?= htmlspecialchars($d[1]) ?>"><?= htmlspecialchars($d[0]) ?></span></div>
                <?php endforeach; ?>
            </details>
            <hr>
        </details>
        <!-- DEBUG -->
        <details>
            <summary>Debug</summary>
            <strong>Data</strong>
            <pre><?php print_r($data); ?></pre>
            <hr>
            <strong>Rowdata</strong>
            <pre><?php print_r($dataRaw); ?></pre>
        </details>
    </div>

    <!-- POPUP -->
    <div id="editBox"></div>

    <script>
        function handleAction(el, text) {
            if (!confirm(text)) return false;

            document.querySelectorAll('.action-btn').forEach(btn => {
                btn.style.pointerEvents = 'none';
                btn.style.opacity = '.5';
            });

            let note = document.getElementById('waitingNote');
            if (note) note.innerText = '⏳ Bitte warten...';

            return true;
        }
    </script>

</body>

</html>
<?php

error_reporting(E_ALL);
ini_set('display_errors',1);

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
    $ctx = stream_context_create([ 'http' => ['timeout' => 5]]);
    $json = @file_get_contents($baseGet . strtolower($cmd), false, $ctx);
    if (!$json) { return null; }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) { return null; }
    return array_values($data)[0] ?? null;
}
// ---------------------------------------------------
// API GET ALL
// ---------------------------------------------------
function syrGetAll($baseGet)
{
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($baseGet . "all", false, $ctx);
    if (!$json) { return []; }
    $data = json_decode($json, true);
    if (!is_array($data)) { return []; }
    return $data;
}
// ---------------------------------------------------
// PROFIL SETZEN
// ---------------------------------------------------
if (isset($_POST['setProfile'])) {
    $p = (int)($_POST['profile'] ?? 1);
    if ($p >= 1 && $p <= 8) {
        @file_get_contents($baseSet . "pa" . $p . "/true"); // Profil aktivieren
        @file_get_contents($baseSet . "prf/" . $p); // danach setzen
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
        if (in_array($type, ['pv','pt','pf'], true)) {
            $value = (int)$valueRaw;
            @file_get_contents($baseSet . $type . $profile . "/" . $value);
        }
        if ($type === 'pm') {
            $value = ((int)$valueRaw === 1) ? 'true' : 'false';
            @file_get_contents($baseSet . "pm" . $profile . "/" . $value);
        }
        // -----------------------------------
        // Mikroleckage Test
        // -----------------------------------
        if ($type === 'drp') {
            $value = (int)$valueRaw;
            if ($value >= 1 && $value <= 3) {
                @file_get_contents($baseSet . "drp/" . $value);
                usleep(300000);
            }
        }
        if ($type === 'dtt') {
            $value = trim((string)$valueRaw);
            if (preg_match('/^\d{2}:\d{2}$/', $value)) {
                @file_get_contents($baseSet . "dtt/" . $value);
                usleep(300000);
            }
        }
        if ($type === 'dex') {
            @file_get_contents($baseSet . "dex/true");
            usleep(300000);
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
    if ($action === 'syrcloseventil') {
        @file_get_contents($baseSet . "ab/true");
        $target = 10;   // ZU
    }
    if ($action === 'syropenventil') {
        @file_get_contents($baseSet . "ab/false");
        $target = 20;   // OFFEN
    }
    // max. 50 Sekunden auf Zielzustand warten
    $maxSeconds = 50;
    $start = time();
    while (true) {
        sleep(1);
        $vlvNow = syrGet("vlv", $baseGet);
        if ($vlvNow == $target) break;
        if ($vlvNow === null) break;
        if ((time() - $start) >= $maxSeconds) break;
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
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
    "DSV"  => $dataRaw["getDSV"]  ?? 0,   // Mikroleckage Status lesen 0-3
    "DRP"  => $dataRaw["getDRP"]  ?? 0,   // Mikroleckage Intervall lesen 1-3
    "DTT"  => $dataRaw["getDTT"]  ?? "04:00", // Mikroleckage Uhrzeit lesen "HH:MM"
];

// diese werte kommen bei all falsch zurück
$data["ALM"] = syrGet("ALM", $baseGet);
$data["ALW"] = syrGet("ALW", $baseGet);
$data["ALN"] = syrGet("ALN", $baseGet);
// Fallback auf alte Einzel-Requests nur wenn /get/all komplett leer ist
if ($useFallback) {
    $keys = [
        "vlv","bat","flo","bar","cel","prf","srn","ver",
        "wip","wgw","mac1","eip","egw","mac2","wfs","wfr",
        "ala","wrn","not","alm","alw","aln","vol","cnd","wti","cen","dsv","drp","dtt"
    ];

    $data = [];
    foreach($keys as $k){
        $data[strtoupper($k)] = syrGet($k, $baseGet);
    }
}
// ---------------------------------------------------
// Profilwerte für aktuelles Profil laden
// ---------------------------------------------------
$prf = (int)($data["PRF"] ?? 1);
if ($prf < 1 || $prf > 8) {
    $prf = 1;
}
if (!$useFallback) {
    $data["PV$prf"] = $dataRaw["getPV$prf"] ?? 0;
    $data["PT$prf"] = $dataRaw["getPT$prf"] ?? 0;
    $data["PF$prf"] = $dataRaw["getPF$prf"] ?? 0;
    $data["PM$prf"] = $dataRaw["getPM$prf"] ?? 0;
} else {
    foreach(["pv","pt","pf","pm"] as $k){
        $data[strtoupper($k).$prf] = syrGet($k.$prf, $baseGet);
    }
}
$pv = $data["PV$prf"] ?? 0;
$pt = $data["PT$prf"] ?? 0;
$pf = $data["PF$prf"] ?? 0;
$pm = $data["PM$prf"] ?? 0;
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
$valveMap = [
    10 => "ZU",
    11 => "am Schließen",
    20 => "OFFEN",
    21 => "am Öffnen"
];
$vlv = $data["VLV"] ?? 0;
$valve = $valveMap[$vlv] ?? "UNBEKANNT";
// Button-Zustände
$disableOpen  = ($vlv == 20 || $vlv == 21);
$disableClose = ($vlv == 10 || $vlv == 11);


// leckage test 
function detectLeakType(array $data): array
{
    // Werte sauber holen (fallbacks)
    $flow   = isset($data['getFLO']) ? (float)$data['getFLO'] : 0;
    $volume = isset($data['getVOL']) ? (float)$data['getVOL'] : 0;
    $alarm  = isset($data['getALA']) ? (int)$data['getALA'] : 0;
    $warn   = isset($data['getWAR']) ? (int)$data['getWAR'] : 0;
    $valve  = isset($data['getVLV']) ? (int)$data['getVLV'] : null;
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
function hardnessDots($dH){
    if($dH < 8) return "🟢⚪⚪";
    if($dH < 14) return "🟢🟡⚪";
    return "🟢🟡🔴";
}
// ---------------------------------------------------
// LISTEN werte extrahieren
// ---------------------------------------------------
function decodeList($str){
    if(!$str) return [];
    return array_filter(array_map('trim', explode(",", $str)));
}
// ---------------------------------------------------
// ALARM
// ---------------------------------------------------
function decodeAlarm($hex)
{
    if (!$hex || strtolower($hex) == "ff") return ["(FF) Kein aktueller Alarm","ok"];
    $hex = strtoupper(trim((string)$hex));
    if (strpos($hex, "0X") !== 0) $hex = "0x".$hex;
    $map = [
        "0xFF" => ["(FF) Kein Alarm","ok"],
        "0xA1" => ["(A1) Endschalter Problem","alarm"],
        "0xA2" => ["(A2) Motorstrom zu hoch","alarm"],
        "0xA3" => ["(A3) Volumenleckage","alarm"],
        "0xA4" => ["(A4) Zeitleckage","alarm"],
        "0xA5" => ["(A5) Durchflussleckage","alarm"],
        "0xA6" => ["(A6) Mikroleckage","warn"],
        "0xA7" => ["(A7) Bodensensor-Leckage","alarm"],
        "0xA8" => ["(A8) Durchflusssensor defekt","alarm"],
        "0xA9" => ["(A9) Drucksensor defekt","alarm"],
        "0xAA" => ["(AA) Temperatursensor defekt","alarm"],
        "0xAB" => ["(AB) Leitwertsensor defekt","alarm"],
        "0xAC" => ["(AC) Leitwertsensor Fehler","alarm"],
        "0xAD" => ["(AD) Wasserhärte zu hoch","warn"],
        "0x0D" => ["(0D) Salz leer","warn"],
        "0x0E" => ["(0E) Ventilposition falsch","alarm"],
    ];
    return $map[$hex] ?? ["Unbekannt ($hex)","warn"];
}
// ---------------------------------------------------
// WARNUNG
// ---------------------------------------------------
function decodeWarning($hex)
{
    if (!$hex || strtolower($hex) == "ff") return ["(FF) Keine aktuelle Warnung","ok"];
    $hex = strtoupper(trim((string)$hex));
    if (strpos($hex, "0X") !== 0) $hex = "0x".$hex;
    $map = [
        "0x01" => ["(01) Stromunterbrechung","warn"],
        "0x07" => ["(07) Leckagewarnung Volumen","warn"],
        "0x08" => ["(08) Batterie leer","warn"],
        "0x02" => ["(02) Salz niedrig","warn"],
        "0x09" => ["(09) Erstbefüllung erforderlich","warn"],
        "0x10" => ["(10) Volumenleck erkannt","warn"],
        "0x11" => ["(11) Zeitleck erkannt","warn"],
    ];
    return $map[$hex] ?? ["Warnung ($hex)","warn"];
}
// ---------------------------------------------------
// NOTIFICATION
// ---------------------------------------------------
function decodeNotification($hex)
{
    if (!$hex || strtolower($hex) == "ff") return ["(FF) Keine aktuelle Meldung","ok"];
    $hex = strtoupper(trim((string)$hex));
    if (strpos($hex, "0X") !== 0) $hex = "0x".$hex;
    $map = [
        "0x01" => ["(01) Softwareupdate verfügbar","warn"],
        "0x04" => ["(04) Softwareupdate installiert","ok"],
        "0x02" => ["(02) Halbjährliche Wartung","warn"],
        "0x03" => ["(03) Jährliche Wartung","warn"],
    ];
    return $map[$hex] ?? ["Info ($hex)","warn"];
}
function decodeMicroStatus($val)
{
    return match((int)$val) {
        0 => ["Nicht aktiv", "secondary"],
        1 => ["Test aktiv", "warning"],
        2 => ["Abgebrochen (Druckabfall)", "danger"],
        3 => ["Übersprungen", "info"],
        default => ["Unbekannt", "dark"]
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
        case '0': return "Nicht verbunden";
        case '1': return "Verbinden...";
        case '2': return "Verbunden";
        default:  return "-";
    }
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>SYR SafeTech Dashboard</title>

<style>
body{font-family:Arial;background:#f4f6f8;margin:20px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:15px;}
.card{background:#fff;padding:15px;border-radius:10px;box-shadow:0 2px 5px rgba(0,0,0,.1);}
.big{font-size:22px;font-weight:bold;}

.badge-ok{color:green;}
.badge-warn{color:#caa500;}
.badge-alarm{color:red;font-weight:bold;}
.badge-secondary{color:#666;}
.badge-warning{color:#caa500;}
.badge-danger{color:red;font-weight:bold;}
.badge-info{color:#0077cc;}
.badge-dark{color:#222;}
details{margin-top:15px;background:#fff;padding:10px;border-radius:10px;}
summary{font-weight:bold;cursor:pointer;}
.row{display:flex;justify-content:space-between;padding:6px;border-bottom:1px solid #eee;}
.btn{
    flex:1;
    text-align:center;
    padding:6px;
    border-radius:6px;
    color:#fff;
    text-decoration:none;
    display:inline-block;
    border:none;
    cursor:pointer;
}
.btn-open{background:#4caf50;}
.btn-close{background:#f44336;}
.btn-disabled{
    opacity:0.4;
    pointer-events:none;
}
.waiting-note{
    margin-top:8px;
    font-size:12px;
    color:#666;
    min-height:16px;
}
.edit-btn {
    cursor: pointer;
    margin-left: 8px;
    color: #888;
}
.edit-btn:hover {
    color: #000;
}
#editBox {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    display: none;
    z-index: 999;
    min-width: 320px;
}
#editBox h3 {
    margin-top: 0;
}
#editBox input[type=number],
#editBox input[type=text],
#editBox input[type=time] {
    width: 100%;
    padding: 8px;
    box-sizing: border-box;
}
#editBox button {
    padding: 8px 12px;
    margin-right: 8px;
}
.profile-form {
    margin-top: 10px;
}
.profile-form select,
.profile-form button {
    padding: 4px 6px;
}
pre {
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
</head>
<body>

<h1>💧 SYR SafeTech Dashboard</h1>
<span>url: <?= htmlspecialchars($url) ?></span>
<br>
&nbsp;

<div class="grid">
    <div class="card">
        <div>🚰 Ventil</div>
        <div class="big"><?= htmlspecialchars($valve) ?></div>
        <div style="margin-top:10px; display:flex; gap:5px;">
            <a href="<?= $disableOpen ? '#' : '?action=syropenventil' ?>"
               class="btn btn-open action-btn <?= $disableOpen ? 'btn-disabled' : '' ?>"
               onclick="<?= $disableOpen ? 'return false;' : "return handleAction(this,'Ventil wirklich öffnen?');" ?>">
               Öffnen
            </a>
            <a href="<?= $disableClose ? '#' : '?action=syrcloseventil' ?>"
               class="btn btn-close action-btn <?= $disableClose ? 'btn-disabled' : '' ?>"
               onclick="<?= $disableClose ? 'return false;' : "return handleAction(this,'Ventil wirklich schließen?');" ?>">
               Schließen
            </a>
        </div>
        <div id="waitingNote" class="waiting-note"></div>
    </div>
<?php
$lamp = '⚪';
switch ($state) {
    case 'ok':
        $lamp = '🟢';
        break;
    case 'warn':
        $lamp = '🟡';
        break;
    case 'alarm':
        $lamp = '🔴';
        break;
}
?>
    <div class="card">
        <div>AlarmStatus</div>
        <div class="big">
            <?= $lamp ?> <?= htmlspecialchars($status) ?>
        </div>
        <div>Leckagetest</div>
        <div><?= $leak['text'] ?></div>       
    </div>
    <div class="card">
        <div>Batterie</div>
        <div class="big"><?= (int)$batPercent ?> %</div>
        <div><?= number_format($batVolt,2) ?> V</div>
    </div>
    <div class="card">
        <div>💧 Durchfluss</div>
        <div class="big"><?= htmlspecialchars((string)$flow) ?> l/h</div>
    </div>
    <div class="card">
        <div>📊 Ges. Verbrauch</div>
        <div class="big"><?= htmlspecialchars((string)$gesVol) ?> l</div>
    </div>
    <div class="card">
        <div>🧭 Druck</div>
        <div class="big"><?= number_format($pressure,2) ?> bar</div>
    </div>
    <div class="card">
        <div>🌡️Temperatur</div>
        <div class="big"><?= htmlspecialchars((string)$temp) ?> °C</div>
    </div>
    <div class="card">
        <div>⚙️Profil</div>
        <div class="big"><?= (int)$prf ?></div>
        <form method="post" class="profile-form">
            <select name="profile">
                <?php for($i=1;$i<=8;$i++): ?>
                    <option value="<?= $i ?>" <?= $i == $prf ? 'selected' : '' ?>>
                        <?= $i ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button type="submit" name="setProfile">OK</button>
        </form>
    </div>
    <div class="card">
        <div>🧪 Wasserhärte</div>
        <div class="big"><?= hardnessDots($dH) ?></div>
        <div><?= htmlspecialchars((string)$dH) ?> °dH (geschätzt)</div>
        <div>Leitwert: <?= htmlspecialchars((string)$leitWert) ?> µS/cm</div>
        <div>berechnete Härte Leitwert/30 <?= htmlspecialchars((string)($leitWert/30)) ?> °dH</div>
    </div>
    <div class="card">
        <div>☁️ Cloud</div>
        <div>
            aktiv: <?= !empty($data['CEN']) ? 'ja' : 'nein' ?><br>
            Übertragung alle <?= htmlspecialchars((string)($data['WTI'] ?? 0)) ?> Sek
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
        <span>Zeitleckage</span>
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
<!-- MIKROLECKAGE TEST -->
<details>
    <summary>💧 Mikro-Leckage Test</summary>
    <?php $ms = decodeMicroStatus($data["DSV"] ?? 0); ?>
    <div class="row">
        <span>Status</span>
        <span class="badge-<?= htmlspecialchars($ms[1]) ?>"><?= htmlspecialchars($ms[0]) ?></span>
    </div>
    <div class="row">
        <span>Intervall</span>
        <span>
            <?php
                $drpText = match((int)($data["DRP"] ?? 0)) {
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
        <span>Uhrzeit</span>
        <span>
            <?= htmlspecialchars((string)($data["DTT"] ?? '-')) ?>
            <span class="edit-btn" onclick="openEdit('dtt', '<?= htmlspecialchars((string)($data["DTT"] ?? '04:00'), ENT_QUOTES) ?>')">✏️</span>
        </span>
    </div>
    <div class="row">
        <span>Mikro-Test</span>
        <span>
            Starten
            <span class="edit-btn" onclick="openEdit('dex', '1')">▶️</span>
        </span>
    </div>
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
        <?php foreach(decodeList($data["ALM"] ?? "") as $v): $d = decodeAlarm($v); ?>
            <div class="row"><span><strong>Alarm</strong></span><span class="badge-<?= htmlspecialchars($d[1]) ?>"><?= htmlspecialchars($d[0]) ?></span></div>
        <?php endforeach; ?>
    </details>
    <hr>
    <?php $w = decodeWarning($data["WRN"] ?? null); ?>
    <div class="row"><span><strong>Warnungen</strong></span><span class="badge-<?= htmlspecialchars($w[1]) ?>"><?= htmlspecialchars($w[0]) ?></span></div>
    <details>
        <summary>Letzte 8 Warnungen</summary>
        <?php foreach(decodeList($data["ALW"] ?? "") as $v): $d = decodeWarning($v); ?>
            <div class="row"><span>Warnung</span><span class="badge-<?= htmlspecialchars($d[1]) ?>"><?= htmlspecialchars($d[0]) ?></span></div>
        <?php endforeach; ?>
    </details>
    <hr>
    <?php $n = decodeNotification($data["NOT"] ?? null); ?>
    <div class="row"><span><strong>Meldungen</strong></span><span class="badge-<?= htmlspecialchars($n[1]) ?>"><?= htmlspecialchars($n[0]) ?></span></div>
   <details>
        <summary>Letzte 8 Meldungen</summary>
        <?php foreach(decodeList($data["ALN"] ?? "") as $v): $d = decodeNotification($v); ?>
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
<!-- POPUP -->
<div id="editBox">
    <h3 id="editTitle">Wert ändern</h3>

    <form method="post">
        <input type="hidden" name="setType" id="setType">
        <input type="hidden" name="profile" value="<?= (int)$prf ?>">
        <input type="number" name="value" id="editValueNumber">
        <input type="time" name="value" id="editValueTime" style="display:none;">
        <br><br>
        <button type="submit">Speichern</button>
        <button type="button" onclick="closeEdit()">Abbrechen</button>
    </form>
</div>
<script>
function openEdit(type, val){
    document.getElementById("setType").value = type;
    const inputNumber = document.getElementById("editValueNumber");
    const inputTime = document.getElementById("editValueTime");
    inputNumber.style.display = "block";
    inputTime.style.display = "none";
    inputNumber.disabled = false;
    inputTime.disabled = true;
    let labels = {
        pv: "Volumen (Liter)",
        pt: "Zeit (Minuten)",
        pf: "Durchfluss (l/h)",
        pm: "Mikroleckage (0=Aus, 1=An)",
        drp: "Intervall (1 Täglich, 2 Wöchentlich, 3 Monatlich)",
        dtt: "Uhrzeit",
        dex: "Mikro-Test starten (0=Nein, 1=Ja)"
    };
    document.getElementById("editTitle").innerText = labels[type] || "Wert ändern";
    if (type === 'dtt') {
        inputNumber.style.display = "none";
        inputTime.style.display = "block";
        inputNumber.disabled = true;
        inputTime.disabled = false;
        inputTime.value = val || "04:00";
    } else {
        inputNumber.value = val;
    }
    if (type === 'dex') {
        inputNumber.value = 1;
    }
    document.getElementById("editBox").style.display = "block";
}
function closeEdit(){
    document.getElementById("editBox").style.display = "none";
}
function handleAction(el, text)
{
    if (!confirm(text)) return false;
    document.body.style.cursor = 'wait';
    document.querySelectorAll('.action-btn').forEach(function(btn) {
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.4';
    });
    el.innerText = '⏳ bitte warten...';
    var note = document.getElementById('waitingNote');
    if (note) {
        note.innerText = '⏳ Warte auf Antwort des Ventils (~50 sec) ...';
    }
    return true;
}
</script>
</body>
</html>
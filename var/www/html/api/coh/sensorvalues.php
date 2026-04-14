<?php
declare(strict_types=1);

// ------------------------------------
// HEADERS + GZIP
// ------------------------------------
header('Content-Type: application/json; charset=utf-8');

// gzip Kompression (massiver Speed Boost bei vielen Daten)
if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
    ob_start('ob_gzhandler');
}

// ------------------------------------
// CONFIG
// ------------------------------------
$API_TOKEN = 'COH_CODE';

$DB = [
  'host' => '127.0.0.1',
  'port' => 3306,
  'user' => 'peter',
  'pass' => 'sql666sql',
  'db'   => 'co5_solar',
];

// dynamisches Limit
$MAX_ROWS  = isset($_GET['bulk']) ? 20000 : 5000;
$MAX_AGE_S = 60 * 60 * 24 * 30; // 30 Tage

// ------------------------------------
// AUTH
// ------------------------------------
$token = $_GET['token'] ?? ($_SERVER['HTTP_X_COH_TOKEN'] ?? '');

if (!hash_equals($API_TOKEN, (string)$token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// ------------------------------------
// INPUT
// ------------------------------------
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
$now = time();

if ($since < 0) $since = 0;
if ($since > $now) $since = $now;
if ($since < $now - $MAX_AGE_S) $since = $now - $MAX_AGE_S;

// ------------------------------------
// DB CONNECT
// ------------------------------------
mysqli_report(MYSQLI_REPORT_OFF);

$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);

if (!@$db->real_connect($DB['host'], $DB['user'], $DB['pass'], $DB['db'], $DB['port'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect_failed']);
    exit;
}

$db->set_charset('utf8mb4');

// ------------------------------------
// QUERY (OPTIMIERT!)
// ------------------------------------
// WICHTIG: nur benötigte Felder laden!
$sql = "SELECT tstamp, sensorID, sensorValue , sensorEinheit
        FROM tl_coh_sensorvalue
        WHERE tstamp > ?
        ORDER BY tstamp ASC
        LIMIT " . (int)$MAX_ROWS;

$stmt = $db->prepare($sql);
$stmt->bind_param('i', $since);
$stmt->execute();
$res = $stmt->get_result();

// ------------------------------------
// JSON STREAMING (ULTRA SCHNELL)
// ------------------------------------
echo '{"ok":true,"since":'.$since.',"rows":[';

$first = true;
$maxT = $since;

while ($r = $res->fetch_assoc()) {

    // int cast für speed + sauber
    $r['tstamp'] = (int)$r['tstamp'];

    if ($r['tstamp'] > $maxT) {
        $maxT = $r['tstamp'];
    }

    if (!$first) {
        echo ',';
    }
    $first = false;

    echo json_encode($r, JSON_UNESCAPED_UNICODE);
}

// Abschluss JSON
echo '],"maxTstamp":'.$maxT.'}';
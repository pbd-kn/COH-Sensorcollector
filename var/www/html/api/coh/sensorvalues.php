<?php
declare(strict_types=1);

// ------------------------------------
// HEADERS + GZIP
// ------------------------------------
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/api_env.php';

// gzip Kompression (massiver Speed Boost bei vielen Daten)
if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
    ob_start('ob_gzhandler');
}

// ------------------------------------
// CONFIG
// ------------------------------------
$API_TOKEN = cohRequireApiToken();

$DB = [
  'host' => '127.0.0.1',
  'port' => 3306,
  'user' => 'peter',
  'pass' => 'sql666sql',
  'db'   => 'co5_solar',
];

// Harte Obergrenze fuer nicht verdichtete Alt-Clients.
$MAX_ROWS  = isset($_GET['bulk']) ? 100000 : 20000;
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
$latest = !empty($_GET['latest']);
$from = isset($_GET['from']) ? (int) $_GET['from'] : null;
$to = isset($_GET['to']) ? (int) $_GET['to'] : null;
$maxPoints = isset($_GET['maxPoints']) ? (int) $_GET['maxPoints'] : 0;
$maxPoints = max(0, min(500, $maxPoints));
$hasRange = $from !== null || $to !== null;
$requestedSensorIds = [];
foreach (explode(',', (string)($_GET['sensorIDs'] ?? '')) as $requestedSensorId) {
    $requestedSensorId = trim($requestedSensorId);
    if ($requestedSensorId !== '') $requestedSensorIds[$requestedSensorId] = true;
}
$now = time();

if ($hasRange) {
    if ($from === null || $to === null || $from < 0 || $to <= $from) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_range']);
        exit;
    }
    // Schutz gegen versehentlich extrem große Abfragen; ein Kalenderjahr ist erlaubt.
    if ($to - $from > 60 * 60 * 24 * 367) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'range_too_large']);
        exit;
    }
    $latest = false;
}

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
$sensorFilter = '';
if ($requestedSensorIds !== []) {
    $quotedSensorIds = array_map(
        static fn (string $id): string => "'" . $db->real_escape_string($id) . "'",
        array_keys($requestedSensorIds)
    );
    $sensorFilter = 's.sensorID IN (' . implode(',', $quotedSensorIds) . ')';
}

// Nur dann verdichten, wenn wenigstens ein angefragter Sensor tatsaechlich
// mehr als maxPoints Rohwerte besitzt. Kleine Datenmengen bleiben unveraendert.
$shouldAggregate = false;
if ($hasRange && $maxPoints > 0) {
    $countSql = "SELECT v.sensor
                   FROM tl_coh_sensorvalue v
                   JOIN tl_coh_sensors s ON s.id = v.sensor
                  WHERE v.tstamp >= ? AND v.tstamp < ?"
        . ($sensorFilter !== '' ? " AND $sensorFilter" : '') . "
                  GROUP BY v.sensor
                 HAVING COUNT(*) > ?
                  LIMIT 1";
    $countStmt = $db->prepare($countSql);
    $countStmt->bind_param('iii', $from, $to, $maxPoints);
    $countStmt->execute();
    $shouldAggregate = $countStmt->get_result()->num_rows > 0;
    $countStmt->close();
}

// ------------------------------------
// QUERY (OPTIMIERT!)
// ------------------------------------
// WICHTIG: nur ben�tigte Felder laden!
$select = "SELECT v.tstamp, s.sensorID, s.sensorTitle, s.sensorLokalId,
                  s.outputMode, v.sensorValue, e.text AS sensorEinheit,
                  t.text AS sensorValueType, s.sensorSource
             FROM tl_coh_sensorvalue v
             JOIN tl_coh_sensors s ON s.id = v.sensor
             JOIN tl_coh_sensoreinheiten e ON e.id = v.einheit
             JOIN tl_coh_sensortypen t ON t.id = v.sensorType";
if ($latest) {
    $sql = $select . "
             JOIN (
                 SELECT sensor, MAX(id) AS latestId
                   FROM tl_coh_sensorvalue
                  GROUP BY sensor
             ) newest ON newest.latestId = v.id
            " . ($sensorFilter !== '' ? "WHERE $sensorFilter" : '') . "
            ORDER BY s.sensorID";
    $stmt = $db->prepare($sql);
} elseif ($hasRange && $maxPoints > 0 && $shouldAggregate) {
    // Pro Sensor und Zeitfenster nur einen gemittelten Messpunkt liefern. Dadurch
    // werden grosse Zeitraeume bereits in der Datenbank auf etwa maxPoints
    // reduziert und nicht erst zum aufrufenden Webserver uebertragen.
    $bucketSeconds = max(1, (int) ceil(($to - $from) / $maxPoints));
    $sql = "SELECT MIN(v.tstamp) AS tstamp, s.sensorID, s.sensorTitle, s.sensorLokalId,
                   s.outputMode,
                   ROUND(AVG(CASE
                       WHEN v.sensorValue REGEXP '^-?[0-9]+([.][0-9]+)?$'
                       THEN CAST(v.sensorValue AS DECIMAL(20,6))
                       ELSE NULL
                   END), 6) AS sensorValue,
                   e.text AS sensorEinheit, t.text AS sensorValueType, s.sensorSource
              FROM tl_coh_sensorvalue v
              JOIN tl_coh_sensors s ON s.id = v.sensor
              JOIN tl_coh_sensoreinheiten e ON e.id = v.einheit
              JOIN tl_coh_sensortypen t ON t.id = v.sensorType
             WHERE v.tstamp >= ? AND v.tstamp < ?" . ($sensorFilter !== '' ? " AND $sensorFilter" : '') . "
             GROUP BY v.sensor, FLOOR((v.tstamp - ?) / ?),
                      s.sensorID, s.sensorTitle, s.sensorLokalId, s.outputMode,
                      e.text, t.text, s.sensorSource
            HAVING AVG(CASE
                       WHEN v.sensorValue REGEXP '^-?[0-9]+([.][0-9]+)?$'
                       THEN CAST(v.sensorValue AS DECIMAL(20,6))
                       ELSE NULL
                   END) IS NOT NULL
             ORDER BY tstamp ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('iiii', $from, $to, $from, $bucketSeconds);
} elseif ($hasRange) {
    $sql = $select . "
            WHERE v.tstamp >= ? AND v.tstamp < ?" . ($sensorFilter !== '' ? " AND $sensorFilter" : '') . "
            ORDER BY v.tstamp ASC, v.id ASC
            LIMIT " . (int)$MAX_ROWS;
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $from, $to);
} else {
    $sql = $select . "
            WHERE v.tstamp > ?" . ($sensorFilter !== '' ? " AND $sensorFilter" : '') . "
            ORDER BY v.tstamp ASC
            LIMIT " . (int)$MAX_ROWS;
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $since);
}
$stmt->execute();
$res = $stmt->get_result();

// ------------------------------------
// JSON STREAMING (ULTRA SCHNELL)
// ------------------------------------
echo '{"ok":true,"since":'.$since
    .',"from":'.($from === null ? 'null' : $from)
    .',"to":'.($to === null ? 'null' : $to)
    .',"rows":[';

$first = true;
$maxT = $since;

while ($r = $res->fetch_assoc()) {
    if ($requestedSensorIds !== [] && !isset($requestedSensorIds[(string)$r['sensorID']])) {
        continue;
    }

    // int cast f�r speed + sauber
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

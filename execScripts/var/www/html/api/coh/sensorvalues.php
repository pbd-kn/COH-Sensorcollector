<?php
declare(strict_types=1);

// html code zum liefern der werte
header('Content-Type: application/json; charset=utf-8');

$API_TOKEN = 'COH_CODE';   // diese code wird beim pull (sensorwert liefern abgefragt

$DB = [
  'host' => '127.0.0.1',
  'port' => 3306,
  'user' => 'peter',
  'pass' => 'sql666sql',
  'db'   => 'co5_solar',
];

$MAX_ROWS  = 20000;
$MAX_AGE_S = 60 * 60 * 24 * 30; // max 30 Tage

// ---- Auth (Token per Header oder Query) ----
$token = $_GET['token'] ?? '';
if ($token === '' && isset($_SERVER['HTTP_X_COH_TOKEN'])) {
  $token = (string) $_SERVER['HTTP_X_COH_TOKEN'];
}
if (!hash_equals($API_TOKEN, (string)$token)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

// ---- Input ----
$since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
$now = time();
if ($since < 0) $since = 0;
if ($since > $now) $since = $now;
if ($since < $now - $MAX_AGE_S) $since = $now - $MAX_AGE_S;

// ---- DB ----
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
if (!@$db->real_connect($DB['host'], $DB['user'], $DB['pass'], $DB['db'], $DB['port'])) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db_connect_failed']);
  exit;
}
$db->set_charset('utf8mb4');

$sql = "SELECT tstamp, sensorID, sensorValue, sensorEinheit, sensorValueType, sensorSource
        FROM tl_coh_sensorvalue
        WHERE tstamp > ?
        ORDER BY tstamp ASC
        LIMIT " . (int)$MAX_ROWS;

$stmt = $db->prepare($sql);
$stmt->bind_param('i', $since);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$maxT = $since;
while ($r = $res->fetch_assoc()) {
  $r['tstamp'] = (int)$r['tstamp'];
  if ($r['tstamp'] > $maxT) $maxT = $r['tstamp'];
  $rows[] = $r;
}

echo json_encode([
  'ok'        => true,
  'since'     => $since,
  'maxTstamp' => $maxT,
  'count'     => count($rows),
  'rows'      => $rows,
], JSON_UNESCAPED_UNICODE);

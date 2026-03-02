<?php
declare(strict_types=1);

// cod zum pus der tabellen
header('Content-Type: application/json; charset=utf-8');

$API_TOKEN = 'COH_CODE';

$DB = [
  'host' => '127.0.0.1',
  'port' => 3306,
  'user' => 'peter',
  'pass' => 'sql666sql',
  'db'   => 'co5_solar',
];

$allowedTables = ['tl_coh_sensors', 'tl_coh_cfgcollect', 'tl_coh_geraete'];

// ---- Auth ----
$token = $_GET['token'] ?? '';
if ($token === '' && isset($_SERVER['HTTP_X_COH_TOKEN'])) {
  $token = (string) $_SERVER['HTTP_X_COH_TOKEN'];
}
if (!hash_equals($API_TOKEN, (string)$token)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

// ---- JSON Body ----
$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_json']);
  exit;
}

$table = (string)($data['table'] ?? '');
$rows  = $data['rows'] ?? null;

if (!in_array($table, $allowedTables, true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'table_not_allowed']);
  exit;
}
if (!is_array($rows)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'rows_missing']);
  exit;
}

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

// Tabelle muss existieren (wir erwarten sie auf Raspi bereits)
$chk = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
if (!$chk || $chk->num_rows === 0) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'table_missing_on_raspi']);
  exit;
}

$db->begin_transaction();
$db->query("DELETE FROM `$table`");

$inserted = 0;
foreach ($rows as $r) {
  if (!is_array($r) || empty($r)) continue;

  $cols = array_keys($r);
  $vals = array_values($r);

  $colList = implode(',', array_map(fn($c) => '`' . str_replace('`','', (string)$c) . '`', $cols));
  $valList = implode(',', array_fill(0, count($vals), '?'));

  $types = '';
  $bind  = [];
  foreach ($vals as $v) {
    // pragmatisch alles als string, MariaDB castet passend
    $types .= 's';
    $bind[] = (string)$v;
  }

  $sql = "REPLACE INTO `$table` ($colList) VALUES ($valList)";
  $stmt = $db->prepare($sql);
  if (!$stmt) continue;

  $stmt->bind_param($types, ...$bind);
  if ($stmt->execute()) $inserted++;
  $stmt->close();
}

$db->commit();

echo json_encode(['ok' => true, 'table' => $table, 'inserted' => $inserted], JSON_UNESCAPED_UNICODE);

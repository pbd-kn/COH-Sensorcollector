<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$API_TOKEN = 'COH_CODE';

$DB = [
  'host' => '127.0.0.1',
  'port' => 3306,
  'user' => 'peter',
  'pass' => 'sql666sql',
  'db'   => 'co5_solar',
];

$allowedTables = ['tl_coh_sensors','tl_coh_cfgcollect','tl_coh_geraete'];


// ---------------- AUTH ----------------

$token = $_SERVER['HTTP_X_COH_TOKEN'] ?? ($_GET['token'] ?? '');

if (!hash_equals($API_TOKEN,$token)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
}


// ---------------- JSON ----------------

$raw = file_get_contents('php://input');

$data = json_decode($raw,true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
}

$table = $data['table'] ?? '';
$rows  = $data['rows'] ?? [];

if (!in_array($table,$allowedTables,true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'table_not_allowed']);
    exit;
}

if (!is_array($rows)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'rows_missing']);
    exit;
}


// ---------------- DB ----------------

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

$db = new mysqli(
    $DB['host'],
    $DB['user'],
    $DB['pass'],
    $DB['db'],
    $DB['port']
);

$db->set_charset('utf8mb4');


// vorhandene Spalten lesen

$existingCols = [];

$res = $db->query("SHOW COLUMNS FROM `$table`");

while ($c = $res->fetch_assoc()) {
    $existingCols[$c['Field']] = true;
}


// Tabelle leeren

$db->query("DELETE FROM `$table`");

$inserted = 0;


// ---------------- Insert ----------------

foreach ($rows as $r) {

    if (!is_array($r)) continue;

    // nur vorhandene Spalten verwenden
    $filtered = array_intersect_key($r,$existingCols);

    if (!$filtered) continue;

    $fields = array_keys($filtered);
    $values = array_values($filtered);

    $colList = '`'.implode('`,`',$fields).'`';
    $place   = implode(',',array_fill(0,count($values),'?'));

    $types = str_repeat('s',count($values));

    $sql = "REPLACE INTO `$table` ($colList) VALUES ($place)";

    $stmt = $db->prepare($sql);

    $stmt->bind_param($types,...$values);

    $stmt->execute();

    $stmt->close();

    $inserted++;
}


echo json_encode([
    'ok'=>true,
    'table'=>$table,
    'rows_received'=>count($rows),
    'inserted'=>$inserted
], JSON_UNESCAPED_UNICODE);

}
catch(Throwable $e){

http_response_code(500);

echo json_encode([
    'ok'=>false,
    'error'=>$e->getMessage()
]);

}
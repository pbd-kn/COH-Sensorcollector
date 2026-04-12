<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$API_TOKEN = 'COH_CODE';
$DB = [ 'host' => '127.0.0.1', 'port' => 3306, 'user' => 'peter', 'pass' => 'sql666sql','db'   => 'co5_solar', ];
$allowedTables = ['tl_coh_sensors','tl_coh_cfgcollect','tl_coh_geraete'];

// ---------------- AUTH ----------------
$token = $_SERVER['HTTP_X_COH_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals($API_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
}
// ---------------- JSON ----------------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
}
$table = $data['table'] ?? '';
$rows  = $data['rows'] ?? [];
if (!in_array($table, $allowedTables, true)) {
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
    $db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db'], $DB['port']);
    $db->set_charset('utf8mb4');
    // ---------------- Spalten lesen ----------------
    $existingCols = [];
    $res = $db->query("SHOW COLUMNS FROM `$table`");
    while ($c = $res->fetch_assoc()) {
        $existingCols[$c['Field']] = true;
    }
    // ---------------- protected fields ----------------
    $protectedFields = [];
    if ($table === 'tl_coh_sensors') {
        $protectedFields[] = 'historycount';
    }
    // ---------------- Insert / Update ----------------
    $inserted = 0;
    $updated  = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        // nur vorhandene Spalten
        $filtered = array_intersect_key($r, $existingCols);
        // protected Felder entfernen
        if ($protectedFields) {
            $filtered = array_diff_key($filtered, array_flip($protectedFields));
        }
        if (!$filtered) continue;
        $fields = array_keys($filtered);
        $values = array_values($filtered);
        $colList = '`'.implode('`,`', $fields).'`';
        $place   = implode(',', array_fill(0, count($values), '?'));
        $types   = str_repeat('s', count($values));
        // ---------------- SQL bauen ----------------
        $updateParts = [];
        foreach ($fields as $f) {
            $updateParts[] = "`$f`=VALUES(`$f`)";
        }
        $sql = "INSERT INTO `$table` ($colList)
                VALUES ($place)
                ON DUPLICATE KEY UPDATE " . implode(',', $updateParts);

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            $inserted++;
        } elseif ($stmt->affected_rows === 2) {
            $updated++;
        }
        $stmt->close();
    }

    echo json_encode([
        'ok'            => true,
        'table'         => $table,
        'rows_received' => count($rows),
        'inserted'      => $inserted,
        'updated'       => $updated
    ], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}
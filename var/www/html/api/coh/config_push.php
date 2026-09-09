<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/api_env.php';

$API_TOKEN = cohRequireApiToken();
$DB = [ 'host' => '127.0.0.1', 'port' => 3306, 'user' => 'peter', 'pass' => 'sql666sql','db'   => 'co5_solar', ];
$allowedTables = ['tl_coh_sensors','tl_coh_cfgcollect','tl_coh_geraete'];

// ---------------- AUTH ----------------
$token = $_SERVER['HTTP_X_COH_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals($API_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
}

// ---------------- CONFIG EXPORT ----------------
// Liefert ausschliesslich Geraete-, Sensor- und Collector-Konfigurationen. Sensorwerte
// sind absichtlich nicht Bestandteil dieses Endpunkts.
if ('GET' === ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db'], $DB['port']);
        $db->set_charset('utf8mb4');

        $fetchRows = static function (mysqli $db, string $tableName): array {
            $rows = [];
            $result = $db->query("SELECT * FROM `$tableName` ORDER BY id");
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            return $rows;
        };

        echo json_encode([
            'ok' => true,
            'devices' => $fetchRows($db, 'tl_coh_geraete'),
            'sensors' => $fetchRows($db, 'tl_coh_sensors'),
            'collectorConfig' => $fetchRows($db, 'tl_coh_cfgcollect'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }

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
$isConfigSnapshot = isset($data['devices'], $data['sensors'])
    && is_array($data['devices'])
    && is_array($data['sensors']);
$hasCollectorConfig = isset($data['collectorConfig']) && is_array($data['collectorConfig']);

if (!$isConfigSnapshot && !in_array($table, $allowedTables, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'table_not_allowed']);
    exit;
}
if (!$isConfigSnapshot && !is_array($rows)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'rows_missing']);
    exit;
}
// ---------------- DB ----------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db'], $DB['port']);
    $db->set_charset('utf8mb4');

    // Atomarer Komplettabgleich fuer den manuellen Contao-Backend-Button.
    // Messwerte werden dabei weder gelesen noch veraendert.
    if ($isConfigSnapshot) {
        $syncTable = static function (
            mysqli $db,
            string $tableName,
            array $tableRows,
            string $identityField,
            array $protectedFields = []
        ): array {
            $existingCols = [];
            $columnResult = $db->query("SHOW COLUMNS FROM `$tableName`");
            while ($column = $columnResult->fetch_assoc()) {
                $existingCols[$column['Field']] = true;
            }

            $receivedIdentities = [];
            $inserted = 0;
            $updated = 0;

            foreach ($tableRows as $row) {
                if (!is_array($row) || !isset($row[$identityField]) || '' === trim((string) $row[$identityField])) {
                    throw new RuntimeException("Ungueltiger Datensatz fuer $tableName: $identityField fehlt");
                }

                $receivedIdentities[] = (string) $row[$identityField];
                $filtered = array_intersect_key($row, $existingCols);
                $filtered = array_diff_key($filtered, array_flip($protectedFields));
                $fields = array_keys($filtered);
                $values = array_values($filtered);
                $columnList = '`'.implode('`,`', $fields).'`';
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $types = str_repeat('s', count($values));
                $updateParts = [];

                foreach ($fields as $field) {
                    $updateParts[] = "`$field`=VALUES(`$field`)";
                }

                $statement = $db->prepare(
                    "INSERT INTO `$tableName` ($columnList) VALUES ($placeholders) "
                    .'ON DUPLICATE KEY UPDATE '.implode(',', $updateParts)
                );
                $statement->bind_param($types, ...$values);
                $statement->execute();

                if (1 === $statement->affected_rows) {
                    ++$inserted;
                } elseif (2 === $statement->affected_rows) {
                    ++$updated;
                }

                $statement->close();
            }

            if ([] === $receivedIdentities) {
                $db->query("DELETE FROM `$tableName`");
            } else {
                $escaped = array_map(
                    static fn (string $value): string => "'".$db->real_escape_string($value)."'",
                    array_values(array_unique($receivedIdentities))
                );
                $db->query(
                    "DELETE FROM `$tableName` WHERE `$identityField` NOT IN (".implode(',', $escaped).')'
                );
            }

            return ['received' => count($tableRows), 'inserted' => $inserted, 'updated' => $updated];
        };

        $db->begin_transaction();

        $deviceResult = $syncTable($db, 'tl_coh_geraete', $data['devices'], 'geraeteID');
        $sensorResult = $syncTable(
            $db,
            'tl_coh_sensors',
            $data['sensors'],
            'sensorID',
            ['historycount', 'lastUpdated', 'pollInterval', 'lastValue', 'lastError']
        );
        $collectorConfigResult = $hasCollectorConfig
            ? $syncTable($db, 'tl_coh_cfgcollect', $data['collectorConfig'], 'cfgID')
            : ['received' => 0, 'inserted' => 0, 'updated' => 0];

        $db->commit();

        echo json_encode([
            'ok' => true,
            'devices' => $deviceResult,
            'sensors' => $sensorResult,
            'collectorConfig' => $collectorConfigResult,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
    $db->begin_transaction();

    // Beim Sensor-Push sendet der Master nur aktive Sensoren.
    // Die Raspi-Konfiguration wird deshalb komplett durch diese Liste ersetzt.
    if ($table === 'tl_coh_sensors') {
        $db->query("DELETE FROM `$table`");
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

    $db->commit();

    echo json_encode([
        'ok'            => true,
        'table'         => $table,
        'rows_received' => count($rows),
        'inserted'      => $inserted,
        'updated'       => $updated
    ], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e) {
    if (isset($db) && $db instanceof mysqli) {
        try {
            $db->rollback();
        } catch (Throwable $rollbackError) {
        }
    }

    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}

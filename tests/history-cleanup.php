<?php
declare(strict_types=1);

require_once __DIR__.'/../sensorCollect/autoload.php';

use PbdKn\cohSensorcollector\SensorHistoryCleanup;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$now = new DateTimeImmutable('2026-11-01 06:00:00', new DateTimeZone('Europe/Berlin'));
$cutoff = SensorHistoryCleanup::cutoff($now)->getTimestamp();
check(SensorHistoryCleanup::cutoff($now)->format('Y-m-d H:i:s') === '2026-08-01 00:00:00', 'Calendar cutoff');
check(SensorHistoryCleanup::cutoff(new DateTimeImmutable('2027-01-31'))->format('Y-m-d') === '2026-10-01', 'Year rollover');

// All mutations are confined to a newly created, randomly named test database.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('COH_TEST_DB_HOST') ?: '127.0.0.1', getenv('COH_TEST_DB_USER') ?: 'root', getenv('COH_TEST_DB_PASSWORD') ?: '', '', (int)(getenv('COH_TEST_DB_PORT') ?: 3306));
$database = 'coh_cleanup_test_'.bin2hex(random_bytes(8));
$db->query("CREATE DATABASE `$database`");
try {
    $db->select_db($database);
    $db->query('CREATE TABLE tl_coh_sensorvalue (id bigint unsigned PRIMARY KEY, sensor int unsigned NOT NULL, tstamp int unsigned NOT NULL, sensorValue text) ENGINE=InnoDB');
    $hour = intdiv($cutoff - 7200, 3600) * 3600;
    $rows = [
        [1, 1, $hour, 'first'], [2, 1, $hour + 120, 'second'],
        [3, 1, $hour + 120, 'tie winner'], [4, 2, $hour, 'other sensor'],
        [5, 1, $hour + 3600, 'next hour'], [6, 1, $cutoff, 'boundary'],
        [7, 1, $cutoff + 120, 'recent'], [8, 3, $hour, 'old current snapshot'],
    ];
    $stmt = $db->prepare('INSERT INTO tl_coh_sensorvalue VALUES (?, ?, ?, ?)');
    foreach ($rows as [$id, $sensor, $stamp, $value]) {
        $stmt->bind_param('iiis', $id, $sensor, $stamp, $value);
        $stmt->execute();
    }
    $cleanup = new SensorHistoryCleanup();
    $preview = $cleanup->run($db, true, $now);
    check(array_sum(array_column($preview['sensors'], 'remove_count')) === 2, 'Dry run counts');
    check((int)$db->query('SELECT COUNT(*) FROM tl_coh_sensorvalue')->fetch_row()[0] === 8, 'Dry run changed rows');
    check($db->query("SHOW TABLES LIKE 'tl_coh_history_cleanup'")->num_rows === 0, 'Dry run created persistent state');
    $result = $cleanup->run($db, false, $now);
    check($result['deleted'] === 2, 'Deleted count');
    $remaining = array_map('intval', array_column($db->query('SELECT id FROM tl_coh_sensorvalue ORDER BY id')->fetch_all(MYSQLI_ASSOC), 'id'));
    check($remaining === [3, 4, 5, 6, 7, 8], 'Wrong readings preserved');
    check($cleanup->run($db, false, $now)['status'] === 'already_done', 'In-process monthly guard');
    check((new SensorHistoryCleanup())->run($db, false, $now)['status'] === 'already_done', 'Restart monthly guard');
    check((new SensorHistoryCleanup())->run($db, false, $now->modify('+1 month'))['status'] === 'completed', 'Next month');
    // More than one deletion batch, and rerun after a simulated interruption.
    $db->query('DELETE FROM tl_coh_history_cleanup');
    for ($i = 100; $i < 2205; $i++) {
        $sensor = 4;
        $stamp = $hour + 1;
        $value = 'batch';
        $stmt->bind_param('iiis', $i, $sensor, $stamp, $value);
        $stmt->execute();
    }
    check((new SensorHistoryCleanup())->run($db, false, $now)['deleted'] === 2104, 'Multiple batches');
    $db->query('DELETE FROM tl_coh_history_cleanup');
    check((new SensorHistoryCleanup())->run($db, false, $now)['deleted'] === 0, 'Idempotent retry');
    echo "History cleanup tests passed.\n";
} finally {
    $db->select_db('information_schema');
    $db->query("DROP DATABASE `$database`");
    $db->close();
}

<?php
declare(strict_types=1);
require_once __DIR__.'/../var/www/html/api/coh/counter_history.php';
$tz = new DateTimeZone('Europe/Berlin');
function ts(string $date): int { return (new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin')))->getTimestamp(); }
function row(string $date, float $value): array { return ['tstamp' => ts($date), 'sensorValue' => $value, 'sensorID' => 'test', 'outputMode' => 'counter']; }
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$start = ts('2026-09-01 00:00');
$base = row('2026-08-31 23:55', 100);
$result = cohCounterBuckets([row('2026-09-01 01:00', 102), row('2026-09-01 01:30', 103), row('2026-09-01 01:45', 1)], $base, $start, $start + 10800, 'day', $tz);
check(array_column($result, 'sensorValue') === [0.0, 2.0, 4.0, 4.0], 'Daily cumulative values, reset and unchanged bucket');
check(array_column($result, 'tstamp') === [$start, $start + 3600, $start + 7200, $start + 10800], 'Daily values at interval ends');
check($result[0]['historyAggregation'] === 'counter', 'Aggregation marker');
$result = cohCounterBuckets([row('2026-09-01 01:30', 103), row('2026-09-01 02:30', 105)], null, $start, $start + 10800, 'day', $tz);
check(array_column($result, 'sensorValue') === [null, null, null, null], 'Unknown midnight baseline must stay a gap');
foreach (['2026-03-29' => 23, '2026-10-25' => 25] as $date => $hours) {
    $from = ts($date);
    $to = (new DateTimeImmutable($date, $tz))->modify('+1 day')->getTimestamp();
    $result = cohCounterBuckets([], $base, $from, $to, 'day', $tz);
    check(count($result) === $hours + 1, 'DST hour count plus midnight baseline');
    check(count(array_unique(array_column($result, 'tstamp'))) === $hours + 1, 'DST timestamps unique');
}
foreach (['week', 'month'] as $unit) {
    $result = cohCounterBuckets([row('2026-09-02 00:00', 110)], $base, $start, ts('2026-09-03'), $unit, $tz);
    check(array_column($result, 'sensorValue') === [10.0, 0.0], 'Daily buckets');
}
$result = cohCounterBuckets([row('2026-10-01', 120)], $base, $start, ts('2026-11-01'), 'year', $tz);
check(array_column($result, 'sensorValue') === [20.0, 0.0], 'Calendar months');
check(cohCounterBuckets([], null, $start, $start + 3600, 'day', $tz) === [], 'No history');
check(cohCounterBuckets([], $base, $start, $start - 1, 'day', $tz) === [], 'Future range');
require_once __DIR__.'/../sensorCollect/SensorManager.php';
$managerClass = new ReflectionClass(\PbdKn\cohSensorcollector\SensorManager::class);
$manager = $managerClass->newInstanceWithoutConstructor();
$apply = $managerClass->getMethod('applyOutputModes');
$input = ['test' => ['sensorID' => 'test', 'sensorValue' => 1250.5]];
foreach (['absolute', 'counter', ''] as $mode) {
    check($apply->invoke($manager, $input, [['sensorID' => 'test', 'outputMode' => $mode]]) === $input, 'Collector preserves absolute readings: '.$mode);
}
echo "Counter history tests passed.\n";

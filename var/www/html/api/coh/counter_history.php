<?php
declare(strict_types=1);

/**
 * Calendar buckets from ordered, absolute readings. A reading on a boundary
 * closes the preceding bucket. Missing readings carry the last known value;
 * increments are attributed to the interval in which they were observed.
 * A decrease is treated as a reset to zero (new reading = increment).
 */
function cohCounterBuckets(iterable $readings, ?array $baseline, int $from, int $to, string $unit, DateTimeZone $timezone): array
{
    $buckets = [];
    for ($start = $from; $start < $to; $start = $end) {
        $date = (new DateTimeImmutable('@'.$start))->setTimezone($timezone);
        // Elapsed hours preserve both occurrences of an hour at the DST change.
        $end = $unit === 'day' ? $start + 3600 : $date->modify($unit === 'year' ? '+1 month' : '+1 day')->getTimestamp();
        $buckets[] = ['start' => $start, 'end' => min($end, $to), 'sum' => 0.0, 'unknown' => false];
    }
    if ($buckets === []) return [];
    $previous = $baseline === null ? null : (float) $baseline['sensorValue'];
    $metadata = $baseline;
    $index = 0;
    $buckets[0]['unknown'] = $previous === null;
    foreach ($readings as $row) {
        if (!is_numeric($row['sensorValue'] ?? null) || (float) $row['sensorValue'] < 0) continue;
        $timestamp = (int) $row['tstamp'];
        if ($timestamp <= $from || $timestamp > $to) continue;
        while ($index < count($buckets) - 1 && $timestamp > $buckets[$index]['end']) {
            ++$index;
            $buckets[$index]['unknown'] = $previous === null;
        }
        $current = (float) $row['sensorValue'];
        if ($previous !== null) {
            $buckets[$index]['sum'] += $current >= $previous ? $current - $previous : $current;
        }
        $previous = $current;
        $metadata = $row;
    }
    if ($metadata === null) return [];
    for ($i = $index + 1; $i < count($buckets); ++$i) {
        $buckets[$i]['unknown'] = $previous === null;
    }
    if ($unit === 'day') {
        // Daily line: accumulated since midnight, located at interval ends.
        // Without a midnight baseline the daily total cannot be established.
        $row = $metadata;
        $row['tstamp'] = $from;
        $row['sensorValue'] = $baseline === null ? null : 0.0;
        $row['historyAggregation'] = 'counter';
        $result = [$row];
        $total = 0.0;
        foreach ($buckets as $bucket) {
            $total += $bucket['sum'];
            $row['tstamp'] = $bucket['end'];
            $row['sensorValue'] = $baseline === null ? null : round($total, 6);
            $result[] = $row;
        }
        return $result;
    }
    return array_map(static function (array $bucket) use ($metadata): array {
        $row = $metadata;
        $row['tstamp'] = $bucket['start'];
        $row['sensorValue'] = $bucket['unknown'] ? null : round($bucket['sum'], 6);
        $row['historyAggregation'] = 'counter';
        return $row;
    }, $buckets);
}

/** Query counters separately, before AVG/downsampling, including their baseline. */
function cohFetchCounterHistory(mysqli $db, string $select, string $sensorFilter, int $from, int $to, string $unit, DateTimeZone $timezone): array
{
    $result = $db->query("SELECT s.id FROM tl_coh_sensors s WHERE s.outputMode = 'counter'" . ($sensorFilter === '' ? '' : ' AND '.$sensorFilter));
    if (!$result) throw new RuntimeException('Counter sensor query failed.');
    $ids = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $rows = [];
    foreach ($ids as $sensor) {
        $id = (int) $sensor['id'];
        $numeric = " AND v.sensorValue REGEXP '^[0-9]+([.][0-9]+)?$'";
        $base = $db->query($select." WHERE v.sensor = $id AND v.tstamp <= $from".$numeric.' ORDER BY v.tstamp DESC, v.id DESC LIMIT 1');
        if (!$base) throw new RuntimeException('Counter baseline query failed.');
        $baseline = $base->fetch_assoc();
        $base->free();
        // Stream all raw readings; never truncate a counter to MAX_ROWS.
        $values = $db->query($select." WHERE v.sensor = $id AND v.tstamp > $from AND v.tstamp <= $to".$numeric.' ORDER BY v.tstamp, v.id', MYSQLI_USE_RESULT);
        if (!$values) throw new RuntimeException('Counter history query failed.');
        $readings = (static function () use ($values): Generator {
            while ($row = $values->fetch_assoc()) yield $row;
        })();
        array_push($rows, ...cohCounterBuckets($readings, $baseline, $from, $to, $unit, $timezone));
        $values->free();
    }
    return $rows;
}

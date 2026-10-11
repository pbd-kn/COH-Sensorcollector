<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector;

/** Monthly retention: preserve the newest original reading per sensor/UTC hour. */
final class SensorHistoryCleanup
{
    private ?string $completedMonth = null;

    public static function cutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone(new \DateTimeZone('Europe/Berlin'))
            ->modify('first day of this month')->setTime(0, 0)->modify('-3 months');
    }

    /** Dry runs only read source data and use connection-local temporary tables. */
    public function run(\mysqli $db, bool $dryRun = false, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $month = $now->format('Y-m');
        $cutoff = self::cutoff($now)->getTimestamp();
        if (!$dryRun && $this->completedMonth === $month) {
            return ['status' => 'already_done'];
        }

        $query = static function (string $sql) use ($db): \mysqli_result|bool {
            $result = $db->query($sql);
            if ($result === false) {
                throw new \RuntimeException('History cleanup: '.$db->error);
            }
            return $result;
        };
        $lockName = 'coh_history_cleanup_'.substr(hash('sha256', (string)$query('SELECT DATABASE() AS db')->fetch_assoc()['db']), 0, 20);
        $locked = (int)$query("SELECT GET_LOCK('$lockName', 0) AS acquired")->fetch_assoc()['acquired'] === 1;
        if (!$locked) {
            return ['status' => 'busy'];
        }

        try {
            if (!$dryRun) {
                $query("CREATE TABLE IF NOT EXISTS tl_coh_history_cleanup (
                    month char(7) NOT NULL PRIMARY KEY,
                    cutoff int unsigned NOT NULL,
                    deleted bigint unsigned NOT NULL DEFAULT 0,
                    completed_at int unsigned NOT NULL
                ) ENGINE=InnoDB");
                if ($query("SELECT month FROM tl_coh_history_cleanup WHERE month='$month'")->num_rows > 0) {
                    $this->completedMonth = $month;
                    return ['status' => 'already_done'];
                }
            }

            // No window functions required. Timestamp decides first, id breaks ties.
            $query('DROP TEMPORARY TABLE IF EXISTS coh_history_keep');
            $query('DROP TEMPORARY TABLE IF EXISTS coh_history_remove');
            $query('DROP TEMPORARY TABLE IF EXISTS coh_history_batch');
            $query('CREATE TEMPORARY TABLE coh_history_keep (id bigint unsigned PRIMARY KEY) ENGINE=InnoDB');
            $query("INSERT INTO coh_history_keep (id)
                SELECT MAX(v.id)
                FROM tl_coh_sensorvalue v
                JOIN (
                    SELECT sensor, FLOOR(tstamp / 3600) AS hour_bucket, MAX(tstamp) AS newest
                    FROM tl_coh_sensorvalue WHERE tstamp < $cutoff
                    GROUP BY sensor, FLOOR(tstamp / 3600)
                ) h ON h.sensor=v.sensor AND h.newest=v.tstamp
                GROUP BY v.sensor, h.hour_bucket");
            $query('CREATE TEMPORARY TABLE coh_history_remove (
                id bigint unsigned PRIMARY KEY, tstamp int unsigned NOT NULL,
                sensor int unsigned NOT NULL
            ) ENGINE=InnoDB');
            $query("INSERT INTO coh_history_remove (id, tstamp, sensor)
                SELECT v.id, v.tstamp, v.sensor FROM tl_coh_sensorvalue v
                LEFT JOIN coh_history_keep k ON k.id=v.id
                WHERE v.tstamp < $cutoff AND k.id IS NULL");

            $stats = $query("SELECT v.sensor, COUNT(*) AS old_count,
                    SUM(k.id IS NOT NULL) AS keep_count,
                    SUM(k.id IS NULL) AS remove_count
                FROM tl_coh_sensorvalue v LEFT JOIN coh_history_keep k ON k.id=v.id
                WHERE v.tstamp < $cutoff GROUP BY v.sensor ORDER BY v.sensor")->fetch_all(MYSQLI_ASSOC);
            $deleted = 0;
            if (!$dryRun) {
                $query('CREATE TEMPORARY TABLE coh_history_batch (
                    id bigint unsigned PRIMARY KEY, tstamp int unsigned NOT NULL
                ) ENGINE=InnoDB');
                while (true) {
                    $query('DELETE FROM coh_history_batch');
                    $query('INSERT INTO coh_history_batch (id, tstamp)
                        SELECT id, tstamp FROM coh_history_remove ORDER BY id LIMIT 1000');
                    if ($db->affected_rows === 0) {
                        break;
                    }
                    // A concurrently refreshed snapshot must never be deleted.
                    $query('DELETE v FROM tl_coh_sensorvalue v
                        JOIN coh_history_batch b ON b.id=v.id AND b.tstamp=v.tstamp');
                    $deleted += $db->affected_rows;
                    $query('DELETE r FROM coh_history_remove r JOIN coh_history_batch b ON b.id=r.id');
                }
                $finished = time();
                $query("INSERT INTO tl_coh_history_cleanup (month, cutoff, deleted, completed_at)
                    VALUES ('$month', $cutoff, $deleted, $finished)");
                $this->completedMonth = $month;
            }
            return [
                'status' => $dryRun ? 'dry_run' : 'completed',
                'month' => $month,
                'cutoff' => self::cutoff($now)->format('Y-m-d H:i:s T'),
                'deleted' => $deleted,
                'sensors' => $stats,
            ];
        } finally {
            // Always release the lock, including after SQL errors or partial runs.
            try {
                $query('DROP TEMPORARY TABLE IF EXISTS coh_history_batch, coh_history_remove, coh_history_keep');
            } finally {
                $query("SELECT RELEASE_LOCK('$lockName')");
            }
        }
    }
}

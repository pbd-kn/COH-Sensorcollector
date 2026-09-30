<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor\Km271;

use InvalidArgumentException;
use RuntimeException;

/** Custom timer registers, as documented by FHEM's 00_KM271 (hk1_timer/hk2_timer). */
final class Km271Schedule
{
    public const PROGRAMS = ['Eigen', 'Familie', 'Früh', 'Spät', 'Vormittag', 'Nachmittag', 'Mittag', 'Single', 'Senior'];
    private array $blocks = [];
    private ?int $program = null;

    public function __construct(private readonly int $circuit)
    {
        if (!in_array($circuit, [1, 2], true)) {
            throw new InvalidArgumentException('Heizkreis muss 1 oder 2 sein.');
        }
    }

    public function consume(string $payload): void
    {
        if (strlen($payload) < 3) return;
        $data = array_values(unpack('C*', $payload));
        $address = ($data[0] << 8) | $data[1];
        $base = $this->circuit === 1 ? 0x0100 : 0x0169;
        if ($address === $base && isset(self::PROGRAMS[$data[2]])) $this->program = $data[2];
        $offset = $address - $base;
        if (strlen($payload) >= 8 && $offset >= 7 && $offset <= 98 && $offset % 7 === 0) {
            $this->blocks[intdiv($offset, 7) - 1] = array_slice($data, 2, 6);
        }
    }

    public function complete(): bool
    {
        return $this->program !== null && count($this->blocks) === 14;
    }

    public function snapshot(): array
    {
        if (!$this->complete()) throw new RuntimeException('Schaltzeiten unvollständig empfangen. Bitte erneut laden; es wird nichts geschrieben.');
        ksort($this->blocks);
        $blocks = array_values($this->blocks);
        $bytes = array_merge(...$blocks);
        $intervals = [];
        for ($slot = 0; $slot < 21; ++$slot) {
            $on = $this->decodePoint($bytes[$slot * 4], $bytes[$slot * 4 + 1]);
            $off = $this->decodePoint($bytes[$slot * 4 + 2], $bytes[$slot * 4 + 3]);
            if ($on === null && $off === null) {
                $intervals[] = null;
            } elseif ($on === null || $off === null || $on['mode'] !== 1 || $off['mode'] !== 0) {
                throw new RuntimeException('Schaltpunktfolge in Speicherplatz ' . ($slot + 1) . ' ist kein Tag-/Nacht-Paar. Keine automatische Umdeutung; bitte am Regler prüfen.');
            } else {
                $intervals[] = ['onDay' => $on['day'], 'onTime' => $on['time'], 'offDay' => $off['day'], 'offTime' => $off['time']];
            }
        }
        return ['circuit' => $this->circuit, 'program' => self::PROGRAMS[$this->program], 'blocks' => $blocks,
            'intervals' => $intervals, 'version' => self::version($this->circuit, $this->program, $blocks), 'readAt' => date(DATE_ATOM)];
    }

    public function read(Protocol3964R $protocol, float $seconds = 120): array
    {
        // A fresh log cycle is mandatory; never mix a persistent cache with a write snapshot.
        $this->blocks = [];
        $this->program = null;
        $protocol->receive(1.0);
        $protocol->startLogMode(4.0);
        $deadline = microtime(true) + $seconds;
        while (!$this->complete() && ($remaining = $deadline - microtime(true)) > 0) {
            $payload = $protocol->receive(min(4.0, $remaining));
            if ($payload !== null) $this->consume($payload);
        }
        return $this->snapshot();
    }

    public function preview(array $base, array $intervals): array
    {
        $this->validateBase($base);
        if (count($intervals) !== 21 || !array_is_list($intervals)) throw new InvalidArgumentException('Genau 21 Speicherplätze erwartet.');
        $bytes = [];
        $ranges = [];
        $normalized = [];
        $lastStart = -1;
        foreach ($intervals as $index => $row) {
            if ($row === null) {
                array_push($bytes, 0xC2, 0x90, 0xC2, 0x90);
                $normalized[] = null;
                continue;
            }
            if (!is_array($row)) throw new InvalidArgumentException('Ungültiges Zeitintervall.');
            [$onDay, $onTick] = $this->encodePoint($row['onDay'] ?? null, $row['onTime'] ?? null);
            [$offDay, $offTick] = $this->encodePoint($row['offDay'] ?? null, $row['offTime'] ?? null);
            $start = $onDay * 144 + $onTick;
            $end = $offDay * 144 + $offTick;
            if ($start <= $lastStart) throw new InvalidArgumentException('Intervalle nach Beginn aufsteigend eintragen (Montag bis Sonntag). Zeile ' . ($index + 1) . ' liegt vor der vorherigen Heizzeit.');
            $lastStart = $start;
            if ($start === $end) throw new InvalidArgumentException('Beginn und Ende in Zeile ' . ($index + 1) . ' dürfen nicht gleich sein.');
            foreach ($end > $start ? [[$start, $end]] : [[$start, 1008], [0, $end]] as [$a, $b]) {
                foreach ($ranges as [$c, $d]) {
                    if ($a < $d && $b > $c) throw new InvalidArgumentException('Heizzeiten überschneiden sich (Zeile ' . ($index + 1) . ').');
                }
                $ranges[] = [$a, $b];
            }
            array_push($bytes, ($onDay << 5) | 1, $onTick, $offDay << 5, $offTick);
            $normalized[] = ['onDay' => $onDay, 'onTime' => $row['onTime'], 'offDay' => $offDay, 'offTime' => $row['offTime']];
        }
        // Slots retain their order. Each command replaces three points, including unchanged neighbours.
        $blocks = array_chunk($bytes, 6);
        $commands = [];
        foreach ($blocks as $index => $block) {
            if ($block !== $base['blocks'][$index]) $commands[] = array_merge([0x10 + $this->circuit, ($index + 1) * 7], $block);
        }
        return ['circuit' => $this->circuit, 'intervals' => $normalized, 'blocks' => $blocks,
            'commands' => $commands, 'changedBlocks' => count($commands), 'base' => $base,
            'targetProgram' => 'Eigen', 'activate' => $base['program'] !== 'Eigen'];
    }

    public function write(Protocol3964R $protocol, array $plan): array
    {
        $current = $this->read($protocol);
        if (!hash_equals($plan['base']['version'], $current['version'])) {
            throw new RuntimeException('Programm oder Schaltzeiten wurden inzwischen geändert. Neu laden und erneut prüfen; nichts geschrieben.');
        }
        $sent = 0;
        try {
            foreach ($plan['commands'] as $command) {
                $protocol->send(pack('C*', ...$command));
                ++$sent;
            }
            // Activate only after all timer blocks have been acknowledged. Operating mode stays unchanged.
            if ($plan['activate']) {
                $protocol->send(pack('C*', 0x10 + $this->circuit, 0, 0, 0x65, 0x65, 0x65, 0x65, 0x65));
            }
        } catch (\Throwable $error) {
            throw new RuntimeException("Übertragung abgebrochen: $sent Schaltzeit-Blöcke bestätigt. Der letzte Auftrag kann bereits angekommen sein. Programm möglicherweise teilweise geändert; vor erneutem Speichern neu einlesen. " . $error->getMessage(), 0, $error);
        }
        return ['confirmedBlocks' => $sent, 'program' => 'Eigen', 'sentAt' => date(DATE_ATOM),
            'status' => 'Übertragung vom KM271 bestätigt. Speicherung noch nicht durch erneutes Auslesen geprüft.'];
    }

    private function decodePoint(int $dayMode, int $tick): ?array
    {
        if ($dayMode === 0xC2 && $tick === 0x90) return null;
        $day = $dayMode >> 5;
        $mode = $dayMode & 0x1F;
        if ($day > 6 || $mode > 1 || $tick > 143) throw new RuntimeException('Unbekannter Schaltpunkt empfangen. Bearbeitung gesperrt.');
        return ['day' => $day, 'mode' => $mode, 'time' => sprintf('%02d:%02d', intdiv($tick, 6), ($tick % 6) * 10)];
    }

    private function encodePoint(mixed $day, mixed $time): array
    {
        if (!is_int($day) || $day < 0 || $day > 6 || !is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5]0$/D', $time)) {
            throw new InvalidArgumentException('Wochentag und Uhrzeit in 10-Minuten-Schritten (00:00–23:50) angeben.');
        }
        return [$day, ((int) substr($time, 0, 2)) * 6 + intdiv((int) substr($time, 3, 2), 10)];
    }

    private function validateBase(array $base): void
    {
        $program = array_search($base['program'] ?? null, self::PROGRAMS, true);
        $blocks = $base['blocks'] ?? null;
        if (($base['circuit'] ?? null) !== $this->circuit || $program === false || !is_array($blocks) || !array_is_list($blocks) || count($blocks) !== 14) {
            throw new InvalidArgumentException('Vollständiger Ausgangsstand fehlt. Schaltzeiten neu laden.');
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || !array_is_list($block) || count($block) !== 6) throw new InvalidArgumentException('Ungültiger Ausgangsblock.');
            foreach ($block as $byte) if (!is_int($byte) || $byte < 0 || $byte > 255) throw new InvalidArgumentException('Ungültiges Ausgangsbyte.');
        }
        if (!is_string($base['version'] ?? null) || !hash_equals(self::version($this->circuit, $program, $blocks), $base['version'])) {
            throw new InvalidArgumentException('Ausgangsstand ist inkonsistent. Schaltzeiten neu laden.');
        }
    }

    private static function version(int $circuit, int $program, array $blocks): string
    {
        return hash('sha256', json_encode([$circuit, $program, $blocks], JSON_THROW_ON_ERROR));
    }
}

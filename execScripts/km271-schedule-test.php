<?php
declare(strict_types=1);

// Loopback sockets only: no connection to a heater.
require __DIR__ . '/km271-protocol-test.php';
require_once __DIR__ . '/../sensorCollect/autoload.php';

use PbdKn\cohSensorcollector\Sensor\Km271\Km271Schedule;
use PbdKn\cohSensorcollector\Sensor\Km271\Protocol3964R as ScheduleProtocol;
use PbdKn\cohSensorcollector\Sensor\Km271\Km271WriteCommandEncoder;
use PbdKn\cohSensorcollector\Sensor\Km271\Km271Decoder as ScheduleDecoder;
use PbdKn\cohSensorcollector\Sensor\Km271\Km271ConnectionLock;

function schedulePayloads(int $circuit, int $program = 1, ?array $blocks = null): array
{
    $base = $circuit === 1 ? 0x0100 : 0x0169;
    $payloads = [pack('C*', $base >> 8, $base & 255, $program, 0, 0, 0)];
    for ($i = 0; $i < 14; ++$i) {
        $address = $base + ($i + 1) * 7;
        $payloads[] = pack('C*', $address >> 8, $address & 255, ...($blocks[$i] ?? [0xC2, 0x90, 0xC2, 0x90, 0xC2, 0x90]));
    }
    return $payloads;
}

function snapshot(int $circuit = 1, int $program = 1, ?array $blocks = null): array
{
    $schedule = new Km271Schedule($circuit);
    foreach (schedulePayloads($circuit, $program, $blocks) as $payload) $schedule->consume($payload);
    return $schedule->snapshot();
}

function reject(callable $call, string $label): void
{
    try { $call(); } catch (InvalidArgumentException|RuntimeException $error) { return; }
    throw new RuntimeException('Nicht abgelehnt: ' . $label);
}

$schedule = new Km271Schedule(1);
$base = snapshot();
$rows = $base['intervals'];
assertSame(21, count($rows), '21 Intervalle');
assertSame(0, $schedule->preview($base, $rows)['changedBlocks'], 'Keine unnötigen Schreibbefehle');
$rows[1] = ['onDay' => 0, 'onTime' => '06:30', 'offDay' => 0, 'offTime' => '08:20'];
$plan = $schedule->preview($base, $rows);
assertSame([[0x11, 7, 0xC2, 0x90, 0xC2, 0x90, 1, 39], [0x11, 14, 0, 50, 0xC2, 0x90, 0xC2, 0x90]], $plan['commands'], 'Intervall über Blockgrenze, Nachbarn erhalten');
assertSame($rows, snapshot(1, 0, $plan['blocks'])['intervals'], 'Codierung/Dekodierung');
$deletePlan = $schedule->preview(snapshot(1, 0, $plan['blocks']), array_fill(0, 21, null));
assertSame(2, $deletePlan['changedBlocks'], 'Löschen über Blockgrenze');

$hk2 = new Km271Schedule(2);
$rows2 = snapshot(2)['intervals'];
$rows2[20] = ['onDay' => 6, 'onTime' => '23:00', 'offDay' => 0, 'offTime' => '01:00'];
$p2 = $hk2->preview(snapshot(2), $rows2);
assertSame([0x12, 98, 0xC2, 0x90, 0xC1, 138, 0, 6], $p2['commands'][0], 'HK2 letzter Speicherplatz und Wochenwechsel');

$bad = $rows;
$bad[2] = ['onDay' => 0, 'onTime' => '07:00', 'offDay' => 0, 'offTime' => '09:00'];
reject(fn () => $schedule->preview($base, $bad), 'Überlappung');
$bad[2] = null;
$bad[1]['onTime'] = '06:35';
reject(fn () => $schedule->preview($base, $bad), 'Minutenraster');
$bad[1]['onTime'] = '24:00';
reject(fn () => $schedule->preview($base, $bad), '24 Uhr');
$bad[1]['onTime'] = '08:20';
reject(fn () => $schedule->preview($base, $bad), 'Identische Zeitpunkte');
reject(fn () => $schedule->preview(snapshot(2), $rows), 'Falscher Heizkreis');
reject(fn () => (new Km271Schedule(1))->snapshot(), 'Unvollständiges Lesen');
$corrupt = $base;
$corrupt['blocks'][0][0] = 256;
reject(fn () => $schedule->preview($corrupt, $rows), 'Ungültiges Byte');

// Use the production 3964R implementation with preloaded loopback responses.
function runWrite(array $plan, int $currentProgram, string $responses): array
{
    [$receiver, $sender] = socketPair();
    $wire = frame("\x88\x2B\x30") . "\x10\x10";
    foreach (schedulePayloads($plan['circuit'], $currentProgram, $plan['base']['blocks']) as $payload) $wire .= frame($payload);
    fwrite($sender, $wire . $responses);
    $result = null;
    $error = null;
    try {
        $result = (new Km271Schedule($plan['circuit']))->write(new ScheduleProtocol($receiver, static function (string $line): void {}, .2), $plan);
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
    $sent = readAvailable($sender);
    fclose($receiver);
    fclose($sender);
    return [$result, $error, $sent];
}

[$result, $error, $sent] = runWrite($plan, 1, str_repeat("\x10\x10", 3));
assertSame(null, $error, 'Erfolgreiches Schreiben');
assertSame(2, $result['confirmedBlocks'], 'Bestätigte Blöcke');
$expectedTail = '';
foreach ($plan['commands'] as $command) $expectedTail .= frame(pack('C*', ...$command));
$expectedTail .= frame("\x11\x00\x00\x65\x65\x65\x65\x65");
assertSame(true, str_ends_with($sent, $expectedTail), 'Eigen erst nach allen Zeitblöcken aktivieren');
[$result, $error, $sent] = runWrite($plan, 2, '');
assertSame(null, $result, 'Konflikt ohne Ergebnis');
assertSame(true, str_contains($error, 'inzwischen geändert'), 'Konflikterkennung');
assertSame(false, str_contains($sent, frame(pack('C*', ...$plan['commands'][0]))), 'Bei Konflikt kein Schreiben');
[$result, $error, $sent] = runWrite($plan, 1, "\x10\x10" . str_repeat("\x10\x15", 3));
assertSame(true, str_contains($error, '1 Schaltzeit-Blöcke bestätigt'), 'Teilweises Schreiben melden');
assertSame(false, str_contains($sent, frame("\x11\x00\x00\x65\x65\x65\x65\x65")), 'Kein Eigen nach Fehler');

$encoder = new Km271WriteCommandEncoder();
foreach (Km271Schedule::PROGRAMS as $index => $program) {
    assertSame(sprintf('12 00 %02X 65 65 65 65 65', $index), $encoder->encode('HK2_Heizprogramm', $program)['Hex'], 'HK2 ' . $program);
}
$decoder = new ScheduleDecoder();
$decoder->consume("\x01\x69\x08");
assertSame('Senior', $decoder->values()['hc2HeatingProgram']['value'], 'HK2 Programmrückmeldung');
$lock = new Km271ConnectionLock('km271-test-' . getmypid(), 8234);
reject(fn () => new Km271ConnectionLock('km271-test-' . getmypid(), 8234), 'Paralleler Zugriff');
$lock->release();
$lock = new Km271ConnectionLock('km271-test-' . getmypid(), 8234);
$lock->release();
echo "OK: KM271-Schaltzeiten, Telegramme, Konflikte, Teilfehler und Sperre erfolgreich.\n";

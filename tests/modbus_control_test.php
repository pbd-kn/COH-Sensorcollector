<?php

declare(strict_types=1);

// Ausschließlich Fixtures und TCP auf 127.0.0.1. Keine Gerätezugriffe.
require_once __DIR__ . '/../sensorCollect/Modbus/bootstrap.php';
require_once __DIR__ . '/../sensorCollect/Modbus/ModbusControl.php';

use PbdKn\cohSensorcollector\Modbus\ModbusControl;
use PbdKn\cohSensorcollector\Modbus\ModbusProfile;
use PbdKn\cohSensorcollector\Modbus\ModbusTcpClient;

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectFailure(callable $action, string $text): void
{
    try { $action(); } catch (Throwable $error) {
        verify(str_contains($error->getMessage(), $text), $error->getMessage());
        return;
    }
    throw new RuntimeException('Erwarteter Fehler fehlt: ' . $text);
}

if (($argv[1] ?? '') === '--server') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) throw new RuntimeException($error);
    echo stream_socket_get_name($server, false) . "\n";
    flush();
    $connection = stream_socket_accept($server, 10);
    if (!$connection) exit(2);
    stream_set_timeout($connection, 5);
    $request = '';
    while (strlen($request) < 12) {
        $chunk = fread($connection, 12 - strlen($request));
        if ($chunk === '' || $chunk === false) exit(3);
        $request .= $chunk;
    }
    verify(bin2hex(substr($request, 7)) === '0636470003', 'FC06-Anfrage entspricht dem freigegebenen Auftrag');
    if (($argv[2] ?? '') === 'wrong') $request[11] = chr(2);
    if (($argv[2] ?? '') === 'exception') $request = substr($request, 0, 4) . pack('nCCC', 3, 1, 0x86, 2);
    fwrite($connection, $request);
    fclose($connection);
    fclose($server);
    exit;
}

final class ControlFixture extends ModbusTcpClient
{
    public array $values = [0x3647 => 0, 0x3636 => 0];
    public array $writes = [];
    public bool $ignoreWrite = false;
    public function __construct() {}
    public function readHoldingRegisters(int $address, int $count): array { return [$this->values[$address]]; }
    public function writeSingleRegister(int $address, int $value): void
    {
        $this->writes[] = [$address, $value];
        if (!$this->ignoreWrite) $this->values[$address] = $value;
    }
}

foreach (['saj-h2', 'ampere-storagepro'] as $name) {
    $profile = ModbusProfile::load($name);
    verify(count($profile['controls']) === 2 && count($profile['controlCatalog']) === 84, 'Nur zwei Schreibfreigaben, 84 reine Informationseinträge');
    foreach ($profile['controlCatalog'] as $setting => $definition) {
        verify($definition['writable'] === false && !isset($profile['controls'][$setting]), 'Katalog darf keine zusätzliche Schreibfreigabe erzeugen');
    }
    $fixture = new ControlFixture();
    $control = new ModbusControl($fixture, $profile);
    expectFailure(fn () => $control->execute('planned.onGridSoc', 20, 0), 'nicht zum Schreiben');
    expectFailure(fn () => $control->execute('planned.charge1.start', 360, 0), 'nicht zum Schreiben');
    verify($control->read('control.appMode')['value'] === 0 && $fixture->writes === [], 'Vorschau schreibt nichts');
    expectFailure(fn () => $control->execute('battery.soh', 100, 0), 'nicht zum Schreiben');
    expectFailure(fn () => $control->execute('control.appMode', 14, 0), 'nicht erlaubt');
    expectFailure(fn () => $control->execute('control.appMode', 3, 1), 'Konflikt');
    expectFailure(fn () => $control->execute('control.passiveAction', 2, 0), 'Passiv');
    verify($fixture->writes === [], 'Ungültige Aufträge schreiben nichts');
    verify($control->execute('control.appMode', 3, 0)['verified'], 'Modus schreiben und zurücklesen');
    verify($control->execute('control.passiveAction', 2, 0)['value'] === 2, 'Passives Laden nur im passenden Modus');
    $count = count($fixture->writes);
    $control->execute('control.passiveAction', 2, 2);
    verify(count($fixture->writes) === $count, 'Unveränderter Wert wird nicht geschrieben');
    $fixture->ignoreWrite = true;
    expectFailure(fn () => $control->execute('control.appMode', 0, 3), 'Rücklesen');
    verify(count($fixture->writes) === $count + 1, 'Keine automatische Wiederholung bei Teilfehler');
}

foreach (['ok', 'wrong', 'exception'] as $scenario) {
    $process = proc_open([PHP_BINARY, __FILE__, '--server', $scenario],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $address = trim((string) fgets($pipes[1]));
    verify((bool) preg_match('/^127\.0\.0\.1:(\d+)$/D', $address, $match), 'Lokaler Testserver startet');
    $client = new ModbusTcpClient('127.0.0.1', (int) $match[1]);
    if ($scenario === 'ok') $client->writeSingleRegister(0x3647, 3);
    elseif ($scenario === 'wrong') expectFailure(fn () => $client->writeSingleRegister(0x3647, 3), 'Schreibbestätigung');
    else expectFailure(fn () => $client->writeSingleRegister(0x3647, 3), 'Registeradresse nicht erlaubt');
    $client->close();
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    verify(proc_close($process) === 0, 'Testserver: ' . $errors);
}
echo "OK: Profile, Vorschau, Freigaben, Konflikte, Voraussetzungen, Rücklesen und FC06-TCP.\n";

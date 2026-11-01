<?php

declare(strict_types=1);

require_once __DIR__ . '/StorageProWriter.php';

// Isolated loopback server; never connects to a physical device.
if (($argv[1] ?? '') === '--fixture') {
    $mode = $argv[2];
    $function = (int) $argv[3];
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        exit(2);
    }
    echo stream_socket_get_name($server, false) . "\n";
    fflush(STDOUT);
    $values = $function === 6 ? [10] : [10, 20];
    $readExact = static function ($socket, int $n): string {
        $text = '';
        while (strlen($text) < $n) {
            $chunk = fread($socket, $n - strlen($text));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Fixture EOF');
            }
            $text .= $chunk;
        }
        return $text;
    };
    for ($connection = 0; $connection < 2; ++$connection) {
        $socket = stream_socket_accept($server, 5);
        if ($socket === false) {
            exit(3);
        }
        stream_set_timeout($socket, 3);
        $steps = $connection === 0 || $mode === 'conflict' ? 1 : ($mode === 'echo' || $mode === 'exception' || $mode === 'drop' || $mode === 'header' ? 2 : 3);
        for ($step = 0; $step < $steps; ++$step) {
            $h = unpack('ntid/nprotocol/nlength/Cunit', $readExact($socket, 7));
            $pdu = $readExact($socket, $h['length'] - 1);
            if (ord($pdu[0]) === 3) {
                $answer = $mode === 'conflict' && $connection === 1 ? array_fill(0, count($values), 99) : $values;
                if ($mode === 'readback' && $step === 2) {
                    $answer[0] = 99;
                }
                $reply = pack('CC', 3, count($answer) * 2) . pack('n*', ...$answer);
            } else {
                $expected = $function === 6 ? pack('Cnn', 6, 0x1234, 30) : pack('CnnCnn', 16, 0x1234, 2, 4, 30, 40);
                if ($pdu !== $expected) {
                    exit(4);
                }
                $values = $function === 6 ? [30] : [30, 40];
                if ($mode === 'drop') {
                    fclose($socket);
                    fclose($server);
                    exit(0);
                }
                $reply = $function === 6 ? $pdu : pack('Cnn', 16, 0x1234, 2);
                if ($mode === 'echo') {
                    $reply[2] = chr(ord($reply[2]) ^ 1);
                } elseif ($mode === 'exception') {
                    $reply = pack('CC', $function | 0x80, 2);
                } elseif ($mode === 'header') {
                    $h['tid']++;
                }
            }
            fwrite($socket, pack('nnnC', $h['tid'], 0, strlen($reply) + 1, 1) . $reply);
        }
        // Client must close; no extra read/write or retry is accepted.
        if (fread($socket, 1) !== '' || !feof($socket)) {
            exit(5);
        }
        fclose($socket);
    }
    fclose($server);
    exit(0);
}

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$policy = ['enabled' => true, 'registers' => [4660 => ['minRaw' => 0, 'maxRaw' => 100, 'functions' => [6, 16], 'evidence' => 'Offline fixture only'],
    4661 => ['minRaw' => 0, 'maxRaw' => 100, 'functions' => [16], 'evidence' => 'Offline fixture only']]];
foreach ([6, 16] as $function) {
    foreach (['ok', 'conflict', 'echo', 'exception', 'drop', 'header', 'readback'] as $mode) {
        $process = proc_open([PHP_BINARY, __FILE__, '--fixture', $mode, (string) $function],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start offline fixture');
        }
        try {
            stream_set_timeout($pipes[1], 5);
            $endpoint = trim((string) fgets($pipes[1]));
            if (!preg_match('/^127\.0\.0\.1:(\d+)$/D', $endpoint, $match)) {
                throw new RuntimeException('Invalid fixture address');
            }
            $device = ['host' => '127.0.0.1', 'port' => (int) $match[1], 'unitId' => 1, 'timeoutSeconds' => 2];
            $writer = new \CohEnergy\StorageProWriter($device, $policy);
            $plan = $writer->preview($function, 0x1234, $function === 6 ? [30] : [30, 40]);
            try {
                $result = $writer->execute($plan, $plan['confirmation']);
                $check($mode === 'ok' && $result['status'] === 'verified', 'Unexpected success');
            } catch (RuntimeException $error) {
                $check($mode !== 'ok', $error->getMessage());
                $check($mode === 'conflict' ? str_contains($error->getMessage(), 'nichts geschrieben')
                    : str_contains($error->getMessage(), 'Nicht automatisch'), 'Missing outcome classification');
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $code = proc_close($process);
            $process = null;
            $check($code === 0, 'Fixture failed: ' . $code);
            echo "OK: FC$function $mode\n";
        } finally {
            if (is_resource($process)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
// All of these must reject before any network connection, port 1 is never used.
$device = ['host' => '127.0.0.1', 'port' => 1, 'unitId' => 1, 'timeoutSeconds' => 1];
$writer = new \CohEnergy\StorageProWriter($device, $policy);
foreach ([[6, 0x1234, [101]], [16, 0x1235, [10, 20]], [6, 0x1234, [10, 20]], [16, 65535, [1, 2]]] as [$fc, $address, $values]) {
    try {
        $writer->preview($fc, $address, $values);
        throw new LogicException('Invalid write accepted');
    } catch (InvalidArgumentException | RuntimeException) {
    }
}
$plan = ['device' => $device, 'expiresAt' => time() + 60, 'confirmation' => 'code', 'function' => 6, 'address' => 0x1234, 'values' => [30], 'before' => [10]];
foreach (['disabled', 'expired', 'wrong-confirmation', 'changed-target'] as $mode) {
    $p = $plan;
    $rules = $policy;
    if ($mode === 'disabled') { $rules['enabled'] = false; }
    if ($mode === 'expired') { $p['expiresAt'] = time() - 1; }
    if ($mode === 'changed-target') { $p['device']['host'] = 'localhost'; }
    try {
        (new \CohEnergy\StorageProWriter($device, $rules))->execute($p, $mode === 'wrong-confirmation' ? 'wrong' : 'code');
        throw new LogicException('Gate accepted invalid plan');
    } catch (RuntimeException $error) {
        $check(!str_contains($error->getMessage(), 'Verbindung'), 'Gate reached network');
    }
}
echo "OK: Freigaben, Grenzen, Bestaetigung, Ablauf und Zielbindung. Keine Hardware angesprochen.\n";

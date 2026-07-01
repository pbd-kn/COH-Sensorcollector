<?php
declare(strict_types=1);

/*
 * Kleiner Modbus-TCP-Test fuer AC ELWA / Heizstab.
 *
 * Beispiele:
 *   php modbus-heizstab-test.php
 *   php modbus-heizstab-test.php 1000
 *   php modbus-heizstab-test.php --addr=1000
 *   php modbus-heizstab-test.php --addr=1000 --func=4
 *   php modbus-heizstab-test.php --addr=1001 --write=0 --yes
 *
 * Hinweise:
 *   --func=3 liest Holding Register
 *   --func=4 liest Input Register
 *   --write=... schreibt ein Holding Register mit Function 6
 *   Interaktiv: "1000" liest, "write 1001 123" schreibt nach Rueckfrage
 *   --one-based zieht 1 von der angegebenen Adresse ab, falls die Doku 1-basiert zaehlt
 */

$options = getopt('', [
    'host:',
    'port::',
    'unit::',
    'addr:',
    'func::',
    'write::',
    'timeout::',
    'one-based',
    'signed',
    'yes',
    'help',
]);

$positional = getPositionalArguments($argv);
$defaultHost = '192.168.178.68';

if (!isset($options['addr']) && isset($positional[0])) {
    $options['addr'] = $positional[0];
}

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php modbus-heizstab-test.php\n";
    echo "  php modbus-heizstab-test.php 1000\n";
    echo "  php modbus-heizstab-test.php --addr=1000 [--func=3|4]\n";
    echo "  php modbus-heizstab-test.php --addr=1001 --write=123 --yes\n\n";
    echo "Optionen:\n";
    echo "  --host=IP        Heizstab-IP, Default: $defaultHost\n";
    echo "  --port=502       Modbus TCP Port\n";
    echo "  --unit=1         Modbus Unit-ID\n";
    echo "  --func=3         3=Holding Register, 4=Input Register\n";
    echo "  --one-based      Adresse vor Anfrage um 1 reduzieren\n";
    echo "  --signed         16-bit Wert signed ausgeben\n";
    echo "  --write=N        Holding Register per Function 6 schreiben\n";
    echo "  --yes            Schreiben wirklich ausfuehren\n";
    exit(0);
}

$host = (string)($options['host'] ?? $defaultHost);
$port = (int)($options['port'] ?? 502);
$unitId = (int)($options['unit'] ?? 1);
$function = (int)($options['func'] ?? 3);
$timeout = (float)($options['timeout'] ?? 3);
$isOneBased = isset($options['one-based']);
$asSigned = isset($options['signed']);
$hasWrite = array_key_exists('write', $options);

if ($unitId < 0 || $unitId > 0xFF) {
    fail('Unit-ID ausserhalb 0..255');
}

if (!isset($options['addr'])) {
    interactiveReadLoop($host, $port, $unitId, $function, $timeout, $isOneBased, $asSigned);
    exit(0);
}

$address = parseIntOption((string)$options['addr']);

if (!$hasWrite && !in_array($function, [3, 4], true)) {
    fail('--func muss beim Lesen 3 oder 4 sein');
}

if ($isOneBased) {
    $address--;
}

if ($address < 0 || $address > 0xFFFF) {
    fail('Registeradresse ausserhalb 0..65535');
}

if ($hasWrite && !isset($options['yes'])) {
    fail('Schreiben abgebrochen: Bitte --yes angeben, wenn wirklich geschrieben werden soll');
}

try {
    if ($hasWrite) {
        $value = parseIntOption((string)$options['write']);
        if ($value < 0 || $value > 0xFFFF) {
            fail('Schreibwert ausserhalb 0..65535');
        }

        $socket = openModbusSocket($host, $port, $timeout);
        $response = modbusWriteSingleRegister($socket, $unitId, $address, $value);
        echo "WRITE OK\n";
        echo "host: $host:$port\n";
        echo "unit: $unitId\n";
        echo "addr: $address\n";
        echo "value_uint16: $value\n";
        echo "response: " . bin2hex($response) . "\n";
        fclose($socket);
        exit(0);
    }

    $socket = openModbusSocket($host, $port, $timeout);
    $value = modbusReadSingleRegister($socket, $unitId, $address, $function);
    echo "READ OK\n";
    echo "host: $host:$port\n";
    echo "unit: $unitId\n";
    echo "func: $function\n";
    echo "addr: $address\n";
    echo "value_uint16: $value\n";
    if ($asSigned) {
        echo "value_int16: " . uint16ToInt16($value) . "\n";
    }
    fclose($socket);
} finally {
    if (isset($socket) && is_resource($socket)) {
        fclose($socket);
    }
}

function interactiveReadLoop(
    string $host,
    int $port,
    int $unitId,
    int $function,
    float $timeout,
    bool $isOneBased,
    bool $asSigned
): void {
    define('MODBUS_INTERACTIVE', true);

    if (!in_array($function, [3, 4], true)) {
        fail('--func muss beim Lesen 3 oder 4 sein');
    }

    echo "Modbus Heizstab Test\n";
    echo "Host: $host:$port, Unit: $unitId, Function: $function\n";
    echo "Lesen: 1000 oder read 1000\n";
    echo "Schreiben: write 1001 123\n";
    echo "Beenden mit exit oder q.\n\n";

    while (true) {
        echo "Register> ";
        $line = fgets(STDIN);
        if ($line === false) {
            echo "\n";
            return;
        }

        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (in_array(strtolower($line), ['exit', 'quit', 'q'], true)) {
            echo "Ende.\n";
            return;
        }

        try {
            if (preg_match('/^(?:w|write|set)\s+(\S+)\s+(\S+)$/i', $line, $matches)) {
                $address = parseInteractiveInt($matches[1]);
                $newValue = parseInteractiveInt($matches[2]);
                if ($isOneBased) {
                    $address--;
                }

                if ($address < 0 || $address > 0xFFFF) {
                    echo "FEHLER: Registeradresse ausserhalb 0..65535\n";
                    continue;
                }

                if ($newValue < 0 || $newValue > 0xFFFF) {
                    echo "FEHLER: Schreibwert ausserhalb 0..65535\n";
                    continue;
                }

                $socket = openModbusSocket($host, $port, $timeout);
                try {
                    $oldValue = modbusReadSingleRegister($socket, $unitId, $address, 3);
                } finally {
                    fclose($socket);
                }

                echo "addr $address aktuell = $oldValue, neu = $newValue\n";
                echo "Wirklich schreiben? Tippe yes: ";
                $answer = trim((string)fgets(STDIN));
                if (strtolower($answer) !== 'yes') {
                    echo "Nicht geschrieben.\n";
                    continue;
                }

                $socket = openModbusSocket($host, $port, $timeout);
                try {
                    modbusWriteSingleRegister($socket, $unitId, $address, $newValue);
                } finally {
                    fclose($socket);
                }

                $socket = openModbusSocket($host, $port, $timeout);
                try {
                    $checkValue = modbusReadSingleRegister($socket, $unitId, $address, 3);
                } finally {
                    fclose($socket);
                }

                echo "WRITE OK addr $address = $checkValue\n";
                continue;
            }

            if (preg_match('/^(?:r|read)\s+(\S+)$/i', $line, $matches)) {
                $line = $matches[1];
            }

            $address = parseInteractiveInt($line);
            if ($isOneBased) {
                $address--;
            }

            if ($address < 0 || $address > 0xFFFF) {
                echo "FEHLER: Registeradresse ausserhalb 0..65535\n";
                continue;
            }

            $socket = openModbusSocket($host, $port, $timeout);
            try {
                $value = modbusReadSingleRegister($socket, $unitId, $address, $function);
            } finally {
                fclose($socket);
            }

            echo "addr $address = $value";
            if ($asSigned) {
                echo " signed=" . uint16ToInt16($value);
            }
            echo "\n";
        } catch (Throwable $e) {
            echo "FEHLER: " . $e->getMessage() . "\n";
        }
    }
}

function openModbusSocket(string $host, int $port, float $timeout)
{
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!is_resource($socket)) {
        fail("Verbindung fehlgeschlagen zu $host:$port: [$errno] $errstr");
    }

    stream_set_timeout($socket, (int)$timeout, (int)(($timeout - floor($timeout)) * 1000000));
    return $socket;
}

function modbusReadSingleRegister($socket, int $unitId, int $address, int $function): int
{
    $pdu = pack('Cnn', $function, $address, 1);
    $responsePdu = modbusRequest($socket, $unitId, $pdu);

    $responseFunction = ord($responsePdu[0]);
    if ($responseFunction !== $function) {
        fail("Unerwartete Function in Antwort: $responseFunction");
    }

    $byteCount = ord($responsePdu[1]);
    if ($byteCount !== 2 || strlen($responsePdu) < 4) {
        fail('Unerwartete Register-Antwort: ' . bin2hex($responsePdu));
    }

    return unpack('n', substr($responsePdu, 2, 2))[1];
}

function modbusWriteSingleRegister($socket, int $unitId, int $address, int $value): string
{
    $pdu = pack('Cnn', 6, $address, $value);
    $responsePdu = modbusRequest($socket, $unitId, $pdu);

    $responseFunction = ord($responsePdu[0]);
    if ($responseFunction !== 6) {
        fail("Unerwartete Function in Schreib-Antwort: $responseFunction");
    }

    if (strlen($responsePdu) < 5) {
        fail('Unerwartete Schreib-Antwort: ' . bin2hex($responsePdu));
    }

    return $responsePdu;
}

function modbusRequest($socket, int $unitId, string $pdu): string
{
    static $transactionId = 1;

    $mbap = pack('nnnC', $transactionId++, 0, strlen($pdu) + 1, $unitId);
    $request = $mbap . $pdu;

    $written = fwrite($socket, $request);
    if ($written !== strlen($request)) {
        fail('Request konnte nicht vollstaendig gesendet werden');
    }

    $header = readExact($socket, 7);
    $parts = unpack('ntransaction/nprotocol/nlength/Cunit', $header);
    if ($parts['protocol'] !== 0) {
        fail('Antwort ist kein Modbus TCP Paket');
    }

    $payloadLength = $parts['length'] - 1;
    if ($payloadLength <= 0) {
        fail('Leere Modbus-Antwort');
    }

    $pduResponse = readExact($socket, $payloadLength);
    $function = ord($pduResponse[0]);
    if (($function & 0x80) !== 0) {
        $exceptionCode = strlen($pduResponse) > 1 ? ord($pduResponse[1]) : -1;
        fail("Modbus Exception: function=$function code=$exceptionCode");
    }

    return $pduResponse;
}

function readExact($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length && !feof($socket)) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                fail('Timeout beim Lesen der Modbus-Antwort');
            }
            fail('Verbindung wurde beim Lesen geschlossen');
        }
        $data .= $chunk;
    }

    return $data;
}

function parseIntOption(string $value): int
{
    $value = trim($value);
    if (preg_match('/^0x[0-9a-f]+$/i', $value)) {
        return hexdec($value);
    }

    if (!preg_match('/^-?\d+$/', $value)) {
        fail("Ungueltige Zahl: $value");
    }

    return (int)$value;
}

function parseInteractiveInt(string $value): int
{
    $value = trim($value);
    if (preg_match('/^0x[0-9a-f]+$/i', $value)) {
        return hexdec($value);
    }

    if (!preg_match('/^-?\d+$/', $value)) {
        throw new RuntimeException("Ungueltige Zahl: $value");
    }

    return (int)$value;
}

function uint16ToInt16(int $value): int
{
    return $value >= 0x8000 ? $value - 0x10000 : $value;
}

function fail(string $message): void
{
    if (defined('MODBUS_INTERACTIVE') && MODBUS_INTERACTIVE) {
        throw new RuntimeException($message);
    }

    fwrite(STDERR, "FEHLER: $message\n");
    exit(1);
}

function getPositionalArguments(array $argv): array
{
    $positional = [];
    $skipNext = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($skipNext) {
            $skipNext = false;
            continue;
        }

        if ($arg === '--') {
            continue;
        }

        if (str_starts_with($arg, '--')) {
            if (!str_contains($arg, '=') && in_array($arg, ['--host', '--port', '--unit', '--addr', '--func', '--write', '--timeout'], true)) {
                $skipNext = true;
            }
            continue;
        }

        $positional[] = $arg;
    }

    return $positional;
}

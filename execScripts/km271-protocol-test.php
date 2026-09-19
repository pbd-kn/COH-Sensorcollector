<?php
declare(strict_types=1);

use PbdKn\CohSensorcollector\Km271\Protocol3964R;
use PbdKn\CohSensorcollector\Km271\Km271Decoder;

require_once __DIR__ . '/Km271/Protocol3964R.php';
require_once __DIR__ . '/Km271/Km271Decoder.php';

testValidTelegramWithEscapedDle();
testInvalidChecksumIsRejected();
testReadOnlyLogModeHandshake();
testLogModeCollisionIsResolved();
testImmediateTelegramAfterLogModeIsPreserved();
testWriteCommandIsAcknowledged();
testWriteCollisionIsResolved();
testWriteNakIsRetried();
testKnownValuesAreDecoded();
echo "OK: 3964R-Protokolltests erfolgreich.\n";

function testWriteCommandIsAcknowledged(): void
{
    [$receiver, $sender] = socketPair();
    $payload = "\x07\x00\x65\x0F\x65\x65\x65\x65";
    fwrite($sender, "\x10\x10");
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    $protocol->send($payload, 0.3);
    assertSame("\x02" . substr(frame($payload), 1), readAvailable($sender), 'Schreibtelegramm mit 3964R-Rahmen');
    fclose($receiver);
    fclose($sender);
}

function testWriteCollisionIsResolved(): void
{
    [$receiver, $sender] = socketPair();
    $payload = "\x07\x00\x65\x0F\x65\x65\x65\x65";
    fwrite($sender, "\x02\x10\x10");
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    $protocol->send($payload, 0.3);
    assertSame("\x02\x02" . substr(frame($payload), 1), readAvailable($sender), 'Schreibtelegramm nach STX-Kollision');
    fclose($receiver);
    fclose($sender);
}

function testWriteNakIsRetried(): void
{
    [$receiver, $sender] = socketPair();
    $payload = "\x07\x00\x65\x0C\x65\x65\x65\x65";
    fwrite($sender, "\x10\x15\x10\x10");
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    $protocol->send($payload, 0.5);
    $wire = "\x02" . substr(frame($payload), 1);
    assertSame($wire . $wire, readAvailable($sender), 'Schreibtelegramm wird nach NAK wiederholt');
    fclose($receiver);
    fclose($sender);
}

function testValidTelegramWithEscapedDle(): void
{
    [$receiver, $sender] = socketPair();
    $payload = "\x01\x10\x22";
    fwrite($sender, frame($payload));
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    assertSame($payload, $protocol->receive(0.2), 'Payload inklusive DLE');
    assertSame("\x10\x10", readAvailable($sender), 'Start- und Endquittung');
    fclose($receiver);
    fclose($sender);
}

function testInvalidChecksumIsRejected(): void
{
    [$receiver, $sender] = socketPair();
    fwrite($sender, "\x02\x01\x10\x03\x00");
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    assertSame(null, $protocol->receive(0.2), 'Falsche BCC wird verworfen');
    assertSame("\x10\x15", readAvailable($sender), 'Startquittung und NAK');
    fclose($receiver);
    fclose($sender);
}

function testKnownValuesAreDecoded(): void
{
    $decoder = new Km271Decoder();
    $decoder->consume("\x88\x2B\x37");
    $decoder->consume("\x89\x3C\xFD");
    $decoder->consume("\x84\x27\x2F");
    $values = $decoder->values();
    assertSame(55.0, $values['boilerTemperature']['value'], 'Kesseltemperatur');
    assertSame(-3.0, $values['outsideTemperature']['value'], 'Aussentemperatur signed');
    assertSame(47.0, $values['hotWaterTemperature']['value'], 'Warmwassertemperatur');
}

function testReadOnlyLogModeHandshake(): void
{
    [$receiver, $sender] = socketPair();
    fwrite($sender, "\x10\x10");
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    $protocol->startLogMode(0.2);
    assertSame("\x02\xEE\x00\x00\x10\x03\xFD", readAvailable($sender), 'Logmodus-Lesekommando');
    fclose($receiver);
    fclose($sender);
}

function testLogModeCollisionIsResolved(): void
{
    [$receiver, $sender] = socketPair();
    fwrite($sender, "\x02\x10\x10");
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    $protocol->startLogMode(0.2);
    assertSame("\x02\x02\xEE\x00\x00\x10\x03\xFD", readAvailable($sender), 'Logmodus nach STX-Kollision');
    fclose($receiver);
    fclose($sender);
}

function testImmediateTelegramAfterLogModeIsPreserved(): void
{
    [$receiver, $sender] = socketPair();
    $payload = "\x88\x2B\x37";
    fwrite($sender, "\x10" . frame($payload));
    $protocol = new Protocol3964R($receiver, static function (string $message): void {}, 0.2);
    $protocol->startLogMode(0.2);
    assertSame($payload, $protocol->receive(0.2), 'Telegramm direkt nach Logmodus');
    assertSame("\x02\xEE\x00\x00\x10\x03\xFD\x10\x10", readAvailable($sender), 'Logmodus und Telegrammquittungen');
    fclose($receiver);
    fclose($sender);
}

function socketPair(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new RuntimeException("Testserver fehlgeschlagen: [$errno] $error");
    }
    $address = stream_socket_get_name($server, false);
    $client = $address === false ? false : stream_socket_client('tcp://' . $address, $errno, $error, 1.0);
    $accepted = $client === false ? false : stream_socket_accept($server, 1.0);
    fclose($server);
    if ($client === false || $accepted === false) {
        if (is_resource($client)) {
            fclose($client);
        }
        throw new RuntimeException("Testverbindung fehlgeschlagen: [$errno] $error");
    }
    return [$accepted, $client];
}

function frame(string $payload): string
{
    $bcc = ord("\x10") ^ ord("\x03");
    $wirePayload = '';
    foreach (str_split($payload) as $byte) {
        $bcc ^= ord($byte);
        if ($byte === "\x10") {
            $bcc ^= ord($byte);
            $wirePayload .= "\x10\x10";
        } else {
            $wirePayload .= $byte;
        }
    }
    return "\x02" . $wirePayload . "\x10\x03" . chr($bcc);
}

function readAvailable($stream): string
{
    stream_set_blocking($stream, false);
    usleep(1000);
    $data = stream_get_contents($stream);
    return $data === false ? '' : $data;
}

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s: erwartet %s, erhalten %s', $label, var_export($expected, true), var_export($actual, true)));
    }
}

<?php
declare(strict_types=1);

use PbdKn\CohSensorcollector\Km271\Protocol3964R;

require_once __DIR__ . '/Km271/Protocol3964R.php';

$options = getopt('', ['host::', 'port::', 'timeout::', 'help']);
$host = (string) ($options['host'] ?? '192.168.178.70');
$port = (int) ($options['port'] ?? 8234);
$timeout = (float) ($options['timeout'] ?? 3.0);

if (isset($options['help'])) {
    echo "3964R-Verbindungstest ohne Nutztelegramm.\n\n";
    echo "php km271-handshake-test.php [--host=192.168.178.70] [--port=8234] [--timeout=3]\n";
    exit(0);
}

if ($port < 1 || $port > 65535 || $timeout <= 0) {
    fwrite(STDERR, "Ungueltige Port- oder Timeout-Angabe.\n");
    exit(2);
}

echo "CONNECT $host:$port\n";
$errno = 0;
$error = '';
$stream = @stream_socket_client("tcp://$host:$port", $errno, $error, min(10.0, $timeout));
if (!is_resource($stream)) {
    fwrite(STDERR, "CONNECT ERROR [$errno] $error\n");
    exit(1);
}

$seconds = (int) floor($timeout);
$microseconds = (int) (($timeout - $seconds) * 1_000_000);
stream_set_timeout($stream, $seconds, $microseconds);
stream_set_write_buffer($stream, 0);

echo "TX 02 (STX, 3964R-Verbindungsaufbau; keine Nutzdaten)\n";
if (fwrite($stream, Protocol3964R::STX) !== 1) {
    fwrite(STDERR, "FEHLER: STX konnte nicht gesendet werden.\n");
    fclose($stream);
    exit(1);
}
fflush($stream);

$answer = fread($stream, 1);
$meta = stream_get_meta_data($stream);
fclose($stream);

if ($answer === false || $answer === '') {
    if (!empty($meta['timed_out'])) {
        fwrite(STDERR, "TIMEOUT: Keine Antwort vom KM271.\n");
    } else {
        fwrite(STDERR, "FEHLER: Verbindung ohne Antwort geschlossen.\n");
    }
    fwrite(STDERR, "Erwartet wurde 10 (DLE). Adapterzaehler und Verkabelung pruefen.\n");
    exit(1);
}

$hex = Protocol3964R::hex($answer);
echo "RX $hex\n";
if ($answer === Protocol3964R::DLE) {
    echo "OK: KM271 hat den 3964R-Verbindungsaufbau mit DLE bestaetigt.\n";
    echo "Der Test endet absichtlich vor einem Nutztelegramm.\n";
    exit(0);
}
if ($answer === Protocol3964R::STX) {
    fwrite(STDERR, "KOLLISION: KM271 sendete gleichzeitig STX. Test erneut ausfuehren.\n");
    exit(1);
}

fwrite(STDERR, "UNERWARTETE ANTWORT: $hex statt 10 (DLE).\n");
exit(1);

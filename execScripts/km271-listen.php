<?php
declare(strict_types=1);

use PbdKn\CohSensorcollector\Km271\Protocol3964R;

require_once __DIR__ . '/Km271/Protocol3964R.php';

$options = getopt('', ['host::', 'port::', 'duration::', 'timeout::', 'log::', 'help']);
$host = (string) ($options['host'] ?? '192.168.178.70');
$port = (int) ($options['port'] ?? 8234);
$duration = (float) ($options['duration'] ?? 60.0);
$telegramTimeout = (float) ($options['timeout'] ?? 4.0);
$logfile = (string) ($options['log'] ?? (__DIR__ . '/km271-raw.log'));

if (isset($options['help'])) {
    echo "Rein lesender KM271/3964R-Test; keine Nutztelegramme werden gesendet.\n\n";
    echo "php km271-listen.php [--host=192.168.178.70] [--port=8234]\n";
    echo "                      [--duration=60] [--timeout=4] [--log=DATEI]\n";
    exit(0);
}
if ($port < 1 || $port > 65535 || $duration <= 0 || $telegramTimeout <= 0) {
    fwrite(STDERR, "Ungueltige Port- oder Timeout-Angabe.\n");
    exit(2);
}

$logHandle = @fopen($logfile, 'ab');
if (!is_resource($logHandle)) {
    fwrite(STDERR, "Logdatei kann nicht geoeffnet werden: $logfile\n");
    exit(2);
}
$log = static function (string $message) use ($logHandle): void {
    $line = sprintf("%s %s\n", date('Y-m-d H:i:s.v'), $message);
    echo $line;
    fwrite($logHandle, $line);
    fflush($logHandle);
};

$errno = 0;
$error = '';
$log("CONNECT $host:$port; passive 3964R receive mode");
$stream = @stream_socket_client("tcp://$host:$port", $errno, $error, min(10.0, $telegramTimeout));
if (!is_resource($stream)) {
    $log("CONNECT ERROR [$errno] $error");
    fclose($logHandle);
    exit(1);
}

stream_set_write_buffer($stream, 0);
$protocol = new Protocol3964R($stream, $log, $telegramTimeout);
$deadline = microtime(true) + $duration;
$count = 0;
$exitCode = 0;
try {
    while (($remaining = $deadline - microtime(true)) > 0) {
        $telegram = $protocol->receive($remaining);
        if ($telegram === null) {
            break;
        }
        ++$count;
        $log(sprintf('TELEGRAM #%d length=%d payload=%s', $count, strlen($telegram), Protocol3964R::hex($telegram)));
    }
} catch (Throwable $exception) {
    $log('FATAL ' . $exception->getMessage());
    $exitCode = 1;
}
$log("DONE telegrams=$count");
fclose($stream);
fclose($logHandle);
exit($exitCode);

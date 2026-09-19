<?php
declare(strict_types=1);

use PbdKn\CohSensorcollector\Km271\Protocol3964R;
use PbdKn\CohSensorcollector\Km271\Km271Decoder;

require_once __DIR__ . '/Km271/Protocol3964R.php';
require_once __DIR__ . '/Km271/Km271Decoder.php';

/*
 * Interaktiver, rein lesender Test fuer Buderus KM271.
 * Es werden ausschliesslich spontan gesendete 3964R-Telegramme empfangen und
 * quittiert. Das Programm sendet keine KM271-Nutzdaten und keine Parameter.
 */

date_default_timezone_set('Europe/Berlin');

$options = parseCliOptions($argv);
$host = (string) ($options['host'] ?? '192.168.178.70');
$port = (int) ($options['port'] ?? 8234);
$collectSeconds = (float) ($options['duration'] ?? 30.0);
$telegramTimeout = (float) ($options['timeout'] ?? 4.0);
$debug = filterBool($options['debug'] ?? false);
$logMode = filterBool($options['log-mode'] ?? true);
$logfile = (string) ($options['log'] ?? (__DIR__ . '/km271-raw.log'));

if (isset($options['help'])) {
    printHelp();
    exit(0);
}
if ($port < 1 || $port > 65535 || $collectSeconds <= 0 || $telegramTimeout <= 0) {
    fwrite(STDERR, "FEHLER: Ungueltige Port- oder Timeout-Angabe.\n");
    exit(2);
}

$telegrams = [];
$decoder = new Km271Decoder();

echo "Buderus KM271 3964R Testloop (rein lesend)\n";
echo "Ziel:          $host:$port\n";
echo "Sammelzeit:    $collectSeconds s\n";
echo "Telegramm-T/O: $telegramTimeout s\n";
echo "Rohlog:        $logfile\n";
echo "Debug:         " . ($debug ? 'an' : 'aus') . "\n";
echo "Logmodus:      " . ($logMode ? 'an (nur Lesekommando EE 00 00)' : 'aus') . "\n";
echo "Hinweis: Es werden keine Heizungsparameter geschrieben.\n";

try {
    collectTelegrams($collectSeconds);
} catch (Throwable $error) {
    echo 'WARNUNG: ' . $error->getMessage() . PHP_EOL;
}
printHelp();

while (true) {
    echo PHP_EOL . 'KM271> ';
    $line = fgets(STDIN);
    if ($line === false) {
        echo PHP_EOL;
        break;
    }

    $input = trim($line);
    $lower = strtolower($input);
    if (in_array($lower, ['q', 'quit', 'exit'], true)) {
        break;
    }
    if (in_array($lower, ['?', 'h', 'help', 'hilfe'], true)) {
        printHelp();
        continue;
    }
    if ($input === '' || in_array($lower, ['werte', 'values', 'list', 'ls'], true)) {
        printValues($decoder->values());
        continue;
    }
    if ($lower === 'last' || $lower === 'letztes') {
        printTelegrams($telegrams === [] ? [] : [array_key_last($telegrams) => $telegrams[array_key_last($telegrams)]]);
        continue;
    }
    if ($lower === 'clear' || $lower === 'leeren') {
        $telegrams = [];
        $decoder = new Km271Decoder();
        echo "Gesammelte Telegramme geloescht.\n";
        continue;
    }
    if (in_array($lower, ['telegramme', 'raw telegrams'], true)) {
        printTelegrams($telegrams);
        continue;
    }
    if (preg_match('/^(?:r|read|reload|listen|sammeln)(?:\s+([0-9]+(?:\.[0-9]+)?))?$/i', $input, $matches)) {
        $seconds = isset($matches[1]) ? (float) $matches[1] : $collectSeconds;
        if ($seconds <= 0 || $seconds > 3600) {
            echo "Sammelzeit muss zwischen 0 und 3600 Sekunden liegen.\n";
            continue;
        }
        try {
            collectTelegrams($seconds);
        } catch (Throwable $error) {
            echo 'WARNUNG: ' . $error->getMessage() . PHP_EOL;
        }
        continue;
    }
    if (preg_match('/^(?:filter|hex)\s+(.+)$/i', $input, $matches)) {
        printTelegrams(filterTelegrams($telegrams, $matches[1]));
        continue;
    }
    if ($lower === 'json') {
        echo encodeJson(buildValuesDocument()) . PHP_EOL;
        continue;
    }
    if ($lower === 'raw' || $lower === 'save' || $lower === 'speichern') {
        $file = __DIR__ . '/km271-snapshot-' . date('Ymd-His') . '.json';
        if (file_put_contents($file, encodeJson(buildSnapshot($telegrams)) . PHP_EOL, LOCK_EX) === false) {
            echo "WARNUNG: Snapshot konnte nicht geschrieben werden.\n";
        } else {
            echo "Snapshot gespeichert: $file\n";
        }
        continue;
    }
    if ($lower === 'status') {
        printSummary($telegrams);
        continue;
    }

    echo "Unbekannter Befehl. 'help' zeigt alle Befehle.\n";
}

function collectTelegrams(float $seconds): void
{
    global $host, $port, $telegramTimeout, $debug, $logMode, $logfile, $telegrams, $decoder;

    $logHandle = @fopen($logfile, 'ab');
    if (!is_resource($logHandle)) {
        throw new RuntimeException("Rohlog kann nicht geoeffnet werden: $logfile");
    }

    $logger = static function (string $message) use ($logHandle, $debug): void {
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $line = "$timestamp $message\n";
        fwrite($logHandle, $line);
        fflush($logHandle);
        if ($debug) {
            echo $line;
        }
    };

    $errno = 0;
    $error = '';
    echo "Verbinde mit $host:$port und sammle $seconds s ...\n";
    $stream = @stream_socket_client("tcp://$host:$port", $errno, $error, min(10.0, $telegramTimeout));
    if (!is_resource($stream)) {
        fclose($logHandle);
        throw new RuntimeException("Verbindung fehlgeschlagen: [$errno] $error");
    }

    stream_set_write_buffer($stream, 0);
    $protocol = new Protocol3964R($stream, $logger, $telegramTimeout);
    if ($logMode) {
        $protocol->startLogMode($telegramTimeout);
        echo "KM271-Logmodus aktiviert; Konfiguration und Status werden gelesen.\n";
    }
    $deadline = microtime(true) + $seconds;
    $newCount = 0;

    try {
        while (($remaining = $deadline - microtime(true)) > 0) {
            $payload = $protocol->receive($remaining);
            if ($payload === null) {
                break;
            }
            $entry = telegramEntry($payload);
            $entry['decoded'] = $decoder->consume($payload);
            $telegrams[] = $entry;
            ++$newCount;
            $logger(sprintf('TELEGRAM #%d length=%d payload=%s', count($telegrams), $entry['length'], $entry['hex']));
            if ($entry['decoded'] !== []) {
                printValues($entry['decoded'], '  ');
            } elseif ($debug) {
                echo sprintf("#%d  %s  %d Byte  %s\n", count($telegrams), $entry['receivedAt'], $entry['length'], $entry['hex']);
            }
        }
    } finally {
        fclose($stream);
        fclose($logHandle);
    }

    echo "Fertig: $newCount neue, " . count($telegrams) . " insgesamt.\n";
}

function telegramEntry(string $payload): array
{
    return [
        'receivedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        'length' => strlen($payload),
        'hex' => Protocol3964R::hex($payload),
        'bytes' => array_values(unpack('C*', $payload) ?: []),
    ];
}

function filterTelegrams(array $items, string $needle): array
{
    $needle = normalizeHex($needle);
    if ($needle === '') {
        return $items;
    }

    return array_filter($items, static fn (array $item): bool => str_contains(str_replace(' ', '', $item['hex']), $needle));
}

function normalizeHex(string $value): string
{
    return strtoupper((string) preg_replace('/[^0-9A-F]/i', '', $value));
}

function printTelegrams(array $items): void
{
    if ($items === []) {
        echo "Keine passenden Telegramme gesammelt. Mit 'r' neu lesen.\n";
        return;
    }
    foreach ($items as $index => $item) {
        echo sprintf("#%d  %s  %d Byte  %s\n", $index + 1, $item['receivedAt'], $item['length'], $item['hex']);
    }
}

function printSummary(array $items): void
{
    global $decoder;
    printValues($decoder->values());
    echo PHP_EOL;
    $groups = [];
    foreach ($items as $item) {
        $key = $item['hex'];
        $groups[$key] = ($groups[$key] ?? 0) + 1;
    }
    echo 'Telegramme: ' . count($items) . PHP_EOL;
    echo 'Unterschiedliche Payloads: ' . count($groups) . PHP_EOL;
    foreach ($groups as $hex => $count) {
        echo sprintf("%4dx  %s\n", $count, $hex);
    }
}

function buildSnapshot(array $items): array
{
    return buildValuesDocument() + [
        'diagnostics' => [
            'telegramCount' => count($items),
            'telegrams' => array_values($items),
        ],
    ];
}

function buildValuesDocument(): array
{
    global $decoder;
    $readable = [];
    foreach ($decoder->values() as $entry) {
        $key = germanJsonKey($entry['label'], $entry['unit']);
        while (array_key_exists($key, $readable)) {
            $key .= '_2';
        }
        $readable[$key] = [
            'Name' => $entry['label'],
            'Wert' => $entry['value'],
            'Einheit' => $entry['unit'] !== '' ? $entry['unit'] : null,
            'Datum' => $entry['updatedAt'],
        ];
    }

    return [
        'Gerät' => 'Buderus KM271',
        'Betriebsart' => 'nur lesend',
        'Erstellt am' => (new DateTimeImmutable())->format(DATE_ATOM),
        'KM271' => $readable,
    ];
}

function germanJsonKey(string $label, string $unit): string
{
    $key = preg_replace('/[^\p{L}\p{N}]+/u', '_', trim($label));
    $key = trim((string) $key, '_');
    if ($label === 'Brennerlaufzeit') {
        $key .= $unit === 'h' ? '_Stunden' : '_Minuten';
    }
    return $key !== '' ? $key : 'Unbekannter_Wert';
}

function printValues(array $values, string $prefix = ''): void
{
    if ($values === []) {
        echo $prefix . "Noch keine bekannten Buderus-Werte empfangen. Mit 'r 20' weiter lesen.\n";
        return;
    }
    foreach ($values as $entry) {
        $formatted = is_float($entry['value'])
            ? rtrim(rtrim(number_format($entry['value'], 1, ',', ''), '0'), ',')
            : (string) $entry['value'];
        $unit = $entry['unit'] !== '' ? ' ' . $entry['unit'] : '';
        echo sprintf("%s%-38s %s%s\n", $prefix, $entry['label'] . ':', $formatted, $unit);
    }
}

function encodeJson(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function printHelp(): void
{
    echo PHP_EOL;
    echo "Aufruf:\n";
    echo "  php json-buderus-km271-loop.php [--host=IP] [--port=8234] [--duration=30] [--log-mode=1] [--debug=1]\n\n";
    echo "Befehle:\n";
    echo "  [leer], werte         dekodierte Buderus-Werte anzeigen\n";
    echo "  r [SEKUNDEN]          weitere Telegramme sammeln\n";
    echo "  last                  letztes Rohtelegramm anzeigen\n";
    echo "  telegramme            alle Rohtelegramme anzeigen (Diagnose)\n";
    echo "  filter HEX            Payloads nach Hexfolge filtern, z.B. filter C0 05\n";
    echo "  status                Anzahl und unterschiedliche Payloads anzeigen\n";
    echo "  json                  nur lesbare Buderus-Werte als JSON ausgeben\n";
    echo "  raw                   Diagnose-JSON mit Werten und Rohtelegrammen speichern\n";
    echo "  clear                 Sammlung leeren\n";
    echo "  help                  diese Hilfe anzeigen\n";
    echo "  q                     beenden\n";
}

function parseCliOptions(array $arguments): array
{
    $result = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $parts = explode('=', substr($argument, 2), 2);
        $result[$parts[0]] = $parts[1] ?? true;
    }
    return $result;
}

function filterBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

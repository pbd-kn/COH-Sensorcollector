<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api_env.php';

$autoloadCandidates = [
    dirname(__DIR__, 5) . '/sensorCollect/autoload.php',
    dirname(__DIR__, 5) . '/sensorcollect/autoload.php',
    '/home/peter/scripts/coh/sensorcollect/autoload.php',
];
$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'KM271-Autoloader wurde nicht gefunden.']);
    exit;
}
require_once $autoload;

use PbdKn\cohSensorcollector\Sensor\Km271\Km271WriteCommandEncoder;
use PbdKn\cohSensorcollector\Sensor\Km271\Protocol3964R;

function km271Respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$expectedToken = cohRequireApiToken();
$providedToken = trim((string) ($_SERVER['HTTP_X_COH_TOKEN'] ?? ''));
if (!hash_equals($expectedToken, $providedToken)) {
    km271Respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

$encoder = new Km271WriteCommandEncoder();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$protocolLog = [];

if ($method === 'GET') {
    $writeEnabled = filter_var(cohApiEnv('COH_KM271_WRITE_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    $executable = array_keys($encoder->catalog());
    km271Respond(200, [
        'ok' => true,
        'Betriebsart' => $writeEnabled ? 'Schreiben freigegeben' : 'Schreibvorschau; Versand gesperrt',
        'Sendebereit' => $writeEnabled,
        'EchtbetriebErlaubt' => $executable,
        'Schreibauftraege' => $encoder->catalog(),
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    km271Respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $request = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new InvalidArgumentException('JSON-Objekt erwartet.');
    }
    $preview = $encoder->encode(
        (string) ($request['LokaleId'] ?? ''),
        $request['Wert'] ?? null,
    );
    if (empty($request['Ausfuehren'])) {
        km271Respond(200, ['ok' => true, 'Vorschau' => $preview]);
    }

    if (!filter_var(cohApiEnv('COH_KM271_WRITE_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
        km271Respond(409, ['ok' => false, 'error' => 'KM271-Schreiben ist auf dem Raspberry nicht freigeschaltet.']);
    }
    if (($request['Bestaetigung'] ?? '') !== 'SCHREIBEN') {
        km271Respond(400, ['ok' => false, 'error' => 'Die ausdrueckliche Schreibbestaetigung fehlt.']);
    }
    if (!array_key_exists((string) ($preview['LokaleId'] ?? ''), $encoder->catalog())) {
        km271Respond(403, ['ok' => false, 'error' => 'Dieser KM271-Schreibauftrag ist im Echtbetrieb nicht erlaubt.']);
    }

    $host = trim(cohApiEnv('COH_KM271_HOST')) ?: '192.168.178.70';
    $port = (int) (trim(cohApiEnv('COH_KM271_PORT')) ?: '8234');
    $socket = @stream_socket_client("tcp://$host:$port", $errorNumber, $errorMessage, 5.0);
    if (!is_resource($socket)) {
        throw new RuntimeException("KM271 nicht erreichbar: $errorMessage ($errorNumber)");
    }

    try {
        $protocol = new Protocol3964R($socket, static function (string $line) use (&$protocolLog): void {
            $protocolLog[] = $line;
        });
        // Der KM271-Logmodus kann beim TCP-Verbindungsaufbau bereits mitten in
        // einem Sendetakt sein. Ein Telegramm sauber zu Ende empfangen schafft
        // eine definierte Telegrammgrenze fuer den anschliessenden Auftrag.
        $synchronizedPayload = $protocol->receive(1.0);
        if ($synchronizedPayload !== null) {
            $protocolLog[] = 'SYNC received ' . Protocol3964R::hex($synchronizedPayload);
        }
        $protocol->send(pack('C*', ...$preview['Nutzdaten']));
    } finally {
        fclose($socket);
    }

    $result = $preview;
    $result['Sendebereit'] = true;
    $result['Status'] = 'Vom KM271 mit DLE bestätigt';
    $result['GesendetAm'] = date(DATE_ATOM);
    $result['Auftraggeber'] = trim((string) ($request['Auftraggeber'] ?? '')) ?: ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');

    $audit = json_encode([
        'Zeit' => $result['GesendetAm'],
        'Auftraggeber' => $result['Auftraggeber'],
        'LokaleId' => $result['LokaleId'],
        'Wert' => $result['Wert'],
        'Hex' => $result['Hex'],
        'Status' => $result['Status'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('KM271_WRITE ' . $audit);

    km271Respond(200, ['ok' => true, 'Ergebnis' => $result]);
} catch (JsonException|InvalidArgumentException $error) {
    km271Respond(400, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    $trace = $protocolLog === [] ? '' : ' | ' . implode(' | ', array_slice($protocolLog, -30));
    error_log('KM271_WRITE_ERROR ' . $error->getMessage() . $trace);
    km271Respond(500, ['ok' => false, 'error' => 'KM271-Auftrag fehlgeschlagen: ' . $error->getMessage()]);
}

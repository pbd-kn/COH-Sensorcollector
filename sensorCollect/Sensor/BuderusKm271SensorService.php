<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\mysql_dialog;
use PbdKn\cohSensorcollector\SimpleHttpClient;
use PbdKn\cohSensorcollector\Sensor\Km271\Km271Decoder;
use PbdKn\cohSensorcollector\Sensor\Km271\Protocol3964R;

/** Liest Statuswerte beider Heizkreise eines Buderus KM271 ueber TCP/3964R. */
final class BuderusKm271SensorService implements SensorFetcherInterface
{
    private const DEFAULT_PORT = 8234;
    private const MAX_COLLECTION_SECONDS = 120.0;
    private const TELEGRAM_TIMEOUT = 4.0;
    private array $cachedValues = [];
    private bool $persistentCacheLoaded = false;

    /** Allgemeine Anlagenwerte und die verfuegbaren Werte beider Heizkreise. */
    private const EXPORTED_KEYS = [
        'hc1SummerThreshold', 'hc1NightTemperature', 'hc1DayTemperature',
        'hc1OperatingMode', 'hc1HolidayTemperature', 'hc1MaximumTemperature',
        'hc1DesignTemperature', 'hc1TemperatureOffset', 'hc1RemoteControl',
        'hc1HeatingProgram', 'hc1HolidayDays', 'hc1FlowSetpoint',
        'hc1FlowTemperature', 'hc1RoomSetpoint', 'hc1RoomTemperature',
        'hc1StartOptimization', 'hc1StopOptimization', 'hc1PumpPower',
        'hc1MixerPosition',
        'hc2HeatingProgram', 'hc2FlowSetpoint', 'hc2FlowTemperature', 'hc2RoomSetpoint',
        'hc2RoomTemperature', 'hc2PumpPower', 'hc2MixerPosition',
        'frostThreshold', 'hotWaterConfiguredTemperature', 'hotWaterOperatingMode',
        'hotWaterEnabled', 'hotWaterCirculationSetting', 'hotWaterSetpoint',
        'hotWaterTemperature', 'hotWaterOptimization', 'hotWaterChargePump',
        'hotWaterCirculationPump', 'hotWaterSolarPump',
        'burnerType', 'maximumBoilerTemperature', 'pumpLogicTemperature',
        'boilerSetpoint', 'boilerTemperature', 'burnerOnTemperature',
        'burnerOffTemperature', 'exhaustTemperature', 'outsideTemperature',
        'outsideTemperatureDamped', 'flueGasTest', 'burnerStage1',
        'boilerProtection', 'boilerActive', 'burnerEnable', 'burnerHighEnable',
        'burnerStage2', 'burnerControl', 'errorBurner', 'errorBoilerSensor',
        'errorAuxSensor', 'errorBoilerCold', 'errorFlueGasSensor',
        'errorFlueGasLimit', 'errorSafetyChain', 'errorExternal',
        'burnerRuntimeHours',
    ];

    public function __construct(
        private mysql_dialog $db,
        private Logger $logger,
        private SimpleHttpClient $httpClient,
    ) {
    }

    public function supports($sensor): bool
    {
        return in_array(
            strtolower(trim((string) ($sensor['sensorSource'] ?? ''))),
            ['km271', 'buderus', 'buderus-km271'],
            true,
        );
    }

    public function fetch($sensor): ?array
    {
        $result = $this->fetchArr([$sensor]);
        return $result === null ? null : ($result[(string) ($sensor['sensorID'] ?? '')] ?? null);
    }

    public function fetchArr(array $sensors): ?array
    {
        if ($sensors === []) {
            return [];
        }

        try {
            $this->loadPersistentCache();
            [$host, $port] = $this->parseEndpoint((string) ($sensors[0]['geraeteUrl'] ?? ''));
            $this->logger->debugMe("KM271: verbinde mit $host:$port fuer " . count($sensors) . ' Sensoren');
            $values = $this->readValues($host, $port);
            if ($values === []) {
                $this->logger->Error('KM271: innerhalb der Sammelzeit wurden keine bekannten Werte empfangen.');
                return null;
            }

            // Das KM271 verteilt seine Register ueber mehrere Logzyklen.
            // Innerhalb des dauerhaft laufenden Collectors bleiben zuletzt
            // empfangene Werte erhalten und werden durch neuere ersetzt.
            $this->cachedValues = array_replace($this->cachedValues, $values);
            $this->savePersistentCache();

            $document = $this->buildDocument($this->cachedValues);
            $result = [];
            foreach ($sensors as $sensor) {
                $sensorId = (string) ($sensor['sensorID'] ?? '');
                $localId = trim((string) ($sensor['sensorLokalId'] ?? ''));
                if ($localId === '') {
                    $localId = $sensorId;
                }

                if (strcasecmp($localId, 'KM271') === 0) {
                    $result[$sensorId] = $this->result($sensor, $document, 'json', 'json');
                    continue;
                }

                if (!array_key_exists($localId, $document['KM271'])) {
                    $this->logger->Info("KM271: kein Wert fuer sensorLokalId '$localId' (Sensor $sensorId)");
                    continue;
                }

                $valueObject = $document['KM271'][$localId];
                $result[$sensorId] = $this->result(
                    $sensor,
                    $valueObject['Wert'],
                    (string) ($valueObject['Einheit'] ?? ''),
                    (string) ($sensor['sensorValueType'] ?? ''),
                );
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->Error('KM271: ' . $exception->getMessage());
            return null;
        }
    }

    private function readValues(string $host, int $port): array
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            try {
                $values = $this->readValuesAttempt($host, $port);
                if ($values !== []) {
                    return $values;
                }
                throw new \RuntimeException('keine bekannten Werte empfangen');
            } catch (\Throwable $exception) {
                $lastError = $exception;
                $this->logger->Info(
                    "KM271: Leseversuch $attempt von 3 fehlgeschlagen: " . $exception->getMessage(),
                );
                if ($attempt < 3) {
                    usleep(500_000);
                }
            }
        }

        throw new \RuntimeException(
            'Lesen nach drei Versuchen fehlgeschlagen: ' . ($lastError?->getMessage() ?? 'unbekannter Fehler'),
        );
    }

    private function readValuesAttempt(string $host, int $port): array
    {
        $connectionLock = new \PbdKn\cohSensorcollector\Sensor\Km271\Km271ConnectionLock($host, $port);
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            "tcp://$host:$port",
            $errno,
            $error,
            min(10.0, self::TELEGRAM_TIMEOUT),
        );
        if (!is_resource($stream)) {
            $connectionLock->release();
            throw new \RuntimeException("Verbindung zu $host:$port fehlgeschlagen: [$errno] $error");
        }

        stream_set_write_buffer($stream, 0);
        // Dem TCP/RS232-Adapter Zeit geben, die serielle Seite zu uebernehmen.
        usleep(100_000);
        $protocolLogger = function (string $message): void {
            $this->logger->debugMe('KM271 3964R: ' . $message);
        };
        $protocol = new Protocol3964R($stream, $protocolLogger, self::TELEGRAM_TIMEOUT);
        $decoder = new Km271Decoder();

        try {
            // Ein bereits aktiver KM271-Logstrom kann beim TCP-Verbindungsaufbau
            // mitten in einem Telegramm stehen. Zuerst bis zur naechsten
            // gueltigen STX-Grenze passiv lesen. Nur bei echter Stille wird das
            // Lesekommando EE 00 00 gesendet.
            $firstPayload = $protocol->receive(2.0);
            if ($firstPayload !== null) {
                $decoder->consume($firstPayload);
                $this->logger->debugMe('KM271: Telegrammgrenze vor Logkommando synchronisiert.');
            }
            // Auch bei einem laufenden Grundtelegramm wird der ausfuehrliche
            // Lesemodus angefordert. Nach receive() stehen wir garantiert an
            // einer 3964R-Telegrammgrenze und geraten nicht mitten in Daten.
            $protocol->startLogMode(self::TELEGRAM_TIMEOUT);
            $deadline = microtime(true) + self::MAX_COLLECTION_SECONDS;
            while (($remaining = $deadline - microtime(true)) > 0) {
                // Der KM271-Loglauf besitzt kein ausdrueckliches Endezeichen.
                // Eine Telegrammpause beendet den vollstaendigen Lauf; 120 s
                // bleiben lediglich die obere Sicherheitsgrenze.
                $payload = $protocol->receive(min(self::TELEGRAM_TIMEOUT, $remaining));
                if ($payload === null) {
                    break;
                }
                $decoder->consume($payload);
            }
        } finally {
            fclose($stream);
            $connectionLock->release();
        }

        return array_intersect_key($decoder->values(), array_flip(self::EXPORTED_KEYS));
    }

    private function buildDocument(array $values): array
    {
        $readable = [];
        foreach ($values as $entry) {
            $key = $this->germanJsonKey((string) $entry['label'], (string) $entry['unit']);
            while (array_key_exists($key, $readable)) {
                $key .= '_2';
            }
            $readable[$key] = [
                'Name' => (string) $entry['label'],
                'Wert' => $entry['value'],
                'Einheit' => (string) $entry['unit'] !== '' ? $entry['unit'] : null,
                'Datum' => $entry['updatedAt'],
            ];
        }

        return [
            'Gerät' => 'Buderus KM271',
            'Betriebsart' => 'nur lesend',
            'Erstellt am' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'KM271' => $readable,
        ];
    }

    private function loadPersistentCache(): void
    {
        if ($this->persistentCacheLoaded) {
            return;
        }
        $this->persistentCacheLoaded = true;
        $file = $this->persistentCacheFile();
        if (!is_readable($file)) {
            return;
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $values = is_array($decoded['values'] ?? null) ? $decoded['values'] : [];
            $allowed = array_fill_keys(self::EXPORTED_KEYS, true);
            foreach ($values as $key => $entry) {
                if (isset($allowed[$key]) && is_array($entry) && isset($entry['label'], $entry['updatedAt'])) {
                    $this->cachedValues[$key] = $entry;
                }
            }
            if ($this->cachedValues !== []) {
                $this->logger->Info('KM271: ' . count($this->cachedValues) . ' Werte aus dauerhaftem Cache geladen.');
            }
        } catch (\Throwable $exception) {
            $this->logger->Error('KM271: dauerhafter Cache konnte nicht gelesen werden: ' . $exception->getMessage());
        }
    }

    private function savePersistentCache(): void
    {
        $file = $this->persistentCacheFile();
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            $this->logger->Error("KM271: Cache-Verzeichnis '$directory' konnte nicht angelegt werden.");
            return;
        }

        try {
            $json = json_encode([
                'version' => 1,
                'savedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'values' => $this->cachedValues,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporary = $file . '.tmp';
            if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $file)) {
                @unlink($temporary);
                throw new \RuntimeException('Datei konnte nicht atomar ersetzt werden.');
            }
            @chmod($file, 0660);
        } catch (\Throwable $exception) {
            $this->logger->Error('KM271: dauerhafter Cache konnte nicht gespeichert werden: ' . $exception->getMessage());
        }
    }

    private function persistentCacheFile(): string
    {
        return dirname(__DIR__, 2) . '/var/cache/km271-values.json';
    }

    private function result(array $sensor, mixed $value, string $unit, string $valueType): array
    {
        return [
            'sensorID' => (string) ($sensor['sensorID'] ?? ''),
            'sensorValue' => $value,
            'sensorEinheit' => $unit,
            'sensorValueType' => $valueType,
            'sensorSource' => (string) ($sensor['sensorSource'] ?? ''),
        ];
    }

    private function parseEndpoint(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new \RuntimeException('geraeteUrl fehlt; erwartet wird z. B. 192.168.178.70:8234.');
        }
        $candidate = str_contains($url, '://') ? $url : 'tcp://' . $url;
        $host = parse_url($candidate, PHP_URL_HOST);
        $port = parse_url($candidate, PHP_URL_PORT) ?: self::DEFAULT_PORT;
        if (!is_string($host) || $host === '' || $port < 1 || $port > 65535) {
            throw new \RuntimeException("ungueltige geraeteUrl '$url'.");
        }
        return [$host, (int) $port];
    }

    private function germanJsonKey(string $label, string $unit): string
    {
        $key = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', trim($label)), '_');
        if ($label === 'Brennerlaufzeit') {
            $key .= $unit === 'h' ? '_Stunden' : '_Minuten';
        }
        return $key !== '' ? $key : 'Unbekannter_Wert';
    }
}

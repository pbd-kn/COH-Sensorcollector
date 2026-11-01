<?php

declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Modbus;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Modbus-TCP-Transport ohne externe Bibliothek. */
class ModbusTcpClient
{
    protected string $host;
    protected int $port;
    protected int $unitId;
    private float $timeout;
    /** @var resource|null */
    private $socket = null;
    private int $transactionId = 0;

    public function __construct(string $host, int $port = 502, int $unitId = 1, float $timeout = 3.0)
    {
        $host = trim($host);
        if ($host === '') {
            throw new InvalidArgumentException('Modbus-Host darf nicht leer sein.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Ungültiger TCP-Port.');
        }
        if ($unitId < 0 || $unitId > 255) {
            throw new InvalidArgumentException('Ungültige Modbus Unit-ID.');
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('Timeout muss größer als 0 sein.');
        }

        $this->host = $host;
        $this->port = $port;
        $this->unitId = $unitId;
        $this->timeout = $timeout;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function open(): void
    {
        $this->connect();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function readHoldingRegisters(int $address, int $count): array
    {
        return $this->readRegisters($address, $count, 3);
    }

    /** FC06: genau ein Register, keine Wiederholung bei unklarer Antwort. */
    public function writeSingleRegister(int $address, int $value): void
    {
        if ($address < 0 || $address > 65535 || $value < 0 || $value > 65535) {
            throw new InvalidArgumentException('Ungültige Registeradresse oder ungültiger Rohwert.');
        }
        $this->connect();
        $transactionId = $this->nextTransactionId();
        $pdu = pack('Cnn', 6, $address, $value);
        try {
            $this->writeAll(pack('nnnC', $transactionId, 0, 6, $this->unitId) . $pdu);
            $header = unpack('ntransaction/nprotocol/nlength/Cunit', $this->readExact(7));
            if ($header['transaction'] !== $transactionId || $header['protocol'] !== 0
                || $header['unit'] !== $this->unitId || !in_array($header['length'], [3, 6], true)) {
                throw new RuntimeException('Ungültige Antwort auf den Schreibauftrag; Zustand erneut lesen.');
            }
            $answer = $this->readExact($header['length'] - 1);
            if (strlen($answer) === 2 && ord($answer[0]) === 0x86) {
                throw new RuntimeException($this->formatException(6, ord($answer[1]), $address));
            }
            if ($answer !== $pdu) {
                throw new RuntimeException('Schreibbestätigung stimmt nicht mit dem Auftrag überein; Zustand erneut lesen.');
            }
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** @return list<int> */
    public function readInputRegisters(int $address, int $count): array
    {
        return $this->readRegisters($address, $count, 4);
    }

    private function readRegisters(int $address, int $count, int $functionCode): array
    {
        if ($address < 0 || $address > 65535) {
            throw new InvalidArgumentException('Registeradresse außerhalb von 0..65535.');
        }
        if ($count < 1 || $count > 125 || $address + $count - 1 > 65535) {
            throw new InvalidArgumentException('Registeranzahl außerhalb des zulässigen Bereichs.');
        }

        $this->connect();
        $transactionId = $this->nextTransactionId();
        $request = pack('nnnCCnn', $transactionId, 0, 6, $this->unitId, $functionCode, $address, $count);

        try {
            $this->writeAll($request);
            $header = $this->readExact(7);
            $mbap = unpack('ntransaction/nprotocol/nlength/Cunit', $header);
            if (!is_array($mbap)) {
                throw new RuntimeException('Ungültiger Modbus-MBAP-Header.');
            }
            if ($mbap['transaction'] !== $transactionId || $mbap['protocol'] !== 0) {
                throw new RuntimeException('Modbus-Antwort gehört nicht zur Anfrage.');
            }
            if ($mbap['unit'] !== $this->unitId || $mbap['length'] < 3 || $mbap['length'] > 254) {
                throw new RuntimeException('Ungültige Unit-ID oder Länge in der Modbus-Antwort.');
            }

            $pdu = $this->readExact($mbap['length'] - 1);
            $function = ord($pdu[0]);
            if (($function & 0x80) !== 0) {
                if ($function !== ($functionCode | 0x80) || strlen($pdu) !== 2) {
                    throw new RuntimeException('Ungültige Modbus-Exception-Antwort.');
                }
                $exception = strlen($pdu) > 1 ? ord($pdu[1]) : -1;
                throw new RuntimeException($this->formatException($function & 0x7F, $exception, $address));
            }
            if ($function !== $functionCode || strlen($pdu) < 2) {
                throw new RuntimeException('Unerwartete Modbus-Funktion in der Antwort.');
            }

            $byteCount = ord($pdu[1]);
            if ($byteCount !== $count * 2 || strlen($pdu) !== $byteCount + 2) {
                throw new RuntimeException('Unpassende Datenlänge in der Modbus-Antwort.');
            }
            $decoded = unpack('n*', substr($pdu, 2));
            return is_array($decoded) ? array_values($decoded) : [];
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', str_contains($this->host, ':') ? '[' . trim($this->host, '[]') . ']' : $this->host, $this->port),
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new RuntimeException("Modbus-Verbindung zu {$this->host}:{$this->port} fehlgeschlagen: $error ($errno)");
        }

        $seconds = (int)$this->timeout;
        $microseconds = (int)(($this->timeout - $seconds) * 1000000);
        stream_set_timeout($socket, $seconds, $microseconds);
        stream_set_blocking($socket, true);
        $this->socket = $socket;
    }

    private function nextTransactionId(): int
    {
        $this->transactionId = ($this->transactionId % 65535) + 1;
        return $this->transactionId;
    }

    private function writeAll(string $data): void
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $result = fwrite($this->socket, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Modbus-Anfrage konnte nicht vollständig gesendet werden.');
            }
            $written += $result;
        }
    }

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                $reason = !empty($meta['timed_out']) ? 'Timeout' : 'Verbindung beendet';
                throw new RuntimeException("Modbus-Antwort unvollständig: $reason.");
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function formatException(int $function, int $exception, int $address): string
    {
        $messages = [
            1 => 'Funktion nicht unterstützt',
            2 => 'Registeradresse nicht erlaubt',
            3 => 'Ungültiger Registerwert oder ungültige Anzahl',
            4 => 'Gerätefehler',
            5 => 'Anfrage bestätigt, Bearbeitung dauert an',
            6 => 'Gerät beschäftigt',
            10 => 'Gateway-Pfad nicht verfügbar',
            11 => 'Gateway-Ziel antwortet nicht',
        ];
        $message = $messages[$exception] ?? ($exception === 0 ? 'ungültiger Geräte-Exception-Code 0' : "unbekannter Exception-Code $exception");
        return sprintf('Modbus-Funktion %d, Register 0x%04X: %s.', $function, $address, $message);
    }

}

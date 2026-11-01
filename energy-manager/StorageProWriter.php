<?php

declare(strict_types=1);

namespace CohEnergy;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Explicit opt-in raw-register writer. No automatic retry or rollback. */
final class StorageProWriter
{
    private array $device;
    private array $policy;
    private $socket = null;
    private int $transaction = 0;

    public function __construct(array $device, array $policy)
    {
        if (($device['unitId'] ?? 0) < 1 || $device['unitId'] > 247) {
            throw new InvalidArgumentException('Schreiben nur mit Unit-ID 1..247.');
        }
        $this->device = $device;
        $this->policy = self::normalizePolicy($policy);
    }

    /** Accept hexadecimal JSON strings; reject ambiguous duplicate addresses. */
    public static function normalizePolicy(array $policy): array
    {
        if (!is_bool($policy['enabled'] ?? null) || !is_array($policy['registers'] ?? null)) {
            throw new InvalidArgumentException('Policy braucht enabled (bool) und registers (Objekt).');
        }
        $parse = static function (mixed $value): int {
            if (is_int($value) && $value >= 0 && $value <= 65535) {
                return $value;
            }
            if (is_string($value) && preg_match('/^0[xX][0-9a-fA-F]{1,4}$/D', $value)) {
                return (int) hexdec(substr($value, 2));
            }
            if (is_string($value) && preg_match('/^[0-9]{1,5}$/D', $value) && (int) $value <= 65535) {
                return (int) $value;
            }
            throw new InvalidArgumentException('Adresse/Grenze muss 0..65535 oder eine Hex-Zeichenfolge wie "0x3647" sein.');
        };
        $registers = [];
        foreach ($policy['registers'] as $address => $rule) {
            $address = $parse($address);
            if (array_key_exists($address, $registers)) {
                throw new InvalidArgumentException('Registeradresse mehrfach angegeben (Hex/Dezimal).');
            }
            if (!is_array($rule)) {
                throw new InvalidArgumentException('Registerregel muss ein Objekt sein.');
            }
            $rule['minRaw'] = $parse($rule['minRaw'] ?? null);
            $rule['maxRaw'] = $parse($rule['maxRaw'] ?? null);
            if ($rule['minRaw'] > $rule['maxRaw']) {
                throw new InvalidArgumentException('minRaw darf nicht groesser als maxRaw sein.');
            }
            $registers[$address] = $rule;
        }
        $policy['registers'] = $registers;
        return $policy;
    }

    public function preview(int $function, int $address, array $values): array
    {
        $this->validateWrite($function, $address, $values);
        try {
            $before = $this->read($address, count($values));
            return ['device' => $this->device, 'function' => $function, 'address' => $address,
                'values' => $values, 'before' => $before, 'expiresAt' => time() + 60,
                'confirmation' => bin2hex(random_bytes(6))];
        } finally {
            $this->close();
        }
    }

    public function execute(array $plan, string $confirmation): array
    {
        if (($this->policy['enabled'] ?? false) !== true) {
            throw new RuntimeException('Schreibfreigabe fehlt: policy.enabled muss true sein.');
        }
        if (($plan['device'] ?? null) !== $this->device || time() >= ($plan['expiresAt'] ?? 0)
            || !is_string($plan['confirmation'] ?? null) || !hash_equals($plan['confirmation'], $confirmation)) {
            throw new RuntimeException('Plan abgelaufen, Ziel geaendert oder Bestaetigung falsch. Neue Vorschau erstellen.');
        }
        $function = $plan['function'];
        $address = $plan['address'];
        $values = $plan['values'];
        $this->validateWrite($function, $address, $values);
        $attempted = false;
        try {
            $before = $this->read($address, count($values));
            if (time() >= $plan['expiresAt'] || $before !== $plan['before']) {
                throw new RuntimeException('Ausgangsstand geaendert oder Vorschau abgelaufen; nichts geschrieben.');
            }
            $payload = $function === 6
                ? pack('Cnn', 6, $address, $values[0])
                : pack('CnnC', 16, $address, count($values), count($values) * 2) . pack('n*', ...$values);
            $attempted = true;
            $reply = $this->exchange($payload);
            $expected = $function === 6 ? $payload : pack('Cnn', 16, $address, count($values));
            if ($reply !== $expected) {
                throw new RuntimeException('Schreibantwort stimmt nicht mit Anfrage ueberein.');
            }
            $after = $this->read($address, count($values));
            if ($after !== $values) {
                throw new RuntimeException('Ruecklesewerte weichen vom Auftrag ab.');
            }
            return ['status' => 'verified', 'before' => $before, 'after' => $after];
        } catch (Throwable $error) {
            if ($attempted) {
                throw new RuntimeException('Schreibversuch erfolgt; Ergebnis bei Fehler nicht sicher. Nicht automatisch wiederholen oder zuruecksetzen. ' . $error->getMessage(), 0, $error);
            }
            throw $error;
        } finally {
            $this->close();
        }
    }

    private function validateWrite(int $function, int $address, array $values): void
    {
        if (!in_array($function, [6, 16], true) || !array_is_list($values) || count($values) < 1
            || count($values) > ($function === 6 ? 1 : 123) || $address < 0 || $address + count($values) > 65536) {
            throw new InvalidArgumentException('Ungueltige Funktion, Adresse oder Registeranzahl (FC06: 1, FC16: 1..123).');
        }
        foreach ($values as $offset => $value) {
            $rule = $this->policy['registers'][(string) ($address + $offset)] ?? null;
            if (!is_array($rule) || !is_int($rule['minRaw'] ?? null) || !is_int($rule['maxRaw'] ?? null)
                || $rule['minRaw'] < 0 || $rule['maxRaw'] > 65535 || $rule['minRaw'] > $rule['maxRaw']
                || !is_string($rule['evidence'] ?? null) || trim($rule['evidence']) === ''
                || !in_array($function, $rule['functions'] ?? [], true)) {
                throw new RuntimeException(sprintf('Register 0x%04X fuer diese Funktion nicht dokumentiert freigegeben.', $address + $offset));
            }
            if (!is_int($value) || $value < $rule['minRaw'] || $value > $rule['maxRaw']) {
                throw new InvalidArgumentException('Rohwert ausserhalb der freigegebenen Grenzen.');
            }
        }
    }

    private function read(int $address, int $count): array
    {
        $reply = $this->exchange(pack('Cnn', 3, $address, $count));
        if (strlen($reply) !== 2 + 2 * $count || ord($reply[1]) !== 2 * $count) {
            throw new RuntimeException('Ungueltige FC03-Antwort.');
        }
        return array_values(unpack('n*', substr($reply, 2)));
    }

    private function exchange(string $pdu): string
    {
        if (!is_resource($this->socket)) {
            $host = $this->device['host'];
            if (str_contains($host, ':')) {
                $host = '[' . trim($host, '[]') . ']';
            }
            $this->socket = @stream_socket_client('tcp://' . $host . ':' . $this->device['port'], $errno, $message,
                $this->device['timeoutSeconds'], STREAM_CLIENT_CONNECT);
            if (!is_resource($this->socket)) {
                throw new RuntimeException('Modbus-Verbindung fehlgeschlagen (Socket-Code ' . $errno . ').');
            }
            $timeout = (float) $this->device['timeoutSeconds'];
            stream_set_timeout($this->socket, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));
        }
        $this->transaction = ($this->transaction % 65535) + 1;
        $frame = pack('nnnC', $this->transaction, 0, strlen($pdu) + 1, $this->device['unitId']) . $pdu;
        while ($frame !== '') {
            $sent = fwrite($this->socket, $frame);
            if ($sent === false || $sent === 0) {
                throw new RuntimeException('Anfrage nicht vollstaendig gesendet.');
            }
            $frame = substr($frame, $sent);
        }
        $header = unpack('ntransaction/nprotocol/nlength/Cunit', $this->exact(7));
        if ($header['transaction'] !== $this->transaction || $header['protocol'] !== 0
            || $header['unit'] !== $this->device['unitId'] || $header['length'] < 2 || $header['length'] > 254) {
            throw new RuntimeException('Ungueltiger Modbus-Antwortheader.');
        }
        $reply = $this->exact($header['length'] - 1);
        if (ord($reply[0]) !== ord($pdu[0])) {
            throw new RuntimeException('Unerwartete Funktion oder Modbus-Exception, Code ' . (isset($reply[1]) ? ord($reply[1]) : -1));
        }
        return $reply;
    }

    private function exact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Modbus-Antwort unvollstaendig oder Timeout.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }
}

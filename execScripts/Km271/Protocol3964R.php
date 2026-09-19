<?php
declare(strict_types=1);

namespace PbdKn\CohSensorcollector\Km271;

use RuntimeException;

/** 3964R-Teilnehmer fuer Empfang und ausdruecklich aufgerufenen Versand. */
final class Protocol3964R
{
    public const STX = "\x02";
    public const ETX = "\x03";
    public const DLE = "\x10";
    public const NAK = "\x15";

    /** @var resource */
    private $stream;
    /** @var callable(string): void */
    private $logger;
    private string $pendingInput = '';

    public function __construct($stream, callable $logger, private float $telegramTimeout = 4.0)
    {
        if (!is_resource($stream) || $telegramTimeout <= 0) {
            throw new RuntimeException('Ungueltiger 3964R-Stream oder Timeout.');
        }
        $this->stream = $stream;
        $this->logger = $logger;
        stream_set_blocking($this->stream, false);
    }

    public function receive(float $waitSeconds): ?string
    {
        $listenDeadline = microtime(true) + max(0.0, $waitSeconds);
        while (microtime(true) < $listenDeadline) {
            $byte = $this->readByte($listenDeadline);
            if ($byte === null) {
                return null;
            }
            ($this->logger)('RX idle ' . self::hex($byte));
            if ($byte !== self::STX) {
                continue;
            }

            $this->writeControl(self::DLE, 'start acknowledgement');
            try {
                return $this->receiveBody(microtime(true) + $this->telegramTimeout);
            } catch (RuntimeException $exception) {
                $this->writeControl(self::NAK, 'telegram rejected');
                ($this->logger)('3964R ERROR ' . $exception->getMessage());
                continue;
            }
        }
        return null;
    }

    /** Aktiviert den rein lesenden KM271-Logmodus (Kommando EE 00 00). */
    public function startLogMode(float $timeout = 3.0): void
    {
        $deadline = microtime(true) + $timeout;
        $this->writeControl(self::STX, 'request log mode');
        while (true) {
            $answer = $this->requireByte($deadline, 'DLE vor Logmodus');
            ($this->logger)('RX control ' . self::hex($answer));
            if ($answer === self::DLE) {
                break;
            }
            if ($answer === self::STX) {
                // Beide Partner wollten gleichzeitig senden. Das KM271-
                // Referenzverfahren beantwortet dessen STX erneut mit STX.
                $this->writeControl(self::STX, 'log mode collision retry');
                continue;
            }
            throw new RuntimeException('Logmodus nicht bereit: erwartet DLE, empfangen ' . self::hex($answer));
        }

        $this->writePayload("\xEE\x00\x00", 'read-only log mode');
        $answer = $this->requireByte($deadline, 'DLE nach Logmodus');
        ($this->logger)('RX control ' . self::hex($answer));
        if ($answer === self::STX) {
            // Manche KM271 beginnen nach Annahme des Logkommandos sofort mit
            // dem ersten Datenblock. Das STX gehoert dann bereits zu receive().
            $this->pendingInput .= $answer;
            ($this->logger)('3964R log mode accepted; first telegram already started');
            return;
        }
        if ($answer !== self::DLE) {
            throw new RuntimeException('Logmodus nicht bestaetigt: erwartet DLE, empfangen ' . self::hex($answer));
        }
    }

    /** Sendet genau einen Nutzdatenblock und wartet auf die KM271-Bestaetigung. */
    public function send(string $payload, float $timeout = 4.0): void
    {
        if ($payload === '' || $timeout <= 0) {
            throw new RuntimeException('Ungueltige 3964R-Nutzdaten oder Timeout.');
        }
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $deadline = microtime(true) + $timeout;
            $this->writeControl(self::STX, "send request $attempt");
            for ($collision = 0; $collision <= 3; ++$collision) {
                $answer = $this->requireByte($deadline, 'DLE vor Schreibauftrag');
                ($this->logger)('RX control ' . self::hex($answer));
                if ($answer === self::STX) {
                    $this->writeControl(self::STX, 'send collision retry');
                    continue;
                }
                if ($answer !== self::DLE) {
                    throw new RuntimeException('Schreibauftrag nicht bereit: erwartet DLE, empfangen ' . self::hex($answer));
                }
                break;
            }
            if ($answer !== self::DLE) {
                throw new RuntimeException('Schreibauftrag wegen wiederholter 3964R-Kollision nicht gesendet.');
            }
            usleep(50_000);
            $this->writePayload($payload, "write command $attempt");
            $answer = $this->requireByte($deadline, 'DLE nach Schreibauftrag');
            ($this->logger)('RX control ' . self::hex($answer));
            if ($answer === self::DLE) {
                return;
            }
            if ($answer === self::NAK && $attempt < 3) {
                ($this->logger)("3964R NAK; retrying write command ($attempt/3)");
                usleep(250_000);
                continue;
            }
            if ($answer === self::NAK) {
                throw new RuntimeException('KM271 hat den Schreibauftrag dreimal mit NAK abgelehnt.');
            }
            throw new RuntimeException('Unerwartete Antwort nach Schreibauftrag: ' . self::hex($answer));
        }
    }

    private function receiveBody(float $deadline): string
    {
        $payload = '';
        $checksum = 0;
        while (true) {
            $byte = $this->requireByte($deadline, 'Nutzdaten');
            ($this->logger)('RX data ' . self::hex($byte));
            if ($byte !== self::DLE) {
                $payload .= $byte;
                $checksum ^= ord($byte);
                continue;
            }

            $next = $this->requireByte($deadline, 'Byte nach DLE');
            ($this->logger)('RX data ' . self::hex($next));
            if ($next === self::DLE) {
                $payload .= self::DLE;
                // 3964R bildet die BCC ueber die tatsaechlich uebertragenen
                // Bytes. Ein Nutzdaten-DLE steht zweimal auf der Leitung.
                $checksum ^= ord(self::DLE) ^ ord(self::DLE);
                continue;
            }
            if ($next !== self::ETX) {
                throw new RuntimeException('Ungueltige DLE-Sequenz: 10 ' . self::hex($next));
            }

            $checksum ^= ord(self::DLE) ^ ord(self::ETX);
            $receivedBcc = $this->requireByte($deadline, 'BCC');
            ($this->logger)(sprintf('RX BCC %s, berechnet %02X', self::hex($receivedBcc), $checksum));
            if (ord($receivedBcc) !== $checksum) {
                throw new RuntimeException(sprintf('BCC falsch: empfangen %02X, erwartet %02X', ord($receivedBcc), $checksum));
            }
            $this->writeControl(self::DLE, 'telegram acknowledgement');
            return $payload;
        }
    }

    private function requireByte(float $deadline, string $phase): string
    {
        $byte = $this->readByte($deadline);
        if ($byte === null) {
            throw new RuntimeException('Timeout bei ' . $phase);
        }
        return $byte;
    }

    private function readByte(float $deadline): ?string
    {
        if ($this->pendingInput !== '') {
            $byte = $this->pendingInput[0];
            $this->pendingInput = substr($this->pendingInput, 1);
            return $byte;
        }
        while (($remaining = $deadline - microtime(true)) > 0) {
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $read = [$this->stream];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new RuntimeException('stream_select fehlgeschlagen.');
            }
            if ($selected === 0) {
                return null;
            }
            $byte = fread($this->stream, 1);
            if ($byte === false) {
                throw new RuntimeException('Lesefehler am TCP-Stream.');
            }
            if ($byte !== '') {
                return $byte;
            }
            if (feof($this->stream)) {
                throw new RuntimeException('TCP-Verbindung wurde geschlossen.');
            }
        }
        return null;
    }

    private function writeControl(string $byte, string $reason): void
    {
        if (fwrite($this->stream, $byte) !== 1) {
            throw new RuntimeException('3964R-Steuerzeichen konnte nicht gesendet werden.');
        }
        fflush($this->stream);
        ($this->logger)('TX ' . self::hex($byte) . ' (' . $reason . ')');
    }

    private function writePayload(string $payload, string $reason): void
    {
        $wire = '';
        $bcc = 0;
        foreach (str_split($payload) as $byte) {
            $wire .= $byte;
            $bcc ^= ord($byte);
            if ($byte === self::DLE) {
                $wire .= $byte;
                $bcc ^= ord($byte);
            }
        }
        $bcc ^= ord(self::DLE) ^ ord(self::ETX);
        $frame = $wire . self::DLE . self::ETX . chr($bcc);
        if (fwrite($this->stream, $frame) !== strlen($frame)) {
            throw new RuntimeException('3964R-Nutztelegramm konnte nicht vollstaendig gesendet werden.');
        }
        fflush($this->stream);
        ($this->logger)('TX payload ' . self::hex($frame) . ' (' . $reason . ')');
    }

    public static function hex(string $bytes): string
    {
        return strtoupper(implode(' ', str_split(bin2hex($bytes), 2)));
    }
}

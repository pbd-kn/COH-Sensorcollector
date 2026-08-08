<?php

declare(strict_types=1);

/**
 * Rein lesender Modbus-TCP-Zugriff auf AMPERE.StoragePro / SAJ HS2/H2.
 *
 * Keine externe PHP-Bibliothek erforderlich. Die Klasse implementiert nur
 * Modbus-Funktion 03 (Read Holding Registers); Schreibzugriffe sind absichtlich
 * nicht enthalten.
 */
final class AmpereStorageProModbus
{
    private string $host;
    private int $port;
    private int $unitId;
    private float $timeout;

    /** @var resource|null */
    private $socket = null;

    private int $transactionId = 0;

    /**
     * Felddefinitionen: Adresse, Datentyp, Faktor, Einheit, Beschreibung.
     * Adressen sind nullbasierte Modbus-Protokolladressen.
     *
     * @var array<string,array{address:int,type:string,scale:float,unit:string,description:string}>
     */
    private const FIELDS = [
        'inverter.temperature' => ['address' => 0x4010, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Temperatur des Wechselrichters'],
        'inverter.ambientTemperature' => ['address' => 0x4011, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Umgebungstemperatur des Wechselrichters'],

        'grid.l1.voltage' => ['address' => 0x4031, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Netzspannung Phase L1'],
        'grid.l1.current' => ['address' => 0x4032, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Netzstrom Phase L1'],
        'grid.l1.frequency' => ['address' => 0x4033, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'Hz', 'description' => 'Netzfrequenz Phase L1'],
        'grid.l1.power' => ['address' => 0x4035, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Wirkleistung Phase L1'],
        'grid.l2.voltage' => ['address' => 0x4038, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Netzspannung Phase L2'],
        'grid.l2.current' => ['address' => 0x4039, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Netzstrom Phase L2'],
        'grid.l2.frequency' => ['address' => 0x403A, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'Hz', 'description' => 'Netzfrequenz Phase L2'],
        'grid.l2.power' => ['address' => 0x403C, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Wirkleistung Phase L2'],
        'grid.l3.voltage' => ['address' => 0x403F, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Netzspannung Phase L3'],
        'grid.l3.current' => ['address' => 0x4040, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Netzstrom Phase L3'],
        'grid.l3.frequency' => ['address' => 0x4041, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'Hz', 'description' => 'Netzfrequenz Phase L3'],
        'grid.l3.power' => ['address' => 0x4043, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Wirkleistung Phase L3'],

        'battery.temperature' => ['address' => 0x406E, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Allgemeine Batterietemperatur'],
        'battery.socSummary' => ['address' => 0x406F, 'type' => 'uint16', 'scale' => 0.01, 'unit' => '%', 'description' => 'Zusammengefasster Batterie-Ladezustand'],
        'pv.string1.voltage' => ['address' => 0x4071, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'PV-String 1 Spannung'],
        'pv.string1.current' => ['address' => 0x4072, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'PV-String 1 Strom'],
        'pv.string1.power' => ['address' => 0x4073, 'type' => 'uint16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'PV-String 1 Leistung'],
        'pv.string2.voltage' => ['address' => 0x4074, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'PV-String 2 Spannung'],
        'pv.string2.current' => ['address' => 0x4075, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'PV-String 2 Strom'],
        'pv.string2.power' => ['address' => 0x4076, 'type' => 'uint16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'PV-String 2 Leistung'],

        'house.power' => ['address' => 0x40A0, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Gesamter aktueller Hausverbrauch'],
        'pv.power' => ['address' => 0x40A5, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Gesamte aktuelle PV-Leistung'],
        'battery.power' => ['address' => 0x40A6, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Aktuelle Lade- oder Entladeleistung der Batterie'],
        'grid.powerSummary' => ['address' => 0x40A7, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Aggregierte Netzleistung'],
        'inverter.power' => ['address' => 0x40A9, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Aktuelle Wechselrichterleistung'],
        'grid.power' => ['address' => 0x40AD, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Saldierte Leistung am Netzübergabepunkt'],

        'energy.pv.today' => ['address' => 0x40BF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie heute'],
        'energy.pv.month' => ['address' => 0x40C1, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie im laufenden Monat'],
        'energy.pv.year' => ['address' => 0x40C3, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie im laufenden Jahr'],
        'energy.pv.total' => ['address' => 0x40C5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie gesamt'],
        'energy.battery.chargeToday' => ['address' => 0x40C7, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieladung heute'],
        'energy.battery.chargeTotal' => ['address' => 0x40CD, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieladung gesamt'],
        'energy.battery.dischargeToday' => ['address' => 0x40CF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieentladung heute'],
        'energy.battery.dischargeTotal' => ['address' => 0x40D5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieentladung gesamt'],
        'energy.inverter.today' => ['address' => 0x40D7, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Wechselrichtererzeugung heute'],
        'energy.inverter.total' => ['address' => 0x40DD, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Wechselrichtererzeugung gesamt'],
        'energy.house.today' => ['address' => 0x40DF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Interner SAJ-Lastzähler heute; nicht als bilanziellen Hausverbrauch verwenden'],
        'energy.house.total' => ['address' => 0x40E5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Interner SAJ-Lastzähler gesamt; nicht als bilanziellen Hausverbrauch verwenden'],
        'energy.grid.sellToday' => ['address' => 0x40EF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzeinspeisung (Export) heute'],
        'energy.grid.sellTotal' => ['address' => 0x40F5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzeinspeisung (Export) gesamt'],
        'energy.grid.feedInToday' => ['address' => 0x40F7, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzbezug (Import) heute'],
        'energy.grid.feedInTotal' => ['address' => 0x40FD, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzbezug (Import) gesamt'],
        'energy.grid.sumFeedInToday' => ['address' => 0x4167, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzbezug heute, Summe'],
        'energy.grid.sumSellToday' => ['address' => 0x416F, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzeinspeisung heute, Summe'],

        'battery.soc' => ['address' => 0xA00C, 'type' => 'uint16', 'scale' => 0.01, 'unit' => '%', 'description' => 'Ladezustand Batterie 1'],
        'battery.soh' => ['address' => 0xA00D, 'type' => 'uint16', 'scale' => 0.01, 'unit' => '%', 'description' => 'Gesundheitszustand Batterie 1'],
        'battery.voltage' => ['address' => 0xA00E, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Spannung Batterie 1'],
        'battery.current' => ['address' => 0xA00F, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Strom Batterie 1'],
        'battery.moduleTemperature' => ['address' => 0xA010, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Temperatur Batterie 1'],
        'battery.cycles' => ['address' => 0xA011, 'type' => 'uint16', 'scale' => 1.0, 'unit' => '', 'description' => 'Zyklenzahl Batterie 1'],
    ];

    /**
     * Zusammenhängende, von der SAJ-H2-Integration erprobte Leseblöcke.
     *
     * @var list<array{0:int,1:int}>
     */
    private const SNAPSHOT_BLOCKS = [
        [0x4010, 2],
        [0x4031, 21],
        [0x406E, 15],
        [0x40A0, 14],
        [0x40BF, 32],
        [0x40DF, 32],
        [0xA00C, 6],
    ];

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
        if ($timeout <= 0) {
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

    /** @return array<string,array{address:int,addressHex:string,type:string,scale:float,unit:string,description:string}> */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::FIELDS as $name => $definition) {
            $catalog[$name] = $definition + ['addressHex' => sprintf('0x%04X', $definition['address'])];
        }
        return $catalog;
    }

    /** @return int|float */
    public function readValue(string $name)
    {
        if (!isset(self::FIELDS[$name])) {
            throw new InvalidArgumentException("Unbekannter StoragePro-Wert '$name'.");
        }

        $definition = self::FIELDS[$name];
        $count = $definition['type'] === 'uint32' ? 2 : 1;
        $registers = $this->readHoldingRegisters($definition['address'], $count);
        return self::decode($registers, 0, $definition['type'], $definition['scale']);
    }

    /**
     * Liest alle definierten Messwerte blockweise und liefert fachlich gruppierte Daten.
     *
     * @return array<string,mixed>
     */
    public function readSnapshot(): array
    {
        $rawByAddress = [];
        foreach (self::SNAPSHOT_BLOCKS as [$address, $count]) {
            $registers = $this->readHoldingRegisters($address, $count);
            foreach ($registers as $offset => $value) {
                $rawByAddress[$address + $offset] = $value;
            }
        }

        $values = [];
        foreach (self::FIELDS as $name => $definition) {
            $address = $definition['address'];
            if (!array_key_exists($address, $rawByAddress)) {
                continue;
            }
            $registers = [$rawByAddress[$address]];
            if ($definition['type'] === 'uint32') {
                if (!array_key_exists($address + 1, $rawByAddress)) {
                    continue;
                }
                $registers[] = $rawByAddress[$address + 1];
            }
            $values[$name] = self::decode($registers, 0, $definition['type'], $definition['scale']);
        }
        foreach (['energy.grid.sumFeedInToday', 'energy.grid.sumSellToday'] as $name) {
            try {
                $values[$name] = $this->readValue($name);
            } catch (Throwable) {
                // Optionale Summenzähler sind nicht in jeder Firmware verfügbar.
            }
        }
        $values['energy.house.calculatedToday'] = $values['energy.house.today'];


        $nested = [];
        foreach ($values as $path => $value) {
            self::setPath($nested, $path, $value);
        }

        return [
            'device' => 'AMPERE.StoragePro',
            'host' => $this->host,
            'port' => $this->port,
            'unitId' => $this->unitId,
            'timestamp' => date(DATE_ATOM),
            'data' => $nested,
            'aliases' => [
                'batterySoc' => $values['battery.soc'] ?? null,
                'batteryTemperature' => $values['battery.temperature'] ?? null,
                'batteryPower' => $values['battery.power'] ?? null,
                'pvPower' => $values['pv.power'] ?? null,
                'inverterPower' => $values['inverter.power'] ?? null,
                'housePower' => $values['house.power'] ?? null,
                'houseConsumptionToday' => $values['energy.house.calculatedToday'] ?? null,
                'gridPower' => $values['grid.power'] ?? null,
                'batteryTotalChargeEnergy' => $values['energy.battery.chargeTotal'] ?? null,
                'batteryTotalDischargeEnergy' => $values['energy.battery.dischargeTotal'] ?? null,
                'pvTotalEnergy' => $values['energy.pv.total'] ?? null,
                'gridSellTotal' => $values['energy.grid.sellTotal'] ?? null,
                'gridFeedInTotal' => $values['energy.grid.feedInTotal'] ?? null,
            ],
        ];
    }

    /**
     * Modbus-Funktion 03. Dies ist die einzige vom Client angebotene Operation.
     *
     * @return list<int> Unsigned 16-Bit-Rohregister
     */
    public function readHoldingRegisters(int $address, int $count): array
    {
        if ($address < 0 || $address > 65535) {
            throw new InvalidArgumentException('Registeradresse außerhalb von 0..65535.');
        }
        if ($count < 1 || $count > 125 || $address + $count - 1 > 65535) {
            throw new InvalidArgumentException('Registeranzahl außerhalb des zulässigen Bereichs.');
        }

        $this->connect();
        $transactionId = $this->nextTransactionId();
        $request = pack('nnnCCnn', $transactionId, 0, 6, $this->unitId, 3, $address, $count);

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
            if ($mbap['unit'] !== $this->unitId || $mbap['length'] < 2) {
                throw new RuntimeException('Ungültige Unit-ID oder Länge in der Modbus-Antwort.');
            }

            $pdu = $this->readExact($mbap['length'] - 1);
            $function = ord($pdu[0]);
            if (($function & 0x80) !== 0) {
                $exception = strlen($pdu) > 1 ? ord($pdu[1]) : -1;
                throw new RuntimeException($this->formatException($function & 0x7F, $exception, $address));
            }
            if ($function !== 3 || strlen($pdu) < 2) {
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

    /** @param list<int> $registers
     *  @return int|float
     */
    public static function decode(array $registers, int $offset, string $type, float $scale = 1.0)
    {
        if (!array_key_exists($offset, $registers)) {
            throw new InvalidArgumentException('Register für Dekodierung fehlt.');
        }

        $value = $registers[$offset];
        if ($type === 'int16') {
            $value = $value >= 0x8000 ? $value - 0x10000 : $value;
        } elseif ($type === 'uint32') {
            if (!array_key_exists($offset + 1, $registers)) {
                throw new InvalidArgumentException('Zweites Register für UInt32 fehlt.');
            }
            $value = $value * 65536 + $registers[$offset + 1];
        } elseif ($type !== 'uint16') {
            throw new InvalidArgumentException("Unbekannter Modbus-Datentyp '$type'.");
        }

        $scaled = $value * $scale;
        return $scale === 1.0 ? (int)$scaled : round($scaled, 3);
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
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

    /** @param array<string,mixed> $target
     *  @param int|float $value
     */
    private static function setPath(array &$target, string $path, $value): void
    {
        $parts = explode('.', $path);
        $cursor =& $target;
        foreach ($parts as $index => $part) {
            if ($index === count($parts) - 1) {
                $cursor[$part] = $value;
                break;
            }
            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor =& $cursor[$part];
        }
    }
}

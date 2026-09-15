<?php

namespace PbdKn\cohSensorcollector;
use Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\SensorPararameter;

class SensorManager
{
    private SensorParameter $SensorParameter;

    //public function __construct(array $fetchers)
    public function __construct( private mysql_dialog $db, private Logger $logger, private array $fetchers) {
        $this->SensorParameter = SensorParameter::getInstance();
    }
    public function fetchAll(): array
    {
        return $this->fetchSensors([], true);
    }

    /**
     * Verarbeitet nur die angegebenen sensorIDs bzw. Sensornamen.
     * Bei leerer Auswahl werden alle Sensoren verarbeitet.
     */
    public function fetchSensors(array $selection = [], bool $updateHistoryCounters = false): array
    {
        $pollTime=$this->SensorParameter->getpollTime();  // Minuten
        $this->logger->debugMe( "Fetcher polltime $pollTime");

        // lese alle sensoren incl. geraet
        $sql = "SELECT sensor.*, geraet.* ";
        $sql .= "FROM tl_coh_sensors AS sensor ";
        $sql .= "LEFT JOIN tl_coh_geraete AS geraet ";
        $sql .= "ON sensor.sensorSource = geraet.geraeteID";
        $sql .= " ORDER BY geraet.geraeteID";
        $res=$this->db->query($sql);
        $sensors = [];
        while ($row = $res->fetch_assoc()) {
            if (!$this->isSelected($row, $selection)) {
                continue;
            }
            $sensors[] = $row;
        }        
    
        $allData = [];
        $manualSelection = $selection !== [];
        $this->logger->debugMe( "anz fetchers ".count($this->fetchers));
        foreach ($this->fetchers as $fetcher) {
            $supported = [];

            foreach ($sensors as $sensor) {               // speichere die sensorenpro fetcher
                if ($fetcher->supports($sensor)) {
                    if ($manualSelection) {
                        $supported[] = $sensor;
                        continue;
                    }
                    // Jeder aktive Sensor wird bei jedem Poll-Lauf gelesen. Die
                    // History-Einstellung steuert ausschliesslich das Speichern
                    // in collect.php, nicht mehr die Aktualitaet der Messung.
                    $supported[] = $sensor;
                    
                }
            }            
            if (!empty($supported)) {
                $this->logger->debugMe( "Fetcher " . get_class($fetcher) . " verarbeitet " . count($supported) . " Sensoren");
                $data = $fetcher->fetchArr($supported); // <- Jetzt wird ein Array übergeben
                if (is_array($data)) {
                    $data = $this->applyOutputModes($data, $supported);
                    $allData = array_merge($allData, $data);
                }
            } else {
                $this->logger->debugMe( "Keine Sensoren für ". get_class($fetcher));
            }
        }
        return $allData;
    }

    /**
     * Wertet outputMode einheitlich für die Ergebnisse aller Fetcher aus.
     * Das Konfigurationsfeld bleibt ausschließlich in tl_coh_sensors.
     */
    private function applyOutputModes(array $data, array $sensors): array
    {
        $configById = [];
        foreach ($sensors as $sensor) {
            $configById[(string) $sensor['sensorID']] = $sensor;
        }

        foreach ($data as $key => &$result) {
            if (!is_array($result)) {
                continue;
            }
            $sensorID = (string) ($result['sensorID'] ?? $key);
            $sensor = $configById[$sensorID] ?? null;
            $value = $result['sensorValue'] ?? null;
            if ($sensor === null || !is_numeric($value)) {
                continue;
            }

            $outputMode = strtolower(trim((string) ($sensor['outputMode'] ?? 'absolute')));
            if ($outputMode === '' || $outputMode === 'absolute') {
                continue;
            }

            $start = new \DateTimeImmutable('today midnight');
            $startTimestamp = match ($outputMode) {
                'daily' => $start->getTimestamp(),
                'woche' => $start->modify('-7 days')->getTimestamp(),
                'monat' => $start->modify('-30 days')->getTimestamp(),
                'jahr' => $start->modify('-365 days')->getTimestamp(),
                default => null,
            };
            if ($startTimestamp === null) {
                $this->logger->Info("Unbekannter outputMode '$outputMode' fuer Sensor $sensorID; Wert bleibt absolut.");
                continue;
            }

            $firstValue = $this->getFirstNumericValueSince((int) $sensor['id'], $startTimestamp);
            if ($firstValue === null) {
                // Erster Messwert der Periode: als Basis unverändert zurückgeben und speichern.
                continue;
            }

            $currentValue = (float) $value;
            $result['sensorValue'] = $currentValue >= $firstValue
                ? $currentValue - $firstValue
                : $currentValue; // Zählerrücksetzung
        }
        unset($result);

        return $data;
    }

    private function getFirstNumericValueSince(int $sensor, int $start): ?float
    {
        $stmt = $this->db->prepare(
            'SELECT sensorValue
               FROM tl_coh_sensorvalue
              WHERE sensor = ? AND tstamp >= ?
              ORDER BY tstamp ASC, id ASC
              LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('prepare(first outputMode value) failed: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $sensor, $start);
        $stmt->execute();
        $stmt->bind_result($value);
        $found = $stmt->fetch();
        $stmt->close();

        return $found && is_numeric($value) ? (float) $value : null;
    }

    /**
     * Liefert die konfigurierten Sensoren fuer die Konsolen-Auswahl.
     * Die Quelle kann vollstaendig oder als Teiltext angegeben werden.
     */
    public function getSensorOverview(?string $source = null): array
    {
        $sql = "SELECT sensor.sensorID, sensor.sensorLokalId, sensor.sensorTitle, sensor.sensorSource
                  FROM tl_coh_sensors AS sensor
              ORDER BY sensor.sensorSource, sensor.sensorTitle, sensor.sensorID";
        $res = $this->db->query($sql);
        if (!$res) {
            throw new \RuntimeException('Sensorliste konnte nicht gelesen werden: ' . $this->db->error);
        }

        $source = $source !== null ? strtolower(trim($source)) : null;
        $sensors = [];
        while ($row = $res->fetch_assoc()) {
            $sourceId = trim((string) ($row['sensorSource'] ?? ''));
            if ($source !== null && $source !== ''
                && strpos(strtolower($sourceId), $source) === false) {
                continue;
            }
            $sensors[] = [
                'sensorID' => (string) ($row['sensorID'] ?? ''),
                'sensorLokalId' => (string) ($row['sensorLokalId'] ?? ''),
                'sensorTitle' => (string) ($row['sensorTitle'] ?? ''),
                'source' => $sourceId,
            ];
        }
        $res->free();
        return $sensors;
    }

    /** Liefert die vollstaendige Sensor-Konfiguration ohne verknuepfte Geraetedaten. */
    public function getSensorDetails(array $selection): array
    {
        $res = $this->db->query("SELECT * FROM tl_coh_sensors ORDER BY sensorSource, sensorTitle, sensorID");
        if (!$res) {
            throw new \RuntimeException('Sensordetails konnten nicht gelesen werden: ' . $this->db->error);
        }

        $details = [];
        while ($sensor = $res->fetch_assoc()) {
            if (!$this->isSelected($sensor, $selection)) {
                continue;
            }
            $details[(string) ($sensor['sensorID'] ?? count($details))] = $sensor;
        }
        $res->free();
        return $details;
    }

    private function isSelected(array $sensor, array $selection): bool
    {
        if ($selection === []) {
            return true;
        }

        $wanted = array_map(static fn ($value): string => strtolower(trim((string) $value)), $selection);
        $candidates = [
            strtolower(trim((string) ($sensor['sensorID'] ?? ''))),
            strtolower(trim((string) ($sensor['sensorTitle'] ?? ''))),
        ];

        return array_intersect($wanted, $candidates) !== [];
    }
}
?>

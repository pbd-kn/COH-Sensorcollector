<?php

namespace PbdKn\cohSensorcollector;
use Sensor\SensorFetcherInterface;
use PbdKn\cohSensorcollector\Logger;

class SensorManager
{
    private array $fetchers;
    private ?Logger $logger = null;

    public function __construct(array $fetchers)
    {
        $this->fetchers = $fetchers;
        //$this->logger = new Logger(debug: true);
        $this->logger = Logger::getInstance();
    }

    public function fetchAll($db): array
    {
        // lese alle sensoren incl. geraet
        $sql = "SELECT sensor.*, geraet.* ";
        $sql .= "FROM tl_coh_sensors AS sensor ";
        $sql .= "LEFT JOIN tl_coh_geraete AS geraet ";
        $sql .= "ON sensor.sensorSource = geraet.geraeteID";
        $sql .= " ORDER BY geraet.geraeteID";
        $res=$db->query($sql);
        $sensors = [];
        while ($row = $res->fetch_assoc()) {
            $sensors[] = $row;
        }        
    
        $allData = [];
        $this->logger->debugMe( "anz fetchers ".count($this->fetchers));
        foreach ($this->fetchers as $fetcher) {
            $supported = [];

            foreach ($sensors as $sensor) {               // speichere die sensorenen pro fetcher
                if ($fetcher->supports($sensor)) {
                    $supported[] = $sensor;
                }
            }            
            if (!empty($supported)) {
                $this->logger->debugMe( "Fetcher " . get_class($fetcher) . " verarbeitet " . count($supported) . " Sensoren");
                $data = $fetcher->fetchArr($supported); // <- Jetzt wird ein Array übergeben
                if (is_array($data)) {
                    $allData = array_merge($allData, $data);
                }
            } else {
                $this->logger->debugMe( "Keine Sensoren fuer ". get_class($fetcher));           
            }
        }
        return $allData;
    }
}
?>
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
            $sensors[] = $row;
        }        
    
        $allData = [];
        $this->logger->debugMe( "anz fetchers ".count($this->fetchers));
        foreach ($this->fetchers as $fetcher) {
            $supported = [];

            foreach ($sensors as $sensor) {               // speichere die sensorenpro fetcher
                if ($fetcher->supports($sensor)) {
                    if ($sensor['isHistory'] === '1') {
                        $sensorID = $sensor['sensorID'];
                        $history=$sensor['history'];     // kennzeichnung wie oft gepollt wird bei history = 1 muss gepollt werden historycount nicht berücksichtigen
                        $historycount = $sensor['historycount'] ; // bei historycount <= 0 muss gepollt werden
                            /* 'history' => [
                                'label' => ['Speichern', '0 = nein, 1 = polltime, 2 = stündlich, 3 = täglich, 4 = wöchentlich, 5 = monatlich'],
                                'inputType' => 'select',
                                'options'   => [0,1,2,3,4,5],
                                'reference' => ['Nein','Polltime','Stündlich','Täglich','Wöchentlich','Monatlich'],
                                'eval'      => ['tl_class'=>'w50'],
                                'sql'       => "tinyint(1) NOT NULL default '0'",
                                ],
                            */
                        $this->logger->debugMe( "isHistory " . $sensor['isHistory']  . " sensorID " . $sensor['sensorID']  . " history " . $sensor['history'] . " historycount " . $sensor['historycount']);
                        switch ($history) {
                            case 0:  $text = 'Null'; continue 2;  // gehe 2 noch oben als switch und foreach
                            case 1:  $text = 'pollTime';  $supported[] = $sensor; continue 2;
                            case 2:  $text = 'Stündlich';  $maxcount = (int)(60 / $pollTime);  break;
                            case 3:  $text = 'Täglich'; $maxcount = (int)(60*12 / $pollTime); break;
                            case 4:  $text = 'Wöchentlich'; $maxcount = (int)(60*12*7 / $pollTime); break;
                            case 5:  $text = 'Monatlich';  $maxcount = (int)(60*12*7*30 / $pollTime); break;
                            default: $text = 'Null'; continue 2;
                        }
                        $historycount--;
                        if ($historycount <= 0) {
                            $historycount = $maxcount;
                            $supported[] = $sensor; 
                            //$this->logger->Info( "History neu gesetzt isHistory "  . " sensorID " . $sensor['sensorID'] ." isHistory " . $sensor['isHistory']  . " history " . $sensor['history'] . " historycount(DB) " . $sensor['historycount'] . " maxcount $maxcount text $text");
                        }
                        $sql = "
                                UPDATE tl_coh_sensors
                                    SET historyCount = ?
                                    WHERE sensorID = ?
                                ";
                        $stmt = $this->db->prepare($sql);
                        if ($stmt) {
                            $stmt->bind_param('is', $historycount, $sensorID);
                            $stmt->execute();
                            $this->logger->debugMe("UPDATE DEBUG sensorID=$sensorID historycount=$historycount affected=" . $stmt->affected_rows);
                            $stmt->close();  
                        } else {
                            $this->logger->Error("Sensormanager prepare failed: sql $sql sensorID $sensorID historyCount $historycount" . $db->error);
                        }                      
                    } else {                  
                        $supported[] = $sensor; 
                    }
                    
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
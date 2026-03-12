<?php
namespace PbdKn\cohSensorcollector\Sensor;

use PbdKn\cohSensorcollector\mysql_dialog;

use PbdKn\cohSensorcollector\Logger;
use PbdKn\cohSensorcollector\SimpleHttpClient;
// interface um db logger und smptclient erweitert 
// alle fetcher braucht nun eine constructor so 
//     public function __construct( private mysql_dialog $db, private Logger $logger, private SimpleHttpClient $httpClient) {}        


interface SensorFetcherInterface
{

    public function __construct( mysql_dialog $db, Logger $logger, SimpleHttpClient $httpClient );
    public function supports($sensor): bool;

    public function fetch($sensor): ?array; // <- HIER!

    public function fetchArr(array $sensors): ?array; // neue Methode
}
?>
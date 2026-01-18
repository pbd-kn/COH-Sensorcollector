<?php
namespace PbdKn\cohSensorcollector\Sensor;

interface SensorFetcherInterface
{
    public function supports($sensor): bool;

    public function fetch($sensor): ?array; // <- HIER!

    public function fetchArr(array $sensors): ?array; // neue Methode
}
?>
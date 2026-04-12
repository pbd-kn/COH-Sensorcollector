<?php

namespace PbdKn\cohSensorcollector;

/* Versendung als singleton 
$sp = SensorParameter::getInstance();

$sp->set('PV', 'value', 5.6);
$sp->set('BAT', 'value', 87);

echo $sp->get('PV', 'value');  // 5.6
echo $sp->get('BAT', 'value'); // 87


$sp->setpollTime('10');  // z.B. 10 Minuten
echo $s->pollTime(); // 
$sp->set('anton',25);
echo $sp->get('anton');
*/
class SensorParameter
{
    private static ?SensorParameter $instance = null;

    private string $pollTime;
    private array $data = [];

    // Konstruktor privat ? verhindert new von außen
    private function __construct() {}
    // ---------------------------
    // Singleton Zugriff
    // ---------------------------
    public static function getInstance(): SensorParameter
    {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }
    // ---------------------------
    // FIXE GETTER / SETTER
    // ---------------------------
    public function setpollTime(int $minute): void
    {
        if ($minute === '') {
            throw new \InvalidArgumentException("setpollTime parameter darf nicht leer sein");
        }
        $this->pollTime = $minute;
    }

    public function getpollTime(): int
    {
        return $this->pollTime;
    }

    public function setSensorTitle(?string $title): void
    {
        $this->sensorTitle = $title;
    }

    public function getSensorTitle(): ?string
    {
        return $this->sensorTitle;
    }

    // ---------------------------
    // FLEXIBLE DATEN
    // ---------------------------
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function getAll(): array
    {
        return $this->data;
    }
}
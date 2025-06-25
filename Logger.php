<?php

namespace PbdKn\cohSensorcollector;
use Symfony\Component\DependencyInjection\ContainerInterface;


/* logger aufruf
$logger = Logger::getInstance();                            // beides Standard: null, false
$logger = Logger::getInstance("log.txt");                   // nur Dateiname
$logger = Logger::getInstance("log.txt", true);             // beides setzen
$logger = Logger::getInstance(debug: true);                 // nur Debug true
$logger = Logger::getInstance(dateiname: "log.txt");        // auch mit benanntem Param
*/

class Logger
{
    private string $dateiname;
    private bool $debug = false;
    // Variablen für späte Initialisierung
    private static ?Logger $instance = null;
    private ?StreamHandler $streamHandler = null;
    
    private function __construct(?string $dateiname = null, bool $debug = false)
    {
//        echo "Logger instanziiert mit Datei: {$dateiname}, Debug: " . ($debug ? 'an' : 'aus') . "\n";
        $this->debug=$debug;
        if ($dateiname !== null) $this->dateiname=$dateiname;
//        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        
    }

    private function __clone() {
        // verhindert, dass jemand $cloned = clone $logger macht
        throw new \Exception('Cloning eines Singletons ist nicht erlaubt!');
    }

    public function __wakeup() {
        // verhindert, dass jemand das Objekt per unserialize() wiederherstellt
        throw new \Exception('Wakeup eines Singletons ist nicht erlaubt!');
    }
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public static function getInstance(?string $dateiname = null, bool $debug = false): Logger
    {
        if (self::$instance === null) {
            self::$instance = new self($dateiname, $debug);
        }

        return self::$instance;
    }
    
    public function debugMe(string $txt): void
    {
        if ($this->debug) {
            echo $this->addDebugInfoToText("debugMe: ".$txt);
        }
    }

    public function Error(string $txt): void
    {
        echo $this->addDebugInfoToText("Error: ".$txt);
    }
    public function isDebug(): bool
    {
        return $this->debug;
    }
    
    /* füege modul funktion und zeile dazu
     *
     */
    private function addDebugInfoToText(string $text): string
    {
        // Hole den aktuellen Stack-Trace und extrahiere Informationen
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[1];

        // Extrahiere den Dateinamen und die Zeilennummer
        $file = isset($caller['file']) ? $caller['file'] : 'unknown file';
        $line = isset($caller['line']) ? $caller['line'] : 'unknown line';

        // Extrahiere den Funktionsnamen
        $function = isset($caller['function']) ? $caller['function'] : 'unknown function';

        // Baue den Log-Text mit dem Modulnamen (Dateiname, Zeilennummer und Funktionsname) zusammen
        $logInfo = sprintf('[%s:%d] %s : %s', basename($file), $line, $function, $text);

        // Rückgabe des erweiterten Text
        return $logInfo."\n";
    }

}
?>
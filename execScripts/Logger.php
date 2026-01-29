<?php

class Logger
{
    private string $logfile ="";
    private bool $debug = false;
    private $logfileHandle = null;
    
    public function __construct(?string $logfile = null, bool $debug = false)
    {
        $this->debug=$debug;
        if ($logfile) { 
            $dir = dirname($logfile);
                // nur öffnen, wenn Verzeichnis existiert und beschreibbar ist
            if (is_dir($dir) && is_writable($dir)) {
                $this->logfile=$logfile;
                $this->logfileHandle = @fopen($logfile, 'a');
            } else {
              $this->logfile="";
              $this->logfileHandle=null;
            }
        }            
    }

    public function setLogfile (?string $logfile = null) {
        if ($this->logfileHandle) {
            fclose($this->logfileHandle);
            $this->logfileHandle = null;
        }
        if ($logfile) { 
            $dir = dirname($logfile);
                // nur öffnen, wenn Verzeichnis existiert und beschreibbar ist
            if (is_dir($dir) && is_writable($dir)) {
                $this->logfile=$logfile;
                $this->logfileHandle = @fopen($logfile, 'a');
            } else {
              $this->logfile="";
              $this->logfileHandle=null;
            }
        }            
    }
    
    public function setDebug (bool $debug) {
        $this->debug=$debug;
    }        

    public function debugMe(string $txt): void
    {
        if ($this->debug) {
            if ($this->logfileHandle) {
                fwrite($this->logfileHandle, $this->addDebugInfoToText($txt));
            } else {
                echo $this->addDebugInfoToText("debugMe: ".$txt);
            }
        }
    }

    public function Info(string $txt): void
    {
        if ($this->logfileHandle) {
            fwrite($this->logfileHandle, $this->addDebugInfoToText($txt));
        } else {
            echo $this->addDebugInfoToText("Info: ".$txt);
        }
        
    }

    public function Error(string $txt): void
    {
        if ($this->logfileHandle) {
            fwrite($this->logfileHandle, $this->addDebugInfoToText($txt));
        } else {
            echo $this->addDebugInfoToText("Error: ".$txt);
        }
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
<?php

declare(strict_types=1);

require_once __DIR__ . '/AmpereStorageProModbus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur für die Kommandozeile.\n");
}

$arguments = array_slice($argv, 1);
$host = 'ASP-HSR2103J2311E08738.local';
$port = 502;
$unitId = 1;
$timeout = 3.0;
$valueName = null;
$showCatalog = false;

for ($index = 0; $index < count($arguments); $index++) {
    $argument = $arguments[$index];
    if ($argument === '--host' && isset($arguments[$index + 1])) {
        $host = $arguments[++$index];
    } elseif ($argument === '--port' && isset($arguments[$index + 1])) {
        $port = (int)$arguments[++$index];
    } elseif ($argument === '--unit' && isset($arguments[$index + 1])) {
        $unitId = (int)$arguments[++$index];
    } elseif ($argument === '--timeout' && isset($arguments[$index + 1])) {
        $timeout = (float)$arguments[++$index];
    } elseif ($argument === '--value' && isset($arguments[$index + 1])) {
        $valueName = $arguments[++$index];
    } elseif ($argument === '--catalog') {
        $showCatalog = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        usage(0);
    } else {
        fwrite(STDERR, "Unbekanntes oder unvollständiges Argument: $argument\n");
        usage(2);
    }
}

try {
    if ($showCatalog) {
        printJson(AmpereStorageProModbus::catalog());
        exit(0);
    }

    $client = new AmpereStorageProModbus($host, $port, $unitId, $timeout);
    if ($valueName !== null) {
        $catalog = AmpereStorageProModbus::catalog();
        if (!isset($catalog[$valueName])) {
            throw new InvalidArgumentException("Unbekannter Wert '$valueName'. Mit --catalog werden alle Namen angezeigt.");
        }
        printJson([
            'name' => $valueName,
            'value' => $client->readValue($valueName),
            'unit' => $catalog[$valueName]['unit'],
            'description' => $catalog[$valueName]['description'],
            'address' => $catalog[$valueName]['address'],
            'addressHex' => $catalog[$valueName]['addressHex'],
            'timestamp' => date(DATE_ATOM),
        ]);
        exit(0);
    }

    printJson($client->readSnapshot());
} catch (Throwable $error) {
    fwrite(STDERR, 'StoragePro-Modbusfehler: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

/** @param mixed $data */
function printJson($data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('JSON konnte nicht erzeugt werden: ' . json_last_error_msg());
    }
    echo $json . PHP_EOL;
}

function usage(int $exitCode): void
{
    echo <<<'TEXT'
Lokaler, rein lesender AMPERE.StoragePro-Modbus-Client

Aufruf:
  php json-solar-modbus.php [Optionen]

Optionen:
  --host HOST       Hostname oder IP (Standard: ASP-HSR2103J2311E08738.local)
  --port PORT       Modbus-TCP-Port (Standard: 502)
  --unit ID         Modbus Unit-ID (Standard: 1)
  --timeout SEK     Verbindungs-/Lese-Timeout (Standard: 3)
  --value NAME      Nur einen fachlichen Wert lesen, z.B. battery.soc
  --catalog         Bekannte Werte mit Adresse, Datentyp, Faktor und Einheit
  --help, -h        Diese Hilfe

Beispiele:
  php json-solar-modbus.php
  php json-solar-modbus.php --value pv.power
  php json-solar-modbus.php --host 192.168.178.30 --value battery.soc
  php json-solar-modbus.php --catalog
TEXT;
    echo PHP_EOL;
    exit($exitCode);
}

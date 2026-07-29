# Raspberry-Sensoren: mögliche lokale IDs

Der `RaspberryService` liest die Daten über diese HTTP-API:

```text
http://<raspberry-adresse>/api/coh/raspberry-status.php
```

Die API wird einmal aufgerufen und liefert alle Raspberry-Werte gemeinsam. Danach
wählt die lokale ID aus, welcher Teil der Antwort für einen Sensor zurückgegeben
wird.

## Gruppen

| Sensor-ID | Lokale ID | Rückgabe |
|---|---|---|
| `Raspberry_All` | `raspberry.all` | Vollständiges Array mit `system` und `heating` |
| `Raspberry_System` | `raspberry.system` | Alle Werte aus der Gruppe `system` |
| `Raspberry_Heating` | `raspberry.heating` | Alle Werte aus der Gruppe `heating` |

### `raspberry.all`

Beispiel:

```php
[
    'system' => [
        'diskUsage' => 18.97,
    ],
    'heating' => [
        'serverStatus' => 1,
        'intervals' => [...],
        'protocol' => '...',
    ],
]
```

### `raspberry.system`

Beispiel:

```php
[
    'diskUsage' => 18.97,
]
```

### `raspberry.heating`

Beispiel:

```php
[
    'serverStatus' => 1,
    'intervals' => [...],
    'protocol' => '...',
]
```

## Einzelwerte

| Lokale ID | Rückgabetyp | Bedeutung |
|---|---|---|
| `raspberry.system.diskUsage` | Zahl | Belegung in Prozent, mit PHP ermittelt; bestehender kompatibler Pfad |
| `raspberry.system.diskUsagePhp` | Zahl | Belegung in Prozent aus `disk_total_space()` und `disk_free_space()` |
| `raspberry.system.diskUsageDf` | Zahl | Belegung in Prozent aus `check_disk_usage.sh` beziehungsweise `df` |
| `raspberry.system.diskCheckResult` | Array oder `null` | Vollständige JSON-Rückgabe von `check_disk_usage.sh` mit `Partition`, `value` und `einheit` |
| `raspberry.system.diskCheckScript` | Text oder `null` | Tatsächlich gefundener Pfad von `check_disk_usage.sh` |
| `raspberry.system.diskCheckScriptExecuted` | Boolean | Zeigt, ob das Festplattenscript ausgeführt wurde |
| `raspberry.system.diskFilesystem` | Text | Name der Partition beziehungsweise des Dateisystems, zum Beispiel `/dev/mmcblk0p2` |
| `raspberry.system.diskMountPoint` | Text | Einhängepunkt des Dateisystems, normalerweise `/` |
| `raspberry.system.diskTotalBytes` | Ganzzahl | Gesamtgröße des Dateisystems in Bytes |
| `raspberry.system.diskTotalHuman` | Text | Lesbare Gesamtgröße, zum Beispiel `29.71 GiB` |
| `raspberry.system.diskUsedBytes` | Ganzzahl | Belegter Speicherplatz in Bytes |
| `raspberry.system.diskUsedHuman` | Text | Lesbarer belegter Speicherplatz |
| `raspberry.system.diskFreeBytes` | Ganzzahl | Freier Speicherplatz in Bytes |
| `raspberry.system.diskFreeHuman` | Text | Lesbarer freier Speicherplatz |
| `raspberry.heating.serverStatus` | Ganzzahl | `1`: Heizungsserver läuft, `0`: Heizungsserver läuft nicht |
| `raspberry.heating.intervals` | Array | Konfigurierte Heizintervalle |
| `raspberry.heating.protocol` | Text | Letzte relevanten Einträge aus dem Heizungsprotokoll |

## Kurze, weiterhin gültige lokale IDs

Aus Gründen der Abwärtskompatibilität können die Einzelwerte auch ohne
`raspberry.` angegeben werden:

```text
system.diskUsage
heating.serverStatus
heating.intervals
heating.protocol
```

Die beiden Schreibweisen sind gleichwertig:

```text
raspberry.heating.serverStatus
heating.serverStatus
```

Für neue Sensoren wird die vollständige Schreibweise mit `raspberry.` empfohlen.

## Bereits vorhandene Einzelsensoren

| Sensor-ID | Lokale ID |
|---|---|
| `RaspberryCheckHeizstabServer` | `heating.serverStatus` |
| `RaspberrycheckPlattenbelegung` | `system.diskUsage` |
| `RaspberryHeizstabServerIntervall` | `heating.intervals` |
| `RaspberryHeizstabServerProtokoll` | `heating.protocol` |

`RaspberrycheckSensorCollectorServer` ist deaktiviert, weil der
Sensorcollector nicht mehr lokal auf dem Raspberry ausgeführt wird.

## Einstellungen in Contao

Die Verbindung wird unter **ContaoHab → Sensorcollector-Einstellungen** im
Abschnitt **Raspberry-Status-API** eingerichtet:

- Raspberry-API aktivieren
- Raspberry-Basis-URL
- Raspberry-API-Token
- HTTP-Timeout
- Raspberry-Cachezeit

Innerhalb der eingestellten Cachezeit verwendet der Service eine bereits
geladene API-Antwort erneut. Mehrere ausgewählte Raspberry-Sensoren erzeugen
daher nicht jeweils einen eigenen HTTP-Aufruf.

## Neuen Sensor anlegen

Für einen Sensor, der alle Werte liefert:

```text
Sensor-ID:       Raspberry_All
Quelle:          Raspberry
Lokale ID:       raspberry.all
Wertetyp:        text
Einheit:         -
Ausgabe:         Absolut
```

Obwohl im Backend `text` als Wertetyp eingestellt ist, bleibt der gelesene
Sensorwert bei Gruppenpfaden ein PHP-Array.

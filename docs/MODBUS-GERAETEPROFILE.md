# Modbus-TCP-Geräteprofile

Der gemeinsame Leser liegt in `sensorCollect/Modbus/`. Er trennt TCP-Zugriff,
Registerdefinitionen und Snapshot-Aufbau. Es gibt ausschließlich Lesezugriffe:
Funktion 03 (Holding Registers) und 04 (Input Registers).

Für diese Geräte gibt es genau zwei Collector-Services: `IQBoxSensorService`
und `WechselrichterSensorService`. `ModbusSnapshotReader` ist eine interne
Lesehilfe ohne `SensorFetcherInterface` und wird nicht als Geräte-Service registriert.

## Vorhandene Profile

| Profil | Gerät / Verwendung |
|---|---|
| `saj-h2` | SAJ HS2/H2-Wechselrichter, Registerliste `execScripts/WechselRichter.csv` |
| `ampere-storagepro` | Übernimmt Register, Gruppen und Aliase aus `saj-h2`; nur der Gerätename unterscheidet sich |

Die CSV enthält 409 Einträge, davon 63 mit Datentyp und Faktor. Nur diese 63
vollständig beschriebenen Werte sind im SAJ-Profil enthalten. Die 346 übrigen
Zeilen werden nicht automatisch interpretiert. Dazu gehören unter anderem
Status-/Bitfelder, Schaltzeiten und Schreibparameter. Vor einer Ergänzung müssen
Datentyp, Skalierung und gegebenenfalls Bitaufteilung geklärt werden.
Der Offline-Test gleicht Adresse, Datentyp, Faktor und Einheit mit der CSV ab.

Die Feldnamen entsprechen weiterhin der
[StoragePro-Registerreferenz](AMPERE_STORAGEPRO_MODBUS_JSON_REGISTER.md), etwa
`pv.power`, `battery.soc` und `energy.pv.total`. Das SAJ-Profil meldet als Gerät
`SAJ HS2/H2`. Beide Profile enthalten dieselben Gruppen, `aliases` und den
historischen Pfad `energy.house.calculatedToday`. Letzteres bleibt wie bisher eine
Kopie des internen Lastzählers und ist keine neu berechnete Energiebilanz.
Das Profil `saj-h2` setzt die zu dieser Liste gehörende HS2/H2-Registerbelegung
voraus; andere SAJ-Baureihen benötigen gegebenenfalls ein eigenes Profil.

## Collector konfigurieren

In Contao unter Geräte ein eigenes Gerät anlegen:

| Feld | Beispiel |
|---|---|
| Geräte-ID | `wechselrichter` |
| Geräteadresse (`geraeteUrl`) | IP-Adresse oder Hostname des Wechselrichters |

Die Geräte-ID `iqbox` gehört zum IQBox-Service, `wechselrichter` zum
Wechselrichter-Service. Bestehende IDs `wechselrichterModbus`, `inverter`
und `modbus:saj-h2[:GERAET]` bleiben beim Wechselrichter-Service kompatibel.
Eine allgemeine Gerätequelle `modbus:PROFIL` für beliebige Profile gibt es nicht mehr.

Sensoren erhalten dieses Gerät als Quelle (`sensorSource`). Beispiele für lokale IDs:

| `sensorLokalId` | Ergebnis |
|---|---|
| `modbus.pv.power` | PV-Leistung in W |
| `modbus.inverter.temperature` | Wechselrichtertemperatur in °C |
| `modbus.energy.pv.total` | Absoluter PV-Gesamtzähler in kWh |
| `modbus.pv` | JSON-Gruppe mit PV-Werten |
| `modbus` | Alle Messwerte aus `snapshot.data` |

Einzelpfade und Gruppen funktionieren auch ohne `modbus.`. Bei leerer Einheit oder `-`
übernimmt der Service die Einheit aus dem Profil. Eine ausdrücklich eingetragene
Einheit bleibt erhalten. Zähler bleiben absolute Werte; Speicherung und
History-Auswertung erfolgen weiterhin durch den Collector.

Der Service liest je Host einmal pro Poll-Lauf mit seinem festen Profil.
Mehrere Sensoren teilen denselben Snapshot. Gerätefehler verhindern nicht das
Lesen anderer Geräte. Fehlende Werte werden ausgelassen und protokolliert.
Die TCP-Verbindung wird auch bei Fehlern unmittelbar nach dem Snapshot geschlossen.

Die Verbindung verwendet fest TCP-Port 502, Unit-ID 1 und 3 Sekunden Timeout.
Im Backend kann die Geräteadresse eine IP-Adresse, ein Hostname oder eine
HTTP-/HTTPS-Adresse sein. Aus HTTP-/HTTPS-Adressen wird nur der Host verwendet;
deren HTTP-Port, Pfad und Query verändern die Modbus-Verbindung nicht.
Das Profil gibt der jeweilige Geräte-Service fest vor. Eine zusätzliche
Profilauswahl oder Verbindungsparameter in der Geräteadresse gibt es nicht.
Es ist keine Datenbankmigration nötig.

Vorhandene Quelle `iqbox`, deren lokale IDs und die API `iqbox-modbus.php`
bleiben kompatibel. Der IQBox-Service verwendet denselben `ModbusSnapshotReader`
wie der Wechselrichter-Service. `ModbusSensorValues` bildet für beide dieselben
Live-, Tages-, Gesamt- und Aliasansichten aus den Profildefinitionen. Unterschiedliche Geräteadressen werden dabei
getrennt gelesen. Alte IQBox-Sensoren
müssen nicht umgestellt werden. Eine Nutzung der neuen Quelle direkt durch
einen Contao-Sensorservice ist damit nicht implementiert: Die neuen Quellen
werden auf dem Raspberry gesammelt und über den bisherigen Datenweg gelesen.

Auch beim IQBox-Service können native Gruppen direkt als lokale ID verwendet
werden: `pv`, `battery` oder `energy.pv`. Die Varianten mit `modbus.` funktionieren
weiterhin. Ein JSON-Pfad zu einem Einzelwert liefert den Einzelwert, ein Pfad
zu einem Objekt liefert die ganze Gruppe. Bestehende IQBox-Ansichten wie `live`,
`today` und `lifetime` behalten ihre bisherige Bedeutung.

## Gemeinsame Ansichten für beide Services

`groups` in `Profiles/saj-h2.php` definiert den Aufbau von `live`, `today` und
`lifetime`. Das IQBox-Profil übernimmt diese Definitionen mit dem gesamten
SAJ-Profil. `ModbusSensorValues::buildGroups()` baut die Ansichten einmal je
Snapshot auf; `value()` und `unit()` liefern die konfigurierte lokale Auswahl.
Die Geräte-Services wählen nur Quelle und festes Profil.

`live` enthält wie die Cloud-Ausgabe genau `pvPower`, `housePower`, `gridPower`,
`batteryPower`, `heatingRodPower` und `batterySoc`. Native Details bleiben separat
unter `inverter`, `grid`, `battery`, `pv` und `house` erreichbar. Die bisherigen
Detailpfade wie `live.battery.soc` bleiben ebenfalls lesbar.
`heatingRodPower` ist ohne verfügbares Modbus-Register `null`, keine erfundene `0`.

Beide Services verstehen dieselben lokalen IDs, unter anderem `live.batterySoc`,
`today.work.generation`, `today.selfSufficiency.value`, `lifetime.pvProduction`,
`soc` und `all`. Bei gleichen Rohdaten stimmen Werte und Einheiten überein.
Native Energiewerte bleiben in kWh, die bisherigen Tages-/Gesamtansichten
liefern Wh. Fehlende Werte in einer definierten Ansicht bleiben `null`;
echte Nullmessungen bleiben `0`. Für Prozentwerte ohne gültige Basis wird
ebenfalls `null` ausgegeben. Unbekannte native Sensorpfade werden ausgelassen.

Gruppenfelder nennen unter `from` den nativen Pfad oder eine Liste zulässiger
Ersatzpfade. `factor` und `unit` beschreiben die Ausgabeumrechnung.
`calculation` mit `inputs` verweist auf bereits gebildete Gruppenwerte für
Autarkie und Eigenverbrauch. Einzelne Geräte-Services enthalten keine eigene
Gruppenbildung mehr.

## Geräte-ID wechselrichterModbus

`WechselrichterSensorService.php` erkennt neben `wechselrichter` auch die bisherige Geräte-ID beziehungsweise
`sensorSource` **`wechselrichterModbus` unabhängig von Groß-/Kleinschreibung**.
Die Adresse kommt aus `geraeteUrl` des zugeordneten Geräts, beispielsweise
`192.168.178.30`. Standard sind Port 502, Unit-ID 1 und das Profil `saj-h2`.

Auch die lokale ID ist unabhängig von Groß-/Kleinschreibung:

| `sensorLokalId` | Modbus-Wert |
|---|---|
| `soc`, `SOC`, `Soc` | `battery.soc`, Ladezustand in % |
| `battery.soc` oder `MODBUS.BATTERY.SOC` | Derselbe Ladezustand |
| `energy.pv.total` | Absoluter PV-Gesamtzähler in kWh |
| `battery` | JSON-Gruppe aller Batteriewerte |
| `all` oder `modbus` | Alle Snapshot-Messwerte als JSON-Sammelwert |

Bei einem Rohwert von 4080 für `battery.soc` liefert der Sensor `sensorValue = 40.8`
und `sensorEinheit = %`. Eine leere Einheit oder `-` wird aus dem Profil ergänzt.
Sensor-ID, Quelle und Werttyp bleiben erhalten. Mehrere Sensoren derselben
Geräteverbindung teilen einen Snapshot. Nicht verfügbare Werte werden nicht
als Nullwerte gespeichert.

## Kommandozeilenabfrage

Aus dem Repository:

```sh
php execScripts/json-inverter-modbus-loop.php HOST 502 1 saj-h2
```

`HOST` durch die Geräteadresse ersetzen; HTTP-/HTTPS-Adressen liefern wie im
Collector nur den Host. Als viertes Argument kann auch `ampere-storagepro`
oder ein weiteres installiertes Profil angegeben werden. Port und Unit-ID
sind beim manuellen Test optional einstellbar; die Collector-Werte bleiben fest.

Der Loop verwendet dieselbe Auswahl `ModbusSensorValues` wie beide Services:

```text
live
today
lifetime
get today.work.generation
get live.batterySoc
get energy.pv.total
groups today
list battery
quit
```

`get NAME` und ein direkt eingegebener Name geben nur den ausgewählten Wert oder
die Gruppe aus, ohne zusätzliche `host`-/`value`-Hülle. `info NAME` liefert diese
Metadaten einschließlich Profileinheit bei Bedarf. Jeder Leseaufruf liest einen frischen Snapshot
und schließt die Verbindung vor der Aufbereitung. `json`, `snapshot` und `all`
behalten die native Snapshot-Hülle; `get all` beziehungsweise `get modbus`
liefert deren Datenbereich wie im Collector.

`help`, `groups` und `list` werden aus `fields`, `groups`, `aliases`, `copies`
und `groupDescriptions` des gewählten Profils erzeugt. Neue Profilgruppen und
Unterwerte erscheinen automatisch. Diese Befehle greifen nicht auf das Gerät zu.
Die Hilfe ist auch ohne gestartete Schleife verfügbar:

```sh
php execScripts/json-inverter-modbus-loop.php --help saj-h2
php execScripts/json-inverter-modbus-loop.php --help ampere-storagepro
```

Für die IQBox gibt es den gleichwertigen Einstieg
`execScripts/json-solar-iqexport-loop.php`. Sein Standardprofil ist
`ampere-storagepro`; der Standardhost stammt aus `iqBoxModbus.host` der
Task-Konfiguration, sofern vorhanden. Ein expliziter Host hat Vorrang:

```sh
php execScripts/json-solar-iqexport-loop.php HOST
php execScripts/json-solar-iqexport-loop.php HOST --once=pv
php execScripts/json-solar-iqexport-loop.php --help
```

Beide Einstiege verwenden `execScripts/ModbusLoop.php`. Dieser Dateiname muss
zusammen mit den beiden Programmen installiert werden. Der frühere
Cloud-Export hinter dem IQBox-Dateinamen ist ersetzt; Cloud-spezifische
Exportoptionen gibt es in diesem lokalen Testprogramm nicht mehr.

## Weitere Geräte hinzufügen

Eine PHP-Datei `sensorCollect/Modbus/Profiles/mein-geraet.php` anlegen, die eine
Profildefinition zurückgibt. Profilnamen bestehen aus Kleinbuchstaben, Ziffern
und Bindestrichen. Beispiel mit ausdrücklich beispielhaften Registeradressen:

```php
<?php
return [
    'device' => 'Mein Gerät',
    'fields' => [
        'temperature' => [
            'address' => 100, 'type' => 'int16', 'scale' => 0.1,
            'unit' => '°C', 'description' => 'Temperatur',
        ],
        'energy.total' => [
            'address' => 200, 'type' => 'uint32', 'scale' => 0.01,
            'unit' => 'kWh', 'function' => 4, 'wordOrder' => 'big',
        ],
    ],
];
```

Unterstützt: `uint16`, `int16`, `uint32`, `int32`, `float32`;
`wordOrder` ist `big` (höherwertiges Register zuerst) oder `little`.
Bytes innerhalb eines Registers folgen der Modbus-Netzwerkreihenfolge.
Adressen sind **nullbasierte Protokolladressen**, keine 3xxxx-/4xxxx-Referenznummern.
Datentyp und Adresse sind Pflicht; Faktor ist standardmäßig 1, Funktion 03.

Ohne `blocks` werden nur die Register der einzelnen definierten Werte gelesen.
Erprobte zusammenhängende Bereiche können über
`'blocks' => [[100, 2, 3], [200, 2, 4]]` angegeben werden:
Startadresse, Anzahl (höchstens 125), Funktion (standardmäßig 03).
Keine unbestätigten Registerlücken überbrücken. Firmwareabhängige Felder können
in `'optional' => ['feldname']` stehen; sie müssen außerhalb der Pflichtblöcke
liegen und fehlen bei Lesefehlern im JSON. Pflichtfelder brechen bei Fehlern den
Snapshot dieses Geräts ab. Neue Geräte benötigen keine Kopie des TCP-Clients.

## Offline prüfen und auf dem Raspberry installieren

```sh
php tests/modbus_snapshot_test.php
```

Der Test verwendet Fixtures und lokale TCP-Testserver auf `127.0.0.1`, keine
Heizung, keinen Wechselrichter und keine Datenbank.

Die tatsächliche Installation auf `raspberrypi` verwendet ein kleingeschriebenes
`sensorcollect`. Die Repository-Schreibweise `sensorCollect` nicht als neues
paralleles Zielverzeichnis anlegen.

Benötigte Dateien an diese Installationsorte übertragen:

- Den vollständigen Ordner `sensorCollect/Modbus/` einschließlich `Profiles/`
  nach `/home/peter/scripts/coh/sensorcollect/Modbus/`.
- `sensorCollect/Sensor/AmpereStorageProModbus.php`, `IQBoxSensorService.php`
  sowie `WechselrichterSensorService.php` nach
  `/home/peter/scripts/coh/sensorcollect/Sensor/`.
- Nach Sicherung die alten Dateien `ModbusSensorService.php` und
  `WechselricherSensorService.php` aus dem Raspberry-Verzeichnis `Sensor/`
  entfernen. Der Collector lädt dort jede PHP-Datei automatisch.
- Bei CLI-Nutzung: `execScripts/AmpereStorageProModbus.php` und
  `execScripts/json-inverter-modbus-loop.php` nach
  `/home/peter/scripts/coh/execScripts/`.
- Für die gemeinsamen Testprogramme auch `execScripts/ModbusLoop.php` und
  `execScripts/json-solar-iqexport-loop.php` in dasselbe Verzeichnis übertragen.
- Bei Nutzung der vorhandenen IQBox-HTTP-API:
  `var/www/html/api/coh/AmpereStorageProModbus.php` und `iqbox-modbus.php`
  nach `/var/www/html/api/coh/`.

Die API und CLI laden den gemeinsamen Ordner aus dem Checkout beziehungsweise
aus `/home/peter/scripts/coh/sensorcollect/Modbus/`. Bei einer anderen API-Installation
kann der vollständige Inhalt dieses Ordners alternativ nach
`/var/www/html/api/coh/Modbus/` kopiert werden. Die Adapter sind jetzt auf diesen
gemeinsamen Ordner angewiesen; nicht allein übertragen. Auch das CLI-Skript ist
keine eigenständige Einzeldatei mehr. Die einzige Implementierung der alten
Klasse liegt in `sensorCollect/Modbus/AmpereStorageProModbus.php`; die gleichnamigen
Dateien in `Sensor`, `execScripts` und der API sind nur Lade-Einstiege.

Anschließend `sudo systemctl restart collect.service` ausführen, damit der
Collector den neuen Service lädt.
Für die API ist normalerweise kein Dienstneustart erforderlich; gegebenenfalls
OPcache nach den örtlichen Betriebsregeln erneuern. Vor der Installation die
bisherigen Zieldateien sichern, das Paket auch mit der Raspberry-PHP-Version
offline prüfen und nach der Installation Prüfsummen sowie Dienststatus prüfen.

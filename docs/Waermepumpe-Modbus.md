# Waermepumpe vorbereiten

Profil `waermepumpe`, Service `sensorCollect/Sensor/WaermepumpeSensorServices.php`.
Wie beim Wechselrichter enthält das Profil eine direkte `return [...]`-Liste:
jedes Register steht ausdrücklich unter `fields`, ohne Hilfsfunktion oder Schleifen.
Der Informationskatalog für Schreibmöglichkeiten liegt separat in
`sensorCollect/Modbus/Profiles/waermepumpe-control-catalog.php`.
Gerätename und Geräte-ID/sensorSource: **Waermepumpe** (Quelle ohne Beachtung der
Groß-/Kleinschreibung). Beispiel für `geraeteUrl`: **192.168.178.99**.
Die IP wird nicht als versteckter Ersatz für eine fehlende Geräteadresse verwendet.
Es werden bei der Vorbereitung keine Datenbankeinträge oder Sensoren aktiviert.

## Anschluss und Inbetriebnahme

Quelle: bereitgestellte deutsche GTW-08-Anleitung 7854678 v.01, 15.09.2023,
Druckseiten 57-83. Das Dateipräfix 2026_08 ist keine neue Dokumentrevision.
Das GTW-08 ist ein Modbus-Gateway mit seriellen Einstellungen. Die Beispiel-IP
setzt eine Modbus-TCP/RTU-Bridge voraus, nicht eine dokumentierte TCP-Schnittstelle
der Wärmepumpe. Vor Inbetriebnahme den tatsächlich eingesetzten Adapter prüfen.

Vorbereitet: TCP 502, Unit-ID **100** (Drehschalter 0; weitere Stellungen 101-115),
Timeout 3 Sekunden. Serieller Standard laut Anleitung: 9600 Baud, keine Parität.
Unit-ID bei abweichender Drehschalterstellung im Profil `connection.unitId`
anpassen. Der Service übernimmt sie ausdrücklich; andere Services bleiben bei 1.
Für manuelle CLI-Abfragen muss die Unit-ID ebenfalls explizit angegeben werden.

Lokale Sensor-IDs, jeweils auch mit `modbus.`:

| Lokale ID | Bedeutung |
|---|---|
| `temperature.heatPumpFlow` / `vorlauf` | Wärmepumpenvorlauf, °C |
| `temperature.heatPumpReturn` / `ruecklauf` | Wärmepumpenrücklauf, °C |
| `temperature.outside` | Außentemperatur ohne Korrektur |
| `system.power` | Aktuelle Geräteleistung, kW |
| `system.cop` | Momentaner COP |
| `system.pressure` | Primärwasserdruck, bar |
| `energy.consumed.total` | Absoluter Energieverbrauch, kWh |
| `energy.delivered.heating` | Absolute gelieferte Heizenergie, kWh |
| `system.status` / `system.substatus` | Numerische Zustände |
| `zones.zone1` | Heizkreis 1, tatsächliche Funktion zuerst prüfen |
| `modbus` / `all` | Gesamte verfügbare Messwertgruppe |

`temperature.flow` (400) ist Pflichtwert und wird zuerst gelesen; fehlende
Ausstattungsregister sind optional und fehlen im Ergebnis statt als Null zu erscheinen.
Keine unbekannten Registerlücken werden als große Blöcke abgefragt.
Pro Host und Poll-Lauf wird ein Snapshot geteilt. Bei Inbetriebnahme optionale
Register/Ausstattung einschränken, falls der Adapter viele Ausnahmen langsam beantwortet.
UINT8/ENUM8 verwenden nur das untere Byte. UINT32 verwendet zunächst High-Word zuerst;
die Anleitung legt die Wortfolge nicht eindeutig fest, daher mit realem Zähler prüfen.
Absolute Zähler werden weder zurückgesetzt noch in Differenzen umgewandelt.

## Schreibrechte und Registerkommentare

Jedes Feld enthält `access` (R oder R/W), `description`, `comment`, `options`
und bei R/W `writeFunction=16`. Der `controlCatalog` enthält die dokumentierten
R/W-Möglichkeiten samt Datentyp, Faktor und dokumentierten Auswahlwerten.
`controls` ist leer: Es gibt **keine ausführbare Schreibfreigabe**. Die bestehende
Schreib-API erlaubt dieses Profil noch nicht. Die Datentyp-Tabelle verlangt FC16,
der bestehende Dialog implementiert FC06. Vor einer späteren Freigabe sind FC16,
Wertebereiche, Voraussetzungen, Vorschau/Konfliktprüfung und Rücklesen umzusetzen.
Der Collector führt niemals Start-, Initialisierungs- oder Schreibbefehle aus.

- 258 zuerst setzen; 3 = reine Überwachung. 256/257/259 sind dabei nicht verwendbar.
  Gateway-Regelung ignoriert den Wärmebedarf der Heizkreise. 256=0 ist minimale
  Systemleistung, kein Ausschalten; Standby ist 259=0 bei passendem Algorithmus.
- Heizkreisfunktion und Gerät zuerst prüfen (641/645, je Zone +512). Die Zuordnung
  Heizen/TWW unterscheidet sich nach EHC-/SCB-Ausstattung und Drehschalterstellung.
- Ohne Außen-/Raumfühler: 649=1 und 648 als Vorlaufsollwert (0,01°C).
  Fester Vorlauf über Heizkurve: 649=1, 674=0, 675 als Startwert (0,1°C).
  Raumsollwert 664 (0,1°C) benötigt Raum- oder Außenfühler.
  Manuelle Vorgaben bleiben bis Änderung oder Rückkehr zum Zeitprogramm (649=0).
  649=2 bedeutet Aus/Frostschutz. Für weitere Zonen jeweilige Tabellenadresse nutzen.
- 439-451 sind laut Tabelle R/W, bleiben aber absolute Energiezähler ohne Reset.
- 21020-21025 bestimmen das Verhalten bei Kommunikationsausfall. Zusammengehörige
  Einstellungen nicht unabhängig freigeben; Dauergrenze ist unvollständig dokumentiert.
- 21027 hängt von 21026 ab. Der genannte Bereich 0-25V widerspricht der Überschrift
  0-10V; keine Schreibgrenze daraus ableiten.
- Fehler: 0xFFFF = kein Fehler, 0xFFFE = Gerät nicht verfügbar. Fehlermatrix
  0 verriegelt, 3 gesperrt, 6 Warnung. Ein Rohwert 0 ist nicht pauschal fehlerfrei.

Bewusst markierte/ausgelassene Unstimmigkeiten:

- 415: Beschreibung Erzeugerstarts, Format aber 0,1µA; nur uninterpretierter Rohwert.
- 272: 1% beim Einzelgerät, abweichend 0,01% in Kaskadentabelle.
- COP-Register 9230/9231: Faktor 0,001, aber fragliche Bereichsangabe 0-1.
- Tab.143 enthält ab Zone 7 einen Sprung bei CM13X/CM12X/CM05X/CM01X.
  Tabellenadressen werden wörtlich verwendet, nicht still korrigiert.
- Tab.150: CP21X Zone 5/8 kollidiert mit CP23X; diese zwei CP21X-Werte fehlen.
- Tab.145: Zähler Zone 4/8 überschneiden sich oder widersprechen dem Raster;
  beide Zähler für Zone 4 sowie Pumpenstarts Zone 8 bleiben ausgelassen.
- CM01X heißt Vorlauftemperatur, hat aber Ja/Nein-Format; als Rohwert gekennzeichnet.
- 7163 aus Tab.133 überschneidet UINT32 7162/7163 aus Tab.156;
  verwendet wird der dort eindeutig definierte Rücklauf 7164.
- 7168 und die Kaskadenmitglied-Adressen aus Tab.158 haben keine vollständigen
  Datentyp-/Skalierungsangaben und werden nicht interpretiert.

## Installation / Prüfung

Neue Dateien nach `/home/peter/scripts/coh/sensorcollect/Modbus/Profiles/waermepumpe.php`
und `/home/peter/scripts/coh/sensorcollect/Sensor/WaermepumpeSensorServices.php`.
Auch `waermepumpe-control-catalog.php` in denselben Raspberry-Profilordner übertragen.
Zusätzlich die erweiterten `ModbusProfile.php` und `ModbusSnapshotClient.php`
im vorhandenen `Modbus/` installieren (8-Bit-Dekodierung). Vorherige Dateien sichern.
`php tests/waermepumpe_modbus_test.php` arbeitet ausschließlich mit Fixtures.
Bestehende Regressionen: `php tests/modbus_snapshot_test.php` und
`php tests/modbus_control_test.php` (nur lokale TCP-Testserver).
Nach Installation Collector neu laden; ein Hardwaretest ist erst nach Installation
der Wärmepumpe und des tatsächlich verwendeten Gateways möglich.

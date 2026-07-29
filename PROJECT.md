# COH-Sensorcollector - aktueller Projektstand

Stand: 18.07.2026

## Ampere.IQ-Werteexport fuer andere Anwendungen

Der eigenstaendige, rein lesende Exporter `execScripts/json-solar-iqexport-loop.php`
schreibt alle bekannten App-Werte in eine JSON-Datei. Wichtige Werte wie
`batterySoc` stehen dort direkt unter `aliases`; alle Einzelwerte sind zusaetzlich
unter `values` ueber eindeutige Pfade erreichbar. Details und Beispiele stehen
in `execScripts/json-solar-iqexport-loop.md`. Ohne Optionen startet der Exporter eine
interaktive Schleife, in der einzelne Bereiche ausgewaehlt und unmittelbar als
JSON angezeigt werden. Mit `--all` wird weiterhin die Gesamtdatei erzeugt.

## Heizungsregelung

Die aktive Regelung befindet sich in:

- `execScripts/json-heizung.php`

Start der Dauerschleife:

```bash
php json-heizung.php
```

Lesender Cloudtest ohne Dauerschleife:

```bash
php json-heizung.php cloud-test
```

Die Funktion `decideHeizstabAction()` und ihre ausfuehrliche Beschreibung
bleiben in `json-heizung.php`. Der Kommentar darf bei Aenderungen nicht
entfernt werden.

## Ampere.IQ-Cloud

Der alte lokale IQBox-Zugriff ueber `/rest/items`, IQBox-Cookies und
`kiwisessionid` wird nicht mehr verwendet.

Der Zugriff erfolgt ueber die Ampere.IQ-Cloud:

- OAuth-Anmeldung und Tokenrefresh
- automatische Neuanmeldung bei ungueltigem Token
- HTTPS/cURL-Zugriffe mit Wiederholungsversuchen
- Token und Anmeldedaten in der Parameterdatei, nicht in Umgebungsvariablen

Die Klasse `AmpereIqHttpAccess` befindet sich gemeinsam mit den anderen
Zugriffsklassen in:

- `execScripts/TaskAccess.php`

Sie enthaelt nur HTTP/cURL, OAuth, Tokenverwaltung und Wiederholungen. Sie
enthaelt keine Regelungslogik und keine Auswahl einzelner Messwerte.

`json-solar-iq-loop.php` ist nur ein eigenstaendiges interaktives
Testprogramm. Es wird von der Regelung und von `TaskAccess.php` nicht geladen.
Die Testloop stellt auch die berechneten Filter `lifetime`, `lifetime.work` und
`lifetime.pvProduction` bereit. Hierfuer werden die Cloud-Jahreswerte seit dem
Anlagedatum erst beim ersten Lifetime-Filter geladen und anschliessend fuer die
laufende Testloop wiederverwendet. Die Cloud bietet keinen einzelnen
Lifetime-Endpunkt an. Auch `AmpereIqHttpAccess` laedt diese Jahreswerte erst beim
ersten Lifetime-Sensor und cached sie fuer weitere Sensoren derselben
Client-Instanz fuer 60 Sekunden. Dadurch teilen sich Sensoren einer Sammelrunde
die Abrufe, waehrend ein spaeteres Pollintervall wieder aktuelle Jahreswerte
erhaelt.
Die Dauer wird fuer eigenstaendige Tasks mit `ampereIq.lifetimeCacheSeconds`
in `execScripts/task_solar_params.json` gepflegt. Tasks koennen sie ueber
`ampereIqCloud.lifetimeCacheSeconds` ueberschreiben.
Die Bundle-Sensoren beziehen ihre Parameter dagegen aus dem Backendmodul
`Sensorcollector-Einstellungen` des ContaoHab-Bundles. Die Werte liegen in der Datenbanktabelle
`tl_coh_sensorcollector_settings` und bleiben bei Bundle-Updates erhalten.
Unter `contao/config` liegen keine Sensor-Parameterdateien. Fehlt der
Einstellungsdatensatz, meldet der Sensorservice deshalb einen eindeutigen
Konfigurationsfehler.
Mit einer angehaengten Jahreszahl, zum Beispiel `lifetime.pvProduction 2025`,
wird nur dieses Jahr gelesen. Dieselbe Auswahl kann als `sensorLokalId` eines
IQBox-Sensors verwendet werden.
Auch der normale Cloud-Snapshot der Testloop wird nicht mehr beim Programmstart
geladen. Der Prompt erscheint nach der Anmeldung sofort; ein freier Suchfilter
laedt den Snapshot einmalig. Direkte Befehle laden nur ihren jeweiligen Bereich.

## Gemeinsame Zugriffe

`execScripts/TaskAccess.php` stellt fuer Tasks folgende technische Zugriffe
bereit:

- Ampere.IQ-Cloud ueber `AmpereIqHttpAccess`
- my-PV-Cloud per HTTPS und Bearer-Token
- lokalen my-PV-Heizstab per HTTPS mit Login-Cookie und erneutem Login

Die Auswahl konkreter Endpunkte und das Auslesen fachlicher Variablen bleiben
im jeweiligen Task.

In `json-heizung.php` werden derzeit folgende Ampere.IQ-Werte ausgewaehlt:

- `batterySoc`: Akkustand
- `temperature`: aktuelle Heizstabtemperatur
- `targetTemperature`: Zieltemperatur des Heizstabs
- `temperatureTimestamp`: Zeitstempel der Temperatur

Verwendete Ampere.IQ-Endpunkte:

```text
/api/v1/installation/{installationId}/now/all/power
/api/v1/installation/{installationId}/hems/device
/api/v1/installation/{installationId}/hems/device/{deviceUuid}
```

## Parameterdateien

Die Parameterdateien liegen in `execScripts`:

- `task_heizstab_params.json`: Heizungsregelung, Ampere-Verweis sowie lokaler
  und Cloud-Zugriff auf den Heizstab
- `task_solar_params.json`: Ampere.IQ-Anmeldedaten und gespeicherte OAuth-Token

Passwoerter und Token duerfen nicht in Dokumentation, Quellcodeausgaben oder
Git-Commits uebernommen werden.

## Installation und Updates mit dem Contao Manager

Nach der Installation oder Aktualisierung des Bundles wird im Contao Manager
die Datenbankmigration ausgefuehrt. Dadurch entsteht die Tabelle
`tl_coh_sensorcollector_settings`. Danach im Contao-Backend unter
`COH > COH-Sensorcollector Einstellungen` genau einen Datensatz anlegen und
die Ampere.IQ- sowie Heizstab-Zugangsdaten eintragen.

Bei spaeteren Bundle-Updates bleibt dieser Datensatz erhalten. Es muessen keine
Parameterdateien per FTP/FileZilla hochgeladen werden. Erneuerte Ampere.IQ-
OAuth-Tokens speichert der Sensorservice automatisch wieder in diesem
Datensatz. Das Bundle installiert keine Sensor-Parameterdateien unter
`contao/config`.

## Dateien fuer den Raspberry Pi

Fuer die Heizungsregelung werden mindestens benoetigt:

- `json-heizung.php`
- `TaskAccess.php`
- `Logger.php`
- `task_heizstab_params.json`
- `task_solar_params.json`

`json-solar-iq-loop.php` und `task-access-test.php` sind nur fuer manuelle
Tests erforderlich.

## Bestaetigter Teststand

Folgende Aufrufe wurden erfolgreich getestet:

- `php execScripts/json-heizung.php cloud-test`
- `php execScripts/task-access-test.php all`

Dabei funktionierten:

- Ampere.IQ-Cloudzugriff
- Auslesen von Akku, aktueller Temperatur und Zieltemperatur in
  `json-heizung.php`
- my-PV-Cloudzugriff
- lokaler HTTPS-Zugriff auf den Heizstab

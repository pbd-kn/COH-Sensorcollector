# COH-Sensorcollector - aktueller Projektstand

Stand: 18.07.2026

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


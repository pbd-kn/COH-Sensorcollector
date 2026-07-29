# Gemeinsame Energie-Zugriffe

Jeder PHP-Task unter `execScripts` kann die gemeinsamen Clients so laden:

```php
require_once __DIR__ . '/TaskAccess.php';

$params = TaskAccess::loadParameters(__DIR__ . '/task_heizstab_params.json');
```

## Ampere.IQ-Cloud

Verwendet `ampereIqCloud.paramsFile` und damit `task_solar_params.json`.
Die gemeinsame Klasse erledigt nur HTTPS, Tokenrefresh und automatische
Neuanmeldung. Auswahl und Auswertung der Endpunkte bleiben im jeweiligen Task.

```php
$iq = TaskAccess::ampereIq($params, __DIR__);

$live = $iq->get('/api/v1/installation/{installationId}/now/all/power');
$antwort = $iq->get('/api/v1/installation/{installationId}/hems/device');
```

Werte koennen auch direkt ueber fachliche Namen gelesen werden. Dafuer muss
kein anderes Script wie `json-solar-iqexport-loop.php` geladen werden:

```php
$batterySoc = $iq->getValue('soc');
$batteryPower = $iq->getValue('live.power.batteryPower');
$today = $iq->getValue('today.work');
$consumption = $iq->getValue('today.work.consumation');
$saving = $iq->getValue('today-saving-total');
```

Einzelpfade liefern direkt den Wert, Bereiche liefern ein Array.

Vergangene Tageswerte werden ueber den optionalen zweiten Parameter gelesen:

```php
$yesterdayEnergy = $iq->getValue('today.saving.energy', '2026-07-18');
$yesterdayConsumption = $iq->getValue('today.work.consumption', '2026-07-18');
```

Verlaufswerte sind ueber dieselbe Methode erreichbar:

```php
$soc = $iq->getValue('history.batterySoc', '2026-07-18');
$power = $iq->getValue('history.common.power', '2026-07-18');
$work = $iq->getValue('history.common.work', '2026-07-18');
$consumerPower = $iq->getValue('history.consumption.power', '2026-07-18');
$consumerWork = $iq->getValue('history.consumption.work', '2026-07-18');
$gridDraw = $iq->getValue('history.gridDraw.work', '2026-07-18');
$prices = $iq->getValue('history.electricityPrice', '2026-07-18');
```

Gesamtwerte seit Anlagebeginn werden aus den Cloud-Jahreswerten summiert:

```php
$pvTotalWh = $iq->getValue('lifetime.pvProduction');
$allTotals = $iq->getValue('lifetime.work');
$pvTotalKwh = $pvTotalWh / 1000;
```

Fuer eigenstaendige Tasks wird die Cachezeit in `task_solar_params.json`
eingestellt:

```json
{
    "ampereIq": {
        "lifetimeCacheSeconds": 60,
        "lifetimeRetries": 3,
        "lifetimeRetryDelaySeconds": 2
    }
}
```

Alternativ kann ein Task den Wert in seinem Abschnitt `ampereIqCloud`
ueberschreiben. `0` deaktiviert den Cache, `300` entspricht fuenf Minuten.

`lifetimeRetries` und `lifetimeRetryDelaySeconds` steuern in der interaktiven
Testloop die Wiederholungen bei einem voruebergehenden Cloud-Timeout.

## Bundle-Sensor

`IQBoxSensorService` und `HeizstabSensorService` lesen ihre Einstellungen aus
dem Contao-Backendmodul `COH-Sensorcollector Einstellungen`. Der Datensatz wird
in `tl_coh_sensorcollector_settings` gespeichert und deshalb bei Installationen
und Updates ueber den Contao Manager nicht ueberschrieben. Aktualisierte
Ampere.IQ-Tokens werden ebenfalls dort gespeichert.

Nach der Bundle-Installation ist einmal die Datenbankmigration im Contao
Manager auszufuehren und danach im Backend genau ein Einstellungsdatensatz
anzulegen. Ein manueller Upload per FTP/FileZilla ist nicht erforderlich.

Unter `contao/config` werden keine Sensor-Parameterdateien mehr ausgeliefert
oder gelesen. Fehlt der Datenbankdatensatz, bricht der jeweilige Sensorservice
mit einem eindeutigen Konfigurationsfehler ab. Die JSON-Dateien unter
`execScripts` gehoeren nur zu den eigenstaendigen Test- und Taskprogrammen,
die ausserhalb von Contao laufen.

Ein bestimmtes Jahr kann als Teil der Auswahl oder als zweiter Parameter
angegeben werden. Diese Schreibweise eignet sich auch direkt als
`sensorLokalId` im IQBox-Sensor:

```php
$pv2025 = $iq->getValue('lifetime.pvProduction 2025');
$work2025 = $iq->getValue('lifetime.work 2025');
$pv2025Alternative = $iq->getValue('lifetime.pvProduction', '2025');
```

Ein Schreibzugriff auf einen bekannten Endpunkt ist ebenfalls generisch moeglich:

```php
$iq->patch('/api/v1/installation/{installationId}/...', [
    'wert' => 123,
]);
```

Welche Werte wie `batterySoc`, `temperature` oder `targetTemperature` verwendet
werden, entscheidet und implementiert der aufrufende Task, zum Beispiel
`json-heizung.php`.

## Heizstab my-PV-Cloud

Verwendet den Abschnitt `heizstabApi` aus `task_heizstab_params.json`.

```php
$cloud = TaskAccess::heizstabCloud($params);

$data = $cloud->data();
$setup = $cloud->setup();
$online = $cloud->isOnline();
$steuerbar = $cloud->isPowerControlPossible();
```

Schreibzugriff:

```php
$cloud->setPower(3000, 20); // 3000 W, 20 Minuten gueltig
$cloud->setPower(0, 20);    // ausschalten
```

## Heizstab lokal per HTTPS

Verwendet `urlheizStab` und `heizstabAuth`. Login, Cookie und einmaliger
Re-Login bei abgelaufener Sitzung sind enthalten.

```php
$local = TaskAccess::heizstabLocal($params, __DIR__);

$data = $local->data();
$setup = $local->setup();
```

Schreibzugriffe:

```php
$local->postSetup(['bststrt' => 1]); // Sicherstellung starten
$local->postSetup(['bststrt' => 0]); // Sicherstellung stoppen
$local->setPower(3000);              // HTTP-Leistung setzen
$local->setPower(0);                 // ausschalten
```

## Lesetest

Der Test fuehrt keine Schreibzugriffe aus:

```bash
php task-access-test.php all
php task-access-test.php ampere
php task-access-test.php cloud
php task-access-test.php local
```

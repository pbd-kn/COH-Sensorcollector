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

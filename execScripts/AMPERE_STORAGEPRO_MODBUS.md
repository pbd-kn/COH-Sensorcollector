# AMPERE.StoragePro lokal per Modbus TCP lesen

Der PHP-Client liest den AMPERE.StoragePro direkt im Hausnetz. Home Assistant,
Cloud-Anmeldung und zusätzliche PHP-Pakete sind nicht erforderlich.

Die Implementierung bietet ausschließlich Modbus-Funktion 03 zum Lesen von
Holding-Registern. Schreibzugriffe sind nicht implementiert.

## Verbindung

Für die aktuell untersuchte Anlage gelten:

```text
Host:    ASP-HSR2103J2311E08738.local
IP:      192.168.178.30
Port:    502
Unit-ID: 1
```

Der Hostname ist vorzuziehen, wenn mDNS/Avahi auf dem Raspberry Pi funktioniert.
Die IP sollte alternativ in der FRITZ!Box dauerhaft diesem Gerät zugewiesen
werden.

## Verwendung

Gesamten Snapshot lesen:

```bash
php json-solar-modbus.php
```

Nur einen Wert lesen:

```bash
php json-solar-modbus.php --value pv.power
php json-solar-modbus.php --value battery.soc
php json-solar-modbus.php --value grid.power
```

Alle bekannten Namen und Registerbeschreibungen anzeigen:

```bash
php json-solar-modbus.php --catalog
```

IP explizit angeben:

```bash
php json-solar-modbus.php --host 192.168.178.30
```

## Einbindung in eigenes PHP

```php
require_once __DIR__ . '/AmpereStorageProModbus.php';

$storage = new AmpereStorageProModbus('192.168.178.30');
$soc = $storage->readValue('battery.soc');
$pvPower = $storage->readValue('pv.power');
$snapshot = $storage->readSnapshot();
```

`readSnapshot()` liest zusammenhängende Registerblöcke. Das ist für eine
regelmäßige Sammlung effizienter als viele Aufrufe von `readValue()`.

Die wichtigsten bisherigen IQ-Box-Werte stehen zusätzlich unter `aliases`:

```text
batterySoc
batteryTemperature
batteryPower
pvPower
inverterPower
housePower
houseConsumptionToday
gridPower
batteryTotalChargeEnergy
batteryTotalDischargeEnergy
pvTotalEnergy
gridSellTotal
gridFeedInTotal
```

## Datentypen
`houseConsumptionToday` ist der bilanzierte Hausverbrauch des laufenden Tages:

```text
PV-Erzeugung + Netzbezug + Batterieentladung
- Netzeinspeisung - Batterieladung
```

Der Wert steht im Snapshot außerdem unter
`data.energy.house.calculatedToday`. Die direkt gelesenen Werte
`energy.house.today` und `energy.house.total` sind interne SAJ-Lastzähler und
werden nicht als bilanzierter Hausverbrauch verwendet.


- `uint16`: ein Register ohne Vorzeichen
- `int16`: ein Register mit Vorzeichen; Werte ab 32768 werden negativ dekodiert
- `uint32`: zwei aufeinanderfolgende Register, High-Word zuerst
- `scale`: Multiplikationsfaktor, beispielsweise `0.01` für Hundertstel

Die öffentlich verfügbaren Registerangaben stammen aus den Community-Projekten
`evcc` und `home-assistant-saj-h2-modbus`. AMPERE veröffentlicht selbst keine
vollständige offizielle Schnittstellenbeschreibung. Firmwarestände können sich
daher unterscheiden.

Bei der untersuchten StoragePro-Anlage gilt:

- `energy.grid.sell*`: Netzeinspeisung (Export)
- `energy.grid.feedIn*`: Netzbezug (Import)

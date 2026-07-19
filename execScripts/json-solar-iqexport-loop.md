# Ampere.IQ-Werteexport

`json-solar-iqexport-loop.php` ist eine neue, eigenstaendige Schnittstelle. Sie ist
nicht mit `sensorvalues.php` verbunden und greift nicht auf dessen Datenbank zu.

## Werte interaktiv auswaehlen

```bash
php json-solar-iqexport-loop.php
```

Danach kann in einer Schleife jeweils ein Bereich ausgewaehlt werden. Beispiele:

```text
soc
live
live.power
live.power.batteryPower
today
today-work
today.work
today.work.consumption
today.work.consumation
today-self-sufficiency
today-self-consumption
today-saving
today-saving-energy
today-saving-pv-production
today-saving-grid-feed
today-saving-own-consumption
today-saving-cost
today-saving-own-consumption-cost
today-saving-total
today-saving-grid-feed-compensation
today-saving-grid-feed-price
today-saving-electricity-prices
today-saving-evs
today-saving-emissions
today-saving-emissions-factor
today-saving-emissions-total
soc-history
battery-settings
devices
device DIE-GERAETE-UUID
devices DIE-GERAETE-UUID
all
q
```

Jede Auswahl liest nur die entsprechenden Daten frisch aus der Cloud. Die
Schleife verwendet direkt `AmpereIqHttpAccess::getValue()`: Einzelwerte werden
als JSON-Zahl und Bereiche als JSON-Objekt oder -Array angezeigt. Damit sind
Anzeige und Rueckgabewert in `json-heizung.php` identisch. Ein einzelner Bereich
kann auch ohne Schleife gelesen werden:

```bash
php json-solar-iqexport-loop.php --once=soc
php json-solar-iqexport-loop.php --once=live
php json-solar-iqexport-loop.php --once=live.power.batteryPower
php json-solar-iqexport-loop.php --once=today.work.consumption
```

Bei Pfaden wird die Gross-/Kleinschreibung ignoriert. Die abweichende Schreibweise
`today.work.consumation` wird ebenfalls als `today.work.consumption` verstanden.

## Vollstaendige Exportdatei erzeugen

```bash
php json-solar-iqexport-loop.php --all
```

Dadurch entsteht `json-solar-iqexport-loop.json`. Fuer eine Datei im Webserver:

```bash
php json-solar-iqexport-loop.php --output=/var/www/html/api/json-solar-iqexport-loop.json
```

Der Aufruf kann beispielsweise alle fuenf Minuten per Cron erfolgen. Die Datei
wird atomar ersetzt, sodass andere Anwendungen nie eine halb geschriebene
JSON-Datei lesen.

## Einfacher Zugriff

Wichtige Livewerte stehen direkt unter `aliases`:

```php
$iq = json_decode(file_get_contents('/var/www/html/api/json-solar-iqexport-loop.json'), true);
$batterySoc = $iq['aliases']['batterySoc'];
```

```javascript
const iq = await fetch('/api/json-solar-iqexport-loop.json').then(response => response.json());
const batterySoc = iq.aliases.batterySoc;
```

Unter `values` stehen alle gelesenen Einzelwerte mit einem eindeutigen Pfad,
zum Beispiel `live.batterySoc`. Unter `data` bleiben die vollstaendigen,
strukturierten Antworten erhalten. `writable` beschreibt bekannte
App-Einstellungen und ihre aktuellen Werte; der Export selbst ist absichtlich
rein lesend.

Zugangsdaten und OAuth-Tokens werden nicht exportiert.

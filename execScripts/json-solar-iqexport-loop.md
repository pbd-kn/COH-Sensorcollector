# IQBox direkt per Modbus testen

`json-solar-iqexport-loop.php` verwendet dieselbe Konsole wie
`json-inverter-modbus-loop.php`. Es setzt standardmäßig das Profil
`ampere-storagepro` und den Prompt `iqbox>`; der andere Einstieg verwendet
`saj-h2` und `inverter>`. Beide lesen ausschließlich über Modbus.

```sh
php json-solar-iqexport-loop.php
php json-solar-iqexport-loop.php 192.168.178.30
php json-solar-iqexport-loop.php --help
php json-solar-iqexport-loop.php 192.168.178.30 --once=pv
```

Die Beispiel-IP durch die Adresse der IQBox ersetzen. Ohne Adresse wird
`iqBoxModbus.host` aus `task_heizstab_params.json` neben dem Programm verwendet.
Fehlt dieser Eintrag, gilt der bisherige lokale Standardhost
`ASP-HSR2103J2311E08738.local`. Es werden keine Zugangsdaten aus dieser Datei
ausgegeben und keine Cloud-Verbindungen aufgebaut. Port 502, Unit-ID 1 und
drei Sekunden Timeout sind die Standardwerte. Für manuelle Tests können Port
und Unit-ID als zweites und drittes Argument angegeben werden.

Die Hilfe benötigt keine Geräteverbindung. Bei HTTP-/HTTPS-Geräteadressen wird
wie im Collector nur der Host verwendet.

```text
iqbox> pv
iqbox> live
iqbox> today
iqbox> lifetime
iqbox> get pv.string1.voltage
iqbox> get today.work.generation
iqbox> info live
iqbox> groups
iqbox> list battery
iqbox> q
```

Nur den Text hinter `iqbox>` eingeben. Einzelwerte und Gruppen werden direkt
als JSON ausgegeben. `info NAME` ergänzt Host, Zeitstempel und Einheit.
`json`/`snapshot`/`all` liefern den nativen Snapshot mit Metadaten;
`get all`/`get modbus` liefern nur dessen Datenbereich.

Gruppennamen, Unterwerte und Hilfetexte werden aus dem Profil erzeugt.
`pv`, `live`, `today` und `lifetime` werden mit derselben Funktion wie im
Collector aufbereitet. `live` enthält die sechs Energieflussfelder, fehlende
Werte bleiben `null`. Native Energiewerte sind in kWh, die Tages- und
Gesamtansichten liefern Energiewerte in Wh.

## Umstellung des bisherigen Programms

Dieser Dateiname bezeichnet jetzt den lokalen Modbus-Test. Der frühere
Cloud-Export ist ersetzt; Cloud-spezifische Historien, Einstellungen, Preise,
Gerätelisten sowie `--all`, `--output`, `--stdout` und `--params` sind hier
nicht mehr verfügbar. `--once=NAME` bleibt als rein lesende Einzelabfrage
erhalten. Die Cloud-Zugriffsklasse in `TaskAccess.php` ist davon unabhängig.

Auf dem Raspberry gehören beide Einstiege und `ModbusLoop.php` nach
`/home/peter/scripts/coh/execScripts/`. Die gemeinsamen Modbus-Klassen und
Profile müssen unter `/home/peter/scripts/coh/sensorcollect/Modbus/` liegen.
Vorhandene Dateien vor der Übertragung sichern. Einen laufenden Loop mit `q`
beenden und neu starten; für diese Testprogramme gibt es keinen Dienstneustart.

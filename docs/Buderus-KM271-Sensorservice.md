# Buderus KM271 Sensorservice

Der Sensorservice liest das KM271 ausschliesslich ueber TCP/3964R. Er sendet
das Lesekommando `EE 00 00`, schreibt aber keine Heizungsparameter.

## Gerät

In `tl_coh_geraete` wird ein Gerät mit diesen wesentlichen Werten angelegt:

| Feld | Beispiel |
|---|---|
| `geraeteID` | `KM271` |
| `geraeteUrl` | `192.168.178.70:8234` |

Als `sensorSource` kann bei den Sensoren `KM271`, `Buderus` oder
`buderus-km271` verwendet werden. Damit ist auch eine bestehende Geräte-ID
`Buderus` direkt nutzbar.

## Beispielsensoren

Die `sensorLokalId` entspricht genau dem Schlüssel unter `KM271` in der
JSON-Ausgabe. Jeder Schlüssel enthält ein Objekt aus `Name`, `Wert`, `Einheit`
und `Datum`. Bei einem Einzelsensor liest der Service daraus automatisch
`Wert` und `Einheit`. Die vom KM271 erkannte Einheit hat Vorrang vor der beim
Sensor eingetragenen Einheit.

| sensorID (Beispiel) | sensorLokalId | Einheit | Typ |
|---|---|---|---|
| `km271.alle` | `KM271` | `json` | `json` |
| `km271.aussen` | `Außentemperatur` | `°C` | `Temperatur` |
| `km271.kessel.ist` | `Kessel_Isttemperatur` | `°C` | `Temperatur` |
| `km271.kessel.soll` | `Kessel_Solltemperatur` | `°C` | `Temperatur` |
| `km271.ww.ist` | `Warmwasser_Isttemperatur` | `°C` | `Temperatur` |
| `km271.hk1.vorlauf.ist` | `HK1_Vorlauf_Isttemperatur` | `°C` | `Temperatur` |
| `km271.hk1.vorlauf.soll` | `HK1_Vorlauf_Solltemperatur` | `°C` | `Temperatur` |
| `km271.hk1.tag` | `HK1_Tagtemperatur` | `°C` | `Temperatur` |
| `km271.hk1.nacht` | `HK1_Nachttemperatur` | `°C` | `Temperatur` |
| `km271.hk1.pumpe` | `HK1_Pumpenleistung` | `%` | `Prozent` |
| `km271.brenner.laufzeit` | `Brennerlaufzeit_Stunden` | `h` | `Zeit` |
| `km271.brenner.stufe1` | `Brenner_Stufe_1` | leer | `Status` |
| `km271.stoerung` | `Störung_Brenner` | leer | `Status` |

Der Sammelsensor `KM271` enthält allgemeine Anlagenwerte, HK1 und die vom
Decoder unterstützten HK2-Statuswerte: `HK2_Vorlauf_Solltemperatur`,
`HK2_Vorlauf_Isttemperatur`, `HK2_Raum_Solltemperatur`,
`HK2_Raum_Isttemperatur`, `HK2_Pumpenleistung` und `HK2_Mischerstellung`.
Diese Schlüssel können auch als `sensorLokalId` für einzelne History-Sensoren
verwendet werden. Sie erscheinen erst nach Empfang entsprechender Telegramme.
Beispiel:

```json
{
  "Gerät": "Buderus KM271",
  "Betriebsart": "nur lesend",
  "Erstellt am": "2026-09-18T15:00:00+02:00",
  "KM271": {
    "HK1_Vorlauf_Isttemperatur": {
      "Name": "HK1 Vorlauf Isttemperatur",
      "Wert": 42,
      "Einheit": "°C",
      "Datum": "2026-09-18T15:00:00+02:00"
    }
  }
}
```

## Schreiben von HK2 Tag- und Nachttemperatur

Der separate Schreibkatalog in `Km271WriteCommandEncoder.php` unterstützt
`HK2_Tagtemperatur` und `HK2_Nachttemperatur` mit 10 bis 30 °C in
0,5-°C-Schritten. Beide verwenden den Schreibbefehl `08 00`; die
Nachttemperatur steht an Byteindex 4, die Tagtemperatur an Byteindex 5,
jeweils als doppelter Temperaturwert. Die übrigen Nutzdaten bleiben `65`.
Protokollreferenz: [ESP_Buderus_KM271, km271.cpp](https://github.com/dewenni/ESP_Buderus_KM271/blob/main/src/km271.cpp),
`KM271_SENDCMD_HC2_DAY_SETPOINT` und `KM271_SENDCMD_HC2_NIGHT_SETPOINT`.

Die API `km271-command.php` und die Contao-Templates übernehmen den
Schreibkatalog und die ausführbaren IDs automatisch. Vorschau,
Schreibfreigabe und ausdrückliche Bestätigung gelten auch für diese Werte.
Der lesende Sensorservice bleibt unverändert.

Zur Installation nur `sensorCollect/Sensor/Km271/Km271WriteCommandEncoder.php`
nach `/home/peter/scripts/coh/sensorcollect/Sensor/Km271/Km271WriteCommandEncoder.php`
übertragen. API und Contao-Code benötigen hierfür keine Änderung.
Ein Neustart des Collectors ist nicht erforderlich, da der Encoder durch
die HTTP-API geladen wird. Bei OPcache mit deaktivierter Dateiprüfung muss
der Cache des zuständigen PHP-Webprozesses erneuert werden. Danach die
Contao-Seite neu laden, damit sie den erweiterten Katalog abruft.

Offline-Prüfung: `php execScripts/km271-write-command-test.php`.

## Raspberry Pi

### Aktiver Sommer-/Winterbetrieb

`HK1_Sommerbetrieb` und `HK2_Sommerbetrieb` enthalten den von der Logamatic
gemeldeten Status: `1` = Sommerbetrieb (Schirm), `0` = Winterbetrieb.
Ausgewertet wird Bit 0 der Register `0x8001` beziehungsweise `0x8113`.
Andere Bits dieser Register beeinflussen diesen Wert nicht. Die Werte erscheinen
erst nach Empfang des jeweiligen Telegramms. `Sommergrenze` ist die
gemeinsame Umschaltschwelle für HK1 und HK2 und wird als `Sommergrenze`
angezeigt. JSON und Schreibkatalog verwenden `Sommergrenze`. Der alte Schlüssel
`HK1_Sommergrenze` wird nur beim Einlesen älterer Werte beziehungsweise bei
bestehenden Einzelwert-Konfigurationen und Schreibaufrufen akzeptiert; neue
Ausgaben enthalten ausschließlich `Sommergrenze`. Die Webseite zeigt den Status als Klartext an.

Pruefung: `php execScripts/km271-summer-mode-test.php`.

Diese Dateien müssen gemeinsam nach
`/home/peter/scripts/coh/sensorcollect/Sensor/` übertragen werden:

- `BuderusKm271SensorService.php`
- der Ordner `Km271/` mit `Protocol3964R.php` und `Km271Decoder.php`

`collect.php` erkennt den Service automatisch. Anschliessend wird der
Sensorcollector-Dienst neu gestartet und sein Protokoll geprüft:

```bash
sudo systemctl restart collect.service
sudo systemctl status collect.service
journalctl -u collect.service -n 100 --no-pager
```

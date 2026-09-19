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

Der Sammelsensor `KM271` enthält allgemeine Anlagenwerte und HK1, aber bewusst
keine HK2-Werte. Beispiel:

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

## Raspberry Pi

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

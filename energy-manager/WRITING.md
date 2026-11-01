# Vorbereitete manuelle Modbus-Schreibzugriffe

Die vorhandene Klasse `AmpereStorageProModbus` bleibt unveraendert lesend.
`StorageProWriter.php` ist ein separater PHP-8.1-kompatibler Client im Namespace
`CohEnergy`: FC06 (ein Register), FC16/0x10 (1..123 aufeinanderfolgende Register).
Damit werden keine neuen Schreibfunktionen in die bestehende Raspberry-HTTP-API
oder die vorhandenen Collector-Sensoren eingebaut. Kein Kopieren der lesenden
Client-Klasse ins Bundle erforderlich.

Protokollgrundlage: Modbus Application Protocol V1.1b3, Abschnitte 6.6 und 6.12:
https://www.modbus.org/file/secure/modbusprotocolspecification.pdf

## Noch keine Freigabe fuer reale Register

Lesbare Konfigurationsanzeigen (z. B. `0x4023..0x4030`) sind kein Nachweis, dass
dieselben Adressen geschrieben werden duerfen. Die vorhandenen Nutzerdateien
`execScripts/WechelrichterRegister.txt` und `execScripts/WechselRichter.csv`
enthalten Hinweise auf gesonderte Schreibregister; Modell-/Firmwarepruefung und
Herstellerfreigabe stehen aus. Keine Adresse wurde automatisch uebernommen.
Netz-/Schutzparameter, Betriebsarten und zusammengehoerende Grenzwerte benoetigen
zusaetzlich fachliche Pruefung. Dieser Client validiert rohe Registerwerte,
nicht geraetespezifische Abhaengigkeiten oder die Berechtigung des Bedieners.

## Private Freigabeliste

`write-policy.example.json` ist deaktiviert und enthaelt eine kommentierte,
fiktive Testadresse mit Hex-/Dezimalerklaerungen. Diese Regel vor Verwendung
an einem realen Geraet entfernen; sie ist keine Freigabe. Ausserhalb des Webroots
eine private Kopie anlegen. `enabled: false` erlaubt ausschliesslich lesende
Vorschauen fuer explizit eingetragene Register. Erst spaeter fuer tatsaechlich
erlaubte manuelle Schreibzugriffe `enabled` bewusst auf `true` setzen.

Struktur einer Regel, **nur fuer einen fiktiven Offline-Testserver**, kein
Registervorschlag fuer den Wechselrichter:

```json
{
  "enabled": false,
  "registers": {
    "0x1234": {
      "minRaw": "0x0000",
      "maxRaw": "0x0064",
      "functions": [6, 16],
      "evidence": "Fiktive Adresse 0x1234 fuer Offline-Tests; NICHT fuer StoragePro freigegeben"
    }
  }
}
```

Schluessel sind **nullbasierte Protokolladressen**, wahlweise als Hex-String
`"0x1234"` oder dezimal `"4660"`. `minRaw`/`maxRaw` erlauben ebenfalls
Hex-Strings wie `"0x0064"` oder Dezimalzahlen wie `100`. Hex-Angaben muessen in
JSON in Anfuehrungszeichen stehen. Doppelte Adressen in Hex- und Dezimalschreibweise
werden abgewiesen. Funktionscodes bleiben Dezimalzahlen: `[6]`, `[16]` oder `[6, 16]`.
`minRaw`/`maxRaw` sind rohe 16-Bit-Werte (0..65535), keine skalierten
Watt-/Prozentwerte. Int16-Negativwerte, Mehrwortwerte, Skalierung, Bitmasken und
Endianness muessen anhand der konkreten Registerdokumentation kodiert werden.
Bei FC16 braucht jedes beteiligte Register eine eigene Regel und Funktionsfreigabe.
`evidence` soll konkrete Dokumentation, Modell, Firmware und zulassige Verwendung
festhalten. Der Text selbst ist keine technische Herstellerbestaetigung.

## Verwendung im Dialog

```text
php json-energy-manager-loop.php ZIELADRESSE
energy> write-load C:\privater\pfad\write-policy.local.json
energy> write-plan 6 ADRESSE ROHWERT
```

`ADRESSE` und `ROHWERT` sind Platzhalter; Dezimal oder `0xHEX` eingeben.
Mehrere Rohwerte: `write-plan 16 STARTADRESSE WERT1,WERT2`.
Die Vorschau liest die bisherigen Rohwerte mit FC03 und schliesst danach sofort
die Verbindung. Sie zeigt Ziel, Funktion, Adresse, Alt-/Neuwerte und einen Code.
Nur wenn die Angaben stimmen und der Zugriff erlaubt ist:

```text
energy> write-confirm CODE_AUS_DER_VORSCHAU
```

Der Code gilt maximal 60 Sekunden, nur fuer dieses Ziel und diesen Plan.
Vor jedem Schreibversuch wird der Plan im lokalen `write-events.jsonl`
protokolliert. Ohne erfolgreiches Schreiben/Flush/Synchronisieren des Protokolls
beginnt der Zugriff nicht. Logrotation/Archivierung spaeter vorsehen.
Eine Bestaetigung verbraucht den Plan auch bei Ablehnung oder Fehler.
`write-cancel`, `write-load` und jeder `set`-Befehl verwerfen den Plan.
Eine fehlgeschlagene Policy-Ladung setzt die Freigabe auf gesperrt zurueck.

Nach Bestaetigung: neue Verbindung, FC03-Konfliktpruefung, genau eine
Schreibanfrage, Echo-Pruefung und FC03-Ruecklesepruefung, Verbindung schliessen.
Waehrend dieser kurzen Sequenz bleibt die Verbindung bestehen. Eine Aenderung
durch IQBox/andere Steuerung zwischen Konfliktpruefung und Schreiben laesst sich
protokollbedingt nicht atomar verhindern: vor realem Schreiben die konkurrierende
Steuerung geordnet ausschliessen. Die lokale Sperre bindet nur Prozesse dieses
Energiemanager-Pakets auf demselben Rechner ein.

Timeout, Protokollfehler oder abweichende Ruecklesewerte nach Schreibbeginn
bedeuten **moeglicherweise bereits geschrieben**. Kein automatischer Retry,
kein automatischer Rollback, kein erneuter Versand eines Gesamtplans.
Neuen Istzustand pruefen, bevor ein neuer Plan erstellt wird. FC16 ist kein
garantierter geraeteseitiger Transaktionsschutz fuer mehrere Einstellungen.
Selbstloeschende oder verzoegert wirksame Register sind mit der unmittelbaren
Ruecklesepruefung nicht freigegeben; diese benoetigen geraetespezifische Logik.

`EnergyManager`-Dauerdienst und `live-test.php` bleiben ausschliesslich lesend.
Keine Automatik schreibt, kein neuer HTTP-Schreibendpunkt existiert. Die
Freigabe eines Registers aktiviert keine automatische Heizungsregelung.

## Pruefung und Installation

```text
php write-test.php
php test.php
```

`write-test.php` startet ausschliesslich einen lokalen TCP-Server auf
127.0.0.1 mit dynamischem Port. Er prueft FC06/FC16-Anfragen, Erfolg, Konflikt,
Echo-/Headerfehler, Modbus-Exception, Verbindungsabbruch, abweichende Rueckwerte,
Verbindungsschluss, Freigaben und Planpruefungen. Keine Hardwarezugriffe.
Loopback-Verbindungen und `proc_open` muessen in der Testumgebung erlaubt sein.

Fuer den Raspi spaeter `StorageProWriter.php`, den aktualisierten Dialog und
die Hilfsklassen gemeinsam unter `energy-manager` installieren. Die private
Freigabeliste getrennt, restriktiv und ausserhalb des Webroots aufbewahren.
CLI-Dialog neu starten; kein Collector-/Raspi-Neustart fuer diese Erweiterung
erforderlich. Bisher wurde nichts hochgeladen und kein reales Register geschrieben.

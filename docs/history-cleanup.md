# Monatliche Ausduennung der Sensorhistorie

`sensorCollect/collect.php` startet nach dem Speichern der Messwerte einmal pro
Kalendermonat `SensorHistoryCleanup`. Der erste aktive Lauf holt die Bereinigung
auch nach Ausfall oder Neustart nach. Wegen der vorhandenen Ruhezeit findet sie
normalerweise fruehestens um 06:00 Uhr statt.

Der Stichtag ist der Monatsbeginn in Europe/Berlin minus drei Kalendermonate.
Am 1. November 2026 bleiben daher alle Werte ab 1. August 2026 unveraendert.
Vor dem Stichtag bleibt je `sensor` und Stunde der Eintrag mit dem groessten
`tstamp` erhalten; bei Gleichstand entscheidet die groessere `id`.
IDs, Werte, Einheiten, Typen und Zeitstempel der erhaltenen Zeilen werden nicht
veraendert. Fehlende Stunden werden nicht aufgefuellt. Die Stunden werden ueber
Unixzeit gruppiert, sodass die doppelte Stunde beim Wechsel zur Winterzeit zwei
getrennte Stunden bleibt.

Die alte Loeschregel (bei Daten ueber einem Jahr alles vor zehn Monaten loeschen)
entfaellt. Ausgeduennte Historie bleibt langfristig erhalten; die Datenbank waechst
weiter, aber fuer alte Messwerte nur noch um maximal einen Eintrag pro Sensor und
Stunde. Kurzzeitige Spitzen und Zustandswechsel innerhalb einer Stunde koennen
dabei verloren gehen.

## Probelauf

Auf dem Raspberry im Collector-Verzeichnis:

```sh
php sensorCollect/collect.php --cleanup-dry-run
```

Bei Aufruf direkt aus `sensorCollect`: `php collect.php --cleanup-dry-run`.
Der Probelauf nutzt dieselbe DB-Verbindung wie der Collector, liest keine Sensoren
und veraendert keine dauerhaften Tabellen. Die JSON-Ausgabe nennt den Stichtag
und je numerischer Sensor-ID `old_count`, `keep_count` und `remove_count`.
Auch nach abgeschlossener Monatsbereinigung ist der Probelauf moeglich.

## Betrieb

Beim regulaeren Start ist die Bereinigung automatisch aktiv. Der DB-Benutzer
braucht SELECT, DELETE, INSERT, CREATE und CREATE TEMPORARY TABLES. Die kleine
Tabelle `tl_coh_history_cleanup` wird automatisch angelegt und protokolliert
Monat, Stichtag, Anzahl geloeschter Zeilen und Abschlusszeitpunkt.
Eine Datenbanksperre verhindert parallele Bereinigungsläufe. Geloescht wird in
Paketen von 1000 Zeilen. Ein nach der Auswahl aktualisierter Eintrag wird durch
Vergleich seines Zeitstempels vor dem Loeschen geschuetzt.
Bei Fehlern wird kein erfolgreicher Monat vermerkt; der naechste Collector-Lauf
wiederholt die Bereinigung. Bereits ausgeduennte Stunden bleiben dabei stabil.

Der alte Contao-SyncService ist deaktiviert und wird hier nicht geaendert.

## Integrationstest

```sh
php tests/history-cleanup.php
```

Der Test legt eine zufaellig benannte `coh_cleanup_test_*`-Datenbank an und entfernt
sie danach. Er nutzt standardmaessig `127.0.0.1:3306`, Benutzer `root`, ohne Passwort.
Abweichende Testzugangsdaten koennen mit `COH_TEST_DB_HOST`, `COH_TEST_DB_PORT`,
`COH_TEST_DB_USER` und `COH_TEST_DB_PASSWORD` gesetzt werden.

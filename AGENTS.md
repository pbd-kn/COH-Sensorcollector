# Projektregeln: COH-Sensorcollector

## Aufgabe und Aufbau

- Dieses Repository enthält den eigenständigen Raspberry-PHP-Collector, Gerätezugriffe und die HTTP-API. Das Schwesterprojekt `../contao-contaohab-bundle` enthält Contao-Backend und Darstellung.
- `sensorCollect/collect.php` ist der Collector-Einstieg. `sensorCollect/autoload.php` lädt den Namespace `PbdKn\cohSensorcollector\`; hier gibt es kein Composer-Projekt.
- Sensorservices liegen in `sensorCollect/Sensor/`. `collect.php` lädt die dortigen PHP-Dateien und registriert Implementierungen von `SensorFetcherInterface`. Dessen Konstruktor und Rückgabeformen beachten.
- HTTP-Endpunkte liegen in `var/www/html/api/coh/`; `execScripts/` enthält eigenständige Tasks, Gerätezugriffe und Tests. `var/implementation/` ist nicht automatisch produktiver Bundle-Code: Verwendung prüfen, bevor dort geändert wird.
- Windows-Checkout und Linux-Ziel unterscheiden sich in Pfaden und Groß-/Kleinschreibung. Bestehende Include-Pfade, Namespaces und die Arbeitsverzeichnisse der systemd-Dateien berücksichtigen.

## Daten und Betrieb

- Der Collector speichert Messwerte; Contao liest sie über die API und überträgt Konfiguration über `config_push.php`. Den deaktivierten alten Contao-Sync nicht als neuen Datenweg verwenden.
- Bei API-Änderungen den jeweiligen Client im Schwesterprojekt prüfen. Sensor-IDs, `sensorLokalId`, Felder wie `sensorValue`, `sensorEinheit`, `sensorValueType`, Zeitstempel und Antwortstrukturen kompatibel halten oder die erforderliche Migration benennen.
- JSON-Sammelsensoren und Sensoren ohne History pflegen einen aktuellen Wert. History-Sensoren speichern Änderungen und konfigurierte Kontrollpunkte. Dieses Verhalten in `collect.php` nicht durch allgemeines Anhängen oder Überschreiben aller Messwerte ersetzen.
- Zähler im Modus `counter` behalten absolute Messwerte; Differenzen berechnet `var/www/html/api/coh/counter_history.php`. Fachregeln stehen im Bundle unter `docs/zaehler-history.md`; fehlende Basiswerte und Zeitumstellungen berücksichtigen.
- Historienbereinigung folgt `docs/history-cleanup.md`: einmal monatlich, Stichtag Monatsbeginn minus drei Kalendermonate in Europe/Berlin; ältere Werte auf den letzten Eintrag pro Sensor und Unixstunde ausdünnen. Gleichstand entscheidet die größere ID. Aktuelle Daten, Sperre und Wiederanlaufverhalten erhalten.
- Der normale Collector pausiert derzeit von 20:00 bis 06:00 Uhr. `pollTime` wird als Minuten verwendet. TTY/Konsolenmodus und systemd-Betrieb unterscheiden; beim Wiederverbinden nach der Ruhezeit müssen die Fetcher dieselbe erneuerte DB-Verbindung nutzen.
- `PROJECT.md` beschreibt die Heizungsregelung und die Trennung zwischen technischem Zugriff in `execScripts/TaskAccess.php` und fachlicher Regelung im Task. Den erklärenden Kommentar zu `decideHeizstabAction()` erhalten. Dokumentierte Dateinamen und Bundle-Einstellungstabellen vor Verwendung mit dem aktuellen Code abgleichen: Teile dieser Übersicht sind veraltet.
- KM271-Sensorlesen und Parameterschreiben getrennt behandeln. Für Schaltzeiten zusätzlich `../contao-contaohab-bundle/docs/KM271-Schaltzeiten.md` lesen; gemeinsame Verbindungssperre, Vorschau, Konfliktprüfung und Teilfehlerbehandlung erhalten. Es existieren getrennte KM271-Klassen unter `execScripts/Km271/` und `sensorCollect/Sensor/Km271/`; Änderungen am tatsächlich verwendeten Pfad vornehmen und den anderen bei gemeinsamer Protokolländerung prüfen.
- Modbus-Zuordnungen: `docs/AMPERE_STORAGEPRO_MODBUS_JSON_REGISTER.md`. Raspberry-IDs: `docs/Raspberry-LokalIDs.md`. KM271-Sensorformat: `docs/Buderus-KM271-Sensorservice.md`.

## Arbeiten und Prüfen

- Vor Änderungen `git status --short` prüfen; Nutzeränderungen erhalten. Den Stil der betroffenen Datei beachten und keine unabhängigen Altprogramme umarbeiten.
- Parameterdateien, Cookie-Dateien, Umgebungsdateien und Altcode können echte Zugangsdaten enthalten. Inhalte nicht vollständig ausgeben; keine Passwörter oder Tokens in Dokumentation, Antworten, Fixtures oder neue Commits übernehmen.
- Geänderte PHP-Dateien mit `php -l <Datei>` prüfen. Ein Dateiname mit `test` garantiert keinen Offline-Test: Einstieg und Nebenwirkungen vorher prüfen.
- Offline-Zählerprüfung: `php tests/counter_history_test.php`.
- Offline-KM271-Prüfungen je nach Änderung: `php execScripts/km271-protocol-test.php`, `php execScripts/km271-summer-mode-test.php`, `php execScripts/km271-write-command-test.php`, `php execScripts/km271-schedule-test.php`. Protokoll-/Schaltzeitentests verwenden lokale TCP-Testverbindungen statt der echten Heizung.
- `php tests/history-cleanup.php` ist ein DB-Integrationstest: Er erstellt und entfernt eine zufällig benannte `coh_cleanup_test_*`-Datenbank. Nur gegen einen geeigneten lokalen Testserver ausführen; Zugang über `COH_TEST_DB_HOST`, `COH_TEST_DB_PORT`, `COH_TEST_DB_USER`, `COH_TEST_DB_PASSWORD`. Kein Ersatz durch die Produktionsdatenbank.
- `collect.php` ohne Teststeuerung kann Dauerschleife, Sensorzugriffe, Speicherung und Bereinigung starten. Nicht für einen Syntaxcheck ausführen. `--console` startet einen DB-verbundenen Dialog mit anfänglich ausgeschaltetem Schreiben; `run` und `write on` verändern diese Betriebsart. `--cleanup-dry-run` liest die konfigurierte DB, bleibt deshalb eine bewusste Betriebsprüfung.
- Cloudtests und Gerätescripts können Netzwerkzugriffe, Tokenaktualisierung oder reale Schaltbefehle auslösen. Für lokale Codeprüfung Fixtures/Offline-Tests verwenden; Livezugriffe, Uploads und Dienstneustarts nur im beauftragten Umfang ausführen.
- Bei Änderungen benötigte Raspberry-Dateien und Neustarts benennen. Dateien unter `var/www/html/` sind Quellen für die Installation, keine Bestätigung einer bereits aktualisierten Raspberry-API.

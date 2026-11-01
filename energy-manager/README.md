# Vorbereitung: lokaler IQBox-Ersatz

Stand: 22.10.2026. Dieses Verzeichnis enthaelt jetzt einen ausfuehrbaren,
rein lesenden PHP-Dienst mit DB-Speicherung und einer offline testbaren
Ueberschussberechnung. Ein separater manueller Modbus-Schreibclient ist jetzt
vorbereitet; Verwendung und Freigaben stehen in [WRITING.md](WRITING.md).
Automatische aktive Steuerung ist noch nicht implementiert.
Es wurde nichts auf einem Raspberry installiert, keine Datenbank angelegt
und kein Geraet geschaltet.

## PHP-Code starten

### Interaktiver JSON-Dialog wie die execScripts-Loops

Empfohlener Einstieg fuer manuelles Ausprobieren (nicht `test.php`):

```powershell
php .\json-energy-manager-loop.php
```

Optional Ziel beim Start: `php .\json-energy-manager-loop.php 192.168.178.30`.
Der Start selbst baut keine Verbindung auf. Der Prompt `energy>` erwartet Befehle:

```text
demo
params
live
snapshot
get battery.soc
json
set grid-sign 1
set battery-sign 1
set heater 0
set reserve 200
live
loop 5 30
quit
```

`demo` zeigt Sonne, Netzbezug, Batterieentladung und niedrigen SOC mit Kunstwerten;
es veraendert weder den Live-Cache noch die Parameter. `live` liest nur die beiden
Leistungs-/SOC-Bloecke, `snapshot` den vollstaendigen bestehenden Registerkatalog.
Beide schliessen auch bei Fehlern vor Ausgabe/Berechnung die Verbindung.
`json`, `get` und `simulate` verwenden ausschliesslich den letzten Abruf mit
angezeigtem Alter; die Berechnung sperrt veraltete Werte (derzeit 20 Sekunden).
Beim Wechsel von Ziel/Verbindungsparametern oder Vorzeichen wird der Cache
verworfen; auch nach einem fehlgeschlagenen Abruf bleiben keine alten Daten aktiv.
`loop 5 30` bedeutet fuenf Versuche mit jeweils 30 Sekunden verbindungsfreier
Pause. `loop 0 30` laeuft bis Strg+C und beendet dann das ganze Programm.
Ein endlicher Loop kehrt zum Prompt zurueck. Hilfe mit `help`.

Vorzeichen und Heizlast in diesem Beispiel sind keine bestaetigten Anlagenwerte.
Heizlast nur dann mit 0 annehmen, wenn der Heizstab aus ist oder bewusst ein
hypothetisches Szenario berechnet werden soll. Alle Einstellungen gelten nur fuer
die Sitzung. Live-/Simulationsbefehle schreiben keine Geraetewerte.
Die separaten `write-*`-Befehle sind ausschliesslich nach [WRITING.md](WRITING.md)
zu verwenden und standardmaessig gesperrt. `test.php` bleibt dagegen
der automatische Offline-Prueftest mit kurzer OK-Ausgabe.

### Lesender Live-Test ohne Konfigurationsdatei und ohne DB

Im Verzeichnis `energy-manager`:

```powershell
php .\live-test.php --host=192.168.178.30 --once
php .\live-test.php --host=192.168.178.30 --count=5 --interval=30
php .\live-test.php --host=192.168.178.30 --loop --interval=60
php .\live-test.php --help
```

Host gegebenenfalls anpassen. Der Test liest nur den Leistungsblock
`0x40A0` bis `0x40AD` und Batterie-SOC `0xA00C` (zwei FC03-Anfragen).
Nach Erfolg oder Fehler wird sofort geschlossen, erst danach erfolgen Ausgabe,
Berechnung und Pause. `interval` ist die verbindungsfreie Pause zwischen den
Versuchen, nicht die garantierte Abtastperiode. Standard 30 Sekunden, Minimum 5.
Strg+C beendet den Dauerlauf. Keine Datenbank, keine Schreibbefehle.
Die Einzelinstanzsperre wird mit dem Energiemanager auf demselben Rechner geteilt;
andere Rechner, IQBox und bestehende Collector-Prozesse kennen diese Sperre nicht.
Kurze Lesezugriffe reduzieren die Belegung, garantieren aber keine Stoerungsfreiheit
bei Geraeten mit nur einer erlaubten TCP-Verbindung. Bei Problemen Test beenden.

Nach Bestaetigung der Vorzeichen kann eine Heizlastannahme mitgerechnet werden:

```powershell
php .\live-test.php --host=192.168.178.30 --once --grid-import-sign=1 --battery-discharge-sign=1 --heater-watts=0 --min-soc=50 --reserve=100 --max-heater=3000
```

Die Zahlen sind explizite Testannahmen! `heater-watts=0` nur verwenden, wenn der
Heizstab tatsaechlich aus ist, oder bewusst ein hypothetisches Szenario gewuenscht
ist. Im Dauerlauf bleibt die angenommene Heizlast konstant, sie wird nicht
gemessen. Ohne Heizlastparameter bleibt die Bilanz unbekannt. Vorzeichen `1`
bedeutet positiver Rohwert = Netzbezug bzw. Batterieentladung; bei umgekehrter
Geraetekonvention `-1` verwenden. Ohne beide Angaben werden nur Rohwerte angezeigt.
PV, Haus, Netz, Batterie, SOC und simulierte Entscheidung erscheinen je Runde
lesbar auf der Konsole. Ausfallversuche zaehlen bei `--count` mit; nach einem
Fehler beendet der Test seine Serie mit Exitcode 1. Hardwaretest noch ausstehend.

### Collector mit Konfiguration und optionaler DB

Voraussetzungen: PHP >= 8.1 mit pdo_mysql, Netzwerkzugriff auf StoragePro;
unter Linux optional pcntl fuer geregeltes Beenden. Kein Composer erforderlich.

1. `config.example.json` ausserhalb des Webroots als `config.json` kopieren.
   StoragePro-Host eintragen. Fuer den Start `enabled` auf `true` setzen;
   `mode` zunaechst `observe` lassen. Die deaktivierte Vorlage ist absichtlich
   noch keine gueltige Verbindungskonfiguration.
2. Ohne Netzwerkzugriff pruefen:
   `php energy-manager.php --config=/privater/pfad/config.json --check`
3. Einmalig nur lesen, noch ohne DB:
   `php energy-manager.php --config=/privater/pfad/config.json --once --no-db`
4. Fuer DB-Betrieb eigene Datenbank anlegen und nach Pruefung den Tabellenentwurf
   `schema.draft.sql` einmal darin importieren. Nicht in die Produktionsdatenbank
   des bisherigen Collectors importieren. SQL wurde hier nicht gegen MySQL getestet.
5. Umgebungsvariablen `COH_ENERGY_DSN` (z. B.
   `mysql:host=127.0.0.1;dbname=coh_energy;charset=utf8mb4`),
   `COH_ENERGY_DB_USER`, `COH_ENERGY_DB_PASSWORD` setzen. Der Laufzeitbenutzer
   braucht SELECT und INSERT fuer die drei Tabellen; Schema separat verwalten.
6. Ohne `--no-db` starten, zunaechst `--once`, dann als Dauerdienst.
   Die Unit `energy-manager.service.example` erst nach Pfad-/Benutzeranpassung
   installieren. EnvironmentFile liegt ausserhalb des Repositories mit restriktiven
   Rechten; dort keine Shell-Befehle, sondern systemd-Umgebungszuweisungen hinterlegen.
   Der Dienst liest die Konfiguration beim Start; Aenderungen brauchen Neustart.

Windows verwendet denselben CLI-Aufruf mit Windows-Konfigurationspfad.
Keine Web-Anwendung: Aufruf per HTTP ist gesperrt. stdout enthaelt Snapshots,
stderr Fehlerklassen ohne Geraeteantworten; fuer Dauerbetrieb Logrotation einrichten.
Pro Runde werden DB-Transaktionen genutzt; bei Fehlern folgt ein neuer Versuch
erst in der naechsten Runde. Es werden niemals Geraetebefehle versendet.

`simulate` braucht bestaetigte Vorzeichen (-1 oder 1), SOC-/Leistungsreserve,
maximale Heizleistung und `consumerPriority: ["heater"]`. Die reine Funktion
`EnergyManager::decide()` kann mit bekannten Heizstab-Istwerten getestet werden.
Der CLI-Dienst uebergibt derzeit bewusst `null`: die my-PV-Telemetrie ist noch
nicht zugeordnet; die Entscheidung lautet deshalb unvollstaendige Bilanz.
Kein Ersatz durch einen erfundenen Nullwert. Temperaturfreigabe, Hysterese,
Rampen, lokale Heizstab-Befehlsablaufzeit und Ruecklesepruefung folgen vor aktivem
Betrieb. `active` wird bereits bei der Konfigurationspruefung abgewiesen.

Offline-Test: `php test.php`. Dieser Test liest keine Geraete und keine DB.

## Vereinbarter Umfang

- AMPERE.StoragePro direkt ueber Modbus TCP lesen; vorhandene Wechselrichterdaten
  und `docs/AMPERE_STORAGEPRO_MODBUS_JSON_REGISTER.md` verwenden.
- PV-Ueberschuss lokal auswerten, vorhandenen my-PV-Heizstab lokal regeln.
- Zukuenftige Waermepumpe ueber Shelly und SG Ready vorbereiten. Bis Modell,
  Kontaktbelegung und Herstellerfreigaben bekannt sind, bleibt dieser Treiber aus.
- Messwerte, Konfiguration und Entscheidungen auf dem Raspi speichern.
- WAMP/Contao liest die Raspi-API und verwaltet Einstellungen.
- Ziel: Betrieb ohne IQBox und ohne Ampere-Cloud; diese Unabhaengigkeit muss
  beim spaeteren Abnahmetest auch praktisch nachgewiesen werden.

## Dateien und Verwendung

`config.example.json` beschreibt die Konfiguration des neuen CLI-Dienstes.
`null` bedeutet unbekannt und muss vor Freigabe validiert werden;
es darf nicht als Nullwert interpretiert werden. Prioritaeten und Schwellen sind
bewusst nicht vorgegeben. Keine Zugangsdaten in diese Vorlage eintragen.

`schema.draft.sql` ist ein pruefbarer Tabellenentwurf fuer eine eigene
MySQL/MariaDB-Datenbank. Noch nicht importieren. Alle Zeitstempel sind UTC;
Tagesgrenzen fuer Auswertungen liegen in Europe/Berlin. Messwerte behalten die
bestehenden JSON-Pfade und absoluten Zaehlerstaende. Fehlende Werte bleiben fehlend.
Fuer die spaetere Implementierung fehlen noch Migration, Aufbewahrung/Aggregation,
API und Authentifizierung. Der DB-Zugriff ist implementiert, aber noch nicht
gegen eine Datenbank getestet. Bei 5 Sekunden entstehen bis zu
17.280 Snapshots pro Tag; Speicherbedarf und Aufbewahrung vor Dauerbetrieb messen.

## Vorhandene Bausteine

- `sensorCollect/Sensor/AmpereStorageProModbus.php`: lesender Geraetezugriff;
  weitere Kopien unter `execScripts/` und im API-Verzeichnis. Eine gemeinsame
  Implementierung und deren Installation vor der Erweiterung festlegen.
- `execScripts/TaskAccess.php`, `HeizstabLocalAccess`: lokaler my-PV-Zugriff.
  `setPower()` existiert; zulassige Leistung, Temperaturfreigabe und Verhalten bei
  Kommunikationsausfall muessen fuer das konkrete Geraet verifiziert werden.
- `execScripts/json-heizung.php`: bisherige Regelung. Den Kommentar zu
  `decideHeizstabAction()` erhalten. Alte und neue Regelung duerfen nicht parallel
  dieselben Ausgaenge steuern.
- Der normale Collector pausiert nachts. Deshalb eigener 24/7-Dienst; dessen
  Ausfall darf den vorhandenen Collector nicht stoppen.

## Noch zu implementieren

1. PHP-8.1-kompatibler CLI-Dienst mit Einzelinstanzsperre, geregeltem Stop,
   begrenzten Timeouts und systemd-Installation. Installationspfade am Raspi
   pruefen: vorhandene Units verwenden unterschiedliche Schreibweisen/Pfade.
2. Gemeinsamer Snapshot je Runde mit Zeit und Qualitaet; bei Teilfehlern keine
   unvollstaendige Bilanz als gueltige Regelgrundlage verwenden.
3. Ueberschussregler mit bestaetigter Vorzeichenkonvention, SOC-Reserve,
   Leistungsreserve, einstellbarer Verbraucherprioritaet, Hysterese und Rampen.
   PV-Leistung allein ist kein verfuegbarer Ueberschuss. Netzleistung, aktuelle
   Verbraucherleistung und Batteriefluss gemeinsam bilanzieren; eine bereits
   eingeschaltete Last darf ihren eigenen Ueberschuss nicht scheinbar beseitigen.
4. Getrennte Modi `observe` (nur lesen), `simulate` (Entscheidungen protokollieren,
   nichts schreiben) und `active` (explizit freigegebene Treiber). Schema und Modi
   sind ein Vertrag fuer die kuenftige Implementierung, heute nicht verfuegbar.
5. Heizstabtreiber mit Ruecklesepruefung und dokumentiertem Timeoutverhalten.
   Schutzfunktionen des Geraets bleiben massgeblich. Ein geplanter Stopp-Befehl
   reicht bei Netzwerkausfall nicht: lokale Befehlsablaufzeit/Watchdog pruefen.
6. SG-Ready-Treiber erst nach Modell-/Verdrahtungspruefung; Kontaktzustand fuer
   Normalbetrieb, Ueberschussanforderung und Ausfall explizit hinterlegen.
   Mindestlauf-/Pausenzeiten und Neustartzustand persistent behandeln. SG Ready
   ist eine Betriebsanforderung und kein Nachweis laufender Waermepumpenleistung.
7. Authentifizierte Raspi-API und Contao-Oberflaeche fuer Livewerte, History,
   Konfiguration mit Revisionspruefung und zeitlich begrenzte Handvorgaben.
   Keine freien Register-/URL-Schreibendpunkte. Zugangsdaten ausserhalb Webroot.
8. Befehle vor Versand protokollieren, Ergebnis und Ruecklesewert ergaenzen.
   Bei unklarem Ergebnis keine blinde Wiederholung. Veraltete Befehle nach
   Neustart nicht abarbeiten. Veraltete Messwerte sperren neue Erhoehungen;
   das je Geraet definierte Ausfallverhalten ausloesen.

Wechselrichter-Schreibbefehle sind ein separater Ausbau; der manuelle Transport
ist inzwischen vorbereitet (siehe WRITING.md): bestehende lesbare
Sollwertregister belegen keine Schreibfreigabe. Modell, Firmware, Betriebsmodus,
Registerkodierung und Rueckfallverhalten vor einer Implementierung absichern.
Preisbasiertes Netzladen und Wallbox sind derzeit keine konkretisierten Module.

## Umstieg auf dem Raspi und in WAMP

### 1. Bestand sichern (vor Installation)

- Raspi-Sicherung und DB-Dump erstellen; Ruecksicherung pruefen. Zugangsdaten
  und lokale Konfiguration gesondert geschuetzt sichern.
- Tatsaechliche systemd-Units, ExecStart-Pfade, Benutzer und Geraeteadressen
  dokumentieren. `systemctl cat heizstab.service` und
  `systemctl cat collect.service` sind lesende Bestandspruefungen, sofern die
  Dienste dort so heissen. Keine Parameter-/Token-Dateien oeffentlich ausgeben.
- IQBox-Einstellungen, Heizstabsteuerquelle und Normalzustaende erfassen.
  Bestehende History sichern: Modbus liefert keine vollstaendige alte App-History.
- Firmware, Netz-/Batterievorzeichen und gemessene Leistungsbilanz abgleichen.

### 2. Spaeter die implementierte Version installieren

- Zunaechst nur den oben beschriebenen lesenden Dienst aus diesem Collector-Projekt
  auf den Raspi kopieren und testen. Aktive Regelung erst nach deren Implementierung
  und Offline-Tests installieren. Der vorhandene Modbus-Client unter
  `sensorCollect/Sensor/AmpereStorageProModbus.php` muss relativ zum neuen
  Verzeichnis erhalten bleiben.
- Finale Migration in eigener DB mit begrenztem Dienstbenutzer ausfuehren;
  Konfiguration ausserhalb des Webroots installieren.
- Eigenen systemd-Dienst mit bestaetigten Pfaden einrichten, zunaechst `observe`.
  Keine Abhaengigkeit vom eingeschalteten WAMP-Rechner.
- Benoetigte API-Dateien auf dem Raspi und Client-/Oberflaechen-Aenderungen im
  Bundle gemeinsam installieren; Contao-Cache und gegebenenfalls Assets erneuern.
  Einen Raspi-Gesamtneustart nur bei konkretem Bedarf, sonst betroffene Dienste.

### 3. Parallel beobachten, ohne zweite Steuerung

- IQBox behaelt zunaechst die Kontrolle. Neuer Dienst schreibt keine Befehle.
- Mehrere Tag-/Nachtzyklen inklusive PV-Schwankungen erfassen. Werte mit
  bestehender Anzeige vergleichen, Netzbezug/-einspeisung separat pruefen.
- In `simulate` Entscheidungen und Prioritaeten pruefen. DB-/Netzausfall,
  Teilantworten, veraltete Werte, Neustart und abgelaufene Befehle offline testen.
- Ohne installierte Waermepumpe deren Verhalten ausschliesslich simulieren.

### 4. Kontrollierte Uebernahme

- Geraeteweises Wartungsfenster festlegen. Fuer jedes uebernommene Geraet zuerst
  alle bisherigen schreibenden Instanzen deaktivieren: IQBox-Funktion,
  Heizstab-Automatik und gegebenenfalls `heizstab.service` bzw. weitere Tasks.
  Nicht ungeprueft den gesamten Collector stoppen.
- Heizstab zuerst mit begrenzter Leistung uebernehmen. Sollwert, Istleistung,
  Temperaturbegrenzung, Nullvorgabe und Verbindungsausfall pruefen.
- Erst nach bestandenen Tests dauerhaften Automatikbetrieb freigeben.
- Waermepumpe spaeter separat mit dem Installateur abnehmen. Shelly-Modell,
  geeignete Kontakte und SG-Ready-Belegung nicht aus dem Namen ableiten.
- IQBox erst entfernen, wenn geklaert und getestet ist, dass keine benoetigte
  Messung, Kommunikation oder weitere Geraetefunktion davon abhaengt. Die
  Abschaltung darf keine parallele Ersatz-Steuerung aktivieren.

### 5. Abnahme und Rueckfall

- Abnahme: Tag/Nacht, Wolken, Batterie-SOC-Grenze, keine unerwuenschte
  Batterieentladung fuer Waerme, Mindestlaufzeiten, WAMP ausgeschaltet,
  Raspi-Neustart und definierte Ausfallzustaende pruefen.
- Erst dann Cloud-Zugang als verbleibende Betriebsabhaengigkeit entfernen;
  nachgewiesenen lokalen Betrieb dokumentieren.
- Rueckfall: neue Steuerung stoppen und Ausgangszustaende am Geraet pruefen.
  Bei nicht erreichbarem Geraet dessen lokale Bedienung/Watchdog verwenden.
  Erst danach IQBox bzw. bisherige Heizstabregelung wieder als einzige
  Steuerinstanz aktivieren und Funktion kontrollieren.
- Messdaten und Befehlsprotokoll erhalten. Keine Tabellen fuer den Rueckfall
  loeschen und keine Altbefehle erneut ausfuehren.

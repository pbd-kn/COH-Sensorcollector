# Buderus KM271: isolierter 3964R-Empfangstest

Der Test ist absichtlich noch kein Sensor-Fetcher und verwendet keine
Datenbank. Er verbindet sich per TCP mit dem RS232/TCP-Adapter und arbeitet als
passiver 3964R-Empfaenger. Nutztelegramme oder Heizungsparameter werden nicht
gesendet. Lediglich die vom Protokoll verlangten Steuerzeichen `DLE`
(Empfangsbereitschaft/Quittung) beziehungsweise `NAK` bei Fehlern gehen zum
KM271.

## Start

```bash
php execScripts/km271-protocol-test.php
php execScripts/km271-handshake-test.php
php execScripts/km271-listen.php --duration=120
php execScripts/json-buderus-km271-loop.php
```

`km271-handshake-test.php` sendet einmalig nur `STX` und erwartet `DLE`. Damit
werden beide Datenrichtungen und die 3964R-Erkennung geprueft. Nach der Antwort
wird die TCP-Verbindung beendet; ein Nutztelegramm wird nicht gesendet.

`json-buderus-km271-loop.php` ist der interaktive Test im Stil der vorhandenen
JSON-Loop-Programme. Beim Start sammelt er 30 Sekunden lang Telegramme, damit
die sequenziell gelieferten Heizkreis-, Warmwasser-, Kessel- und Aussenwerte
vollstaendig eintreffen, und
zeigt bekannte Buderus-Werte wie Kessel-, Warmwasser- und Aussentemperatur,
Heizkreiswerte, Pumpen, Brennerzustand, Stoerungen und Brennerlaufzeit an. Mit
`r 20` wird beispielsweise weitere 20 Sekunden gelesen, `werte` zeigt den
aktuellen dekodierten Stand, `json` gibt nur die lesbaren Fachwerte aus und
`raw` speichert Werte und Rohtelegramme als Diagnose-JSON. Rohtelegramme bleiben mit `telegramme` und `filter C0 05` fuer
Diagnosen erreichbar. Die Loop aktiviert mit dem dokumentierten Kommando
`EE 00 00` den rein lesenden KM271-Logmodus, damit auch Konfigurationswerte
uebertragen werden. Heizungsparameter werden nicht geschrieben.
Die normale JSON-Ausgabe verwendet ausschliesslich deutsche Feldnamen und
enthaelt keine internen englischen Programmschluessel. Die Fachwerte sind unter
`KM271` direkt ueber stabile Schluessel wie `HK1_Nachttemperatur` erreichbar;
`Einheiten` und `Aktualisiert am` verwenden dieselben Schluessel.

Vorgaben sind `192.168.178.70`, Port `8234`, 60 Sekunden Laufzeit und vier
Sekunden Telegramm-Timeout. Optionen zeigt `--help`. Die Ausgabe erscheint auf
der Konsole und standardmaessig in `execScripts/km271-raw.log`. Sie enthaelt
Steuerbytes, empfangene und berechnete BCC sowie den unmaskierten Nutzinhalt in
Hexdarstellung.

Ein erfolgreicher Empfang sieht sinngemaess so aus:

```text
RX idle 02
TX 10 (start acknowledgement)
...
RX BCC 7A, berechnet 7A
TX 10 (telegram acknowledgement)
TELEGRAM #1 length=... payload=...
```

Kommt kein `02` (STX), ist TCP erreichbar, aber noch kein serieller
Datenaustausch belegt. Dann Verkabelung und Adaptermodus pruefen. Die richtige
1:1-/Nullmodem-Belegung haengt von den DTE/DCE-Rollen ab. Der Adapterzaehler fuer
Empfang vom PC belegt nur die TCP-zu-RS232-Richtung, nicht eine KM271-Antwort.

## Bestaetigte Verkabelung der vorhandenen Geraete

Am konkreten KM271 und am vorhandenen TCP232-302 wurde Pin 2 jeweils als
RS232-TX-Ausgang gemessen (KM271 etwa -8 V, Adapter etwa -5 V gegen Pin 5).
Das urspruengliche 1:1-Kabel verband deshalb TX mit TX. Der erfolgreiche
Handshake `TX 02` / `RX 10` wurde mit folgender Kreuzung nachgewiesen:

```text
KM271 Pin 2 (TX)  -> Adapter Pin 3 (RX)
KM271 Pin 3 (RX)  <- Adapter Pin 2 (TX)
KM271 Pin 5 (GND) -- Adapter Pin 5 (GND)
```

## Ablauf

1. Auf `STX` warten und mit `DLE` antworten.
2. Nutzdaten lesen; Nutzdaten-`DLE` ist auf der Leitung verdoppelt.
3. `DLE ETX` beendet das Telegramm.
4. BCC als XOR ueber unmaskierte Nutzdaten und `DLE ETX` pruefen.
5. Gueltig mit `DLE`, fehlerhaft mit `NAK` quittieren.

Kollisionsbehandlung fuer einen aktiven Sender ist bewusst nicht enthalten,
weil der erste Test keine Nutztelegramme sendet.

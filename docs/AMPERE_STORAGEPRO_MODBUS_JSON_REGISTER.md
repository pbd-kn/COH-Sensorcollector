# AMPERE.StoragePro: JSON-Werte und Modbusregister

Diese Referenz dokumentiert die Werte, die der lokale Modbus-Client in `snapshot.data` beziehungsweise über den Contao-Pfad `modbus.*` bereitstellt. Der Zugriff ist ausschließlich lesend über Modbus-Funktion 03.

Dokumentationsstand: 9. August 2026  
Aktuelle Gerätewerte ausgelesen am: **09.08.2026 12:58:44 MESZ** von **192.168.178.30**

> Die Spalte „Aktueller Wert“ ist eine Momentaufnahme. Leistungs-, Strom-, Temperatur- und Tageswerte können sich jederzeit ändern.

## Verwendung

Interaktive Anzeige:

```text
php json-solar-modbus-loop.php 192.168.178.30
storagepro> snapshot
storagepro> json
storagepro> get battery.soc
```

Ein einzelner Wert wird in Contao mit vorangestelltem `modbus.` ausgewählt. Beispiel: Aus dem JSON-Pfad `battery.soc` wird die Auswahl `modbus.battery.soc`.

Einmalige JSON-Ausgabe:

```text
php json-solar-modbus.php --host 192.168.178.30
```

## Datentypen und Skalierung

- `uint16`: vorzeichenloses 16-Bit-Register.
- `int16`: vorzeichenbehaftetes 16-Bit-Register.
- `uint32`: vorzeichenloser 32-Bit-Wert aus zwei aufeinanderfolgenden Registern.
- Ausgabewert = Rohwert × Faktor. Die JSON-Ausgabe enthält bereits den fertig skalierten Wert.
- Vorzeichen bei Leistungs- und Stromwerten hängen von der Richtungskonvention der Gerätefirmware ab.

## Registerübersicht

| JSON-Pfad in `snapshot.data` | Contao-Auswahl | Register hex | Register dez. | Typ | Faktor | Einheit | Aktueller Wert | Bedeutung |
|---|---|---:|---:|---|---:|---|---:|---|
| `inverter.temperature` | `modbus.inverter.temperature` | `0x4010` | 16400 | `int16` | 0.1 | °C | **69,3 °C** | Temperatur des Wechselrichters |
| `inverter.ambientTemperature` | `modbus.inverter.ambientTemperature` | `0x4011` | 16401 | `int16` | 0.1 | °C | **63,3 °C** | Umgebungstemperatur des Wechselrichters |
| `inverter.dischargePowerSetpoint` | `modbus.inverter.dischargePowerSetpoint` | `0x4023` | 16419 | `int16` | 1 | W | **10000 W** | Sollwert der Wechselrichter-Entladeleistung |
| `inverter.chargePowerSetpoint` | `modbus.inverter.chargePowerSetpoint` | `0x4024` | 16420 | `int16` | 1 | W | **10000 W** | Sollwert der Wechselrichter-Ladeleistung |
| `battery.dischargeCurrentSetpoint` | `modbus.battery.dischargeCurrentSetpoint` | `0x4025` | 16421 | `int16` | 0.1 | A | **30 A** | Sollwert des Batterie-Entladestroms |
| `battery.chargeCurrentSetpoint` | `modbus.battery.chargeCurrentSetpoint` | `0x4026` | 16422 | `int16` | 0.1 | A | **0 A** | Sollwert des Batterie-Ladestroms |
| `battery.statusDisplay` | `modbus.battery.statusDisplay` | `0x4027` | 16423 | `uint16` | 1 | – | **544** | Batteriestatus-Anzeige |
| `battery.protocol` | `modbus.battery.protocol` | `0x4028` | 16424 | `int16` | 1 | – | **22** | Eingestelltes Batterieprotokoll |
| `battery.chargeSocUpperLimit` | `modbus.battery.chargeSocUpperLimit` | `0x4029` | 16425 | `int16` | 1 | % | **100 %** | Obere SOC-Grenze beim Laden |
| `battery.dischargeSocLowerLimit` | `modbus.battery.dischargeSocLowerLimit` | `0x402A` | 16426 | `int16` | 1 | % | **4 %** | Untere SOC-Grenze beim Entladen |
| `battery.depthOfDischargeSetpoint` | `modbus.battery.depthOfDischargeSetpoint` | `0x402B` | 16427 | `int16` | 1 | % | **100 %** | Eingestellte Entladetiefe |
| `battery.reserveSoc` | `modbus.battery.reserveSoc` | `0x402C` | 16428 | `int16` | 1 | % | **0 %** | Eingestellte SOC-Reserve |
| `meter.mode` | `modbus.meter.mode` | `0x4030` | 16432 | `int16` | 1 | – | **1** | Eingestellter Zählermodus |
| `grid.l1.voltage` | `modbus.grid.l1.voltage` | `0x4031` | 16433 | `uint16` | 0.1 | V | **236,3 V** | Netzspannung Phase L1 |
| `grid.l1.current` | `modbus.grid.l1.current` | `0x4032` | 16434 | `int16` | 0.01 | A | **8,26 A** | Netzstrom Phase L1 |
| `grid.l1.frequency` | `modbus.grid.l1.frequency` | `0x4033` | 16435 | `uint16` | 0.01 | Hz | **49,97 Hz** | Netzfrequenz Phase L1 |
| `grid.l1.power` | `modbus.grid.l1.power` | `0x4035` | 16437 | `int16` | 1 | W | **1945 W** | Wirkleistung Phase L1 |
| `grid.l2.voltage` | `modbus.grid.l2.voltage` | `0x4038` | 16440 | `uint16` | 0.1 | V | **236,5 V** | Netzspannung Phase L2 |
| `grid.l2.current` | `modbus.grid.l2.current` | `0x4039` | 16441 | `int16` | 0.01 | A | **8,22 A** | Netzstrom Phase L2 |
| `grid.l2.frequency` | `modbus.grid.l2.frequency` | `0x403A` | 16442 | `uint16` | 0.01 | Hz | **49,97 Hz** | Netzfrequenz Phase L2 |
| `grid.l2.power` | `modbus.grid.l2.power` | `0x403C` | 16444 | `int16` | 1 | W | **1936 W** | Wirkleistung Phase L2 |
| `grid.l3.voltage` | `modbus.grid.l3.voltage` | `0x403F` | 16447 | `uint16` | 0.1 | V | **235,9 V** | Netzspannung Phase L3 |
| `grid.l3.current` | `modbus.grid.l3.current` | `0x4040` | 16448 | `int16` | 0.01 | A | **8,29 A** | Netzstrom Phase L3 |
| `grid.l3.frequency` | `modbus.grid.l3.frequency` | `0x4041` | 16449 | `uint16` | 0.01 | Hz | **49,97 Hz** | Netzfrequenz Phase L3 |
| `grid.l3.power` | `modbus.grid.l3.power` | `0x4043` | 16451 | `int16` | 1 | W | **1951 W** | Wirkleistung Phase L3 |
| `battery.temperature` | `modbus.battery.temperature` | `0x406E` | 16494 | `int16` | 0.1 | °C | **34 °C** | Allgemeine Batterietemperatur |
| `battery.socSummary` | `modbus.battery.socSummary` | `0x406F` | 16495 | `uint16` | 0.01 | % | **100 %** | Zusammengefasster Batterie-Ladezustand |
| `pv.string1.voltage` | `modbus.pv.string1.voltage` | `0x4071` | 16497 | `uint16` | 0.1 | V | **339,9 V** | PV-String 1 Spannung |
| `pv.string1.current` | `modbus.pv.string1.current` | `0x4072` | 16498 | `uint16` | 0.01 | A | **9,17 A** | PV-String 1 Strom |
| `pv.string1.power` | `modbus.pv.string1.power` | `0x4073` | 16499 | `uint16` | 1 | W | **3116 W** | PV-String 1 Leistung |
| `pv.string2.voltage` | `modbus.pv.string2.voltage` | `0x4074` | 16500 | `uint16` | 0.1 | V | **360,7 V** | PV-String 2 Spannung |
| `pv.string2.current` | `modbus.pv.string2.current` | `0x4075` | 16501 | `uint16` | 0.01 | A | **8,6 A** | PV-String 2 Strom |
| `pv.string2.power` | `modbus.pv.string2.power` | `0x4076` | 16502 | `uint16` | 1 | W | **3102 W** | PV-String 2 Leistung |
| `house.power` | `modbus.house.power` | `0x40A0` | 16544 | `int16` | 1 | W | **4017 W** | Gesamter aktueller Hausverbrauch |
| `pv.power` | `modbus.pv.power` | `0x40A5` | 16549 | `int16` | 1 | W | **6204 W** | Gesamte aktuelle PV-Leistung |
| `battery.power` | `modbus.battery.power` | `0x40A6` | 16550 | `int16` | 1 | W | **0 W** | Aktuelle Lade- oder Entladeleistung der Batterie |
| `grid.powerSummary` | `modbus.grid.powerSummary` | `0x40A7` | 16551 | `int16` | 1 | W | **5829 W** | Aggregierte Netzleistung |
| `inverter.power` | `modbus.inverter.power` | `0x40A9` | 16553 | `int16` | 1 | W | **5748 W** | Aktuelle Wechselrichterleistung |
| `grid.power` | `modbus.grid.power` | `0x40AD` | 16557 | `int16` | 1 | W | **-2187 W** | Saldierte Leistung am Netzübergabepunkt |
| `energy.pv.today` | `modbus.energy.pv.today` | `0x40BF` | 16575 | `uint32` | 0.01 | kWh | **24,44 kWh** | PV-Energie heute |
| `energy.pv.month` | `modbus.energy.pv.month` | `0x40C1` | 16577 | `uint32` | 0.01 | kWh | **416 kWh** | PV-Energie im laufenden Monat |
| `energy.pv.year` | `modbus.energy.pv.year` | `0x40C3` | 16579 | `uint32` | 0.01 | kWh | **7265,23 kWh** | PV-Energie im laufenden Jahr |
| `energy.pv.total` | `modbus.energy.pv.total` | `0x40C5` | 16581 | `uint32` | 0.01 | kWh | **21785,23 kWh** | PV-Energie gesamt |
| `energy.battery.chargeToday` | `modbus.energy.battery.chargeToday` | `0x40C7` | 16583 | `uint32` | 0.01 | kWh | **2,84 kWh** | Batterieladung heute |
| `energy.battery.chargeTotal` | `modbus.energy.battery.chargeTotal` | `0x40CD` | 16589 | `uint32` | 0.01 | kWh | **3166,28 kWh** | Batterieladung gesamt |
| `energy.battery.dischargeToday` | `modbus.energy.battery.dischargeToday` | `0x40CF` | 16591 | `uint32` | 0.01 | kWh | **1,45 kWh** | Batterieentladung heute |
| `energy.battery.dischargeTotal` | `modbus.energy.battery.dischargeTotal` | `0x40D5` | 16597 | `uint32` | 0.01 | kWh | **2737,52 kWh** | Batterieentladung gesamt |
| `energy.inverter.today` | `modbus.energy.inverter.today` | `0x40D7` | 16599 | `uint32` | 0.01 | kWh | **23,42 kWh** | Wechselrichtererzeugung heute |
| `energy.inverter.total` | `modbus.energy.inverter.total` | `0x40DD` | 16605 | `uint32` | 0.01 | kWh | **23711,38 kWh** | Wechselrichtererzeugung gesamt |
| `energy.house.today` | `modbus.energy.house.today` | `0x40DF` | 16607 | `uint32` | 0.01 | kWh | **7,84 kWh** | Interner SAJ-Lastzähler heute; nicht als bilanziellen Hausverbrauch verwenden |
| `energy.house.total` | `modbus.energy.house.total` | `0x40E5` | 16613 | `uint32` | 0.01 | kWh | **10923 kWh** | Interner SAJ-Lastzähler gesamt; nicht als bilanziellen Hausverbrauch verwenden |
| `energy.grid.sellToday` | `modbus.energy.grid.sellToday` | `0x40EF` | 16623 | `uint32` | 0.01 | kWh | **6,36 kWh** | Netzeinspeisung (Export) heute |
| `energy.grid.sellTotal` | `modbus.energy.grid.sellTotal` | `0x40F5` | 16629 | `uint32` | 0.01 | kWh | **4622,02 kWh** | Netzeinspeisung (Export) gesamt |
| `energy.grid.feedInToday` | `modbus.energy.grid.feedInToday` | `0x40F7` | 16631 | `uint32` | 0.01 | kWh | **0,09 kWh** | Netzbezug (Import) heute |
| `energy.grid.feedInTotal` | `modbus.energy.grid.feedInTotal` | `0x40FD` | 16637 | `uint32` | 0.01 | kWh | **2038,33 kWh** | Netzbezug (Import) gesamt |
| `energy.grid.sumFeedInToday` | `modbus.energy.grid.sumFeedInToday` | `0x4167` | 16743 | `uint32` | 0.01 | kWh | **0,06 kWh** | Netzbezug heute, Summe |
| `energy.grid.sumSellToday` | `modbus.energy.grid.sumSellToday` | `0x416F` | 16751 | `uint32` | 0.01 | kWh | **15,64 kWh** | Netzeinspeisung heute, Summe |
| `battery.soc` | `modbus.battery.soc` | `0xA00C` | 40972 | `uint16` | 0.01 | % | **100 %** | Ladezustand Batterie 1 |
| `battery.soh` | `modbus.battery.soh` | `0xA00D` | 40973 | `uint16` | 0.01 | % | **97,5 %** | Gesundheitszustand Batterie 1 |
| `battery.voltage` | `modbus.battery.voltage` | `0xA00E` | 40974 | `uint16` | 0.1 | V | **213 V** | Spannung Batterie 1 |
| `battery.current` | `modbus.battery.current` | `0xA00F` | 40975 | `int16` | 0.01 | A | **0 A** | Strom Batterie 1 |
| `battery.moduleTemperature` | `modbus.battery.moduleTemperature` | `0xA010` | 40976 | `int16` | 0.1 | °C | **34 °C** | Temperatur Batterie 1 |
| `battery.cycles` | `modbus.battery.cycles` | `0xA011` | 40977 | `uint16` | 1 | – | **0** | Zyklenzahl Batterie 1 |

## Hinweise zu den Einstellungsregistern `0x4023–0x4030`

Diese Register beschreiben Konfigurationen oder Grenzwerte, nicht zwingend den gerade aktiven Energiefluss.

- `inverter.dischargePowerSetpoint` und `inverter.chargePowerSetpoint`: eingestellte Leistungsgrenzen beziehungsweise Sollwerte in Watt.
- `battery.dischargeCurrentSetpoint` und `battery.chargeCurrentSetpoint`: Stromgrenzen beziehungsweise Sollwerte in Ampere. Ein Wert `0` kann firmwareabhängig „kein fester Sollwert“ oder „deaktiviert“ bedeuten.
- `battery.statusDisplay`: interner Statuscode beziehungsweise Bitwert. Eine Klartextdekodierung ist ohne Herstellertabelle nicht möglich.
- `battery.protocol`: Kennzahl des gewählten Batterie-Kommunikationsprotokolls.
- `battery.chargeSocUpperLimit`: obere Ladegrenze der Batterie.
- `battery.dischargeSocLowerLimit`: untere Entladegrenze der Batterie.
- `battery.depthOfDischargeSetpoint`: eingestellte Entladetiefe; die genaue Verrechnung mit der unteren SOC-Grenze ist firmwareabhängig.
- `battery.reserveSoc`: zusätzlich zurückgehaltener Ladezustand, beispielsweise als Reserve.
- `meter.mode`: interne Kennzahl des eingestellten Zählermodus. Die Zuordnung der Zahlen zu Betriebsarten benötigt die Herstellerdokumentation.
- Die Register `0x402D`, `0x402E` und `0x402F` werden als reserviert behandelt und nicht in die JSON-Ausgabe übernommen.

## JSON-Struktur (gekürzt)

```json
{
  "device": "AMPERE.StoragePro",
  "host": "192.168.178.30",
  "port": 502,
  "unitId": 1,
  "timestamp": "...",
  "data": {
    "inverter": {
      "dischargePowerSetpoint": 10000,
      "chargePowerSetpoint": 10000
    },
    "battery": {
      "dischargeCurrentSetpoint": 30,
      "chargeCurrentSetpoint": 0,
      "chargeSocUpperLimit": 100,
      "dischargeSocLowerLimit": 4,
      "depthOfDischargeSetpoint": 100,
      "reserveSoc": 0
    },
    "meter": {
      "mode": 1
    }
  }
}
```

Die Zahlen im Beispiel stammen aus dem aktuellen Testabruf und können sich durch Geräteeinstellungen oder Betriebszustände ändern.

## Wichtige Energiehinweise

- `energy.house.today` und `energy.house.total` sind interne SAJ-Lastzähler. Sie sollen nicht ungeprüft als bilanzierter Hausverbrauch verwendet werden.
- `energy.grid.sell*` steht in dieser Integration für Netzeinspeisung (Export).
- `energy.grid.feedIn*` steht in dieser Integration für Netzbezug (Import).
- Die JSON-Pfade sind die stabilen Bezeichner für CLI, API und Contao-Auswahl.

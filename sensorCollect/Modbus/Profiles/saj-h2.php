<?php

declare(strict_types=1);

// SAJ HS2/H2: die 63 vollstaendig typisierten Werte aus execScripts/WechselRichter.csv.
// Unvollstaendige CSV-Zeilen werden nicht als Messwerte geraten.
return [
    'device' => 'SAJ HS2/H2',
    // Nur Anzeigeinformationen; die Schreib-Allowlist controls bleibt unverändert.
    'controlCatalog' => require __DIR__ . '/saj-h2-control-catalog.php',
    // Ausschließlich diskrete, in WechelrichterRegister.txt als R/W belegte Einstellungen.
    // Weitere Register erst mit belegter Codierung und Wertebereich freigeben.
    'controls' => [
        'control.appMode' => [
            'address' => 0x3647, 'label' => 'Betriebsmodus',
            'options' => [0 => 'Eigenverbrauch', 2 => 'Backup', 3 => 'Passiv'],
        ],
        'control.passiveAction' => [
            'address' => 0x3636, 'label' => 'Passive Batteriesteuerung',
            'options' => [0 => 'Bereitschaft', 1 => 'Entladen', 2 => 'Laden'],
            'requires' => ['address' => 0x3647, 'value' => 3, 'message' => 'Betriebsmodus muss bereits Passiv sein.'],
        ],
    ],
    'fields' => [
        'inverter.temperature' => ['address' => 0x4010, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Temperatur des Wechselrichters'],
        'inverter.ambientTemperature' => ['address' => 0x4011, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Umgebungstemperatur des Wechselrichters'],

        'inverter.dischargePowerSetpoint' => ['address' => 0x4023, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Sollwert der Wechselrichter-Entladeleistung'],
        'inverter.chargePowerSetpoint' => ['address' => 0x4024, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Sollwert der Wechselrichter-Ladeleistung'],
        'battery.dischargeCurrentSetpoint' => ['address' => 0x4025, 'type' => 'int16', 'scale' => 0.1, 'unit' => 'A', 'description' => 'Sollwert des Batterie-Entladestroms'],
        'battery.chargeCurrentSetpoint' => ['address' => 0x4026, 'type' => 'int16', 'scale' => 0.1, 'unit' => 'A', 'description' => 'Sollwert des Batterie-Ladestroms'],
        'battery.statusDisplay' => ['address' => 0x4027, 'type' => 'uint16', 'scale' => 1.0, 'unit' => '', 'description' => 'Batteriestatus-Anzeige'],
        'battery.protocol' => ['address' => 0x4028, 'type' => 'int16', 'scale' => 1.0, 'unit' => '', 'description' => 'Eingestelltes Batterieprotokoll'],
        'battery.chargeSocUpperLimit' => ['address' => 0x4029, 'type' => 'int16', 'scale' => 1.0, 'unit' => '%', 'description' => 'Obere SOC-Grenze beim Laden'],
        'battery.dischargeSocLowerLimit' => ['address' => 0x402A, 'type' => 'int16', 'scale' => 1.0, 'unit' => '%', 'description' => 'Untere SOC-Grenze beim Entladen'],
        'battery.depthOfDischargeSetpoint' => ['address' => 0x402B, 'type' => 'int16', 'scale' => 1.0, 'unit' => '%', 'description' => 'Eingestellte Entladetiefe'],
        'battery.reserveSoc' => ['address' => 0x402C, 'type' => 'int16', 'scale' => 1.0, 'unit' => '%', 'description' => 'Eingestellte SOC-Reserve'],
        'meter.mode' => ['address' => 0x4030, 'type' => 'int16', 'scale' => 1.0, 'unit' => '', 'description' => 'Eingestellter Zählermodus'],
        'grid.l1.voltage' => ['address' => 0x4031, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Netzspannung Phase L1'],
        'grid.l1.current' => ['address' => 0x4032, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Netzstrom Phase L1'],
        'grid.l1.frequency' => ['address' => 0x4033, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'Hz', 'description' => 'Netzfrequenz Phase L1'],
        'grid.l1.power' => ['address' => 0x4035, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Wirkleistung Phase L1'],
        'grid.l2.voltage' => ['address' => 0x4038, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Netzspannung Phase L2'],
        'grid.l2.current' => ['address' => 0x4039, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Netzstrom Phase L2'],
        'grid.l2.frequency' => ['address' => 0x403A, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'Hz', 'description' => 'Netzfrequenz Phase L2'],
        'grid.l2.power' => ['address' => 0x403C, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Wirkleistung Phase L2'],
        'grid.l3.voltage' => ['address' => 0x403F, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Netzspannung Phase L3'],
        'grid.l3.current' => ['address' => 0x4040, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Netzstrom Phase L3'],
        'grid.l3.frequency' => ['address' => 0x4041, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'Hz', 'description' => 'Netzfrequenz Phase L3'],
        'grid.l3.power' => ['address' => 0x4043, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Wirkleistung Phase L3'],

        'battery.temperature' => ['address' => 0x406E, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Allgemeine Batterietemperatur'],
        'battery.socSummary' => ['address' => 0x406F, 'type' => 'uint16', 'scale' => 0.01, 'unit' => '%', 'description' => 'Zusammengefasster Batterie-Ladezustand'],
        'pv.string1.voltage' => ['address' => 0x4071, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'PV-String 1 Spannung'],
        'pv.string1.current' => ['address' => 0x4072, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'PV-String 1 Strom'],
        'pv.string1.power' => ['address' => 0x4073, 'type' => 'uint16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'PV-String 1 Leistung'],
        'pv.string2.voltage' => ['address' => 0x4074, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'PV-String 2 Spannung'],
        'pv.string2.current' => ['address' => 0x4075, 'type' => 'uint16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'PV-String 2 Strom'],
        'pv.string2.power' => ['address' => 0x4076, 'type' => 'uint16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'PV-String 2 Leistung'],

        'house.power' => ['address' => 0x40A0, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Gesamter aktueller Hausverbrauch'],
        'pv.power' => ['address' => 0x40A5, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Gesamte aktuelle PV-Leistung'],
        'battery.power' => ['address' => 0x40A6, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Aktuelle Lade- oder Entladeleistung der Batterie'],
        'grid.powerSummary' => ['address' => 0x40A7, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Aggregierte Netzleistung'],
        'inverter.power' => ['address' => 0x40A9, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Aktuelle Wechselrichterleistung'],
        'grid.power' => ['address' => 0x40AD, 'type' => 'int16', 'scale' => 1.0, 'unit' => 'W', 'description' => 'Saldierte Leistung am Netzübergabepunkt'],

        'energy.pv.today' => ['address' => 0x40BF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie heute'],
        'energy.pv.month' => ['address' => 0x40C1, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie im laufenden Monat'],
        'energy.pv.year' => ['address' => 0x40C3, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie im laufenden Jahr'],
        'energy.pv.total' => ['address' => 0x40C5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'PV-Energie gesamt'],
        'energy.battery.chargeToday' => ['address' => 0x40C7, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieladung heute'],
        'energy.battery.chargeTotal' => ['address' => 0x40CD, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieladung gesamt'],
        'energy.battery.dischargeToday' => ['address' => 0x40CF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieentladung heute'],
        'energy.battery.dischargeTotal' => ['address' => 0x40D5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Batterieentladung gesamt'],
        'energy.inverter.today' => ['address' => 0x40D7, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Wechselrichtererzeugung heute'],
        'energy.inverter.total' => ['address' => 0x40DD, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Wechselrichtererzeugung gesamt'],
        'energy.house.today' => ['address' => 0x40DF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Interner SAJ-Lastzähler heute; nicht als bilanziellen Hausverbrauch verwenden'],
        'energy.house.total' => ['address' => 0x40E5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Interner SAJ-Lastzähler gesamt; nicht als bilanziellen Hausverbrauch verwenden'],
        'energy.grid.sellToday' => ['address' => 0x40EF, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzeinspeisung (Export) heute'],
        'energy.grid.sellTotal' => ['address' => 0x40F5, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzeinspeisung (Export) gesamt'],
        'energy.grid.feedInToday' => ['address' => 0x40F7, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzbezug (Import) heute'],
        'energy.grid.feedInTotal' => ['address' => 0x40FD, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzbezug (Import) gesamt'],
        'energy.grid.sumFeedInToday' => ['address' => 0x4167, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzbezug heute, Summe'],
        'energy.grid.sumSellToday' => ['address' => 0x416F, 'type' => 'uint32', 'scale' => 0.01, 'unit' => 'kWh', 'description' => 'Netzeinspeisung heute, Summe'],

        'battery.soc' => ['address' => 0xA00C, 'type' => 'uint16', 'scale' => 0.01, 'unit' => '%', 'description' => 'Ladezustand Batterie 1'],
        'battery.soh' => ['address' => 0xA00D, 'type' => 'uint16', 'scale' => 0.01, 'unit' => '%', 'description' => 'Gesundheitszustand Batterie 1'],
        'battery.voltage' => ['address' => 0xA00E, 'type' => 'uint16', 'scale' => 0.1, 'unit' => 'V', 'description' => 'Spannung Batterie 1'],
        'battery.current' => ['address' => 0xA00F, 'type' => 'int16', 'scale' => 0.01, 'unit' => 'A', 'description' => 'Strom Batterie 1'],
        'battery.moduleTemperature' => ['address' => 0xA010, 'type' => 'int16', 'scale' => 0.1, 'unit' => '°C', 'description' => 'Temperatur Batterie 1'],
        'battery.cycles' => ['address' => 0xA011, 'type' => 'uint16', 'scale' => 1.0, 'unit' => '', 'description' => 'Zyklenzahl Batterie 1'],
    ],
    'blocks' => [
        [0x4010, 2],
        [0x4023, 14],
        [0x4031, 21],
        [0x406E, 15],
        [0x40A0, 14],
        [0x40BF, 32],
        [0x40DF, 32],
        [0xA00C, 6],
    ],
    'optional' => ['energy.grid.sumFeedInToday', 'energy.grid.sumSellToday'],
    // Historischer Pfad bleibt für beide Geräte als Kopie des Hauszählers erhalten.
    'copies' => ['energy.house.calculatedToday' => 'energy.house.today'],
    // Beide Geräte verwenden diese Ansichten. Native Register bleiben in kWh;
    // die bisherigen today-/lifetime-Ansichten liefern Energiewerte in Wh.
    'groups' => [
        'live' => [
            'pvPower' => ['from' => 'pv.power', 'unit' => 'W'],
            'housePower' => ['from' => 'house.power', 'transform' => 'negativeAbs', 'unit' => 'W'],
            'gridPower' => ['from' => 'grid.power', 'unit' => 'W'],
            'batteryPower' => ['from' => 'battery.power', 'unit' => 'W'],
            'heatingRodPower' => null,
            'batterySoc' => ['from' => 'battery.soc', 'unit' => '%'],
            // Bestehende IQBox-Templates lesen Details aus live.battery usw.
            'inverter' => ['from' => 'inverter', 'default' => []],
            'grid' => ['from' => 'grid', 'default' => []],
            'battery' => ['from' => 'battery', 'default' => []],
            'pv' => ['from' => 'pv', 'default' => []],
            'house' => ['from' => 'house', 'default' => []],
        ],
        'today' => [
            'work' => [
                'generation' => ['from' => ['energy.inverter.today', 'energy.pv.today'], 'factor' => 1000, 'unit' => 'Wh'],
                'consumption' => ['from' => 'energy.house.today', 'factor' => 1000, 'unit' => 'Wh'],
                'batteryFeed' => ['from' => 'energy.battery.chargeToday', 'factor' => 1000, 'unit' => 'Wh'],
                'batteryDraw' => ['from' => 'energy.battery.dischargeToday', 'factor' => 1000, 'unit' => 'Wh'],
                'gridFeed' => ['from' => ['energy.grid.sumSellToday', 'energy.grid.sellToday'], 'factor' => 1000, 'unit' => 'Wh'],
                'gridDraw' => ['from' => ['energy.grid.sumFeedInToday', 'energy.grid.feedInToday'], 'factor' => 1000, 'unit' => 'Wh'],
                'unit' => 'Wh',
            ],
            'selfSufficiency' => [
                'value' => ['calculation' => 'remainingPercent', 'inputs' => ['today.work.consumption', 'today.work.gridDraw'], 'unit' => '%'],
            ],
            'selfConsumption' => [
                'value' => ['calculation' => 'remainingPercent', 'inputs' => ['today.work.generation', 'today.work.gridFeed'], 'unit' => '%'],
            ],
            'saving' => [
                'energy' => [
                    'pvProduction' => ['from' => ['energy.inverter.today', 'energy.pv.today'], 'factor' => 1000, 'unit' => 'Wh'],
                    'gridFeed' => ['from' => ['energy.grid.sumSellToday', 'energy.grid.sellToday'], 'factor' => 1000, 'unit' => 'Wh'],
                    'ownConsumption' => ['calculation' => 'positiveDifference', 'inputs' => ['today.work.generation', 'today.work.gridFeed'], 'unit' => 'Wh'],
                ],
                '_notice' => 'Kosten, Preise, Fahrzeuge und Emissionen sind nicht per Modbus verfügbar.',
            ],
        ],
        'lifetime' => [
            'pvProduction' => ['from' => 'energy.pv.total', 'factor' => 1000, 'unit' => 'Wh'],
            'work' => [
                'generation' => ['from' => 'energy.pv.total', 'factor' => 1000, 'unit' => 'Wh'],
                'consumption' => null,
                'batteryFeed' => ['from' => 'energy.battery.chargeTotal', 'factor' => 1000, 'unit' => 'Wh'],
                'batteryDraw' => ['from' => 'energy.battery.dischargeTotal', 'factor' => 1000, 'unit' => 'Wh'],
                'gridFeed' => null,
                'gridDraw' => null,
                'unit' => 'Wh',
                'throughDate' => ['date' => 'Y-m-d'],
                'source' => 'StoragePro-Modbus-Teilzaehler',
                '_notice' => 'Netz-Gesamtwerte und Gesamtverbrauch sind per Modbus nicht verlaesslich; dafuer Tasmota verwenden.',
            ],
        ],
    ],
    // Hilfetexte für Loop/list; das IQBox-Profil übernimmt sie unverändert.
    'groupDescriptions' => [
        'live' => 'Sechs Energieflusswerte und native Detailgruppen wie bisher bei IQBox; fehlende Messwerte bleiben unbekannt',
        'today' => 'Tagesenergien in Wh, Autarkie und Eigenverbrauch',
        'today.work' => 'Tageserzeugung, Verbrauch sowie Batterie- und Netzenergien in Wh',
        'today.selfSufficiency' => 'Autarkiegrad aus Tagesverbrauch und Netzbezug in Prozent',
        'today.selfConsumption' => 'Eigenverbrauchsanteil aus Tageserzeugung und Einspeisung in Prozent',
        'today.saving' => 'Energiebilanz ohne Kosten oder Preise',
        'today.saving.energy' => 'Erzeugung, Einspeisung und Eigenverbrauch in Wh',
        'lifetime' => 'Wie lifetime.work: verfügbare Gesamtenergien in Wh und Hinweise',
        'lifetime.work' => 'Gesamtenergien in Wh; unbekannte oder unzuverlässige Zähler bleiben null',
    ],
    'aliases' => [
        'batterySoc' => 'battery.soc',
        'batteryTemperature' => 'battery.temperature',
        'batteryPower' => 'battery.power',
        'pvPower' => 'pv.power',
        'inverterPower' => 'inverter.power',
        'housePower' => 'house.power',
        'houseConsumptionToday' => 'energy.house.calculatedToday',
        'gridPower' => 'grid.power',
        'batteryTotalChargeEnergy' => 'energy.battery.chargeTotal',
        'batteryTotalDischargeEnergy' => 'energy.battery.dischargeTotal',
        'pvTotalEnergy' => 'energy.pv.total',
        'gridSellTotal' => 'energy.grid.sellTotal',
        'gridFeedInTotal' => 'energy.grid.feedInTotal',
    ],
];

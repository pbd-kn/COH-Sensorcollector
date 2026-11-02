<?php

declare(strict_types=1);

// Reiner Informationskatalog. KEINE Schreibfreigaben und KEINE Modbus-Zugriffe.
// Die ausführbare Allowlist bleibt separat in saj-h2.php unter controls.
// Quelle: execScripts/WechelrichterRegister.txt sowie number.py/charge_control.py
// in github.com/stanus74/home-assistant-saj-h2-modbus (geprüft 01.11.2026).
$catalog = [];
foreach ([
    'onGridSoc' => ['0x3644', 'Entladegrenze im Netzbetrieb'],
    'offGridSoc' => ['0x3645', 'Entladegrenze im Inselbetrieb'],
    'chargeUpperSoc' => ['0x3646', 'Batterie-Ladeobergrenze'],
] as $key => [$register, $label]) {
    $catalog['planned.' . $key] = ['label' => $label, 'group' => 'Batteriegrenzen', 'register' => $register,
        'range' => '0–100 %; Abstände zwischen Ladeobergrenze und Entladegrenzen müssen zusätzlich geprüft werden.'];
}
foreach ([
    'passiveGridChargePower' => ['0x3637', 'Passiv: Netz-Ladeleistung'],
    'passiveGridDischargePower' => ['0x3638', 'Passiv: Netz-Entladeleistung'],
    'passiveBatteryChargePower' => ['0x3639', 'Passiv: Batterie-Ladeleistung'],
    'passiveBatteryDischargePower' => ['0x363A', 'Passiv: Batterie-Entladeleistung'],
    'batteryChargePowerLimit' => ['0x364D', 'Maximale Batterie-Ladeleistung'],
    'batteryDischargePowerLimit' => ['0x364E', 'Maximale Batterie-Entladeleistung'],
    'gridChargePowerLimit' => ['0x364F', 'Maximale Netz-Ladeleistung'],
    'gridDischargePowerLimit' => ['0x3650', 'Maximale Netz-Einspeiseleistung'],
    'exportLimit' => ['0x365A', 'Einspeisebegrenzung'],
] as $key => [$register, $label]) {
    $catalog['planned.' . $key] = ['label' => $label, 'group' => 'Leistungsvorgaben', 'register' => $register,
        'range' => 'Referenzintegration: Registerbereich 0–1100. Relativer Nennleistungsanteil, kein Wattwert; Skalierung und Gerätegrenzen vor Schreibfreigabe prüfen.'];
}
foreach ($catalog as &$definition) {
    $definition['writable'] = false;
    $definition['options'] = [];
    $definition['notice'] = 'Dokumentiert als schreibbar, im System noch nicht zum Schreiben freigegeben.';
}
unset($definition);
return $catalog;

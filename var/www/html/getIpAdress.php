<?php

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------
// Erkennen: Browser oder CLI (cron)
// ---------------------------------------------------

$isCli = (php_sapi_name() === 'cli');

$runMode = $isCli ? 'cron/cli' : 'browser';

// ---------------------------------------------------
// Geräte-Mapping
// ---------------------------------------------------

$deviceMap = [
    "b8-d8-12-a1-e0-4f" => "IQBox",
    "d4-8a-fc-15-ff-98" => "Tasmota",
    "98-6d-35-c1-23-52" => "myPV",
    "d8-3a-dd-66-0c-92" => "Raspberry",
    "60-e8-5b-6e-b0-44" => "SYR Leckage"
];

// ---------------------------------------------------
// ARP lesen
// ---------------------------------------------------

$arp = shell_exec("/usr/sbin/arp -a");
$devices = [];

foreach (explode("\n", $arp) as $line)
{
    $line = trim($line);
    if (!$line) continue;

    if (preg_match('/\((\d+\.\d+\.\d+\.\d+)\) at ([0-9a-f:]{17})/i', $line, $m))
    {
        $ip  = $m[1];
        $mac = strtolower(str_replace(":", "-", $m[2]));
    }
    else continue;

    // Broadcast raus
    if ($ip === "192.168.178.255") continue;

    // Multicast raus
    if (
        $ip === "255.255.255.255" ||
        str_starts_with($ip, "224.") ||
        str_starts_with($ip, "239.")
    ) continue;

    $hostname = @gethostbyaddr($ip);
    if ($hostname == $ip) $hostname = null;

    $type = $deviceMap[$mac] ?? "unknown";

    $devices[] = [
        "ip"       => $ip,
        "mac"      => $mac,
        "hostname" => $hostname,
        "type"     => $type
    ];
}

// ---------------------------------------------------
// Datei
// ---------------------------------------------------

$file = "/tmp/lan_devices.json";

// ---------------------------------------------------
// Ergebnis
// ---------------------------------------------------

$result = [
    "timestamp"   => time(),
    "datetime"    => date("Y-m-d H:i:s"),
    "runMode"     => $runMode,
    "deviceCount" => count($devices),
    "savedFile"   => $file,
    "devices"     => $devices
];

// ---------------------------------------------------
// Speichern
// ---------------------------------------------------

file_put_contents(
    $file,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

// ---------------------------------------------------
// Ausgabe
// ---------------------------------------------------

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
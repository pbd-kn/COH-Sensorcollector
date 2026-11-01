<?php



// ---------------------------------------------------
// ARP lesen return array
// ---------------------------------------------------
function getIpAdressRaspi(): array
{
    // ---------------------------------------------------
    // Geräte-Mapping
    // ---------------------------------------------------

    $deviceMap = [
        "b8-d8-12-a1-e0-4f" => "IQBox",
        "d4-8a-fc-15-ff-98" => "Tasmota",
        "98-6d-35-c2-54-61" => "myPV",
        "d8-3a-dd-66-0c-92" => "Raspberry",
        "60-e8-5b-6e-b0-44" => "SYR Leckage (WLAN)",
        "c4-98-94-e1-2e-b9" => "SYR Leckage (LAN)",
        "30-e3-a4-a9-16-31" => "Laptop-DellXPS (WLAN)",
        "c0-47-0e-7a-20-30" => "Laptop-DellXPS (LAN)",
        "e0-28-6d-ba-67-13" => "Fritzbox Gateway",
        "00-04-00-61-61-f1" => "E352 Drucker",
        "c4-de-e2-ea-cb-e3" => "Wechselrichter",
        "38-f4-06-10-68-cf" => "Buderus Tcp Adapter",        
    ];
    
    $arp = shell_exec("/usr/sbin/arp -a");
    $devices = [];
    foreach (explode("\n", (string) $arp) as $line)
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
        $devices[$mac] = [
            "ip"       => $ip,
            "mac"      => $mac,
            "hostname" => $hostname,
            "type"     => $type
        ];
    }
    // ---------------------------------------------------
    // Ausgabe
    // ---------------------------------------------------

    return $devices;
}

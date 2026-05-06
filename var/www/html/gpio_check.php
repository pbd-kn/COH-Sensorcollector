<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ----------------------------------------------------
// GPIO einstellen
// ----------------------------------------------------
$gpio = 17;   // GPIO17 = physischer Pin 11

// ----------------------------------------------------
// GPIO als Eingang setzen
// ----------------------------------------------------
@shell_exec("sudo pinctrl set {$gpio} ip");

// ----------------------------------------------------
// GPIO Status lesen
// ----------------------------------------------------
$result = trim(shell_exec("sudo pinctrl get {$gpio} 2>&1") ?? '');

$stateText = 'Unbekannt';
$stateClass = 'unknown';

if (preg_match('/\bhi\b/i', $result)) {
    $stateText = 'HIGH / 1 / Spannung liegt an';
    $stateClass = 'high';
} elseif (preg_match('/\blo\b/i', $result)) {
    $stateText = 'LOW / 0 / keine Spannung';
    $stateClass = 'low';
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="2">
<title>GPIO Pin prüfen</title>

<style>
body {
    font-family: Arial, sans-serif;
    background: #f4f4f4;
    padding: 30px;
}

.box {
    max-width: 600px;
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

.status {
    font-size: 28px;
    font-weight: bold;
    padding: 20px;
    border-radius: 10px;
    margin-top: 20px;
}

.high {
    background: #d4edda;
    color: #155724;
}

.low {
    background: #f8d7da;
    color: #721c24;
}

.unknown {
    background: #fff3cd;
    color: #856404;
}

pre {
    background: #222;
    color: #0f0;
    padding: 12px;
    border-radius: 8px;
    overflow-x: auto;
}
</style>
</head>

<body>

<div class="box">
    <h1>GPIO Pin prüfen</h1>

    <p><strong>GPIO:</strong> <?= htmlspecialchars((string)$gpio) ?></p>
    <p><strong>Raspberry Pi Pin:</strong> Pin 11</p>

    <div class="status <?= htmlspecialchars($stateClass) ?>">
        <?= htmlspecialchars($stateText) ?>
    </div>

    <h3>Rohwert von pinctrl:</h3>
    <pre><?= htmlspecialchars($result) ?></pre>

    <p>Diese Seite aktualisiert sich automatisch alle 2 Sekunden.</p>
</div>

</body>
</html>
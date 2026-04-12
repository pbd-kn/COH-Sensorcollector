<?php
$files = glob("*.php");

$files = array_filter($files, function($f) {
    return $f !== "index.php";
});

sort($files);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Raspberry Navigation</title>

<style>
body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: #f5f7fa;
    color: #1e293b;
}

/* ---------------------------------------------------
   HEADER
--------------------------------------------------- */
.header {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    padding: 15px 30px;
    font-size: 20px;
    font-weight: 600;
}

/* ---------------------------------------------------
   NAVBAR
--------------------------------------------------- */
.navbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    padding: 10px 30px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
}

.navbar a {
    text-decoration: none;
    color: #334155;
    padding: 6px 12px;
    border-radius: 6px;
    background: #f1f5f9;
    font-size: 14px;
    transition: 0.2s;
}

.navbar a:hover {
    background: #3b82f6;
    color: #ffffff;
}

/* ---------------------------------------------------
   CONTENT
--------------------------------------------------- */
.container {
    padding: 30px;
}

.subtitle {
    color: #64748b;
    margin-top: 5px;
}

.footer {
    margin-top: 40px;
    font-size: 12px;
    color: #94a3b8;
}
</style>

</head>
<body>

<div class="header">
    ⚙️ Raspberry Pi Dashboard
</div>

<div class="navbar">
    <?php foreach ($files as $file): ?>
        <?php
        $title = str_replace(".php", "", $file);
        $title = str_replace("_", " ", $title);
        $title = ucfirst($title);
        ?>
        <a href="<?= htmlspecialchars($file) ?>">
            <?= htmlspecialchars($title) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="container">

    <div class="subtitle">
        Wähle oben ein Script aus der Navigation
    </div>

    <div class="footer">
        <?= date("d.m.Y H:i") ?>
    </div>

</div>

</body>
</html>
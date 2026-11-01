<?php

declare(strict_types=1);

// Register, Ansichten (groups) und Aliase kommen aus dem gemeinsamen SAJ-Profil.
// Nur der Gerätename unterscheidet sich.
$profile = require __DIR__ . '/saj-h2.php';
$profile['device'] = 'AMPERE.StoragePro';
return $profile;

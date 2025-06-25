<?php
// /var/www/html/oh-transformSolar.php
//
// Get the input data from a query parameter or POST body
/*
 * liefert die jsondaten des Heizungsservers
 * 
 */
// Globaole Daten
$paramsFile = "/etc/openhab/scripts/task_solar_params.json";   // dateiname der Parameter

if (file_exists($paramsFile)) {
        $params = file_get_contents($paramsFile);
        echo $params;
} else {
   echo "$paramsFile existiert nicht\n ";
}
?>



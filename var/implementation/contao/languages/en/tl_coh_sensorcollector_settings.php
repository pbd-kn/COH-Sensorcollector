<?php

$GLOBALS['TL_LANG']['MOD']['sensorcollector_settings'] = ['Sensorcollector settings', 'Manage IQBox and heating rod credentials'];

$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['general_legend'] = 'General';
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['ampere_legend'] = 'Ampere.IQ cloud';
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstab_legend'] = 'Heating rod access';
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['tasmota_legend'] = 'Tasmota access';
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstab_local_legend'] = 'Local heating rod';
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstab_cloud_legend'] = 'my-PV cloud';
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberry_api_legend'] = 'Raspberry status API';
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['title'] = ['Label', 'Label for this settings record'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['ampereUsername'] = ['Ampere.IQ username', 'Email address used to sign in'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['amperePassword'] = ['Ampere.IQ password', 'Password used to sign in'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['ampereRetries'] = ['Maximum attempts', 'Number of cloud request attempts'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['ampereRetryDelay'] = ['Retry delay', 'Delay between attempts in seconds'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['lifetimeCacheSeconds'] = ['Lifetime cache duration', 'Lifetime value validity in seconds'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabAccess'] = ['Access mode', 'Selects exactly one access method for the heating rod.'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabAccessOptions'] = ['disabled' => 'Disabled', 'local' => 'Local', 'cloud' => 'my-PV cloud'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabAuthEnabled'] = ['Use authentication', 'Sign in to the local heating rod and use the session cookie'];

$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabLocalEnabled'] = ['Enable local heating rod', 'Use local access to the heating rod'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabUrl'] = ['Local heating rod URL', 'For example https://192.168.178.68'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabLoginPath'] = ['Login path', 'Default: /auth.jsn'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabPassword'] = ['Heating rod password', 'Password for local access'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabPasswordField'] = ['Password field', 'Default: pw'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabCookieFile'] = ['Cookie file', 'Cookie file inside the installed ContaoHab bundle'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabInsecureTls'] = ['Allow insecure TLS', 'Only for local devices with a self-signed certificate'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['tasmotaAccess'] = ['Access mode', 'Read Tasmota directly on the local network or through the Raspberry API.'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['tasmotaAccessOptions'] = ['disabled' => 'Disabled', 'local' => 'Direct local access', 'raspberry' => 'Through Raspberry API'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['tasmotaRaspberryBaseUrl'] = ['Raspberry base URL', 'URL reachable from the hosting server, for example the MyFRITZ address.'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['tasmotaRaspberryToken'] = ['Raspberry API token', 'Sent as X-COH-TOKEN.'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['tasmotaRaspberryPath'] = ['Tasmota API path', 'Default: /api/coh/tasmota.php'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['tasmotaRequestTimeout'] = ['HTTP timeout', 'Maximum request duration in seconds.'];

$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabCloudEnabled'] = ['Enable my-PV cloud', 'Use cloud access instead of the local URL'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabCloudBaseUrl'] = ['my-PV cloud URL', 'API base URL'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabCloudSerial'] = ['Serial number', 'my-PV device serial number'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['heizstabCloudApiToken'] = ['API token', 'my-PV cloud bearer token'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryAccess'] = ['Access mode', 'Read Raspberry values through the local LAN or the external HTTP address.'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryAccessOptions'] = ['disabled' => 'Disabled', 'local' => 'Local (LAN)', 'http' => 'External HTTP'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryApiWanBaseUrl'] = ['External Raspberry base URL', 'URL reachable from the hosting server, for example the MyFRITZ address.'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryApiPath'] = ['Raspberry API path', 'Default: /api/coh/raspberry-status.php'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryApiEnabled'] = ['Enable Raspberry API', 'Read status values through the Raspberry HTTP API'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryApiBaseUrl'] = ['Raspberry base URL', 'For example http://192.168.178.49'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryApiToken'] = ['Raspberry API token', 'Sent to the Raspberry as X-COH-TOKEN'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryApiTimeout'] = ['HTTP timeout', 'Maximum request duration in seconds'];
$GLOBALS['TL_LANG']['tl_coh_sensorcollector_settings']['raspberryApiCacheSeconds'] = ['Raspberry cache duration', 'Reuse an already loaded status response for this many seconds'];

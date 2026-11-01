<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/api_env.php';
$token = cohRequireApiToken();
if (!hash_equals($token, (string) ($_SERVER['HTTP_X_COH_TOKEN'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$client = null;
$lock = null;
try {
    $root = '/home/peter/scripts/coh/sensorcollect/Modbus';
    require_once $root . '/bootstrap.php';
    require_once $root . '/ModbusControl.php';
    $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $profileName = $input['profile'] ?? '';
    if (!in_array($profileName, ['saj-h2', 'ampere-storagepro'], true)) {
        throw new InvalidArgumentException('Unzulässiges Geräteprofil.');
    }
    $profile = \PbdKn\cohSensorcollector\Modbus\ModbusProfile::load($profileName);
    $action = $input['action'] ?? '';
    if ($action === 'catalog') {
        $catalog = [];
        foreach ($profile['controls'] ?? [] as $setting => $definition) {
            $catalog[$setting] = ['label' => $definition['label'], 'options' => $definition['options'],
                'writable' => true, 'group' => 'Betrieb',
                'notice' => $definition['requires']['message'] ?? ''];
        }
        // Informationsfelder werden niemals in die ausführbare Allowlist übernommen.
        foreach ($profile['controlCatalog'] ?? [] as $setting => $definition) {
            if (!isset($catalog[$setting])) $catalog[$setting] = array_replace($definition, ['writable' => false, 'options' => []]);
        }
        echo json_encode(['ok' => true, 'catalog' => $catalog], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    $host = strtolower(trim((string) ($input['host'] ?? '')));
    if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/D', $host)) {
        throw new InvalidArgumentException('Ungültiger Gerätehost.');
    }
    $ip = gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)
        || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        throw new InvalidArgumentException('Nur lokale Geräteadressen sind erlaubt.');
    }
    if (!in_array($action, ['preview', 'execute'], true)) {
        throw new InvalidArgumentException('Unbekannte Aktion.');
    }
    $setting = (string) ($input['setting'] ?? '');
    $value = $input['value'] ?? null;
    if (!is_int($value) || !isset($profile['controls'][$setting]['options'][$value])) {
        throw new InvalidArgumentException('Ungültige Einstellung oder ungültiger Auswahlwert.');
    }
    $lock = fopen(sys_get_temp_dir() . '/coh-modbus-control-' . hash('sha256', $ip) . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Ein anderer Schreibdialog greift gerade auf das Gerät zu.');
    }
    $client = new \PbdKn\cohSensorcollector\Modbus\ModbusTcpClient($ip);
    $control = new \PbdKn\cohSensorcollector\Modbus\ModbusControl($client, $profile);
    if ($action === 'preview') {
        $result = $control->read($setting);
    } else {
        if (($input['confirmed'] ?? false) !== true || !is_int($input['expected'] ?? null)) {
            throw new InvalidArgumentException('Bestätigung oder Ausgangswert fehlt.');
        }
        $result = $control->execute($setting, $value, $input['expected']);
        error_log(sprintf('COH Modbus write: %s %s %s = %d verified', $ip, $profileName, $setting, $value));
    }
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} finally {
    if ($client !== null) $client->close();
    if (is_resource($lock)) fclose($lock);
}

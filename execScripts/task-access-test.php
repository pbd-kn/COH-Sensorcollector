<?php

declare(strict_types=1);

require_once __DIR__ . '/TaskAccess.php';

$mode = strtolower(trim((string)($argv[1] ?? 'all')));
$paramsFile = (string)($argv[2] ?? (__DIR__ . '/task_heizstab_params.json'));

try {
    $parameters = TaskAccess::loadParameters($paramsFile);

    if (in_array($mode, ['all', 'ampere', 'iq'], true)) {
        $client = TaskAccess::ampereIq($parameters, __DIR__);
        $values = $client->get('/api/v1/installation/{installationId}/now/all/power');
        printResult('Ampere.IQ-Cloud HTTP', $values);
    }

    if (in_array($mode, ['all', 'cloud', 'mypv'], true)) {
        $client = TaskAccess::heizstabCloud($parameters);
        $values = array_merge($client->setup(), $client->data());
        printResult('Heizstab my-PV-Cloud', selectHeizstabValues($values));
    }

    if (in_array($mode, ['all', 'local'], true)) {
        if (PHP_OS_FAMILY === 'Windows' && isset($parameters['heizstabAuth'])) {
            $parameters['heizstabAuth']['cookieDir'] = sys_get_temp_dir() . '/coh-task-access-test';
            unset($parameters['heizstabAuth']['cookieFile']);
        }
        $client = TaskAccess::heizstabLocal($parameters, __DIR__);
        $values = array_merge($client->setup(), $client->data());
        printResult('Heizstab lokal HTTPS', selectHeizstabValues($values));
    }

    if (!in_array($mode, ['all', 'ampere', 'iq', 'cloud', 'mypv', 'local'], true)) {
        throw new InvalidArgumentException('Erlaubt sind: all, ampere, cloud oder local.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

function selectHeizstabValues(array $values): array
{
    return [
        'temperature' => normalizeHeizstabTemperature($values['temp1'] ?? null),
        'targetTemperature' => normalizeHeizstabTemperature($values['ww1target'] ?? ($values['ww1boost'] ?? null)),
        'power' => $values['power_elwa2'] ?? ($values['power'] ?? null),
        'boostActive' => $values['boostactive'] ?? ($values['bststrt'] ?? null),
    ];
}

function normalizeHeizstabTemperature(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    $temperature = (float)$value;
    return $temperature > 100 ? $temperature / 10 : $temperature;
}

function printResult(string $title, array $values): void
{
    echo PHP_EOL . $title . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    echo json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . PHP_EOL;
}


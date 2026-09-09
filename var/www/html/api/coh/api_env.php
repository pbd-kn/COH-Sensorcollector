<?php

declare(strict_types=1);

/**
 * Reads an API setting from the process environment or an untracked
 * .env.local file. No secret value belongs in this repository.
 */
function cohApiEnv(string $name): string
{
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    foreach (['/home/peter/scripts/coh/.env.local', '/var/www/.env.local'] as $file) {
        if (!is_readable($file)) {
            continue;
        }

        $values = parse_ini_file($file, false, INI_SCANNER_RAW);
        $value = is_array($values) ? ($values[$name] ?? '') : '';
        if (is_string($value) && trim($value) !== '') {
            return trim($value, " \t\n\r\0\x0B\"'");
        }
    }

    return '';
}

function cohRequireApiToken(): string
{
    $token = cohApiEnv('COH_API_TOKEN');
    if ($token !== '') {
        return $token;
    }

    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'COH_API_TOKEN is not configured']);
    exit;
}

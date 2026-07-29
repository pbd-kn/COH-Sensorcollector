<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector;

final class BundleSettings
{
    private ?array $row = null;

    public function __construct(private mysql_dialog $db)
    {
    }

    public function ampereIq(): array
    {
        $row = $this->databaseRow();
        if ($row !== null) {
            $tokens = json_decode((string)($row['ampereTokens'] ?? ''), true);
            return [
                'username' => (string)($row['ampereUsername'] ?? ''),
                'password' => (string)($row['amperePassword'] ?? ''),
                'tokens' => is_array($tokens) ? $tokens : [],
                'retries' => max(1, (int)($row['ampereRetries'] ?? 3)),
                'retryDelay' => max(0, (int)($row['ampereRetryDelay'] ?? 10)),
                'lifetimeCacheSeconds' => max(0, (int)($row['lifetimeCacheSeconds'] ?? 60)),
            ];
        }
        throw new \RuntimeException(
            'Ampere.IQ-Einstellungen fehlen. Bitte im Contao-Backend unter '
            . 'COH > COH-Sensorcollector Einstellungen einen Datensatz anlegen.'
        );
    }

    public function heatingRod(): array
    {
        $row = $this->databaseRow();
        if ($row !== null) {
            return [
                'urlheizStab' => (string)($row['heizstabUrl'] ?? ''),
                'heizstabAuth' => [
                    'enabled' => !empty($row['heizstabLocalEnabled']),
                    'loginPath' => (string)($row['heizstabLoginPath'] ?? '/auth.jsn'),
                    'password' => (string)($row['heizstabPassword'] ?? ''),
                    'passwordField' => (string)($row['heizstabPasswordField'] ?? 'pw'),
                    'cookieFile' => (string)($row['heizstabCookieFile'] ?? 'vendor/pbd-kn/contao-contaohab-bundle/contao/config/heizstabCookieFile.txt'),
                    'insecureTls' => !empty($row['heizstabInsecureTls']),
                ],
                'heizstabApi' => [
                    'enabled' => !empty($row['heizstabCloudEnabled']),
                    'baseUrl' => (string)($row['heizstabCloudBaseUrl'] ?? 'https://api.my-pv.com/api/v1'),
                    'serial' => (string)($row['heizstabCloudSerial'] ?? ''),
                    'apiToken' => (string)($row['heizstabCloudApiToken'] ?? ''),
                ],
            ];
        }
        throw new \RuntimeException(
            'Heizstab-Einstellungen fehlen. Bitte im Contao-Backend unter '
            . 'COH > COH-Sensorcollector Einstellungen einen Datensatz anlegen.'
        );
    }

    public function saveAmpereTokens(array $tokens): void
    {
        $row = $this->databaseRow();
        if ($row === null || empty($row['id'])) {
            return;
        }
        $json = json_encode($tokens, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Ampere.IQ-Tokens konnten nicht als JSON gespeichert werden.');
        }
        $id = (int)$row['id'];
        $tstamp = time();
        $stmt = $this->db->prepare(
            'UPDATE tl_coh_sensorcollector_settings SET ampereTokens = ?, tstamp = ? WHERE id = ?'
        );
        if (!$stmt) {
            throw new \RuntimeException('Token-Update konnte nicht vorbereitet werden.');
        }
        $stmt->bind_param('sii', $json, $tstamp, $id);
        $stmt->execute();
        $stmt->close();
        $this->row['ampereTokens'] = $json;
        $this->row['tstamp'] = $tstamp;
    }

    private function databaseRow(): ?array
    {
        if ($this->row !== null) {
            return $this->row ?: null;
        }
        $result = $this->db->query(
            'SELECT * FROM tl_coh_sensorcollector_settings ORDER BY id ASC LIMIT 1'
        );
        if (!$result) {
            $this->row = [];
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        $this->row = is_array($row) ? $row : [];
        return $this->row ?: null;
    }

}

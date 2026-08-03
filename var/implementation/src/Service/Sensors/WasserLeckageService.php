<?php

declare(strict_types=1);

namespace PbdKn\ContaoContaohabBundle\Service\Sensors;

use PbdKn\ContaoContaohabBundle\Model\SensorModel;
use PbdKn\ContaoContaohabBundle\Service\LoggerService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WasserLeckageService implements SensorFetcherInterface
{
    private array $deviceData = [];

    public function __construct(private readonly HttpClientInterface $httpClient, private readonly LoggerService $logger)
    {
    }

    public function supports(SensorModel $sensor): bool
    {
        if (strtolower((string) $sensor->sensorSource) !== 'wasserleckage') {
            return false;
        }
        $time = date('H:i');
        return $time < '00:00' || $time > '04:30';
    }

    public function fetch(SensorModel $sensor): ?array
    {
        return $this->fetchArr([$sensor])[(string) $sensor->sensorID] ?? null;
    }

    public function fetchArr(array $sensors, ?string $date = null, array $fetchedValues = []): ?array
    {
        if ($sensors === []) {
            return [];
        }
        $url = trim((string) $sensors[0]->geraeteUrl);
        if ($url === '') {
            $this->logger->Error('WasserLeckage: Geräte-URL fehlt.');
            return null;
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'http://' . $url;
        }

        try {
            $this->deviceData = [];
            foreach (['all', 'alm', 'alw', 'aln'] as $command) {
                $data = $this->httpClient->request('GET', rtrim($url, '/') . ':5333/trio/get/' . $command, ['timeout' => 10])->toArray();
                $this->deviceData = array_merge($this->deviceData, $data);
            }
        } catch (\Throwable $error) {
            $this->logger->Error('WasserLeckage: Lesen fehlgeschlagen: ' . $error->getMessage());
            return null;
        }

        $result = [];
        foreach ($sensors as $sensor) {
            $localId = strtoupper(trim((string) $sensor->sensorLokalId));
            if ($localId === '') {
                continue;
            }
            [$value, $unit, $type] = $this->convert($localId, $this->deviceData['get' . $localId] ?? 0);
            $result[(string) $sensor->sensorID] = [
                'sensorID' => (string) $sensor->sensorID,
                'sensorValue' => $value,
                'sensorEinheit' => $unit ?: (string) $sensor->sensorEinheit,
                'sensorValueType' => $type ?: (string) $sensor->sensorValueType,
                'sensorSource' => (string) $sensor->sensorSource,
                'outputMode' => (string) $sensor->outputMode,
            ];
        }
        return $result;
    }

    private function convert(string $localId, mixed $value): array
    {
        return match ($localId) {
            'BAT' => [(float) $value / 100, 'V', 'float'],
            'CEL' => [(float) $value / 10, '°C', 'float'],
            'BAR' => [(float) $value / 1000, 'Bar', 'float'],
            'VOL' => [(float) $value, 'l', 'float'],
            'VLV' => [(int) $value, '', 'int'],
            default => [$value, '', ''],
        };
    }
}

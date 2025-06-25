<?php
namespace PbdKn\cohSensorcollector;

use PbdKn\cohSensorcollector\Sensor\SensorFetcherInterface;

class FetcherRegistry
{
    private array $fetchers = [];

    public function registerFetcher(string $tag, SensorFetcherInterface $fetcher): void
    {
        $this->fetchers[$tag][] = $fetcher;
    }

    public function getFetchersByTag(string $tag): array
    {
        return $this->fetchers[$tag] ?? [];
    }
}
?>
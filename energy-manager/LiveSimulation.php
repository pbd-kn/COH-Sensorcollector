<?php

declare(strict_types=1);

namespace CohEnergy;

final class LiveSimulation
{
    /** Two read requests only; always disconnect before returning or throwing. */
    public static function read(object $client): array
    {
        try {
            $registers = $client->readHoldingRegisters(0x40A0, 14);
            $soc = $client->readValue('battery.soc');
            return ['data' => [
                'house' => ['power' => \AmpereStorageProModbus::decode($registers, 0, 'int16')],
                'pv' => ['power' => \AmpereStorageProModbus::decode($registers, 5, 'int16')],
                'battery' => ['power' => \AmpereStorageProModbus::decode($registers, 6, 'int16'), 'soc' => $soc],
                'grid' => ['power' => \AmpereStorageProModbus::decode($registers, 13, 'int16')],
            ]];
        } finally {
            $client->close();
        }
    }
}

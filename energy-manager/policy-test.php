<?php

declare(strict_types=1);

require_once __DIR__ . '/StorageProWriter.php';

use CohEnergy\StorageProWriter;

$rule = ['minRaw' => '0x0000', 'maxRaw' => '0X0064', 'functions' => [6], 'evidence' => 'Offline test'];
$normalized = StorageProWriter::normalizePolicy(['enabled' => false, 'registers' => ['0x1234' => $rule]]);
if ($normalized['registers'][4660]['minRaw'] !== 0 || $normalized['registers'][4660]['maxRaw'] !== 100) {
    throw new RuntimeException('Hex normalization failed');
}
$decimal = $rule;
$decimal['minRaw'] = 0;
$decimal['maxRaw'] = 100;
if (StorageProWriter::normalizePolicy(['enabled' => false, 'registers' => [4660 => $decimal]]) !== $normalized) {
    throw new RuntimeException('Decimal compatibility failed');
}
foreach ([['0x1234' => $rule, 4660 => $rule], ['0x10000' => $rule], ['0xZZZZ' => $rule],
    ['0x1234' => array_replace($rule, ['maxRaw' => -1])], ['0x1234' => array_replace($rule, ['minRaw' => 101])]] as $registers) {
    try {
        StorageProWriter::normalizePolicy(['enabled' => false, 'registers' => $registers]);
        throw new RuntimeException('Invalid policy accepted');
    } catch (InvalidArgumentException) {
    }
}
echo "OK: Hex-/Dezimalfreigaben, Grenzen und doppelte Adressen; kein Netzwerkzugriff.\n";

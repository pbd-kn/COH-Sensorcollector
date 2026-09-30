<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor\Km271;

use InvalidArgumentException;

/**
 * Validiert verstaendliche KM271-Schreibauftraege und erzeugt den 8-Byte-Nutzdatenblock.
 * Diese Klasse uebertraegt selbst keine Daten an die Heizung.
 */
final class Km271WriteCommandEncoder
{
    private const FILL = 0x65;

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        return [
            'HK2_Heizprogramm' => $this->choice('HK2 Heizprogramm', ['Eigen', 'Familie', 'Früh', 'Spät', 'Vormittag', 'Nachmittag', 'Mittag', 'Single', 'Senior']),
            'HK1_Betriebsart' => $this->choice('HK1 Betriebsart', ['Nacht', 'Tag', 'Automatik']),
            'HK1_Tagtemperatur' => $this->number('HK1 Tagtemperatur', '°C', 10, 30, 0.5),
            'HK1_Nachttemperatur' => $this->number('HK1 Nachttemperatur', '°C', 10, 30, 0.5),
            'HK1_Urlaubstemperatur' => $this->number('HK1 Urlaubstemperatur', '°C', 10, 30, 0.5),
            'HK1_Sommergrenze' => [
                'Bezeichnung' => 'HK1 Sommergrenze',
                'Typ' => 'TemperaturOderBetriebsart',
                'Einheit' => '°C',
                'Minimum' => 10,
                'Maximum' => 30,
                'Schrittweite' => 1,
                'Sonderwerte' => ['Sommer', 'Winter'],
            ],
            'HK1_Frostschutz_ab' => $this->number('HK1 Frostschutz ab', '°C', -20, 10, 1),
            'HK1_Auslegungstemperatur' => $this->number('HK1 Auslegungstemperatur', '°C', 30, 90, 1),
            'HK1_Aufschalttemperatur' => $this->number('HK1 Aufschalttemperatur', '°C', 0, 10, 1),
            'HK1_Aussenhalt_ab' => $this->number('HK1 Außenhalt ab', '°C', -20, 10, 1),
            'HK1_Absenkungsart' => $this->choice('HK1 Absenkungsart', ['Abschalt', 'Reduziert', 'Raumhalt', 'Aussenhalt']),
            'HK1_Heizprogramm' => $this->choice('HK1 Heizprogramm', ['Eigen', 'Familie', 'Früh', 'Spät', 'Vormittag', 'Nachmittag', 'Mittag', 'Single', 'Senior']),
            'HK1_Ferientage' => $this->number('HK1 Ferientage', 'Tage', 0, 99, 1),
            'Warmwasser_Betriebsart' => $this->choice('Warmwasser Betriebsart', ['Nacht', 'Tag', 'Automatik']),
            'Warmwasser_eingestellte_Temperatur' => $this->number('Warmwasser eingestellte Temperatur', '°C', 30, 60, 1),
            'Warmwasser_Zirkulation_Einstellung' => $this->choice('Warmwasser Zirkulation Einstellung', ['Aus', '1 ×/h', '2 ×/h', '3 ×/h', '4 ×/h', '5 ×/h', '6 ×/h', 'An']),
        ];
    }

    /**
     * @return array{LokaleId:string,Wert:mixed,Einheit:?string,Nutzdaten:array<int,int>,Hex:string,Sendebereit:bool}
     */
    public function encode(string $localId, mixed $value): array
    {
        $localId = trim($localId);
        if (!isset($this->catalog()[$localId])) {
            throw new InvalidArgumentException("KM271-Schreibauftrag '$localId' ist nicht erlaubt.");
        }

        [$normalized, $unit, $bytes] = match ($localId) {
            'HK2_Heizprogramm' => $this->enumPayload($value, ['Eigen', 'Familie', 'Früh', 'Spät', 'Vormittag', 'Nachmittag', 'Mittag', 'Single', 'Senior'], [0x12, 0x00, null, 0x65, 0x65, 0x65, 0x65, 0x65], $localId, null),
            'HK1_Betriebsart' => $this->enumPayload($value, ['Nacht', 'Tag', 'Automatik'], [0x07, 0x00, 0x65, 0x65, 0x65, 0x65, null, 0x65], $localId),
            'HK1_Tagtemperatur' => $this->halfDegreePayload($value, 10, 30, [0x07, 0x00, 0x65, 0x65, 0x65, null, 0x65, 0x65], $localId),
            'HK1_Nachttemperatur' => $this->halfDegreePayload($value, 10, 30, [0x07, 0x00, 0x65, 0x65, null, 0x65, 0x65, 0x65], $localId),
            'HK1_Urlaubstemperatur' => $this->halfDegreePayload($value, 10, 30, [0x07, 0x00, 0x65, 0x65, 0x65, 0x65, 0x65, null], $localId),
            'HK1_Sommergrenze' => $this->summerPayload($value),
            'HK1_Frostschutz_ab' => $this->integerPayload($value, -20, 10, [0x07, 0x31, 0x65, 0x65, 0x65, 0x65, 0x65, null], $localId, true),
            'HK1_Auslegungstemperatur' => $this->integerPayload($value, 30, 90, [0x07, 0x0E, 0x65, 0x65, 0x65, 0x65, null, 0x65], $localId),
            'HK1_Aufschalttemperatur' => $this->integerPayload($value, 0, 10, [0x07, 0x15, null, 0x65, 0x65, 0x65, 0x65, 0x65], $localId),
            'HK1_Aussenhalt_ab' => $this->integerPayload($value, -20, 10, [0x07, 0x15, 0x65, 0x65, null, 0x65, 0x65, 0x65], $localId, true),
            'HK1_Absenkungsart' => $this->enumPayload($value, ['Abschalt', 'Reduziert', 'Raumhalt', 'Aussenhalt'], [0x07, 0x1C, 0x65, null, 0x65, 0x65, 0x65, 0x65], $localId),
            'HK1_Heizprogramm' => $this->enumPayload($value, ['Eigen', 'Familie', 'Früh', 'Spät', 'Vormittag', 'Nachmittag', 'Mittag', 'Single', 'Senior'], [0x11, 0x00, null, 0x65, 0x65, 0x65, 0x65, 0x65], $localId, null),
            'HK1_Ferientage' => $this->integerPayload($value, 0, 99, [0x11, 0x00, 0x65, 0x65, 0x65, null, 0x65, 0x65], $localId, false, 'Tage'),
            'Warmwasser_Betriebsart' => $this->enumPayload($value, ['Nacht', 'Tag', 'Automatik'], [0x0C, 0x0E, null, 0x65, 0x65, 0x65, 0x65, 0x65], $localId, null),
            'Warmwasser_eingestellte_Temperatur' => $this->integerPayload($value, 30, 60, [0x0C, 0x07, 0x65, 0x65, 0x65, null, 0x65, 0x65], $localId),
            'Warmwasser_Zirkulation_Einstellung' => $this->enumPayload($value, ['Aus', '1 ×/h', '2 ×/h', '3 ×/h', '4 ×/h', '5 ×/h', '6 ×/h', 'An'], [0x0C, 0x0E, 0x65, 0x65, 0x65, 0x65, 0x65, null], $localId, null),
        };

        return [
            'LokaleId' => $localId,
            'Wert' => $normalized,
            'Einheit' => $unit,
            'Nutzdaten' => $bytes,
            'Hex' => implode(' ', array_map(static fn (int $byte): string => sprintf('%02X', $byte), $bytes)),
            // Erst ein spaeterer, ausdruecklich freigegebener Writer darf dies auf true setzen.
            'Sendebereit' => false,
        ];
    }

    private function choice(string $label, array $choices): array
    {
        return ['Bezeichnung' => $label, 'Typ' => 'Auswahl', 'Einheit' => null, 'Auswahl' => $choices];
    }

    private function number(string $label, string $unit, int|float $min, int|float $max, int|float $step): array
    {
        return ['Bezeichnung' => $label, 'Typ' => 'Zahl', 'Einheit' => $unit, 'Minimum' => $min, 'Maximum' => $max, 'Schrittweite' => $step];
    }

    private function enumPayload(mixed $value, array $choices, array $template, string $name, ?string $unit = null): array
    {
        $index = $this->choiceIndex($value, $choices, $name);
        return [$choices[$index], $unit, $this->insert($template, $index)];
    }

    private function summerPayload(mixed $value): array
    {
        if (is_string($value) && !is_numeric(trim($value))) {
            $normalized = $this->normalize($value);
            $raw = match ($normalized) {
                'sommer' => 9,
                'winter' => 31,
                default => throw new InvalidArgumentException('HK1_Sommergrenze erlaubt Sommer, Winter oder 10 bis 30 °C.'),
            };
            $display = $raw === 9 ? 'Sommer' : 'Winter';
            return [$display, null, $this->insert([0x07, 0x00, 0x65, null, 0x65, 0x65, 0x65, 0x65], $raw)];
        }

        return $this->integerPayload($value, 10, 30, [0x07, 0x00, 0x65, null, 0x65, 0x65, 0x65, 0x65], 'HK1_Sommergrenze');
    }

    private function halfDegreePayload(mixed $value, float $min, float $max, array $template, string $name): array
    {
        $number = $this->numeric($value, $name);
        if ($number < $min || $number > $max || abs($number * 2 - round($number * 2)) > 0.00001) {
            throw new InvalidArgumentException("$name erlaubt $min bis $max °C in Schritten von 0,5 °C.");
        }
        $number = round($number * 2) / 2;
        return [$number, '°C', $this->insert($template, (int) round($number * 2))];
    }

    private function integerPayload(mixed $value, int $min, int $max, array $template, string $name, bool $signed = false, string $unit = '°C'): array
    {
        $number = $this->numeric($value, $name);
        if ($number < $min || $number > $max || floor($number) !== $number) {
            throw new InvalidArgumentException("$name erlaubt nur ganze Werte von $min bis $max.");
        }
        $integer = (int) $number;
        $raw = $signed && $integer < 0 ? $integer + 256 : $integer;
        return [$integer, $unit, $this->insert($template, $raw)];
    }

    private function choiceIndex(mixed $value, array $choices, string $name): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $index = (int) $value;
            if (array_key_exists($index, $choices)) {
                return $index;
            }
        }
        $wanted = $this->normalize((string) $value);
        foreach ($choices as $index => $choice) {
            if ($wanted === $this->normalize($choice)) {
                return $index;
            }
        }
        throw new InvalidArgumentException("Ungueltiger Wert fuer $name. Erlaubt: " . implode(', ', $choices) . '.');
    }

    private function numeric(mixed $value, string $name): float
    {
        $candidate = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if (!is_int($candidate) && !is_float($candidate) && !(is_string($candidate) && is_numeric($candidate))) {
            throw new InvalidArgumentException("$name erwartet einen Zahlenwert.");
        }
        return (float) $candidate;
    }

    private function insert(array $template, int $raw): array
    {
        foreach ($template as &$byte) {
            if ($byte === null) {
                $byte = $raw;
                break;
            }
        }
        unset($byte);
        return array_map(static fn ($byte): int => (int) $byte, $template);
    }

    private function normalize(string $value): string
    {
        return strtolower(str_replace(['ä', 'ö', 'ü', 'ß', ' ', '-', '_', '×'], ['ae', 'oe', 'ue', 'ss', '', '', '', 'x'], trim($value)));
    }
}

<?php
declare(strict_types=1);

namespace PbdKn\CohSensorcollector\Km271;

/** Dekodiert die wichtigsten Statusregister der Logamatic 2107/KM271. */
final class Km271Decoder
{
    private array $values = [];
    private array $runtimeBytes = [];

    /** @return array<string, array<string, mixed>> Nur durch dieses Telegramm aktualisierte Werte. */
    public function consume(string $payload): array
    {
        if (strlen($payload) < 3) {
            return [];
        }
        $bytes = array_values(unpack('C*', $payload) ?: []);
        if (count($bytes) < 3) {
            return [];
        }
        $register = ($bytes[0] << 8) | $bytes[1];
        $raw = $bytes[2];
        $updated = [];

        $this->consumeConfiguration($updated, $register, $bytes);

        $simple = [
            0x8002 => ['hc1FlowSetpoint', 'HK1 Vorlauf Solltemperatur', '°C', 1.0, false],
            0x8003 => ['hc1FlowTemperature', 'HK1 Vorlauf Isttemperatur', '°C', 1.0, false],
            0x8004 => ['hc1RoomSetpoint', 'HK1 Raum Solltemperatur', '°C', 0.5, false],
            0x8005 => ['hc1RoomTemperature', 'HK1 Raum Isttemperatur', '°C', 0.5, false],
            0x8006 => ['hc1StartOptimization', 'HK1 Einschaltoptimierung', 'min', 1.0, false],
            0x8007 => ['hc1StopOptimization', 'HK1 Ausschaltoptimierung', 'min', 1.0, false],
            0x8008 => ['hc1PumpPower', 'HK1 Pumpenleistung', '%', 1.0, false],
            0x8009 => ['hc1MixerPosition', 'HK1 Mischerstellung', '%', 1.0, true],
            0x8114 => ['hc2FlowSetpoint', 'HK2 Vorlauf Solltemperatur', '°C', 1.0, false],
            0x8115 => ['hc2FlowTemperature', 'HK2 Vorlauf Isttemperatur', '°C', 1.0, false],
            0x8116 => ['hc2RoomSetpoint', 'HK2 Raum Solltemperatur', '°C', 0.5, false],
            0x8117 => ['hc2RoomTemperature', 'HK2 Raum Isttemperatur', '°C', 0.5, false],
            0x811A => ['hc2PumpPower', 'HK2 Pumpenleistung', '%', 1.0, false],
            0x811B => ['hc2MixerPosition', 'HK2 Mischerstellung', '', 1.0, true],
            0x8426 => ['hotWaterSetpoint', 'Warmwasser Solltemperatur', '°C', 1.0, false],
            0x8427 => ['hotWaterTemperature', 'Warmwasser Isttemperatur', '°C', 1.0, false],
            0x8428 => ['hotWaterOptimization', 'Warmwasser Einschaltoptimierung', 'min', 1.0, false],
            0x882A => ['boilerSetpoint', 'Kessel Solltemperatur', '°C', 1.0, false],
            0x882B => ['boilerTemperature', 'Kessel Isttemperatur', '°C', 1.0, false],
            0x882C => ['burnerOnTemperature', 'Brenner Einschalttemperatur', '°C', 1.0, false],
            0x882D => ['burnerOffTemperature', 'Brenner Ausschalttemperatur', '°C', 1.0, false],
            0x8833 => ['exhaustTemperature', 'Abgastemperatur', '°C', 1.0, false],
            0x893C => ['outsideTemperature', 'Außentemperatur', '°C', 1.0, true],
            0x893D => ['outsideTemperatureDamped', 'Außentemperatur gedämpft', '°C', 1.0, true],
        ];

        if (isset($simple[$register]) && !($register === 0x8833 && $raw === 0xFF)) {
            [$key, $label, $unit, $factor, $signed] = $simple[$register];
            $number = $signed ? self::signedByte($raw) : $raw;
            $this->put($updated, $key, $label, $number * $factor, $unit, $register, $raw);
        }

        if ($register === 0x8429) {
            $this->putBits($updated, $register, $raw, [
                0 => ['hotWaterChargePump', 'Warmwasser Ladepumpe'],
                1 => ['hotWaterCirculationPump', 'Warmwasser Zirkulationspumpe'],
                2 => ['hotWaterSolarPump', 'Warmwasser Solarpumpe'],
            ]);
        } elseif ($register === 0x8831) {
            $this->putBits($updated, $register, $raw, [
                0 => ['flueGasTest', 'Abgastest'],
                1 => ['burnerStage1', 'Brenner Stufe 1'],
                2 => ['boilerProtection', 'Kesselschutz'],
                3 => ['boilerActive', 'Kessel aktiv'],
                4 => ['burnerEnable', 'Brennerfreigabe'],
                5 => ['burnerHighEnable', 'Brennerfreigabe hohe Leistung'],
                6 => ['burnerStage2', 'Brenner Stufe 2'],
            ]);
        } elseif ($register === 0x8830) {
            $this->putBits($updated, $register, $raw, [
                0 => ['errorBurner', 'Störung Brenner'],
                1 => ['errorBoilerSensor', 'Störung Kesselfühler'],
                2 => ['errorAuxSensor', 'Störung Zusatzfühler'],
                3 => ['errorBoilerCold', 'Störung Kessel bleibt kalt'],
                4 => ['errorFlueGasSensor', 'Störung Abgasfühler'],
                5 => ['errorFlueGasLimit', 'Störung Abgasgrenze'],
                6 => ['errorSafetyChain', 'Störung Sicherheitskette'],
                7 => ['errorExternal', 'Externe Störung'],
            ]);
        } elseif ($register === 0x8832) {
            $this->put($updated, 'burnerControl', 'Brenner-Ansteuerung', $raw, '', $register, $raw);
        }

        if ($register >= 0x8836 && $register <= 0x8838) {
            $this->runtimeBytes[$register - 0x8836] = $raw;
            if (count($this->runtimeBytes) === 3) {
                $minutes = ($this->runtimeBytes[0] * 65536) + ($this->runtimeBytes[1] * 256) + $this->runtimeBytes[2];
                $this->put($updated, 'burnerRuntimeMinutes', 'Brennerlaufzeit', $minutes, 'min', $register, $raw);
                $this->put($updated, 'burnerRuntimeHours', 'Brennerlaufzeit', round($minutes / 60, 1), 'h', $register, $raw);
            }
        }

        return $updated;
    }

    public function values(): array
    {
        return $this->values;
    }

    private function consumeConfiguration(array &$updated, int $register, array $data): void
    {
        $at = static fn (int $index): ?int => $data[$index] ?? null;
        $modes = ['Nacht', 'Tag', 'Automatik'];
        $onOff = ['aus', 'ein'];
        $circulation = ['Aus', '1 ×/h', '2 ×/h', '3 ×/h', '4 ×/h', '5 ×/h', '6 ×/h', 'An'];
        $burnerTypes = ['1-stufig', '2-stufig', 'Modulierend'];
        $heatingPrograms = ['Eigen', 'Familie', 'Früh', 'Spät', 'Vormittag', 'Nachmittag', 'Mittag', 'Single', 'Senior'];

        if ($register === 0x0000 && count($data) >= 8) {
            $summerRaw = $at(3);
            $summer = $summerRaw === 9 ? 'Sommer' : ($summerRaw === 31 ? 'Winter' : $summerRaw);
            $summerUnit = is_int($summer) ? '°C' : '';
            $this->put($updated, 'hc1SummerThreshold', 'HK1 Sommergrenze', $summer, $summerUnit, $register, $summerRaw);
            $this->put($updated, 'hc1NightTemperature', 'HK1 Nachttemperatur', $at(4) / 2, '°C', $register, $at(4));
            $this->put($updated, 'hc1DayTemperature', 'HK1 Tagtemperatur', $at(5) / 2, '°C', $register, $at(5));
            $this->put($updated, 'hc1OperatingMode', 'HK1 Betriebsart', $modes[$at(6)] ?? ('Code ' . $at(6)), '', $register, $at(6));
            $this->put($updated, 'hc1HolidayTemperature', 'HK1 Urlaubstemperatur', $at(7) / 2, '°C', $register, $at(7));
        } elseif ($register === 0x000E && count($data) >= 7) {
            $this->put($updated, 'hc1MaximumTemperature', 'HK1 Maximaltemperatur', $at(4), '°C', $register, $at(4));
            $this->put($updated, 'hc1DesignTemperature', 'HK1 Auslegungstemperatur', $at(6), '°C', $register, $at(6));
        } elseif ($register === 0x0031 && count($data) >= 8) {
            $this->put($updated, 'hc1TemperatureOffset', 'HK1 Temperatur-Offset', self::signedByte($at(5)) / 2, '°C', $register, $at(5));
            $this->put($updated, 'hc1RemoteControl', 'HK1 Fernbedienung', $onOff[$at(6)] ?? ('Code ' . $at(6)), '', $register, $at(6));
            $this->put($updated, 'frostThreshold', 'Frostschutz ab', self::signedByte($at(7)), '°C', $register, $at(7));
        } elseif ($register === 0x007E && count($data) >= 6) {
            $this->put($updated, 'hotWaterConfiguredTemperature', 'Warmwasser eingestellte Temperatur', $at(5), '°C', $register, $at(5));
        } elseif ($register === 0x0085 && count($data) >= 8) {
            $this->put($updated, 'hotWaterOperatingMode', 'Warmwasser Betriebsart', $modes[$at(2)] ?? ('Code ' . $at(2)), '', $register, $at(2));
            $this->put($updated, 'hotWaterEnabled', 'Warmwasserbereitung', $onOff[$at(5)] ?? ('Code ' . $at(5)), '', $register, $at(5));
            $this->put($updated, 'hotWaterCirculationSetting', 'Warmwasser Zirkulation Einstellung', $circulation[$at(7)] ?? ('Code ' . $at(7)), '', $register, $at(7));
        } elseif ($register === 0x009A && count($data) >= 6) {
            $burnerIndex = max(0, $at(3) - 1);
            $this->put($updated, 'burnerType', 'Brennerart', $burnerTypes[$burnerIndex] ?? ('Code ' . $at(3)), '', $register, $at(3));
            $this->put($updated, 'maximumBoilerTemperature', 'Maximale Kesseltemperatur', $at(5), '°C', $register, $at(5));
        } elseif ($register === 0x00A1 && count($data) >= 8) {
            $this->put($updated, 'pumpLogicTemperature', 'Pumpenlogik Temperatur', $at(2), '°C', $register, $at(2));
        } elseif ($register === 0x0100 && count($data) >= 6) {
            $this->put($updated, 'hc1HeatingProgram', 'HK1 Heizprogramm', $heatingPrograms[$at(2)] ?? ('Code ' . $at(2)), '', $register, $at(2));
            $this->put($updated, 'hc1HolidayDays', 'HK1 Ferientage', $at(5), 'Tage', $register, $at(5));
        }
    }

    private function put(array &$updated, string $key, string $label, int|float|string $value, string $unit, int $register, int $raw): void
    {
        $entry = [
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'register' => sprintf('%04X', $register),
            'raw' => $raw,
            'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $this->values[$key] = $entry;
        $updated[$key] = $entry;
    }

    private function putBits(array &$updated, int $register, int $raw, array $definitions): void
    {
        foreach ($definitions as $bit => [$key, $label]) {
            $this->put($updated, $key, $label, ($raw & (1 << $bit)) !== 0 ? 1 : 0, '', $register, $raw);
        }
    }

    private static function signedByte(int $value): int
    {
        return $value >= 128 ? $value - 256 : $value;
    }
}

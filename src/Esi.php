<?php
declare(strict_types=1);

/**
 * Environmental Similarity Index (ESI).
 *
 * ESI = sum(w_i * s_i) / sum(w_i), over the parameters that have BOTH values.
 * A missing value removes the parameter from the sum. It is never treated as 0.
 *
 * This class has no database code on purpose: pure maths is easy to test
 * (see tests/EsiTest.php).
 */
final class Esi
{
    /** A pressure ratio of 1000x (or more) gives similarity 0. */
    public const PRESSURE_RATIO_LIMIT = 1000.0;
    /** A temperature difference of 100 K (or more) gives similarity 0. */
    public const TEMP_DIFF_LIMIT_K = 100.0;

    /**
     * Similarity of one parameter, from 0 (very different) to 1 (same).
     * Returns null when it cannot be calculated: the parameter is then excluded.
     */
    public static function similarity(string $method, ?float $earth, ?float $ref): ?float
    {
        if ($earth === null || $ref === null) {
            return null;
        }
        switch ($method) {
            case 'log_ratio':
                if ($earth <= 0 || $ref <= 0) {
                    return null; // a logarithm needs positive numbers
                }
                $s = 1 - abs(log($earth / $ref)) / log(self::PRESSURE_RATIO_LIMIT);
                return max(0.0, $s);

            case 'abs_kelvin':
                // Both values are in Celsius. A difference in Celsius equals a difference in kelvin.
                $kE = self::celsiusToKelvin($earth);
                $kR = self::celsiusToKelvin($ref);
                return max(0.0, 1 - abs($kE - $kR) / self::TEMP_DIFF_LIMIT_K);

            case 'relative':
                $max = max(abs($earth), abs($ref));
                if ($max == 0.0) {
                    return 1.0;
                }
                return max(0.0, 1 - abs($earth - $ref) / $max);

            case 'binary':
                return ((int)round($earth) === (int)round($ref)) ? 1.0 : 0.0;

            default:
                return null; // 'none' = context parameter, not part of the ESI
        }
    }

    /**
     * @param array<int,array{code:string,similarity:?float,weight:int}> $items
     * @return array{esi:?float,available:int,total:int}
     */
    public static function combine(array $items): array
    {
        $num = 0.0;
        $den = 0.0;
        $available = 0;
        foreach ($items as $item) {
            if ($item['similarity'] === null) {
                continue;
            }
            $available++;
            $num += $item['weight'] * $item['similarity'];
            $den += $item['weight'];
        }
        return [
            'esi'       => $den > 0 ? round($num / $den, 4) : null, // null, not 0
            'available' => $available,
            'total'     => count($items),
        ];
    }

    public static function celsiusToKelvin(float $c): float
    {
        return $c + 273.15;
    }

    /** Standard-atmosphere pressure (Pa) at height h metres above sea level. */
    public static function standardPressure(float $elevationM): float
    {
        return 101325.0 * pow(1 - 2.25577e-5 * $elevationM, 5.25588);
    }
}

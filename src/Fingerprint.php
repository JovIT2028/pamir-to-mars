<?php
declare(strict_types=1);

/**
 * "Analog fingerprint": which already-studied Earth analog has the most similar climate?
 *
 * Method (k-nearest-neighbour idea):
 *  1. Every Earth place becomes a vector of the same NASA POWER parameters.
 *  2. Each parameter is standardised (z-score) so that no unit dominates.
 *  3. Euclidean distance between vectors: smaller = more similar.
 *
 * This compares Earth with Earth using one data source, so units and methods match.
 */
final class Fingerprint
{
    public const FEATURES = ['temp_air', 'temp_range', 'solar', 'humidity', 'precip'];

    /**
     * @param array<string,array<string,?float>> $vectors slug => [feature => value|null]
     * @param string[] $analogSlugs
     * @return array{neighbours:array,features_used:string[],places_used:int}|null
     */
    public static function nearest(string $target, array $vectors, array $analogSlugs): ?array
    {
        // Keep only places that have EVERY feature (no guessing of missing values).
        $complete = array_filter($vectors, function ($v) {
            foreach (self::FEATURES as $f) {
                if (!isset($v[$f])) { return false; }
            }
            return true;
        });
        if (!isset($complete[$target]) || count($complete) < 3) {
            return null;
        }

        // z-score per feature
        $z = [];
        foreach (self::FEATURES as $f) {
            $col = array_map(fn($v) => (float)$v[$f], $complete);
            $mean = array_sum($col) / count($col);
            $var = array_sum(array_map(fn($x) => ($x - $mean) ** 2, $col)) / count($col);
            $sd = sqrt($var);
            foreach ($col as $slug => $x) {
                $z[$slug][$f] = $sd > 0 ? ($x - $mean) / $sd : 0.0;
            }
        }

        $out = [];
        foreach ($analogSlugs as $slug) {
            if ($slug === $target || !isset($z[$slug])) {
                continue;
            }
            $sum = 0.0;
            foreach (self::FEATURES as $f) {
                $sum += ($z[$target][$f] - $z[$slug][$f]) ** 2;
            }
            $out[] = ['slug' => $slug, 'distance' => round(sqrt($sum), 3)];
        }
        usort($out, fn($a, $b) => $a['distance'] <=> $b['distance']);

        return ['neighbours' => $out, 'features_used' => self::FEATURES, 'places_used' => count($complete)];
    }
}

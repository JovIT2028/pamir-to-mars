<?php
declare(strict_types=1);

/** Thrown when the browser sends a wrong value. The API turns it into HTTP 400. */
final class ValidationException extends RuntimeException {}

/**
 * Input validation: nothing from $_GET is trusted before it passes through here.
 */
final class Validator
{
    public static function slug($value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9_-]{1,60}$/', $value)) {
            throw new ValidationException("Parameter '$field' is missing or not valid.");
        }
        return $value;
    }

    public static function optionalEnum($value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ValidationException("Parameter '$field' must be one of: " . implode(', ', $allowed) . '.');
        }
        return $value;
    }

    /**
     * A clicked point: "lat" and "lon" in decimal degrees. Returns [lat, lon] as floats.
     * Strict format and range, so nothing odd reaches the NASA URL.
     * @return array{0:float,1:float}
     */
    public static function latLon($lat, $lon): array
    {
        foreach (['lat' => [$lat, 90], 'lon' => [$lon, 180]] as $name => [$v, $limit]) {
            if (!is_string($v) || !preg_match('/^-?\d{1,3}(\.\d{1,8})?$/', $v) || abs((float)$v) > $limit) {
                throw new ValidationException("Parameter '$name' must be a number between -$limit and $limit.");
            }
        }
        return [(float)$lat, (float)$lon];
    }

    /** 0 = annual, 1-12 = month. */
    public static function month($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_string($value) || !preg_match('/^\d{1,2}$/', $value) || (int)$value > 12) {
            throw new ValidationException("Parameter 'month' must be a whole number from 0 to 12.");
        }
        return (int)$value;
    }

    /**
     * Weights from the sliders: w[pressure]=3&w[solar]=0 ...
     * Each must be a whole number 0-5 and belong to a known ESI parameter.
     * @param string[] $allowedCodes
     * @return array<string,int>
     */
    public static function weights($value, array $allowedCodes): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            throw new ValidationException("Parameter 'w' must be a list, for example w[pressure]=3.");
        }
        $out = [];
        foreach ($value as $code => $w) {
            if (!is_string($code) || !in_array($code, $allowedCodes, true)) {
                throw new ValidationException('Unknown ESI parameter in weights.');
            }
            if (!is_string($w) || !preg_match('/^[0-5]$/', $w)) {
                throw new ValidationException("Weight for '$code' must be a whole number from 0 to 5.");
            }
            $out[$code] = (int)$w;
        }
        return $out;
    }
}

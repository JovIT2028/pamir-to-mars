<?php
declare(strict_types=1);

/**
 * Imports long-term climate averages ("climatology") from the NASA POWER API.
 *
 * API docs: https://power.larc.nasa.gov/docs/services/api/temporal/climatology/
 * No key is needed. One request = one point on Earth.
 *
 * Safety of external data:
 *  - time limit on every request
 *  - the JSON structure is checked before anything is saved
 *  - NASA's "fill value" (missing data, usually -999) is skipped, never saved as a number
 *  - units are checked before a value is converted
 */
final class PowerImporter
{
    public const ENDPOINT = 'https://power.larc.nasa.gov/api/temporal/climatology/point';
    public const POWER_PARAMS = ['T2M', 'T2M_MAX', 'T2M_MIN', 'TS', 'PS', 'ALLSKY_SFC_SW_DWN', 'RH2M', 'PRECTOTCORR'];
    private const MONTHS = ['ANN' => 0, 'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
                            'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    /** @var callable(string):string  returns the response body or throws */
    private $fetcher;
    /** @var string[] */
    public array $warnings = [];

    private ?MeasurementWriter $writer;

    public function __construct(?MeasurementWriter $writer = null, ?callable $fetcher = null)
    {
        $this->writer = $writer;
        $this->fetcher = $fetcher ?? [self::class, 'httpGet'];
    }

    public static function url(float $lat, float $lon): string
    {
        return self::ENDPOINT . '?' . http_build_query([
            'parameters' => implode(',', self::POWER_PARAMS),
            'community'  => 'RE',
            'longitude'  => $lon,
            'latitude'   => $lat,
            'format'     => 'JSON',
        ]);
    }

    /**
     * Fetches one point and saves it.
     * @return array{rows:int, elevation:?float}
     */
    public function importPoint(int $locationId, float $lat, float $lon): array
    {
        if ($this->writer === null) {
            throw new LogicException('importPoint needs a MeasurementWriter.');
        }
        $r = $this->readPoint($lat, $lon);
        foreach ($r['rows'] as [$param, $month, $value, $type, $period, $note]) {
            $this->writer->save($locationId, $param, 'nasa_power', $month, $value, $type, $period, $note);
        }
        return ['rows' => count($r['rows']), 'elevation' => $r['elevation']];
    }

    /**
     * Fetches one point and returns the checked values WITHOUT saving anything.
     * @return array{rows:array<int,array>, elevation:?float, period:string, note:string}
     */
    public function readPoint(float $lat, float $lon): array
    {
        $body = ($this->fetcher)(self::url($lat, $lon));
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['properties']['parameter']) || !is_array($json['properties']['parameter'])) {
            throw new RuntimeException('Unexpected answer from NASA POWER (no properties.parameter).');
        }
        $data  = $json['properties']['parameter'];
        $fill  = isset($json['header']['fill_value']) && is_numeric($json['header']['fill_value'])
               ? (float)$json['header']['fill_value'] : -999.0;
        $units = [];
        foreach (($json['parameters'] ?? []) as $name => $info) {
            if (is_array($info) && isset($info['units']) && is_string($info['units'])) {
                $units[$name] = $info['units'];
            }
        }
        $period = 'NASA POWER climatology';
        foreach (['range', 'title'] as $k) {
            if (isset($json['header'][$k]) && is_string($json['header'][$k]) && $json['header'][$k] !== '') {
                $period = $json['header'][$k];
                break;
            }
        }
        $elev = $json['geometry']['coordinates'][2] ?? null;
        $elev = is_numeric($elev) && (float)$elev !== $fill ? (float)$elev : null;
        $cellNote = $elev === null ? 'NASA POWER grid-cell average.'
                  : 'NASA POWER grid-cell average (cell mean elevation ' . round($elev) . ' m).';

        $get = function (string $name, string $monthKey) use ($data, $fill): ?float {
            $v = $data[$name][$monthKey] ?? null;
            if (!is_numeric($v)) { return null; }
            $v = (float)$v;
            // skip the fill value and anything not finite
            return (abs($v - $fill) < 1e-6 || !is_finite($v)) ? null : $v;
        };

        // POWER name => [our parameter, scale factor]
        $map = ['T2M' => ['temp_air', 1.0], 'TS' => ['temp_surface', 1.0], 'RH2M' => ['humidity', 1.0]];

        // Pressure: POWER gives kPa, we store Pa.
        if ($this->unitHas($units, 'PS', 'kpa')) {
            $map['PS'] = ['pressure', 1000.0];
        }
        // Solar: kWh/m2/day expected; MJ/m2/day is converted (1 kWh = 3.6 MJ).
        $su = strtolower($units['ALLSKY_SFC_SW_DWN'] ?? 'kw-hr/m^2/day');
        if (str_contains($su, 'kw')) {
            $map['ALLSKY_SFC_SW_DWN'] = ['solar', 1.0];
        } elseif (str_contains($su, 'mj')) {
            $map['ALLSKY_SFC_SW_DWN'] = ['solar', 1 / 3.6];
        } else {
            $this->warnings[] = "Solar radiation skipped: unexpected unit '$su'.";
        }
        if ($this->unitHas($units, 'PRECTOTCORR', 'mm')) {
            $map['PRECTOTCORR'] = ['precip', 1.0];
        }

        $out = [];
        $ranges = [];
        foreach (self::MONTHS as $key => $month) {
            foreach ($map as $powerName => [$param, $scale]) {
                $v = $get($powerName, $key);
                if ($v === null) { continue; }
                if (!$this->plausible($param, $v * $scale)) {
                    $this->warnings[] = "Implausible $param value skipped: " . ($v * $scale);
                    continue;
                }
                $out[] = [$param, $month, $v * $scale, 'modelled', $period, $cellNote];
            }
            // Temperature spread = T2M_MAX - T2M_MIN for each month (our calculation).
            // The ANN column of POWER is the extreme of the whole year, so it is NOT used here:
            // the annual value is the mean of the 12 monthly spreads (see below).
            if ($month >= 1) {
                $max = $get('T2M_MAX', $key);
                $min = $get('T2M_MIN', $key);
                if ($max !== null && $min !== null && $max >= $min) {
                    $ranges[$month] = $max - $min;
                    $out[] = ['temp_range', $month, $max - $min, 'calculated', $period,
                        'T2M_MAX minus T2M_MIN (monthly values from NASA POWER); larger than a true mean daily range. ' . $cellNote];
                }
            }
        }
        if (count($ranges) === 12) {
            $out[] = ['temp_range', 0, array_sum($ranges) / 12, 'calculated', $period,
                'Mean of the 12 monthly T2M_MAX minus T2M_MIN spreads (NASA POWER); larger than a true mean daily range. ' . $cellNote];
        }
        if ($out === []) {
            throw new RuntimeException('NASA POWER answered, but no usable values were found.');
        }
        return ['rows' => $out, 'elevation' => $elev, 'period' => $period, 'note' => $cellNote];
    }

    private function unitHas(array $units, string $name, string $needle): bool
    {
        if (!isset($units[$name])) {
            return true; // no unit block in the answer: trust the documented default
        }
        if (str_contains(strtolower($units[$name]), $needle)) {
            return true;
        }
        $this->warnings[] = "$name skipped: unexpected unit '{$units[$name]}'.";
        return false;
    }

    /** Very wide sanity limits: they only catch broken data. */
    private function plausible(string $param, float $v): bool
    {
        switch ($param) {
            case 'temp_air':
            case 'temp_surface':
                return $v > -100 && $v < 70;
            case 'pressure':
                return $v > 20000 && $v < 110000;
            case 'solar':
                return $v >= 0 && $v < 15;
            case 'humidity':
                return $v >= 0 && $v <= 100;
            case 'precip':
                return $v >= 0 && $v < 100;
            default:
                return true;
        }
    }

    /** One quick attempt, used while a visitor waits (the map click). */
    public static function httpGetFast(string $url): string
    {
        return self::httpGet($url, 15, 1);
    }

    public static function httpGet(string $url, int $timeout = 40, int $attempts = 2): string
    {
        $lastError = 'unknown error';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'pamir-to-mars/1.0 (NASA Space Apps 2026)',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $body = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                if ($body !== false && $code === 200) { return (string)$body; }
                $lastError = $err !== '' ? $err : "HTTP $code";
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
                $body = @file_get_contents($url, false, $ctx);
                if ($body !== false) { return $body; }
                $lastError = 'request failed (curl extension is not enabled)';
            }
            if ($attempt < $attempts) { sleep(3); }
        }
        throw new RuntimeException('NASA POWER request failed: ' . $lastError);
    }
}

<?php
declare(strict_types=1);
/**
 * Tests of the ESI maths. No database needed.
 *   php tests/EsiTest.php
 */
require __DIR__ . '/../src/Esi.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Fingerprint.php';

$passed = 0; $failed = 0;
function check(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ok    $name\n"; } else { $failed++; echo "  FAIL  $name\n"; }
}
function near(?float $a, float $b, float $eps = 0.005): bool { return $a !== null && abs($a - $b) < $eps; }

echo "ESI tests\n";

// 1. Karakul vs Gale pressure: P(3960 m) ~ 61,960 Pa against 730 Pa -> s ~ 0.36
$p = Esi::standardPressure(3960);
check('1a standard pressure at 3960 m is about 61,960 Pa', abs($p - 61960) < 150);
check('1b pressure similarity Karakul vs Gale is about 0.36', near(Esi::similarity('log_ratio', $p, 730.0), 0.357));

// 2. Equal values -> 1
check('2a equal pressure -> 1', near(Esi::similarity('log_ratio', 730.0, 730.0), 1.0));
check('2b equal temperature -> 1', near(Esi::similarity('abs_kelvin', -33.0, -33.0), 1.0));
check('2c equal solar -> 1', near(Esi::similarity('relative', 5.5, 5.5), 1.0));

// 3. Missing value -> excluded, completeness drops, and it is NOT counted as zero
$items = [
    ['code' => 'pressure', 'similarity' => 0.4, 'weight' => 3],
    ['code' => 'temp',     'similarity' => null, 'weight' => 3],
    ['code' => 'crater',   'similarity' => 1.0, 'weight' => 2],
];
$r = Esi::combine($items);
check('3a missing parameter excluded from the average', near($r['esi'], (3 * 0.4 + 2 * 1.0) / 5));
check('3b completeness is 2 of 3', $r['available'] === 2 && $r['total'] === 3);
check('3c missing input gives null similarity', Esi::similarity('abs_kelvin', null, -33.0) === null);

// 4. No data or all weights zero -> null ("n/a"), never 0
check('4a nothing available -> ESI null', Esi::combine([['code' => 'x', 'similarity' => null, 'weight' => 3]])['esi'] === null);
check('4b all weights zero -> ESI null', Esi::combine([['code' => 'x', 'similarity' => 0.7, 'weight' => 0]])['esi'] === null);

// 5. Invalid weights are rejected
$bad = function ($w): bool {
    try { Validator::weights($w, ['pressure']); return false; } catch (ValidationException $e) { return true; }
};
check('5a negative weight rejected', $bad(['pressure' => '-1']));
check('5b weight above 5 rejected', $bad(['pressure' => '6']));
check('5c non-number weight rejected', $bad(['pressure' => 'abc']));
check('5d unknown parameter rejected', $bad(['hack' => '1']));
check('5e valid weight accepted', Validator::weights(['pressure' => '5'], ['pressure']) === ['pressure' => 5]);

// 6. Temperature: Celsius is converted to kelvin; 100 K apart -> 0; 50 K apart -> 0.5
check('6a celsius to kelvin', near(Esi::celsiusToKelvin(-33.0), 240.15));
check('6b 50 K apart -> 0.5', near(Esi::similarity('abs_kelvin', 17.0, -33.0), 0.5));
check('6c more than 100 K apart -> 0, not negative', Esi::similarity('abs_kelvin', 80.0, -60.0) === 0.0);

// Extra safety checks
check('7a 1000x pressure ratio -> 0', near(Esi::similarity('log_ratio', 730000.0, 730.0), 0.0));
check('7b non-positive pressure -> null', Esi::similarity('log_ratio', 0.0, 730.0) === null);
check('7c binary match / mismatch', Esi::similarity('binary', 1.0, 1.0) === 1.0 && Esi::similarity('binary', 0.0, 1.0) === 0.0);
check('7d context parameter (none) -> null', Esi::similarity('none', 1.0, 1.0) === null);
check('7e slug validation blocks SQL-like input', (function () {
    try { Validator::slug("karakul' OR 1=1", 'earth'); return false; } catch (ValidationException $e) { return true; }
})());

// Clicked-point coordinates (the "analyze any point" API)
$badLL = function ($a, $b): bool {
    try { Validator::latLon($a, $b); return false; } catch (ValidationException $e) { return true; }
};
check('9a valid lat/lon accepted', Validator::latLon('38.17', '73.96') === [38.17, 73.96]);
check('9b latitude above 90 rejected', $badLL('91', '0'));
check('9c longitude above 180 rejected', $badLL('0', '181'));
check('9d letters, exponent and injection rejected', $badLL('abc', '0') && $badLL('1e3', '0') && $badLL("1' OR 1=1", '0'));
check('9e missing value rejected', $badLL(null, '0'));

// Fingerprint: the target must be closest to the place with the most similar numbers
$v = [
    'target' => ['temp_air' => -4, 'temp_range' => 14, 'solar' => 5.5, 'humidity' => 40, 'precip' => 0.3],
    'cold'   => ['temp_air' => -5, 'temp_range' => 13, 'solar' => 5.4, 'humidity' => 42, 'precip' => 0.4],
    'hot'    => ['temp_air' => 25, 'temp_range' => 8,  'solar' => 6.5, 'humidity' => 70, 'precip' => 4.0],
    'gap'    => ['temp_air' => 10, 'temp_range' => null, 'solar' => 5, 'humidity' => 50, 'precip' => 1.0],
];
$f = Fingerprint::nearest('target', $v, ['cold', 'hot', 'gap']);
check('8a nearest analog is the similar one', $f !== null && $f['neighbours'][0]['slug'] === 'cold');
check('8b place with a missing feature is left out', $f !== null && count($f['neighbours']) === 2);
check('8c too little data -> null', Fingerprint::nearest('target', ['target' => $v['target']], ['cold']) === null);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

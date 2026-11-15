<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET /api/compare.php?earth=karakul&ref=gale   -> side-by-side table
api(function (Repository $repo): array {
    $earth = requireLocation($repo, Validator::slug($_GET['earth'] ?? null, 'earth'), 'earth');
    $ref   = requireLocation($repo, Validator::slug($_GET['ref'] ?? null, 'ref'), 'reference');
    $svc   = new EsiService($repo);

    $eValues = $repo->bestValues($earth['id'], 0);
    $rValues = $repo->bestValues($ref['id'], 0);

    $rows = [];
    foreach ($repo->parameters() as $p) {
        $e = $eValues[$p['code']] ?? null;
        $r = $rValues[$p['code']] ?? null;
        $diff = null;
        if ($e !== null && $r !== null) {
            if ($p['esi_method'] === 'log_ratio' && $r['value'] > 0) {
                $diff = ['kind' => 'ratio', 'value' => round($e['value'] / $r['value'], 1)];
            } elseif ($p['esi_method'] === 'binary') {
                $diff = ['kind' => 'match', 'value' => (int)round($e['value']) === (int)round($r['value'])];
            } else {
                $diff = ['kind' => 'difference', 'value' => round($e['value'] - $r['value'], 2)];
            }
        }
        $rows[] = [
            'code' => $p['code'], 'name' => $p['name'], 'unit' => $p['unit'],
            'in_esi' => $p['esi_method'] !== 'none',
            'earth' => $svc->publicValue($e), 'reference' => $svc->publicValue($r),
            'difference' => $diff,
        ];
    }

    // Honest limitations, chosen by the kind of reference.
    $limits = ['Earth and ' . ucfirst($ref['body']) . ' elevations use different reference surfaces, so they are shown but not subtracted.'];
    if ($ref['body'] === 'moon') {
        $limits[] = 'The Moon has no atmosphere: pressure, air temperature and humidity have no lunar equivalent and are not compared.';
        $limits[] = 'The Pamir is a weak analog for lunar polar lighting (grazing sunlight, permanently shadowed craters).';
    } else {
        $limits[] = 'Even at 4,000 m the air on Earth is far denser than on Mars. A Pamir site is not a pressure analog.';
        $limits[] = 'Earth has liquid water, oxygen and life. Similarity is claimed only for the parameters listed here.';
    }

    return [
        'earth' => ['slug' => $earth['slug'], 'name' => $earth['name'], 'elevation_m' => $earth['elevation_m'],
                    'elevation_ref' => $earth['elevation_ref'], 'is_verified' => $earth['is_verified']],
        'reference' => ['slug' => $ref['slug'], 'name' => $ref['name'], 'body' => $ref['body'],
                        'elevation_m' => $ref['elevation_m'], 'elevation_ref' => $ref['elevation_ref']],
        'rows' => $rows,
        'limitations' => $limits,
    ];
});

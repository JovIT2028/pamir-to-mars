<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/Fingerprint.php';

// GET /api/fingerprint.php?earth=karakul   -> nearest known Earth analogs by climate
api(function (Repository $repo): array {
    $earth = requireLocation($repo, Validator::slug($_GET['earth'] ?? null, 'earth'), 'earth');

    $places = array_merge($repo->locations('site', 'earth'), $repo->locations('known_analog', 'earth'));
    $vectors = [];
    $names = [];
    $analogs = [];
    foreach ($places as $p) {
        $vals = $repo->bestValues($p['id'], 0);
        foreach (Fingerprint::FEATURES as $f) {
            $vectors[$p['slug']][$f] = $vals[$f]['value'] ?? null;
        }
        $names[$p['slug']] = $p['name'];
        if ($p['kind'] === 'known_analog') {
            $analogs[] = $p['slug'];
        }
    }

    $result = Fingerprint::nearest($earth['slug'], $vectors, $analogs);
    if ($result === null) {
        return ['earth' => $earth['slug'], 'available' => false, 'neighbours' => [],
                'note' => 'Not enough data yet. Run scripts/import_power.php so that the sites and the known analogs have NASA POWER values.'];
    }
    foreach ($result['neighbours'] as &$n) {
        $n['name'] = $names[$n['slug']];
    }
    return [
        'earth' => $earth['slug'], 'available' => true,
        'neighbours' => $result['neighbours'],
        'features_used' => $result['features_used'],
        'places_used' => $result['places_used'],
        'note' => 'Distance in standardised climate space (NASA POWER climatology): smaller means more similar climate. '
                . 'Calculated from only ' . $result['places_used'] . ' places, so treat it as an indication. '
                . 'It compares climate only, not geology, and it is not a comparison with Mars itself.',
    ];
});

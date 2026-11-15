<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET /api/location.php?slug=karakul   -> one location with every available value and its source
api(function (Repository $repo): array {
    $loc = requireLocation($repo, Validator::slug($_GET['slug'] ?? null, 'slug'));
    $svc = new EsiService($repo);
    $values = $repo->bestValues($loc['id'], 0);

    $out = [];
    foreach ($repo->parameters() as $p) {
        $out[] = [
            'code'  => $p['code'],
            'name'  => $p['name'],
            'unit'  => $p['unit'],
            'in_esi' => $p['esi_method'] !== 'none',
            'value' => $svc->publicValue($values[$p['code']] ?? null), // null = not available yet
        ];
    }
    unset($loc['id']);
    return ['location' => $loc, 'parameters' => $out];
});

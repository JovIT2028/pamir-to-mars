<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET /api/locations.php?kind=site&body=earth   -> list of locations for the map and the dropdowns
api(function (Repository $repo): array {
    $kind = Validator::optionalEnum($_GET['kind'] ?? null, ['site', 'reference', 'grid', 'known_analog'], 'kind');
    $body = Validator::optionalEnum($_GET['body'] ?? null, ['earth', 'mars', 'moon'], 'body');
    $rows = $repo->locations($kind, $body);
    foreach ($rows as &$r) {
        unset($r['id']);
    }
    return ['count' => count($rows), 'locations' => $rows];
});

<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET /api/heatmap.php?ref=gale   -> ESI of every grid cell of the Eastern Pamir
api(function (Repository $repo): array {
    $ref    = requireLocation($repo, Validator::slug($_GET['ref'] ?? null, 'ref'), 'reference');
    $params = $repo->parameters();
    $svc    = new EsiService($repo);

    $cells = [];
    foreach ($repo->locations('grid', 'earth') as $cell) {
        $r = $svc->compute($cell, $ref, [], 0, $params);
        if ($r['esi'] === null) {
            continue; // a cell without data is left out, not coloured as zero
        }
        $cells[] = [
            'slug' => $cell['slug'], 'lat' => $cell['latitude'], 'lon' => $cell['longitude'],
            'elevation_m' => $cell['elevation_m'], 'esi' => $r['esi'],
            'available' => $r['completeness']['available'], 'total' => $r['completeness']['total'],
        ];
    }
    $top = $cells;
    usort($top, fn($a, $b) => $b['esi'] <=> $a['esi']);

    return [
        'reference' => $ref['slug'],
        'cell_size' => ['lat' => 0.5, 'lon' => 0.625],
        'count' => count($cells),
        'cells' => $cells,
        'top5' => array_slice($top, 0, 5),
        'note' => count($cells) === 0
            ? 'No grid data is loaded yet. Run scripts/import_grid.php first.'
            : 'Each cell is one NASA POWER grid cell (about 0.5 x 0.625 degrees, roughly 55 x 55 km). It averages peaks and valleys together. '
              . 'Impact geology is not known per cell, so it is excluded from the cell index.',
        'disclaimer' => EsiService::DISCLAIMER,
    ];
});

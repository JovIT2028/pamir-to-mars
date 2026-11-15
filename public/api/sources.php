<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET /api/sources.php   -> data-source registry and parameter definitions
api(function (Repository $repo): array {
    $params = array_map(function ($p) { unset($p['id']); return $p; }, $repo->parameters());
    return [
        'sources' => $repo->sources(),
        'parameters' => $params,
        'esi' => [
            'formula' => 'ESI = sum(w_i * s_i) / sum(w_i) over the parameters that have both values',
            'pressure_ratio_limit' => Esi::PRESSURE_RATIO_LIMIT,
            'temp_diff_limit_k' => Esi::TEMP_DIFF_LIMIT_K,
            'disclaimer' => EsiService::DISCLAIMER,
        ],
    ];
});

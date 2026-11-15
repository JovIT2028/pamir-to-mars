<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET /api/esi.php?earth=karakul&ref=gale&w[pressure]=3&month=0
api(function (Repository $repo): array {
    $earth  = requireLocation($repo, Validator::slug($_GET['earth'] ?? null, 'earth'), 'earth');
    $ref    = requireLocation($repo, Validator::slug($_GET['ref'] ?? null, 'ref'), 'reference');
    $month  = Validator::month($_GET['month'] ?? null);
    $params = $repo->parameters();
    $esiCodes = array_column(array_filter($params, fn($p) => $p['esi_method'] !== 'none'), 'code');
    $weights = Validator::weights($_GET['w'] ?? null, $esiCodes);

    return (new EsiService($repo))->compute($earth, $ref, $weights, $month, $params);
});

<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET /api/seasons.php?earth=karakul&ref=gale   -> ESI for each of the 12 months
api(function (Repository $repo): array {
    $earth  = requireLocation($repo, Validator::slug($_GET['earth'] ?? null, 'earth'), 'earth');
    $ref    = requireLocation($repo, Validator::slug($_GET['ref'] ?? null, 'ref'), 'reference');
    $params = $repo->parameters();
    $svc    = new EsiService($repo);

    $months = [];
    $best = null;
    $hasMonthly = false;
    for ($m = 1; $m <= 12; $m++) {
        $r = $svc->compute($earth, $ref, [], $m, $params);
        $monthlyParams = 0;
        foreach ($r['parameters'] as $p) {
            if ($p['earth'] !== null && !$p['earth']['annual_fallback']) {
                $monthlyParams++;
            }
        }
        $hasMonthly = $hasMonthly || $monthlyParams > 0;
        $months[] = ['month' => $m, 'esi' => $r['esi'], 'available' => $r['completeness']['available'],
                     'monthly_parameters' => $monthlyParams];
        if ($r['esi'] !== null && $monthlyParams > 0 && ($best === null || $r['esi'] > $best['esi'])) {
            $best = ['month' => $m, 'esi' => $r['esi']];
        }
    }
    // If the reference temperature is only a short average (not a long-term mean), the ranking must say so.
    $refTemp = $repo->bestValues($ref['id'], 0)['temp_surface'] ?? null;
    $shortRef = $refTemp !== null && stripos((string)$refTemp['note'], 'not an annual mean') !== false;

    return [
        'earth' => $earth['slug'], 'reference' => $ref['slug'],
        'months' => $months,
        'best_month' => $hasMonthly ? $best : null,
        'note' => $hasMonthly
            ? 'Earth values are monthly climatology. The reference site uses its single long-term value, because monthly reference data is not loaded.'
            : 'No monthly Earth data is loaded yet. Run scripts/import_power.php first.',
        'caveat' => $shortRef
            ? 'The reference temperature is a one-month average, not an annual mean, so this ranking mostly shows when the Earth temperature is closest to that single value. Treat it as an indication, not as a recommendation of the best season.'
            : null,
        'disclaimer' => EsiService::DISCLAIMER,
    ];
});

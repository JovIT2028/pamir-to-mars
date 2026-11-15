<?php
declare(strict_types=1);
/**
 * Imports NASA POWER climatology for the Pamir sites and the known Earth analogs.
 *
 *   php scripts/import_power.php                  (sites + known analogs)
 *   php scripts/import_power.php --kind=site      (only Pamir sites)
 *
 * Safe to run again: existing rows are updated, not duplicated.
 */
require __DIR__ . '/_cli.php';

$kinds = array_filter(explode(',', opt('kind', 'site,known_analog')));
foreach ($kinds as $k) {
    if (!in_array($k, ['site', 'known_analog', 'grid'], true)) { out("Unknown kind: $k"); exit(1); }
}

$pdo = Db::pdo();
$repo = new Repository($pdo);
$writer = new MeasurementWriter($pdo);
$importer = new PowerImporter($writer);

$total = 0; $failed = []; $done = 0;
foreach ($kinds as $kind) {
    foreach ($repo->locations($kind, 'earth') as $loc) {
        out(sprintf('-> %s (%.3f, %.3f)', $loc['slug'], $loc['latitude'], $loc['longitude']));
        try {
            $r = $importer->importPoint($loc['id'], $loc['latitude'], $loc['longitude']);
            $total += $r['rows']; $done++;
            out("   saved {$r['rows']} values from NASA POWER");

            // Extra estimate from the site's own elevation (kept next to the NASA value, labelled "calculated").
            if ($loc['elevation_m'] !== null) {
                $writer->save($loc['id'], 'pressure', 'std_atmosphere', 0, Esi::standardPressure($loc['elevation_m']),
                    'calculated', null, 'Standard atmosphere at ' . round($loc['elevation_m']) . ' m.');
                $total++;
            }
        } catch (Throwable $e) {
            $failed[] = $loc['slug'] . ': ' . $e->getMessage();
            out('   FAILED: ' . $e->getMessage());
        }
        sleep(1); // be polite to the NASA server: one request at a time
    }
}
foreach (array_unique($importer->warnings) as $w) { out("warning: $w"); }

$status = $failed === [] ? 'ok' : ($done > 0 ? 'partial' : 'error');
$writer->log('nasa_power', $status, $total,
    "Locations done: $done. " . ($failed ? 'Failed: ' . implode(' | ', $failed) : 'No failures.'));
out("Finished: status=$status, values saved=$total");
exit($status === 'error' ? 1 : 0);

<?php
declare(strict_types=1);
/**
 * Builds the heatmap: creates one location per NASA POWER grid cell of the
 * Eastern Pamir region and imports its climatology.
 *
 *   php scripts/import_grid.php
 *   php scripts/import_grid.php --lat-min=37 --lat-max=39.5 --lon-min=72.5 --lon-max=75
 *
 * Cell centres follow the MERRA-2 grid: 0.5 degrees in latitude, 0.625 in longitude.
 * The default box has 6 x 5 = 30 cells = 30 requests (about one minute).
 * The box also covers parts of neighbouring countries.
 */
require __DIR__ . '/_cli.php';

$latMin = (float)opt('lat-min', '37');   $latMax = (float)opt('lat-max', '39.5');
$lonMin = (float)opt('lon-min', '72.5'); $lonMax = (float)opt('lon-max', '75');
if ($latMin >= $latMax || $lonMin >= $lonMax || $latMin < -90 || $latMax > 90 || $lonMin < -180 || $lonMax > 180) {
    out('Invalid bounding box.'); exit(1);
}
// snap to the MERRA-2 grid
$lat0 = ceil($latMin / 0.5) * 0.5;
$lon0 = -180 + ceil(($lonMin + 180) / 0.625) * 0.625;
$cells = [];
for ($lat = $lat0; $lat <= $latMax + 1e-9; $lat += 0.5) {
    for ($lon = $lon0; $lon <= $lonMax + 1e-9; $lon += 0.625) {
        $cells[] = [round($lat, 3), round($lon, 3)];
    }
}
if (count($cells) > 120) { out('Too many cells (' . count($cells) . '). Use a smaller box.'); exit(1); }
out(count($cells) . ' grid cells to import.');

$pdo = Db::pdo();
$writer = new MeasurementWriter($pdo);
$importer = new PowerImporter($writer);
$insert = $pdo->prepare(
    "INSERT INTO locations (slug, name, body, kind, latitude, longitude, is_verified, description)
     VALUES (?, ?, 'earth', 'grid', ?, ?, 1, 'NASA POWER grid cell (heatmap).')
     ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude)"
);
$find = $pdo->prepare('SELECT id FROM locations WHERE slug = ?');
$setElev = $pdo->prepare("UPDATE locations SET elevation_m = ?, elevation_ref = 'NASA POWER grid-cell mean' WHERE id = ?");

$total = 0; $failed = []; $done = 0;
foreach ($cells as [$lat, $lon]) {
    $slug = sprintf('grid_%s%04d_%s%05d', $lat >= 0 ? 'n' : 's', abs((int)round($lat * 100)), $lon >= 0 ? 'e' : 'w', abs((int)round($lon * 100)));
    $insert->execute([$slug, sprintf('Grid cell %.2f, %.3f', $lat, $lon), $lat, $lon]);
    $find->execute([$slug]);
    $id = (int)$find->fetchColumn();
    try {
        $r = $importer->importPoint($id, $lat, $lon);
        if ($r['elevation'] !== null) { $setElev->execute([$r['elevation'], $id]); }
        $total += $r['rows']; $done++;
        out("-> $slug: {$r['rows']} values");
    } catch (Throwable $e) {
        $failed[] = "$slug: " . $e->getMessage();
        out("-> $slug FAILED: " . $e->getMessage());
    }
    sleep(1);
}
foreach (array_unique($importer->warnings) as $w) { out("warning: $w"); }
$status = $failed === [] ? 'ok' : ($done > 0 ? 'partial' : 'error');
$writer->log('nasa_power', $status, $total, "Grid cells done: $done of " . count($cells) . '. ' . ($failed ? 'Failed: ' . implode(' | ', array_slice($failed, 0, 10)) : 'No failures.'));
out("Finished: status=$status, values saved=$total");
exit($status === 'error' ? 1 : 0);

<?php
declare(strict_types=1);
/**
 * World scan: "where on Earth is most Mars-like?"
 * Fetches the NASA POWER climate of one cell every STEP degrees (default 10), scores each cell against a
 * Mars reference site with the same ESI code the API uses, and writes public/static/worldscan__ref-<slug>.json.
 * The page reads that file, so the scan costs nothing at run time.
 *
 *   php scripts/scan_world.php                 (reference: gale, step 10 degrees, about 600 cells, ~15 minutes)
 *   php scripts/scan_world.php --step=20       (quick test, about 150 cells)
 *
 * Safe to stop and run again: every fetched cell is kept in the probe_cache table. One request at a time.
 * Honest note: Gale has no solar value yet, and a bare cell has no impact geology, so each cell is scored on
 * pressure and surface temperature only (2 of 4 parameters).
 */
require __DIR__ . '/_cli.php';
require_once __DIR__ . '/../src/Fingerprint.php';

$step = (float)opt('step', '10');
$refSlug = (string)opt('ref', 'gale');
if ($step < 2.5 || $step > 30 || !preg_match('/^[a-z0-9_-]{1,60}$/', $refSlug)) { out('Bad options.'); exit(1); }

$pdo  = Db::pdo();
$repo = new Repository($pdo);
$ref  = $repo->location($refSlug);
if ($ref === null || $ref['kind'] !== 'reference') { out("Reference '$refSlug' not found."); exit(1); }

$params = $repo->parameters();
$svc = new EsiService($repo);
$importer = new PowerImporter(null);
$sel = $pdo->prepare('SELECT payload FROM probe_cache WHERE lat = ? AND lon = ?');
$ins = $pdo->prepare('INSERT INTO probe_cache (lat, lon, payload) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = CURRENT_TIMESTAMP');

$cells = [];
for ($lat = -80.0; $lat <= 80.0 + 1e-9; $lat += $step) {
    for ($lon = -180.0; $lon < 180.0 - 1e-9; $lon += $step) { $cells[] = [round($lat, 3), round($lon, 3)]; }
}
out(count($cells) . " cells, reference $refSlug.");

$rows = []; $fetched = 0; $failed = 0; $n = 0;
foreach ($cells as [$lat, $lon]) {
    $n++;
    $cLat = max(-90.0, min(90.0, round($lat / 0.5) * 0.5));
    $cLon = round(-180 + round(($lon + 180) / 0.625) * 0.625, 3);
    $sel->execute([$cLat, $cLon]);
    $payload = $sel->fetchColumn();
    $data = $payload !== false ? json_decode((string)$payload, true) : null;
    if (!is_array($data)) {
        try {
            $p = $importer->readPoint($cLat, $cLon);
            $annual = [];
            foreach ($p['rows'] as [$param, $month, $value, $type, $period, $note]) {
                if ($month === 0) { $annual[$param] = ['value' => $value, 'data_type' => $type, 'period' => $period, 'note' => $note]; }
            }
            $data = ['elevation' => $p['elevation'], 'values' => $annual];
            $ins->execute([$cLat, $cLon, json_encode($data)]);
            $fetched++;
            sleep(1); // one polite request at a time
        } catch (Throwable $e) {
            $failed++;
            out("  cell $lat,$lon failed: " . $e->getMessage());
            continue;
        }
    }
    $e = [];
    foreach ($data['values'] as $code => $v) {
        $e[$code] = ['code' => $code, 'month' => 0, 'value' => (float)$v['value'], 'data_type' => $v['data_type'], 'period' => $v['period'],
                     'note' => $v['note'], 'source_code' => 'nasa_power', 'source_name' => 'NASA POWER', 'source_url' => null, 'annual_fallback' => false];
    }
    $r = $svc->computeFromValues('scan', $e, $ref, [], 0, $params);
    $rows[] = [
        'lat' => $lat, 'lon' => $lon, 'esi' => $r['esi'], 'n' => $r['completeness']['available'],
        'elev' => $data['elevation'] === null ? null : round((float)$data['elevation']),
        'p' => isset($e['pressure']) ? round($e['pressure']['value']) : null,
        't' => isset($e['temp_surface']) ? round($e['temp_surface']['value'], 1) : null,
    ];
    if ($n % 25 === 0) { out("  $n / " . count($cells) . " (fetched $fetched, failed $failed)"); }
}

$dir = __DIR__ . '/../public/static';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }
$file = "$dir/worldscan__ref-$refSlug.json";
file_put_contents($file, json_encode([
    'reference' => $refSlug, 'step' => $step, 'generated' => gmdate('Y-m-d'), 'cells' => $rows,
    'note' => 'Each cell is scored on pressure and surface temperature only (2 of 4 ESI parameters): the reference has no solar value yet and a bare cell has no impact geology. Cell elevation is the NASA POWER cell mean. Ocean cells are not removed here.',
], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
out('Saved ' . count($rows) . " cells to public/static/worldscan__ref-$refSlug.json (failed: $failed).");
exit($failed > count($cells) / 4 ? 1 : 0);

<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/Fingerprint.php';
require __DIR__ . '/../../src/MeasurementWriter.php';
require __DIR__ . '/../../src/PowerImporter.php';

/**
 * GET /api/probe.php?lat=38.1&lon=73.5&ref=gale
 *
 * "Analyze any point": takes the NASA POWER climate of the grid cell around the clicked point
 * (cached in MySQL, so a cell is requested from NASA only once), then compares it with a
 * Mars / Moon reference site and with the known Earth analogs.
 * Nothing is saved to the measurements table: a clicked point is not a research site.
 */
api(function (Repository $repo): array {
    [$lat, $lon] = Validator::latLon($_GET['lat'] ?? null, $_GET['lon'] ?? null);
    $ref = requireLocation($repo, Validator::slug($_GET['ref'] ?? null, 'ref'), 'reference');

    // Snap to the centre of the MERRA-2 cell (0.5 deg latitude, 0.625 deg longitude).
    $cellLat = max(-90.0, min(90.0, round($lat / 0.5) * 0.5));
    $cellLon = round(-180 + round(($lon + 180) / 0.625) * 0.625, 3);
    if ($cellLon > 180) { $cellLon -= 360; }

    $pdo = Db::pdo();
    $st = $pdo->prepare('SELECT payload FROM probe_cache WHERE lat = ? AND lon = ?');
    $st->execute([$cellLat, $cellLon]);
    $payload = $st->fetchColumn();
    $cached = $payload !== false;
    $data = $cached ? json_decode((string)$payload, true) : null;

    if (!is_array($data)) {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if (!RateLimit::hit('probe:' . $ip, 8, 60)) {
            header('Retry-After: 30');
            Response::error('Too many new points in a minute. Please wait a little (points already seen are instant).', 429);
        }
        try {
            $importer = new PowerImporter(null, [PowerImporter::class, 'httpGetFast']);
            $p = $importer->readPoint($cellLat, $cellLon);
        } catch (RuntimeException $e) {
            error_log('[pamir-to-mars] probe: ' . $e->getMessage());
            Response::error('NASA POWER has no usable data for this point right now. Try another place or try again.', 502);
        }
        $annual = [];
        foreach ($p['rows'] as [$param, $month, $value, $type, $period, $note]) {
            if ($month === 0) {
                $annual[$param] = ['value' => $value, 'data_type' => $type, 'period' => $period, 'note' => $note];
            }
        }
        $data = ['elevation' => $p['elevation'], 'values' => $annual];
        $ins = $pdo->prepare('INSERT INTO probe_cache (lat, lon, payload) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = CURRENT_TIMESTAMP');
        $ins->execute([$cellLat, $cellLon, json_encode($data)]);
    }

    // Values in the same shape as Repository::bestValues(), so the normal ESI code can be used.
    $source = null;
    foreach ($repo->sources() as $s) {
        if ($s['code'] === 'nasa_power') { $source = $s; }
    }
    $eValues = [];
    foreach ($data['values'] as $code => $v) {
        $eValues[$code] = [
            'code' => $code, 'month' => 0, 'value' => (float)$v['value'], 'data_type' => $v['data_type'],
            'period' => $v['period'], 'note' => $v['note'],
            'source_code' => 'nasa_power', 'source_name' => $source['name'] ?? 'NASA POWER',
            'source_url' => $source['url'] ?? null, 'annual_fallback' => false,
        ];
    }

    $params = $repo->parameters();
    $svc = new EsiService($repo);
    $esi = $svc->computeFromValues('probe', $eValues, $ref, [], 0, $params);

    $shown = [];
    foreach ($params as $p) {
        $shown[] = ['code' => $p['code'], 'name' => $p['name'], 'unit' => $p['unit'],
                    'in_esi' => $p['esi_method'] !== 'none', 'value' => $svc->publicValue($eValues[$p['code']] ?? null)];
    }

    // Climate fingerprint against the known analogs (same method as fingerprint.php).
    $places = array_merge($repo->locations('site', 'earth'), $repo->locations('known_analog', 'earth'));
    $vectors = ['probe' => []];
    foreach (Fingerprint::FEATURES as $f) { $vectors['probe'][$f] = $eValues[$f]['value'] ?? null; }
    $names = []; $analogs = [];
    foreach ($places as $pl) {
        $vals = $repo->bestValues($pl['id'], 0);
        foreach (Fingerprint::FEATURES as $f) { $vectors[$pl['slug']][$f] = $vals[$f]['value'] ?? null; }
        $names[$pl['slug']] = $pl['name'];
        if ($pl['kind'] === 'known_analog') { $analogs[] = $pl['slug']; }
    }
    $fp = Fingerprint::nearest('probe', $vectors, $analogs);
    $neighbours = [];
    if ($fp !== null) {
        foreach ($fp['neighbours'] as $n) { $n['name'] = $names[$n['slug']]; $neighbours[] = $n; }
    }

    $elev = $data['elevation'];
    $notes = [
        'Climate is the NASA POWER grid-cell average (about 55 km wide), so a mountain point is averaged with its valleys.',
        'Impact geology is unknown for a clicked point, so it is left out of the index (completeness shows this).',
    ];
    if ($elev !== null && $elev <= 0) {
        $notes[] = 'The cell mean elevation is 0 m or below: this is probably sea or a very low area, and the result is not meaningful for a Mars comparison.';
    }

    return [
        'point' => ['clicked_lat' => $lat, 'clicked_lon' => $lon, 'cell_lat' => $cellLat, 'cell_lon' => $cellLon,
                    'cell_elevation_m' => $elev, 'cached' => $cached],
        'reference' => $ref['slug'],
        'esi' => $esi,
        'parameters' => $shown,
        'fingerprint' => ['available' => $fp !== null, 'neighbours' => $neighbours,
                          'places_used' => $fp['places_used'] ?? 0],
        'notes' => $notes,
        'disclaimer' => EsiService::DISCLAIMER,
    ];
});

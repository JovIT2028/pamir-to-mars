<?php
declare(strict_types=1);
/**
 * Saves a backup copy of every API answer the website needs, as JSON files in public/static/.
 * If the server or database is down during the demo, app.js reads these files instead.
 *
 *   php scripts/export_static.php                      (asks http://pamir-to-mars)
 *   php scripts/export_static.php --base=http://localhost
 *
 * File names follow app.js: "<endpoint>__<key>-<value>_<key>-<value>.json"
 * with every character other than A-Z a-z 0-9 _ - replaced by "_".
 */
require __DIR__ . '/_cli.php';

$base = rtrim((string)opt('base', 'http://pamir-to-mars'), '/');
$dir  = __DIR__ . '/../public/static';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) { out('Cannot create ' . $dir); exit(1); }
// Remove only the files this script writes; the world scan (worldscan__*.json) is made by scan_world.php and kept.
foreach (glob($dir . '/*.json') ?: [] as $old) {
    if (strpos(basename($old), 'worldscan__') !== 0) { unlink($old); }
}

$repo  = new Repository(Db::pdo());
$sites = array_column($repo->locations('site', 'earth'), 'slug');
$refs  = array_column($repo->locations('reference'), 'slug');

$calls = [['sources', []], ['locations', ['kind' => 'site']], ['locations', ['kind' => 'reference']], ['locations', ['kind' => 'known_analog']]];
foreach ($sites as $s) {
    $calls[] = ['location', ['slug' => $s]];
    $calls[] = ['fingerprint', ['earth' => $s]];
    foreach ($refs as $r) {
        foreach (['compare', 'esi', 'seasons'] as $ep) { $calls[] = [$ep, ['earth' => $s, 'ref' => $r]]; }
    }
}
$analogs = array_column($repo->locations('known_analog', 'earth'), 'slug');
foreach ($analogs as $a) { $calls[] = ['location', ['slug' => $a]]; }
foreach ($refs as $r) {
    $calls[] = ['location', ['slug' => $r]];
    $calls[] = ['heatmap', ['ref' => $r]];
}

$ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
$ok = 0; $bad = 0;
foreach ($calls as [$ep, $params]) {
    $qs  = http_build_query($params);
    $url = "$base/api/$ep.php" . ($qs !== '' ? "?$qs" : '');
    $body = @file_get_contents($url, false, $ctx);
    $json = $body === false ? null : json_decode($body, true);
    if (!is_array($json) || isset($json['error'])) {
        out("FAILED $url" . (is_array($json) && isset($json['error']) ? ': ' . $json['error'] : ''));
        $bad++;
        continue;
    }
    $pairs = [];
    foreach ($params as $k => $v) { $pairs[] = "$k-$v"; }
    $key = $ep . ($pairs ? '__' . implode('_', $pairs) : '');
    $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.json';
    file_put_contents("$dir/$name", json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    $ok++;
}
out("Saved $ok files to public/static/" . ($bad ? ", $bad failed" : ''));
exit($bad ? 1 : 0);

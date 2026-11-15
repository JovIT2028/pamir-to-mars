<?php
declare(strict_types=1);
/**
 * Builds the static demo for GitHub Pages into dist/ (not committed).
 * No PHP or MySQL there, so: api/ and admin/ are left out, the saved JSON copy (public/static) is used,
 * and config.js switches the app to static mode.
 *
 *   php scripts/export_static.php     (refresh the saved copy first)
 *   php scripts/build_pages.php
 */
$root = dirname(__DIR__);
$src  = $root . '/public';
$dst  = $root . '/dist';
$skip = ['api', 'admin', '.htaccess'];

function rrmdir(string $d): void
{
    if (!is_dir($d)) { return; }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        is_dir("$d/$f") ? rrmdir("$d/$f") : unlink("$d/$f");
    }
    @rmdir($d);
}
function rcopy(string $from, string $to, array $skip): int
{
    @mkdir($to, 0755, true);
    $n = 0;
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..' || in_array($f, $skip, true)) { continue; }
        if (is_dir("$from/$f")) { $n += rcopy("$from/$f", "$to/$f", []); }
        else { copy("$from/$f", "$to/$f"); $n++; }
    }
    return $n;
}

rrmdir($dst);
$n = rcopy($src, $dst, $skip);
file_put_contents($dst . '/assets/js/config.js',
    "/* Static demo build: the app reads static/*.json instead of api/*.php. */\nwindow.P2M_STATIC = true;\n");
file_put_contents($dst . '/.nojekyll', '');
echo "Built $n files into dist/ (static demo, no PHP).\n";

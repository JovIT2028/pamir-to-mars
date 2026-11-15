<?php
declare(strict_types=1);
/**
 * Loads values prepared by hand from other sources (MODIS via AppEEARS, LRO Diviner, SRTM ...).
 *
 *   php scripts/import_csv.php data/my_values.csv
 *
 * CSV columns (first line is the header):
 *   location_slug,parameter_code,source_code,month,value,data_type,period,note
 * month: 0 = annual / long-term, 1-12 = month. See data/example_measurements.csv.
 * Every row is checked. If one row is wrong, NOTHING is saved (all or nothing).
 */
require __DIR__ . '/_cli.php';

$file = $argv[1] ?? null;
if ($file === null || !is_readable($file)) { out('Usage: php scripts/import_csv.php <file.csv>'); exit(1); }

$pdo = Db::pdo();
$repo = new Repository($pdo);
$writer = new MeasurementWriter($pdo);

$h = fopen($file, 'r');
$header = fgetcsv($h, null, ',', '"', '\\');
$expected = ['location_slug', 'parameter_code', 'source_code', 'month', 'value', 'data_type', 'period', 'note'];
if ($header === false || array_map('trim', $header) !== $expected) {
    out('Wrong header. Expected: ' . implode(',', $expected)); exit(1);
}

$rows = []; $errors = []; $line = 1; $sourcesUsed = [];
while (($c = fgetcsv($h, null, ',', '"', '\\')) !== false) {
    $line++;
    if ($c === [null] || count(array_filter($c, fn($x) => trim((string)$x) !== '')) === 0) { continue; }
    if (count($c) !== 8) { $errors[] = "line $line: expected 8 columns"; continue; }
    [$slug, $param, $source, $month, $value, $type, $period, $note] = array_map('trim', $c);
    $loc = preg_match('/^[a-z0-9_-]{1,60}$/', $slug) ? $repo->location($slug) : null;
    if ($loc === null)                         { $errors[] = "line $line: unknown location '$slug'"; continue; }
    if (!$writer->hasParameter($param))        { $errors[] = "line $line: unknown parameter '$param'"; continue; }
    if (!$writer->hasSource($source))          { $errors[] = "line $line: unknown source '$source'"; continue; }
    if (!preg_match('/^\d{1,2}$/', $month) || (int)$month > 12) { $errors[] = "line $line: month must be 0-12"; continue; }
    if (!is_numeric($value) || !is_finite((float)$value))       { $errors[] = "line $line: value is not a number (leave missing rows out)"; continue; }
    if (!in_array($type, MeasurementWriter::DATA_TYPES, true))  { $errors[] = "line $line: unknown data_type '$type'"; continue; }
    if ($period === '')                        { $errors[] = "line $line: period is required (which dates does the value cover?)"; continue; }
    $rows[] = [$loc['id'], $param, $source, (int)$month, (float)$value, $type, $period, $note === '' ? null : $note];
    $sourcesUsed[$source] = ($sourcesUsed[$source] ?? 0) + 1;
}
fclose($h);

if ($errors) {
    foreach ($errors as $e) { out("ERROR $e"); }
    out('Nothing was saved. Fix the file and run again.');
    exit(1);
}
$pdo->beginTransaction();
foreach ($rows as $r) { $writer->save(...$r); }
foreach ($sourcesUsed as $src => $n) { $writer->log($src, 'ok', $n, 'CSV import: ' . basename($file)); }
$pdo->commit();
out('Saved ' . count($rows) . ' values.');

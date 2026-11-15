<?php
declare(strict_types=1);

// Shared start of every command-line script.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs only from the command line.');
}
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/MeasurementWriter.php';
require __DIR__ . '/../src/PowerImporter.php';
ini_set('display_errors', '1');

function out(string $line): void { fwrite(STDOUT, $line . PHP_EOL); }

/** Reads --name=value options. */
function opt(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, "--$name=")) { return substr($a, strlen($name) + 3); }
    }
    return $default;
}

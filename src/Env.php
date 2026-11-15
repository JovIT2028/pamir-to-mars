<?php
declare(strict_types=1);

require_once __DIR__ . '/compat.php';

/**
 * Reads the .env file from the project root.
 * Why: passwords and keys must never be written in code that goes to GitHub.
 */
final class Env
{
    private static ?array $values = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$values === null) {
            self::$values = self::load(dirname(__DIR__) . '/.env');
        }
        $fromServer = getenv($key);
        if ($fromServer !== false && $fromServer !== '') {
            return $fromServer;
        }
        return self::$values[$key] ?? $default;
    }

    private static function load(string $path): array
    {
        $values = [];
        if (!is_readable($path)) {
            return $values;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $values[trim($k)] = trim(trim($v), "\"'");
        }
        return $values;
    }
}

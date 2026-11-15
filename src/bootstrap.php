<?php
declare(strict_types=1);

/**
 * Loaded first by every API file and every script.
 * It loads the classes and makes sure errors never leak passwords or paths.
 */
require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Esi.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/EsiService.php';
require_once __DIR__ . '/RateLimit.php';
require_once __DIR__ . '/Auth.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');          // errors go to the log, never to the visitor
ini_set('log_errors', '1');

/**
 * Wraps an API endpoint: only GET, validation errors become 400, anything else 500.
 */
function api(callable $handler): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        Response::error('Only GET is supported.', 405);
    }
    try {
        $repo = new Repository(Db::pdo());
        Response::json($handler($repo));
    } catch (ValidationException $e) {
        Response::error($e->getMessage(), 400);
    } catch (NotFoundException $e) {
        Response::error($e->getMessage(), 404);
    } catch (Throwable $e) {
        error_log('[pamir-to-mars] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $debug = Env::get('APP_DEBUG', '0') === '1';
        Response::error($debug ? 'Server error: ' . $e->getMessage() : 'Server error. Please try again later.', 500);
    }
}

final class NotFoundException extends RuntimeException {}

/** Finds a location by slug with the required body, or stops with 404. */
function requireLocation(Repository $repo, string $slug, ?string $mustBeEarth = null): array
{
    $loc = $repo->location($slug);
    if ($loc === null) {
        throw new NotFoundException("Location '$slug' was not found.");
    }
    if ($mustBeEarth === 'earth' && $loc['body'] !== 'earth') {
        throw new ValidationException("'$slug' is not an Earth location.");
    }
    if ($mustBeEarth === 'reference' && $loc['kind'] !== 'reference') {
        throw new ValidationException("'$slug' is not a Mars or Moon reference site.");
    }
    return $loc;
}

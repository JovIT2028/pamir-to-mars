<?php
declare(strict_types=1);
/**
 * Creates (or resets the password of) an admin user. Run it yourself in a terminal:
 *   php scripts/create_admin.php admin
 * The password is typed here and stored only as a bcrypt hash. It is never written to a file.
 */
require __DIR__ . '/_cli.php';

$username = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9_.-]{3,40}$/', $username)) {
    out('Usage: php scripts/create_admin.php <username>   (3-40 characters: a-z 0-9 _ . -)');
    exit(1);
}
fwrite(STDOUT, "Password for '$username' (at least 12 characters; it is visible while you type): ");
$pw = trim((string)fgets(STDIN));
if (strlen($pw) < 12) { out('Too short: use at least 12 characters.'); exit(1); }

Db::pdo()->prepare(
    'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), failed_attempts = 0, locked_until = NULL'
)->execute([$username, password_hash($pw, PASSWORD_DEFAULT)]);
out("Admin '$username' is saved. Open http://pamir-to-mars/admin/");

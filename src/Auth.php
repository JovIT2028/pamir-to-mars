<?php
declare(strict_types=1);

/**
 * Admin login: sessions, password_hash, lock after wrong passwords, CSRF tokens.
 * Used only by public/admin/. The public API never starts a session.
 */
final class Auth
{
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 10;
    public const IDLE_SECONDS = 3600;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('p2m_admin');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        if (isset($_SESSION['last']) && time() - (int)$_SESSION['last'] > self::IDLE_SECONDS) {
            $_SESSION = [];
        }
        $_SESSION['last'] = time();
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
    }

    /** @return string|null an error message, or null when the login worked */
    public static function login(PDO $pdo, string $username, string $password, string $ip): ?string
    {
        if (!RateLimit::hit('login:' . $ip, 10, 300)) {
            return 'Too many attempts. Please wait a few minutes.';
        }
        $st = $pdo->prepare('SELECT id, password_hash, (locked_until IS NOT NULL AND locked_until > NOW()) AS locked FROM admin_users WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        // Always verify a hash, so a wrong username takes as long as a wrong password.
        $ok = password_verify($password, $u ? (string)$u['password_hash'] : password_hash('not-a-real-password', PASSWORD_DEFAULT));
        $generic = 'Wrong username or password.';
        if (!$u) {
            return $generic;
        }
        if ((int)$u['locked'] === 1) {
            return 'This account is locked for a few minutes after too many wrong passwords.';
        }
        if (!$ok) {
            $pdo->prepare('UPDATE admin_users SET failed_attempts = failed_attempts + 1,
                           locked_until = IF(failed_attempts >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until) WHERE id = ?')
                ->execute([self::MAX_FAILS, self::LOCK_MINUTES, $u['id']]);
            return $generic;
        }
        $pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$u['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf'];
    }

    public static function csrfOk($token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $token);
    }
}

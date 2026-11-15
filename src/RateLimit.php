<?php
declare(strict_types=1);

/**
 * Tiny file-based rate limit: at most $max hits per $seconds for one key (an IP address).
 * Used before every request that calls NASA for a visitor, so nobody can use us to flood NASA.
 */
final class RateLimit
{
    public static function hit(string $key, int $max, int $seconds): bool
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p2m_rl_' . hash('sha256', $key) . '.json';
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return true; // cannot store the counter: do not block real visitors
        }
        flock($fh, LOCK_EX);
        $now  = time();
        $list = json_decode((string)stream_get_contents($fh), true);
        $list = is_array($list) ? array_values(array_filter($list, function ($t) use ($now, $seconds) {
            return is_int($t) && $t > $now - $seconds;
        })) : [];
        $allowed = count($list) < $max;
        if ($allowed) {
            $list[] = $now;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($list));
        flock($fh, LOCK_UN);
        fclose($fh);
        return $allowed;
    }
}

<?php
declare(strict_types=1);

namespace SyncCampus;

/**
 * Slows down guessing of access codes and staff passwords: after five wrong
 * attempts on the same reference or email, further tries wait one minute.
 */
final class Throttle
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 60;
    private const FORGET_SECONDS = 900;

    public static function check(string $key): void
    {
        $until = Db::value('SELECT locked_until FROM request_throttle WHERE throttle_key = ?', [$key]);
        if ($until !== null && strtotime($until . ' UTC') > time()) {
            throw new HttpError(429, 'Too many wrong attempts. Please wait a minute and try again.');
        }
    }

    public static function fail(string $key): void
    {
        $row = Db::one('SELECT attempts, updated_at FROM request_throttle WHERE throttle_key = ?', [$key]);
        // Old attempts are forgotten after a quiet spell, so honest users are never locked out for good.
        $stale = $row !== null && strtotime($row['updated_at'] . ' UTC') < time() - self::FORGET_SECONDS;
        $attempts = ($row === null || $stale ? 0 : (int) $row['attempts']) + 1;
        if ($row !== null && $attempts === 1) {
            Db::run('UPDATE request_throttle SET attempts = 1, locked_until = NULL, updated_at = ? WHERE throttle_key = ?', [Time::now(), $key]);
            return;
        }
        $lockedUntil = $attempts >= self::MAX_ATTEMPTS ? Time::now(self::LOCK_SECONDS) : null;
        if ($attempts === 1) {
            Db::run(
                'INSERT INTO request_throttle (throttle_key, attempts, locked_until, updated_at) VALUES (?, 1, NULL, ?)',
                [$key, Time::now()]
            );
            return;
        }
        Db::run(
            'UPDATE request_throttle SET attempts = ?, locked_until = ?, updated_at = ? WHERE throttle_key = ?',
            [$attempts, $lockedUntil, Time::now(), $key]
        );
    }

    public static function clear(string $key): void
    {
        Db::run('DELETE FROM request_throttle WHERE throttle_key = ?', [$key]);
    }
}

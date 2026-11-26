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

    public static function check(string $key): void
    {
        $until = Db::value('SELECT locked_until FROM request_throttle WHERE throttle_key = ?', [$key]);
        if ($until !== null && strtotime($until . ' UTC') > time()) {
            throw new HttpError(429, 'Too many wrong attempts. Please wait a minute and try again.');
        }
    }

    public static function fail(string $key): void
    {
        $attempts = (int) Db::value('SELECT attempts FROM request_throttle WHERE throttle_key = ?', [$key]) + 1;
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

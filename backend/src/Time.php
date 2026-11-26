<?php
declare(strict_types=1);

namespace SyncCampus;

/** Every timestamp in the database is UTC, stored as 'Y-m-d H:i:s'. */
final class Time
{
    public static function now(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    /** Database timestamp -> ISO 8601 for the browser. */
    public static function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return gmdate('c', strtotime($value . ' UTC'));
    }

    public static function hoursSince(string $value): float
    {
        return (time() - strtotime($value . ' UTC')) / 3600;
    }

    public static function hoursBetween(string $from, string $to): float
    {
        return (strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 3600;
    }

    /** ISO date from a form (any timezone) -> database timestamp, or null. */
    public static function fromInput(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $stamp = strtotime((string) $value);
        if ($stamp === false) {
            throw new HttpError(422, 'Dates must use ISO 8601 format');
        }
        return gmdate('Y-m-d H:i:s', $stamp);
    }
}

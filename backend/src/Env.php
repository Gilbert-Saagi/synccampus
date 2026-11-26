<?php
declare(strict_types=1);

namespace SyncCampus;

/**
 * Reads settings from real environment variables, falling back to backend/.env
 * for local development. Hosting panels (Render, cPanel) set real variables,
 * and those always take priority over the file.
 */
final class Env
{
    private static array $fromFile = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            self::$fromFile[$key] = trim($value, "\"'");
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_SERVER[$key] ?? self::$fromFile[$key] ?? '';
        }
        return $value === '' ? $default : (string) $value;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'development') === 'production';
    }
}

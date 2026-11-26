<?php
declare(strict_types=1);

// Shared start-up for the web entry point and the command-line scripts in bin/.

date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $prefix = 'SyncCampus\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

SyncCampus\Env::load(dirname(__DIR__) . '/.env');

if (SyncCampus\Env::get('APP_ENV', 'development') === 'production') {
    ini_set('display_errors', '0');
}

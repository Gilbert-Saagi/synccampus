<?php
declare(strict_types=1);

// Single entry point for every /api request.

use SyncCampus\HttpError;
use SyncCampus\Request;
use SyncCampus\Response;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $router = require dirname(__DIR__) . '/src/routes.php';
    $response = $router->dispatch(Request::fromGlobals());
} catch (HttpError $e) {
    $response = new Response(['detail' => $e->getMessage()], $e->status);
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    $response = new Response(['detail' => 'Database error'], 500);
} catch (Throwable $e) {
    error_log($e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $response = new Response(['detail' => 'Internal server error'], 500);
}

$response->send();

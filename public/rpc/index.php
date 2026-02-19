<?php

/**
 * RPC Entry Point
 *
 * All API requests go through this endpoint.
 * Request format: POST with JSON body containing method and params.
 */

declare(strict_types=1);

use App\Rpc\RPC;

require_once __DIR__ . '/../../vendor/autoload.php';

// Determine which .env file to load based on APP_ENV
$env = getenv('APP_ENV') ?: 'dev';
$envFile = '.env.' . $env;

// Always load base .env first if it exists
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

// Then load environment-specific .env file if it exists
$dotenvEnv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2), $envFile);
$dotenvEnv->safeLoad();

header('Content-Type: application/json');

// Handle CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$rpc = new RPC();
echo $rpc->handle();
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Set up test environment
$_ENV['DB_DRIVER'] = 'sqlite';
$_ENV['INTERNAL_API_KEY'] = 'test-api-key-12345';

// Create a fresh test database
$testDbPath = __DIR__ . '/../data/database.sqlite';

// Remove existing test database
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

// Initialize the database
use App\Lib\DB;

$pdo = DB::connection();

// Create schema
$schema = <<<SQL
CREATE TABLE vehicles (
    id INTEGER PRIMARY KEY,
    vehicle_number VARCHAR(20) NOT NULL UNIQUE,
    mapon_unit_id INTEGER,
    created_at TEXT
);

CREATE TABLE transactions (
    id INTEGER PRIMARY KEY,
    vehicle_number VARCHAR(20) NOT NULL,
    card_number VARCHAR(50),
    transaction_date TEXT NOT NULL,
    station_name VARCHAR(255),
    station_country VARCHAR(10),
    product_type VARCHAR(50) NOT NULL,
    quantity DECIMAL(10, 3) NOT NULL,
    unit VARCHAR(10) DEFAULT 'L',
    unit_price DECIMAL(10, 4),
    total_amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'EUR',
    original_currency VARCHAR(3),
    original_amount DECIMAL(10, 2),
    mapon_unit_id INTEGER,
    enrichment_status VARCHAR(20) DEFAULT 'pending',
    gps_latitude DECIMAL(10, 7),
    gps_longitude DECIMAL(10, 7),
    odometer_gps INTEGER,
    enriched_at TEXT,
    import_batch_id VARCHAR(100),
    created_at TEXT,
    updated_at TEXT
)
SQL;

$pdo->exec($schema);

$pdo->exec("INSERT INTO vehicles (vehicle_number, mapon_unit_id, created_at) VALUES ('NJ-2702', 417038, datetime())");
$pdo->exec("INSERT INTO vehicles (vehicle_number, mapon_unit_id, created_at) VALUES ('OC-4485', 199332, datetime())");

#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Lib\DB;

echo "Setting up Fuel API database...\n";

try {
    $pdo = DB::connection();

    $statements = array_filter(
        array_map('trim', explode(';', getSchema())),
        fn($s) => !empty($s)
    );

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    echo "Schema created successfully.\n";

    seedVehicles();

    echo "\nSetup complete! Run 'php -S localhost:8000 -t public public/router.php' to start the development server.\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

function getSchema(): string
{
    // Read schema from ../schema.sql
    $schemaPath = dirname(__DIR__) . '/schema.sql';
    if (!file_exists($schemaPath)) {
        throw new RuntimeException("Schema file not found: $schemaPath");
    }
    return file_get_contents($schemaPath);

}

function seedVehicles(): void
{
    $pdo = DB::connection();

    $vehicles = [
        ['vehicle_number' => 'NJ-2702', 'mapon_unit_id' => 417038],
        ['vehicle_number' => 'OC-4485', 'mapon_unit_id' => 199332],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO vehicles (vehicle_number, mapon_unit_id, created_at) VALUES (:vehicle_number, :mapon_unit_id, :created_at)'
    );

    foreach ($vehicles as $v) {
        $stmt->execute([
            'vehicle_number' => $v['vehicle_number'],
            'mapon_unit_id' => $v['mapon_unit_id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    echo "Inserted " . count($vehicles) . " vehicles.\n";
}

<?php

declare(strict_types=1);

namespace App\Domain\Mapon;

/**
 * Data transfer object for Mapon unit GPS data.
 */
readonly class MaponUnitData
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?int $odometer,
        public string $datetime,
    ) {}
}

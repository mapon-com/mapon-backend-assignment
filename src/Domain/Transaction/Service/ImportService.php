<?php

declare(strict_types=1);

namespace App\Domain\Transaction\Service;

use App\Domain\Transaction\DTO\ImportResult;
use App\Domain\Transaction\Repository\TransactionRepository;
use App\Lib\Transaction;
use App\Lib\Vehicle;
use Exception;
use InvalidArgumentException;
use Random\RandomException;

/**
 * Service for importing fuel transactions from CSV.
 *
 * Expected CSV format (fuel card provider export):
 *   Date,Time,Card Nr.,Vehicle Nr.,Product,Amount,Total sum,Currency,Country,Country ISO,Fuel station
 */
class ImportService
{
    private TransactionRepository $repository;

    private const FUEL_PRODUCTS = [
        'Diesel',
        'AdBlue',
        'Super 98',
        'CNG',
        'Super 95',
        'Fuel',
    ];

    public function __construct()
    {
        $this->repository = new TransactionRepository();
    }

    /**
     * Import transactions from CSV data.
     * @throws RandomException
     */
    public function importFromCsv(string $csvData): ImportResult
    {
        $batchId = $this->generateBatchId();
        $lines = explode("\n", trim($csvData));

        if (count($lines) < 2) {
            return new ImportResult(0, 0, 0, ['CSV must contain header and at least one data row'], $batchId);
        }

        // Parse header
        $header = str_getcsv(array_shift($lines), ',', '"', '');
        $headerMap = array_flip(array_map('trim', $header));

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($lines as $lineNum => $line) {
            if (empty(trim($line))) {
                continue;
            }

            $rowNum = $lineNum + 2; // +2 because header is line 1, and we shifted it

            try {
                $row = str_getcsv($line, ',', '"', '');
                $result = $this->parseRow($row, $headerMap, $batchId);

                if ($result === null) {
                    // Non-fuel product, skip
                    $skipped++;
                    continue;
                }

                $this->repository->save($result);
                $imported++;
            } catch (Exception $e) {
                $failed++;
                $errors[] = "Row $rowNum: " . $e->getMessage();
            }
        }

        return new ImportResult($imported, $skipped, $failed, $errors, $batchId);
    }

    /**
     * Parse a CSV row into a Transaction model.
     * Returns null if the product should be skipped (non-fuel).
     */
    private function parseRow(array $row, array $headerMap, string $batchId): ?Transaction
    {
        // Get raw product first to check if we should skip
        $rawProduct = $this->getFieldOrDefault($row, $headerMap, 'Product', '');
        if (!$this->isFuelProduct($rawProduct)) {
            return null;
        }

        $transaction = new Transaction();
        $transaction->import_batch_id = $batchId;
        $transaction->created_at = date('Y-m-d H:i:s');
        $transaction->enrichment_status = Transaction::ENRICHMENT_PENDING;

        // Parse date and time (European format: DD.MM.YYYY and HH:MM:SS)
        $date = $this->getField($row, $headerMap, 'Date');
        $time = $this->getField($row, $headerMap, 'Time');
        $transaction->transaction_date = $this->parseDateTime($date, $time);

        // Vehicle and card
        $vehicleNumber = $this->getFieldOrNull($row, $headerMap, 'Vehicle Nr.');
        if (empty($vehicleNumber)) {
            throw new InvalidArgumentException('Missing vehicle number');
        }
        $transaction->vehicle_number = $vehicleNumber;
        $transaction->card_number = $this->getFieldOrNull($row, $headerMap, 'Card Nr.');

        // Look up Mapon unit ID from vehicles table
        $transaction->mapon_unit_id = Vehicle::getMaponUnitId($vehicleNumber);

        // Product type mapping
        $transaction->product_type = $this->mapProductType($rawProduct);

        // Amount (European decimal format: comma as separator)
        $rawAmount = $this->getField($row, $headerMap, 'Amount');
        $transaction->quantity = $this->parseEuropeanNumber($rawAmount);

        // Determine unit based on product type
        $transaction->unit = $this->determineUnit($transaction->product_type);

        // Total sum and currency
        $rawTotal = $this->getField($row, $headerMap, 'Total sum');
        $rawCurrency = $this->getFieldOrDefault($row, $headerMap, 'Currency', 'EUR');

        $transaction->original_amount = $this->parseEuropeanNumber($rawTotal);
        $transaction->original_currency = $rawCurrency;

        // Convert to EUR if needed
        $converted = $this->convertToEur($transaction->original_amount, $rawCurrency);
        $transaction->total_amount = $converted;
        $transaction->currency = 'EUR';

        // Calculate unit price
        if ($transaction->quantity > 0) {
            $transaction->unit_price = round($transaction->total_amount / $transaction->quantity, 4);
        }

        // Station info
        $transaction->station_name = $this->getFieldOrNull($row, $headerMap, 'Fuel station');
        $transaction->station_country = $this->getFieldOrNull($row, $headerMap, 'Country ISO')
            ?? $this->getFieldOrNull($row, $headerMap, 'Country');

        return $transaction;
    }

    /**
     * Parse European date (DD.MM.YYYY) and time (HH:MM:SS) into ISO format.
     */
    private function parseDateTime(string $date, string $time): string
    {
        // Handle DD.MM.YYYY format
        $parts = explode('.', $date);
        if (count($parts) === 3) {
            $date = "$parts[2]-$parts[1]-$parts[0]";
        }

        return "$date $time";
    }

    /**
     * Parse European number format (comma as decimal separator).
     */
    private function parseEuropeanNumber(string $value): float
    {
        $value = preg_replace('/\s/', '', $value); // Remove any thousand separators
        $value = str_replace(',', '.', $value); // Replace comma with dot for decimal

        return (float) $value;
    }

    /**
     * Check if a product is non-fuel and should be skipped.
     */
    private function isFuelProduct(string $product): bool
    {
        $normalizedProduct = strtolower(trim($product));

        foreach (self::FUEL_PRODUCTS as $fuel) {
            $normalizedFuel = strtolower(trim($fuel));

            if (str_contains($normalizedProduct, $normalizedFuel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine the unit of measurement based on product type.
     */
    private function determineUnit(string $productType): string
    {
        return match ($productType) {
            'electric' => 'kWh',
            'cng' => 'kg',
            default => 'L',
        };
    }

    private function getField(array $row, array $headerMap, string $field): string
    {
        if (!isset($headerMap[$field])) {
            throw new InvalidArgumentException("Missing required column: $field");
        }

        $index = $headerMap[$field];
        if (!isset($row[$index]) || trim($row[$index]) === '') {
            throw new InvalidArgumentException("Missing value for: $field");
        }

        return trim($row[$index]);
    }

    private function getFieldOrNull(array $row, array $headerMap, string $field): ?string
    {
        if (!isset($headerMap[$field])) {
            return null;
        }

        $index = $headerMap[$field];
        $value = $row[$index] ?? '';

        return trim($value) !== '' ? trim($value) : null;
    }

    private function getFieldOrDefault(array $row, array $headerMap, string $field, string $default): string
    {
        return $this->getFieldOrNull($row, $headerMap, $field) ?? $default;
    }

    /**
     * Map raw product names to standardized types.
     */
    private function mapProductType(string $rawProduct): string
    {
        $normalized = strtolower(trim($rawProduct));

        switch (true) {
            case str_contains($normalized, 'diesel'):
            case str_contains($normalized, 'motorin'):
            case str_contains($normalized, 'gasoil'):
            case str_contains($normalized, 'gas-oil'):
            case str_contains($normalized, 'derv'):
            case $normalized === 'd miles':
            case str_contains($normalized, 'premium diesel'):
                return 'diesel';

            case str_contains($normalized, 'petrol'):
            case str_contains($normalized, 'gasoline'):
            case str_contains($normalized, 'benzin'):
            case str_contains($normalized, 'super'):
            case str_contains($normalized, 'unleaded'):
            case str_contains($normalized, '95'):
            case str_contains($normalized, '98'):
            case str_contains($normalized, 'e5'):
            case str_contains($normalized, 'e10'):
            case str_contains($normalized, 'futura'):
            case $normalized === '95 miles':
            case str_contains($normalized, 'milesplus'):
                return 'petrol';

            case str_contains($normalized, 'lpg'):
            case str_contains($normalized, 'autogas'):
                return 'lpg';

            case str_contains($normalized, 'adblue'):
            case str_contains($normalized, 'ad blue'):
            case str_contains($normalized, 'def'):
            case str_contains($normalized, 'urea'):
                return 'adblue';

            case str_contains($normalized, 'cng'):
            case str_contains($normalized, 'natural gas'):
            case str_contains($normalized, 'biocng'):
                return 'cng';

            case str_contains($normalized, 'electric'):
            case str_contains($normalized, 'charging'):
            case str_contains($normalized, 'ev'):
                return 'electric';

            default:
                return 'other';
        }
    }

    /**
     * Convert amount to EUR.
     */
    private function convertToEur(float $amount, string $currency): float
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return $amount;
        }

        $rates = [
            'USD' => 0.92,
            'GBP' => 1.17,
            'PLN' => 0.22,
            'CZK' => 0.041,
            'SEK' => 0.087,
            'NOK' => 0.086,
            'DKK' => 0.13,
            'CHF' => 1.04,
            'HUF' => 0.0025,
            'RON' => 0.20,
            'BGN' => 0.51,
            'HRK' => 0.13,
            'RUB' => 0.010,
            'UAH' => 0.025,
            'TRY' => 0.028,
        ];

        if (!isset($rates[$currency])) {
            return $amount;
        }

        return round($amount * $rates[$currency], 2);
    }

    /**
     * @throws RandomException
     */
    private function generateBatchId(): string
    {
        return 'import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }
}

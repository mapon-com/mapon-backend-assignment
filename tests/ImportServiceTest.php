<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Transaction\Service\ImportService;
use App\Lib\DB;
use App\Lib\Transaction;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase
{
    private ImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear transactions table before each test
        /** @noinspection SqlWithoutWhere */
        DB::execute('DELETE FROM transactions');

        $this->service = new ImportService();
    }

    public function testImportValidCsv(): void
    {
        $csv = <<<CSV
            Date,Time,Card Nr.,Vehicle Nr.,Product,Amount,Total sum,Currency,Country,Country ISO,Fuel station
            15.01.2025,10:00:00,1234567890123456,NJ-2702,Diesel,"45,50","65,98",EUR,Latvia,LV,Shell Riga
            15.01.2025,14:30:00,1234567890123456,NJ-2702,Super 95,"38,00","57,76",EUR,Latvia,LV,Circle K Riga
            CSV;

        $result = $this->service->importFromCsv($csv);

        $this->assertEquals(2, $result->imported);
        $this->assertEquals(0, $result->failed);
        $this->assertEmpty($result->errors);
        $this->assertStringStartsWith('import_', $result->batchId);

        // Verify transactions were saved
        $transactions = Transaction::getByVehicleNumber('NJ-2702');
        $this->assertCount(2, $transactions);
    }

    public function testImportWithMissingVehicleNumber(): void
    {
        $csv = <<<CSV
            Date,Time,Card Nr.,Vehicle Nr.,Product,Amount,Total sum,Currency,Country,Country ISO,Fuel station
            15.01.2025,10:00:00,1234567890123456,,Diesel,"45,50","65,98",EUR,Latvia,LV,Shell Riga
            CSV;

        $result = $this->service->importFromCsv($csv);

        $this->assertEquals(0, $result->imported);
        $this->assertEquals(1, $result->failed);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('vehicle', strtolower($result->errors[0]));
    }

    public function testImportWithCurrencyConversion(): void
    {
        $csv = <<<CSV
            Date,Time,Card Nr.,Vehicle Nr.,Product,Amount,Total sum,Currency,Country,Country ISO,Fuel station
            15.01.2025,10:00:00,1234567890123456,NJ-2702,Diesel,"50,00","310,00",PLN,Poland,PL,Orlen Warsaw
            CSV;

        $result = $this->service->importFromCsv($csv);

        $this->assertEquals(1, $result->imported);

        // Verify currency was converted
        $transactions = Transaction::getByVehicleNumber('NJ-2702');
        $transaction = $transactions[0];

        $this->assertEquals('EUR', $transaction->currency);
        $this->assertEquals('PLN', $transaction->original_currency);
        $this->assertEquals(310.00, (float) $transaction->original_amount);
        // PLN rate is 0.22, so 310 * 0.22 = 68.20
        $this->assertEquals(68.20, (float) $transaction->total_amount);
    }

    public function testImportProductTypeMapping(): void
    {
        $csv = <<<CSV
            Date,Time,Card Nr.,Vehicle Nr.,Product,Amount,Total sum,Currency,Country,Country ISO,Fuel station
            15.01.2025,10:00:00,1234567890123456,NJ-2702,Diesel,"45,00","65,25",EUR,Latvia,LV,Station 1
            15.01.2025,11:00:00,1234567890123456,NJ-2702,Super 95,"40,00","62,00",EUR,Latvia,LV,Station 2
            15.01.2025,12:00:00,1234567890123456,NJ-2702,AdBlue,"10,00","9,00",EUR,Latvia,LV,Station 3
            15.01.2025,13:00:00,1234567890123456,NJ-2702,Unknown Fuel,"30,00","30,00",EUR,Latvia,LV,Station 4
            CSV;

        $result = $this->service->importFromCsv($csv);

        $this->assertEquals(4, $result->imported);

        $transactions = Transaction::getByVehicleNumber('NJ-2702');

        // Sort by date to ensure consistent order
        usort($transactions, fn($a, $b) => strcmp($a->transaction_date, $b->transaction_date));

        $this->assertEquals('diesel', $transactions[0]->product_type);
        $this->assertEquals('petrol', $transactions[1]->product_type);
        $this->assertEquals('adblue', $transactions[2]->product_type);
        $this->assertEquals('other', $transactions[3]->product_type);
    }

    public function testImportSkipsNonFuelProducts(): void
    {
        $csv = <<<CSV
            Date,Time,Card Nr.,Vehicle Nr.,Product,Amount,Total sum,Currency,Country,Country ISO,Fuel station
            15.01.2025,10:00:00,1234567890123456,NJ-2702,Diesel,"45,00","65,25",EUR,Latvia,LV,Station 1
            15.01.2025,11:00:00,1234567890123456,NJ-2702,Coffee,"1,00","2,50",EUR,Latvia,LV,Station 1
            15.01.2025,12:00:00,1234567890123456,NJ-2702,Car Wash,"1,00","8,00",EUR,Latvia,LV,Station 1
            CSV;

        $result = $this->service->importFromCsv($csv);

        $this->assertEquals(1, $result->imported);
        $this->assertEquals(2, $result->skipped);
    }

    public function testImportEmptyCsv(): void
    {
        $csv = "Date,Time,Card Nr.,Vehicle Nr.,Product,Amount,Total sum,Currency,Country,Country ISO,Fuel station\n";

        $result = $this->service->importFromCsv($csv);

        $this->assertEquals(0, $result->imported);
        $this->assertEquals(0, $result->failed);
    }

    public function testImportHeaderOnlyReturnsError(): void
    {
        $csv = "Date,Time,Card Nr.";

        $result = $this->service->importFromCsv($csv);

        $this->assertEquals(0, $result->imported);
        $this->assertNotEmpty($result->errors);
    }
}

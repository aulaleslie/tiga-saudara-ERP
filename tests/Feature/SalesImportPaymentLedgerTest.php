<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesImportPaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'perdana@example.com',
            'company_phone' => '222',
            'company_address' => 'Perdana Address',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'perdana@example.com',
            'footer_text' => '',
        ]);

        Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Perdana Warehouse',
        ]);

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);
    }

    /** @test */
    public function fully_paid_sales_import_creates_one_active_payment_with_sale_reference(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-001',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-PAY-001')->firstOrFail();

        $this->assertSame('PAID', $sale->payment_status);
        $this->assertEquals(111000.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);
        $this->assertCount(1, $sale->salePayments);
        $this->assertSame($sale->reference, $sale->salePayments->first()->reference);
        $this->assertSame('ACTIVE', $sale->salePayments->first()->status);
        $this->assertSame('CASH', strtoupper((string) $sale->salePayments->first()->paymentMethod?->name));
        $this->assertEquals(111000.0, (float) $sale->salePayments->first()->amount);
    }

    /** @test */
    public function partially_paid_sales_import_creates_one_partial_payment(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-002',
                'pembayaran' => '61000',
                'sisa_tagihan' => '50000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-PAY-002')->firstOrFail();
        $this->assertSame('PARTIAL', $sale->payment_status);
        $this->assertEquals(61000.0, (float) $sale->paid_amount);
        $this->assertEquals(50000.0, (float) $sale->due_amount);
        $this->assertCount(1, $sale->salePayments);
    }

    /** @test */
    public function unpaid_sales_import_creates_no_payment_row(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-003',
                'pembayaran' => '0',
                'sisa_tagihan' => '111000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-PAY-003')->firstOrFail();
        $this->assertSame('UNPAID', $sale->payment_status);
        $this->assertEquals(0.0, (float) $sale->paid_amount);
        $this->assertEquals(111000.0, (float) $sale->due_amount);
        $this->assertCount(0, $sale->salePayments);
    }

    /** @test */
    public function sales_import_prefers_pembayaran_and_today_outstanding_and_falls_back_when_payment_is_blank(): void
    {
        $preferredBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-004',
                'pembayaran' => '20000',
                'sisa_tagihan_hari_ini' => '91000',
                'sisa_tagihan' => '0',
                'source_total' => '111000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($preferredBatch);

        $preferredSale = Sale::where('imported_sales_reference_number', 'INV-PAY-004')->firstOrFail();
        $this->assertSame('PARTIAL', $preferredSale->payment_status);
        $this->assertEquals(20000.0, (float) $preferredSale->paid_amount);
        $this->assertEquals(91000.0, (float) $preferredSale->due_amount);

        $fallbackBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-005',
                'pembayaran' => '',
                'sisa_tagihan_hari_ini' => '10000',
                'sisa_tagihan' => '50000',
                'source_total' => '111000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($fallbackBatch);

        $fallbackSale = Sale::where('imported_sales_reference_number', 'INV-PAY-005')->firstOrFail();
        $this->assertSame('PARTIAL', $fallbackSale->payment_status);
        $this->assertEquals(101000.0, (float) $fallbackSale->paid_amount);
        $this->assertEquals(10000.0, (float) $fallbackSale->due_amount);
    }

    /** @test */
    public function sales_import_applies_repeated_document_discount_once_and_ignores_discount_percent_for_reconciliation(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'JL.2021.17756',
                'produk' => 'TEST PRODUCT A',
                'diskon' => '15000',
                'diskon_document_persen' => '7.26',
                'pembayaran' => '207000',
                'sisa_tagihan' => '0',
                'source_total' => '207000',
            ]),
            $this->baseRow([
                'no_faktur' => 'JL.2021.17756',
                'produk' => 'TEST PRODUCT B',
                'diskon' => '15000',
                'diskon_document_persen' => '7.26',
                'pembayaran' => '207000',
                'sisa_tagihan' => '0',
                'source_total' => '207000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'JL.2021.17756')->firstOrFail();

        $this->assertSame('PAID', $sale->payment_status);
        $this->assertEquals(207000.0, (float) $sale->total_amount);
        $this->assertEquals(15000.0, (float) $sale->discount_amount);
        $this->assertEquals(0.0, (float) $sale->discount_percentage);
        $this->assertEquals(207000.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);
        $this->assertCount(1, $sale->salePayments);
        $this->assertEquals(207000.0, (float) $sale->salePayments->first()->amount);
        $this->assertCount(2, $sale->saleDetails);
        $this->assertEquals(0.0, (float) $sale->saleDetails->first()->product_discount_amount);
        $this->assertDatabaseMissing('sales_import_rows', [
            'batch_id' => $batch->id,
            'status' => SalesImportRow::STATUS_INVALID,
        ]);
    }

    /** @test */
    public function sales_import_applies_repeated_shipping_once_per_invoice_group(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-SHIP-001',
                'produk' => 'TEST PRODUCT A',
                'harga_satuan' => '50000',
                'pajak' => '5500',
                'biaya_pengiriman' => '5000',
                'pembayaran' => '116000',
                'sisa_tagihan' => '0',
                'source_total' => '116000',
            ]),
            $this->baseRow([
                'no_faktur' => 'INV-SHIP-001',
                'produk' => 'TEST PRODUCT B',
                'harga_satuan' => '50000',
                'pajak' => '5500',
                'biaya_pengiriman' => '5000',
                'pembayaran' => '116000',
                'sisa_tagihan' => '0',
                'source_total' => '116000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-SHIP-001')->firstOrFail();

        $this->assertEquals(116000.0, (float) $sale->total_amount);
        $this->assertEquals(5000.0, (float) $sale->shipping_amount);
        $this->assertEquals(116000.0, (float) $sale->paid_amount);
        $this->assertCount(1, $sale->salePayments);
    }

    /** @test */
    public function conflicting_repeated_sales_discount_values_invalidate_the_invoice_group_without_creating_documents_or_payments(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-DISC-CONFLICT-001',
                'produk' => 'TEST PRODUCT A',
                'diskon' => '15000',
                'source_total' => '207000',
            ]),
            $this->baseRow([
                'no_faktur' => 'INV-DISC-CONFLICT-001',
                'produk' => 'TEST PRODUCT B',
                'diskon' => '10000',
                'source_total' => '212000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $this->assertDatabaseMissing('sales', ['imported_sales_reference_number' => 'INV-DISC-CONFLICT-001']);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertTrue(
            SalesImportRow::where('batch_id', $batch->id)
                ->where('status', SalesImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function conflicting_repeated_sales_shipping_values_invalidate_the_invoice_group_without_creating_documents_or_payments(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-SHIP-CONFLICT-001',
                'produk' => 'TEST PRODUCT A',
                'biaya_pengiriman' => '5000',
                'source_total' => '116000',
            ]),
            $this->baseRow([
                'no_faktur' => 'INV-SHIP-CONFLICT-001',
                'produk' => 'TEST PRODUCT B',
                'biaya_pengiriman' => '7000',
                'source_total' => '118000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $this->assertDatabaseMissing('sales', ['imported_sales_reference_number' => 'INV-SHIP-CONFLICT-001']);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertTrue(
            SalesImportRow::where('batch_id', $batch->id)
                ->where('status', SalesImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function conflicting_sales_payment_fields_or_non_reconciling_totals_invalidate_the_invoice_group(): void
    {
        $conflictBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-006',
                'produk' => 'TEST PRODUCT A',
                'pembayaran' => '111000',
                'source_total' => '222000',
            ]),
            $this->baseRow([
                'no_faktur' => 'INV-PAY-006',
                'produk' => 'TEST PRODUCT B',
                'pembayaran' => '110000',
                'source_total' => '222000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($conflictBatch);

        $this->assertDatabaseMissing('sales', ['imported_sales_reference_number' => 'INV-PAY-006']);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertTrue(
            SalesImportRow::where('batch_id', $conflictBatch->id)
                ->where('status', SalesImportRow::STATUS_INVALID)
                ->exists()
        );

        $mismatchBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-007',
                'pembayaran' => '100000',
                'sisa_tagihan' => '0',
                'source_total' => '111000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($mismatchBatch);

        $this->assertDatabaseMissing('sales', ['imported_sales_reference_number' => 'INV-PAY-007']);
        $this->assertTrue(
            SalesImportRow::where('batch_id', $mismatchBatch->id)
                ->where('status', SalesImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function missing_cash_method_blocks_paid_sales_groups_but_not_unpaid_groups(): void
    {
        DB::table('setting_pos_payment_methods')->delete();
        DB::table('payment_methods')->delete();

        $paidBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-008',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($paidBatch);

        $this->assertDatabaseMissing('sales', ['imported_sales_reference_number' => 'INV-PAY-008']);

        $unpaidBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-009',
                'pembayaran' => '0',
                'sisa_tagihan' => '111000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($unpaidBatch);

        $this->assertDatabaseHas('sales', ['imported_sales_reference_number' => 'INV-PAY-009']);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    /** @test */
    public function sales_import_late_failures_do_not_leave_sale_or_payment_rows_behind(): void
    {
        Location::query()->delete();

        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-011',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $this->assertDatabaseMissing('sales', ['imported_sales_reference_number' => 'INV-PAY-011']);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertTrue(
            SalesImportRow::where('batch_id', $batch->id)
                ->where('status', SalesImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function duplicate_sales_imports_do_not_backfill_or_duplicate_payment_rows(): void
    {
        $firstBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-010',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($firstBatch);
        $this->assertDatabaseCount('sale_payments', 1);

        $duplicateBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-PAY-010',
                'pembayaran' => '50000',
                'sisa_tagihan' => '61000',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($duplicateBatch);

        $this->assertDatabaseCount('sale_payments', 1);
        $duplicateRow = SalesImportRow::where('batch_id', $duplicateBatch->id)->firstOrFail();
        $this->assertSame(SalesImportRow::STATUS_SKIPPED, $duplicateRow->status);
    }

    protected function createImportBatch(array $rows): SalesImportBatch
    {
        $user = User::factory()->create(['is_active' => 1]);

        $batch = SalesImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => SalesImportBatch::STATUS_PROCESSING,
        ]);

        foreach ($rows as $index => $rowData) {
            SalesImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $index + 2,
                'raw_json' => $rowData,
            ]);
        }

        return $batch;
    }

    protected function baseRow(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '01/10/2024',
            'no_faktur' => 'INV-TAG-001',
            'customer' => 'TEST CUSTOMER',
            'produk' => 'TEST PRODUCT',
            'kuantitas' => '1',
            'satuan' => 'PCS',
            'harga_satuan' => '100000',
            'tarif_pajak' => '11.0',
            'pajak' => '11000',
            'tag' => '',
            'gudang' => '',
            'pembayaran' => '0',
            'sisa_tagihan' => '111000',
        ], $overrides);
    }
}
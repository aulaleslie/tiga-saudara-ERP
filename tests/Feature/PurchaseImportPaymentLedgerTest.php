<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportPaymentLedgerTest extends TestCase
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
    public function fully_paid_purchase_import_creates_one_active_payment_with_purchase_reference(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-001',
                'harga_satuan' => '100000',
                'tarif_pajak' => '11.0',
                'pajak' => '11000',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-PAY-001')->first();

        $this->assertNotNull($purchase);
        $this->assertSame('PAID', $purchase->payment_status);
        $this->assertEquals(111000.0, (float) $purchase->paid_amount);
        $this->assertEquals(0.0, (float) $purchase->due_amount);

        $payments = $purchase->purchasePayments()->get();

        $this->assertCount(1, $payments);
        $this->assertSame('ACTIVE', $payments->first()->status);
        $this->assertSame($purchase->reference, $payments->first()->reference);
        $this->assertSame('CASH', strtoupper((string) $payments->first()->paymentMethod?->name));
        $this->assertSame('2024-10-01', $payments->first()->date->format('Y-m-d'));
        $this->assertEquals(111000.0, (float) $payments->first()->amount);
    }

    /** @test */
    public function partially_paid_purchase_import_creates_one_partial_payment(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-002',
                'pembayaran' => '61000',
                'sisa_tagihan' => '50000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-PAY-002')->firstOrFail();

        $this->assertSame('PARTIAL', $purchase->payment_status);
        $this->assertEquals(61000.0, (float) $purchase->paid_amount);
        $this->assertEquals(50000.0, (float) $purchase->due_amount);
        $this->assertCount(1, $purchase->purchasePayments);
        $this->assertEquals(61000.0, (float) $purchase->purchasePayments->first()->amount);
    }

    /** @test */
    public function unpaid_purchase_import_creates_no_payment_row(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-003',
                'pembayaran' => '0',
                'sisa_tagihan' => '111000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-PAY-003')->firstOrFail();

        $this->assertSame('UNPAID', $purchase->payment_status);
        $this->assertEquals(0.0, (float) $purchase->paid_amount);
        $this->assertEquals(111000.0, (float) $purchase->due_amount);
        $this->assertCount(0, $purchase->purchasePayments);
    }

    /** @test */
    public function purchase_import_prefers_pembayaran_and_today_outstanding_and_falls_back_when_payment_is_blank(): void
    {
        $preferredBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-004',
                'pembayaran' => '20000',
                'sisa_tagihan_hari_ini' => '91000',
                'sisa_tagihan' => '0',
                'source_total' => '111000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($preferredBatch);

        $preferredPurchase = Purchase::where('supplier_purchase_number', 'PO-PAY-004')->firstOrFail();
        $this->assertSame('PARTIAL', $preferredPurchase->payment_status);
        $this->assertEquals(20000.0, (float) $preferredPurchase->paid_amount);
        $this->assertEquals(91000.0, (float) $preferredPurchase->due_amount);

        $fallbackBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-005',
                'pembayaran' => '',
                'sisa_tagihan_hari_ini' => '10000',
                'sisa_tagihan' => '50000',
                'source_total' => '111000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($fallbackBatch);

        $fallbackPurchase = Purchase::where('supplier_purchase_number', 'PO-PAY-005')->firstOrFail();
        $this->assertSame('PARTIAL', $fallbackPurchase->payment_status);
        $this->assertEquals(101000.0, (float) $fallbackPurchase->paid_amount);
        $this->assertEquals(10000.0, (float) $fallbackPurchase->due_amount);
    }

    /** @test */
    public function purchase_import_accepts_exported_float_payment_fields_with_single_dot_decimals(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-004A',
                'kuantitas' => '30',
                'harga_satuan' => '4346846.846847',
                'tarif_pajak' => '11.0',
                'pembayaran' => '130405405.40541',
                'sisa_tagihan_hari_ini' => '14344594.594595',
                'sisa_tagihan' => '0.0',
                'source_total' => '144750000.000005',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-PAY-004A')->firstOrFail();

        $this->assertSame('PARTIAL', $purchase->payment_status);
        $this->assertEquals(130405405.41, (float) $purchase->paid_amount);
        $this->assertEquals(14344594.59, (float) $purchase->due_amount);
        $this->assertCount(1, $purchase->purchasePayments);
        $this->assertEquals(130405405.41, (float) $purchase->purchasePayments->first()->amount);
        $this->assertDatabaseMissing('purchase_import_rows', [
            'batch_id' => $batch->id,
            'status' => PurchaseImportRow::STATUS_INVALID,
        ]);
    }

    /** @test */
    public function purchase_import_preserves_existing_line_discount_behavior_separately_from_document_discount(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-LINE-DISC-001',
                'diskon_persen' => '10',
                'pembayaran' => '99900',
                'sisa_tagihan' => '0',
                'source_total' => '99900',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-LINE-DISC-001')
            ->with('purchaseDetails')
            ->firstOrFail();

        $this->assertEquals(0.0, (float) $purchase->discount_amount);
        $this->assertCount(1, $purchase->purchaseDetails);
        $this->assertEquals(10000.0, (float) $purchase->purchaseDetails->first()->product_discount_amount);
        $this->assertSame('PERCENTAGE', $purchase->purchaseDetails->first()->product_discount_type);
    }

    /** @test */
    public function purchase_import_applies_repeated_document_discount_once_and_ignores_discount_percent_for_reconciliation(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'ONLINE SBY / INV/2026/002725',
                'produk' => 'TEST PRODUCT A',
                'diskon' => '15000',
                'diskon_document_persen' => '7.26',
                'pembayaran' => '207000',
                'sisa_tagihan' => '0',
                'source_total' => '207000',
            ]),
            $this->baseRow([
                'no_faktur' => 'ONLINE SBY / INV/2026/002725',
                'produk' => 'TEST PRODUCT B',
                'diskon' => '15000',
                'diskon_document_persen' => '7.26',
                'pembayaran' => '207000',
                'sisa_tagihan' => '0',
                'source_total' => '207000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'ONLINE SBY / INV/2026/002725')->firstOrFail();

        $this->assertSame('PAID', $purchase->payment_status);
        $this->assertEquals(207000.0, (float) $purchase->total_amount);
        $this->assertEquals(15000.0, (float) $purchase->discount_amount);
        $this->assertEquals(0.0, (float) $purchase->discount_percentage);
        $this->assertEquals(207000.0, (float) $purchase->paid_amount);
        $this->assertEquals(0.0, (float) $purchase->due_amount);
        $this->assertCount(1, $purchase->purchasePayments);
        $this->assertEquals(207000.0, (float) $purchase->purchasePayments->first()->amount);
        $this->assertDatabaseMissing('purchase_import_rows', [
            'batch_id' => $batch->id,
            'status' => PurchaseImportRow::STATUS_INVALID,
        ]);
    }

    /** @test */
    public function purchase_import_applies_repeated_shipping_once_per_invoice_group(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-SHIP-001',
                'produk' => 'TEST PRODUCT A',
                'harga_satuan' => '50000',
                'pajak' => '5500',
                'biaya_pengiriman' => '5000',
                'pembayaran' => '116000',
                'sisa_tagihan' => '0',
                'source_total' => '116000',
            ]),
            $this->baseRow([
                'no_faktur' => 'PO-SHIP-001',
                'produk' => 'TEST PRODUCT B',
                'harga_satuan' => '50000',
                'pajak' => '5500',
                'biaya_pengiriman' => '5000',
                'pembayaran' => '116000',
                'sisa_tagihan' => '0',
                'source_total' => '116000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-SHIP-001')->firstOrFail();

        $this->assertEquals(116000.0, (float) $purchase->total_amount);
        $this->assertEquals(5000.0, (float) $purchase->shipping_amount);
        $this->assertEquals(116000.0, (float) $purchase->paid_amount);
        $this->assertCount(1, $purchase->purchasePayments);
    }

    /** @test */
    public function conflicting_repeated_purchase_discount_values_invalidate_the_invoice_group_without_creating_documents_or_payments(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-DISC-CONFLICT-001',
                'produk' => 'TEST PRODUCT A',
                'diskon' => '15000',
                'source_total' => '207000',
            ]),
            $this->baseRow([
                'no_faktur' => 'PO-DISC-CONFLICT-001',
                'produk' => 'TEST PRODUCT B',
                'diskon' => '10000',
                'source_total' => '212000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $this->assertDatabaseMissing('purchases', ['supplier_purchase_number' => 'PO-DISC-CONFLICT-001']);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->assertTrue(
            PurchaseImportRow::where('batch_id', $batch->id)
                ->where('status', PurchaseImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function blank_purchase_document_discount_rows_do_not_conflict_with_repeated_non_blank_values(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-DISC-BLANK-001',
                'produk' => 'TEST PRODUCT A',
                'diskon' => '',
                'pembayaran' => '207000',
                'sisa_tagihan' => '0',
                'source_total' => '207000',
            ]),
            $this->baseRow([
                'no_faktur' => 'PO-DISC-BLANK-001',
                'produk' => 'TEST PRODUCT B',
                'diskon' => '15000',
                'pembayaran' => '207000',
                'sisa_tagihan' => '0',
                'source_total' => '207000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-DISC-BLANK-001')->firstOrFail();

        $this->assertSame('PAID', $purchase->payment_status);
        $this->assertEquals(15000.0, (float) $purchase->discount_amount);
        $this->assertEquals(207000.0, (float) $purchase->total_amount);
        $this->assertDatabaseMissing('purchase_import_rows', [
            'batch_id' => $batch->id,
            'status' => PurchaseImportRow::STATUS_INVALID,
        ]);
    }

    /** @test */
    public function conflicting_repeated_purchase_shipping_values_invalidate_the_invoice_group_without_creating_documents_or_payments(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-SHIP-CONFLICT-001',
                'produk' => 'TEST PRODUCT A',
                'biaya_pengiriman' => '5000',
                'source_total' => '116000',
            ]),
            $this->baseRow([
                'no_faktur' => 'PO-SHIP-CONFLICT-001',
                'produk' => 'TEST PRODUCT B',
                'biaya_pengiriman' => '7000',
                'source_total' => '118000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $this->assertDatabaseMissing('purchases', ['supplier_purchase_number' => 'PO-SHIP-CONFLICT-001']);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->assertTrue(
            PurchaseImportRow::where('batch_id', $batch->id)
                ->where('status', PurchaseImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function blank_purchase_shipping_rows_do_not_conflict_with_repeated_non_blank_values(): void
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-SHIP-BLANK-001',
                'produk' => 'TEST PRODUCT A',
                'harga_satuan' => '50000',
                'pajak' => '5500',
                'biaya_pengiriman' => '',
                'pembayaran' => '116000',
                'sisa_tagihan' => '0',
                'source_total' => '116000',
            ]),
            $this->baseRow([
                'no_faktur' => 'PO-SHIP-BLANK-001',
                'produk' => 'TEST PRODUCT B',
                'harga_satuan' => '50000',
                'pajak' => '5500',
                'biaya_pengiriman' => '5000',
                'pembayaran' => '116000',
                'sisa_tagihan' => '0',
                'source_total' => '116000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-SHIP-BLANK-001')->firstOrFail();

        $this->assertSame('PAID', $purchase->payment_status);
        $this->assertEquals(5000.0, (float) $purchase->shipping_amount);
        $this->assertEquals(116000.0, (float) $purchase->total_amount);
        $this->assertDatabaseMissing('purchase_import_rows', [
            'batch_id' => $batch->id,
            'status' => PurchaseImportRow::STATUS_INVALID,
        ]);
    }

    /** @test */
    public function conflicting_purchase_payment_fields_or_non_reconciling_totals_invalidate_the_invoice_group(): void
    {
        $conflictBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-006',
                'produk' => 'TEST PRODUCT A',
                'pembayaran' => '111000',
                'source_total' => '222000',
            ]),
            $this->baseRow([
                'no_faktur' => 'PO-PAY-006',
                'produk' => 'TEST PRODUCT B',
                'pembayaran' => '110000',
                'source_total' => '222000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($conflictBatch);

        $this->assertDatabaseMissing('purchases', ['supplier_purchase_number' => 'PO-PAY-006']);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->assertTrue(
            PurchaseImportRow::where('batch_id', $conflictBatch->id)
                ->where('status', PurchaseImportRow::STATUS_INVALID)
                ->exists()
        );

        $mismatchBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-007',
                'pembayaran' => '100000',
                'sisa_tagihan' => '0',
                'source_total' => '111000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($mismatchBatch);

        $this->assertDatabaseMissing('purchases', ['supplier_purchase_number' => 'PO-PAY-007']);
        $this->assertTrue(
            PurchaseImportRow::where('batch_id', $mismatchBatch->id)
                ->where('status', PurchaseImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function missing_cash_method_blocks_paid_purchase_groups_but_not_unpaid_groups(): void
    {
        DB::table('setting_pos_payment_methods')->delete();
        DB::table('payment_methods')->delete();

        $paidBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-008',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($paidBatch);

        $this->assertDatabaseMissing('purchases', ['supplier_purchase_number' => 'PO-PAY-008']);

        $unpaidBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-009',
                'pembayaran' => '0',
                'sisa_tagihan' => '111000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($unpaidBatch);

        $this->assertDatabaseHas('purchases', ['supplier_purchase_number' => 'PO-PAY-009']);
        $this->assertDatabaseCount('purchase_payments', 0);
    }

    /** @test */
    public function purchase_import_late_failures_do_not_leave_purchase_or_payment_rows_behind(): void
    {
        Location::query()->delete();

        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-011',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $this->assertDatabaseMissing('purchases', ['supplier_purchase_number' => 'PO-PAY-011']);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->assertTrue(
            PurchaseImportRow::where('batch_id', $batch->id)
                ->where('status', PurchaseImportRow::STATUS_INVALID)
                ->exists()
        );
    }

    /** @test */
    public function duplicate_purchase_imports_do_not_backfill_or_duplicate_payment_rows(): void
    {
        $firstBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-010',
                'pembayaran' => '111000',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($firstBatch);
        $this->assertDatabaseCount('purchase_payments', 1);

        $duplicateBatch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'PO-PAY-010',
                'pembayaran' => '50000',
                'sisa_tagihan' => '61000',
            ]),
        ]);

        app(PurchaseImportService::class)->processBatch($duplicateBatch);

        $this->assertDatabaseCount('purchase_payments', 1);
        $duplicateRow = PurchaseImportRow::where('batch_id', $duplicateBatch->id)->firstOrFail();
        $this->assertSame(PurchaseImportRow::STATUS_SKIPPED, $duplicateRow->status);
    }

    protected function createImportBatch(array $rows): PurchaseImportBatch
    {
        $user = User::factory()->create(['is_active' => 1]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => PurchaseImportBatch::STATUS_PROCESSING,
        ]);

        foreach ($rows as $index => $rowData) {
            PurchaseImportRow::create([
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
            'no_faktur' => 'PO-TAG-001',
            'supplier' => 'TEST SUPPLIER',
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
<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesImportTagPriorityPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    /** @var array<string, Setting> */
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        foreach ([
            'PERDANA',
            'CV TIGA NUSA COMPUTER',
            'CV TOP IT INTERNUSA',
            'WHITE KNIGHT COMPUTER',
            'DAIZU KEDELAI',
        ] as $i => $name) {
            $setting = $this->createSetting($name, "s{$i}@example.com");
            Location::create(['setting_id' => $setting->id, 'name' => "Gudang {$name}"]);
            $this->settings[$name] = $setting;
        }

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->settings['PERDANA']->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $this->actingAs(User::factory()->create());
    }

    private function createSetting(string $companyName, string $email): Setting
    {
        return Setting::create([
            'company_name' => $companyName,
            'company_email' => $email,
            'company_phone' => '000',
            'notification_email' => $email,
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => '',
            'company_address' => '',
        ]);
    }

    private function process(array $rows): SalesImportBatch
    {
        $batch = SalesImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => md5(uniqid()),
            'status' => SalesImportBatch::STATUS_PROCESSING,
        ]);

        foreach ($rows as $index => $rowData) {
            SalesImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $index + 2,
                'raw_json' => array_merge([
                    'tanggal' => '01/10/2024',
                    'no_faktur' => 'INV-001',
                    'customer' => 'TEST CUSTOMER',
                    'produk' => 'MONITOR SAMPLE',
                    'kuantitas' => '1',
                    'satuan' => 'PCS',
                    'harga_satuan' => '100000',
                    'tarif_pajak' => '0',
                    'pajak' => '0',
                    'tag' => '',
                    'gudang' => '',
                    'source_total' => '',
                    'pembayaran' => '',
                    'sisa_tagihan' => '0',
                    'biaya_pengiriman' => '0',
                ], $rowData),
            ]);
        }

        app(SalesImportService::class)->processBatch($batch);

        return $batch;
    }

    private function sale(string $invoice): ?Sale
    {
        return Sale::where('imported_sales_reference_number', $invoice)->first();
    }

    // 1.2 — mapped Tag overrides *, TP, and unmarked markers
    public function test_mapped_tag_overrides_markers(): void
    {
        $this->process([
            ['no_faktur' => 'INV-AST', 'produk' => '* MONITOR SAMPLE', 'tag' => 'perdana'],
            ['no_faktur' => 'INV-TP', 'produk' => 'MONITOR SAMPLE TP', 'tag' => 'cv tiga nusa'],
            ['no_faktur' => 'INV-PLAIN', 'produk' => 'MONITOR SAMPLE', 'tag' => 'rahmat'],
        ]);

        $this->assertEquals($this->settings['PERDANA']->id, $this->sale('INV-AST')->setting_id);
        $this->assertEquals($this->settings['CV TIGA NUSA COMPUTER']->id, $this->sale('INV-TP')->setting_id);
        $this->assertEquals($this->settings['WHITE KNIGHT COMPUTER']->id, $this->sale('INV-PLAIN')->setting_id);
    }

    // 1.3 — unmapped/blank tag falls back to marker, preserves metadata
    public function test_unmapped_tag_falls_back_to_marker_and_preserves_metadata(): void
    {
        $this->process([
            ['no_faktur' => 'INV-UNMAPPED', 'produk' => 'MONITOR SAMPLE TP', 'tag' => 'some-random-label'],
            ['no_faktur' => 'INV-BLANK', 'produk' => '* MONITOR SAMPLE', 'tag' => ''],
        ]);

        $unmapped = $this->sale('INV-UNMAPPED');
        $this->assertEquals($this->settings['CV TOP IT INTERNUSA']->id, $unmapped->setting_id);
        $tags = $unmapped->tags()->pluck('name')->map(fn ($n) => strtolower($n))->all();
        $this->assertContains('some-random-label', $tags);

        $blank = $this->sale('INV-BLANK');
        $this->assertEquals($this->settings['CV TIGA NUSA COMPUTER']->id, $blank->setting_id);
    }

    // 1.4 — Daizu overrides tag and marker
    public function test_daizu_product_overrides_tag_and_marker(): void
    {
        $this->process([
            ['no_faktur' => 'INV-DZ', 'produk' => '* KEDELAI IMPORT TP', 'tag' => 'perdana', 'gudang' => 'Gudang DAIZU KEDELAI'],
        ]);

        $this->assertEquals($this->settings['DAIZU KEDELAI']->id, $this->sale('INV-DZ')->setting_id);
    }

    // 1.5 — duplicate with changed mapped tag different owner creates new
    public function test_duplicate_with_changed_mapped_tag_different_owner_creates_new(): void
    {
        $this->process([['no_faktur' => 'INV-DUP', 'tag' => 'cv tiga nusa', 'produk' => 'MONITOR SAMPLE']]);
        $first = $this->sale('INV-DUP');

        $batch2 = $this->process([['no_faktur' => 'INV-DUP', 'tag' => 'rahmat', 'produk' => 'MONITOR SAMPLE']]);
        $row = SalesImportRow::where('batch_id', $batch2->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_PROCESSED, $row->status);
        $this->assertNotEquals($first->id, $row->sale_id);
        $this->assertEquals($this->settings['WHITE KNIGHT COMPUTER']->id, Sale::find($row->sale_id)->setting_id);
    }

    // 1.7 — JL1071-style blank-tag split-owner invoice: payment allocated pro-rata
    public function test_blank_tag_split_invoice_allocates_payment_pro_rata(): void
    {
        // Blank tags, split by marker: * → Tiga Nusa (100000), TP → Top IT (200000)
        // Fully paid total 300000.
        $this->process([
            [
                'no_faktur' => 'JL1071', 'produk' => '* PROD A', 'harga_satuan' => '100000', 'kuantitas' => '1',
                'tag' => '', 'source_total' => '300000', 'pembayaran' => '300000', 'sisa_tagihan' => '0',
            ],
            [
                'no_faktur' => 'JL1071', 'produk' => 'PROD B TP', 'harga_satuan' => '200000', 'kuantitas' => '1',
                'tag' => '', 'source_total' => '300000', 'pembayaran' => '300000', 'sisa_tagihan' => '0',
            ],
        ]);

        $sales = Sale::where('imported_sales_reference_number', 'JL1071')->get();
        $this->assertCount(2, $sales);

        $nusa = $sales->firstWhere('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id);
        $top = $sales->firstWhere('setting_id', $this->settings['CV TOP IT INTERNUSA']->id);

        $this->assertEqualsWithDelta(100000, (float) $nusa->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $nusa->due_amount, 0.01);
        $this->assertEqualsWithDelta(200000, (float) $top->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $top->due_amount, 0.01);

        $this->assertEquals(1, SalePayment::where('sale_id', $nusa->id)->count());
        $this->assertEquals(1, SalePayment::where('sale_id', $top->id)->count());
    }

    // 1.8 — partial-payment split owner sums back to source
    public function test_partial_payment_split_owner_sums_back_to_source(): void
    {
        $this->process([
            [
                'no_faktur' => 'SPLIT-1', 'produk' => '* PROD A', 'harga_satuan' => '100000', 'kuantitas' => '1',
                'tag' => '', 'source_total' => '300000', 'pembayaran' => '120000', 'sisa_tagihan' => '180000',
            ],
            [
                'no_faktur' => 'SPLIT-1', 'produk' => 'PROD B TP', 'harga_satuan' => '200000', 'kuantitas' => '1',
                'tag' => '', 'source_total' => '300000', 'pembayaran' => '120000', 'sisa_tagihan' => '180000',
            ],
        ]);

        $sales = Sale::where('imported_sales_reference_number', 'SPLIT-1')->get();
        $this->assertCount(2, $sales);
        $this->assertEqualsWithDelta(120000, round($sales->sum('paid_amount'), 2), 0.01);
        $this->assertEqualsWithDelta(180000, round($sales->sum('due_amount'), 2), 0.01);
    }

    // 1.9 — zero-total owner group: no payment row, stock preserved
    public function test_zero_total_owner_group_has_no_payment_but_keeps_stock(): void
    {
        $this->process([
            [
                'no_faktur' => 'ZT-1', 'produk' => 'PAID PROD', 'harga_satuan' => '100000', 'kuantitas' => '1',
                'tag' => '', 'source_total' => '100000', 'pembayaran' => '100000', 'sisa_tagihan' => '0',
            ],
            [
                'no_faktur' => 'ZT-1', 'produk' => 'FREE PROD TP', 'harga_satuan' => '0', 'kuantitas' => '3',
                'tag' => '', 'source_total' => '100000', 'pembayaran' => '100000', 'sisa_tagihan' => '0',
            ],
        ]);

        $sales = Sale::where('imported_sales_reference_number', 'ZT-1')->get()->keyBy('setting_id');
        $zero = $sales->get($this->settings['CV TOP IT INTERNUSA']->id);

        $this->assertNotNull($zero);
        $this->assertEqualsWithDelta(0, (float) $zero->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $zero->due_amount, 0.01);
        $this->assertEquals(0, SalePayment::where('sale_id', $zero->id)->count());

        $this->assertGreaterThan(0, Transaction::where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->where('type', 'DISPATCH')->count());
    }

    // 3.8 — source total mismatch invalidates all groups
    public function test_source_total_mismatch_invalidates_all_groups(): void
    {
        $batch = $this->process([
            [
                'no_faktur' => 'BAD-1', 'produk' => '* PROD A', 'harga_satuan' => '100000', 'kuantitas' => '1',
                'tag' => '', 'source_total' => '999999', 'sisa_tagihan' => '0',
            ],
            [
                'no_faktur' => 'BAD-1', 'produk' => 'PROD B TP', 'harga_satuan' => '200000', 'kuantitas' => '1',
                'tag' => '', 'source_total' => '999999', 'sisa_tagihan' => '0',
            ],
        ]);

        $this->assertEquals(0, Sale::where('imported_sales_reference_number', 'BAD-1')->count());
        foreach (SalesImportRow::where('batch_id', $batch->id)->get() as $row) {
            $this->assertEquals(SalesImportRow::STATUS_INVALID, $row->status);
        }
    }

    // Regression — split-owner invoice with a repeated document-level Diskon must allocate the
    // discount pro-rata (not subtract it once per owner group), so the source total reconciles
    // and the persisted headers' discounts sum back to the source discount.
    public function test_split_owner_document_discount_is_allocated_not_double_counted(): void
    {
        // Lines 100000 + 100000, repeated Diskon 15000, source Total 185000 (= 200000 - 15000).
        $this->process([
            [
                'no_faktur' => 'DISC-1', 'produk' => '* PROD A', 'harga_satuan' => '100000', 'kuantitas' => '1',
                'tag' => '', 'diskon' => '15000',
                'source_total' => '185000', 'pembayaran' => '185000', 'sisa_tagihan' => '0',
            ],
            [
                'no_faktur' => 'DISC-1', 'produk' => 'PROD B TP', 'harga_satuan' => '100000', 'kuantitas' => '1',
                'tag' => '', 'diskon' => '15000',
                'source_total' => '185000', 'pembayaran' => '185000', 'sisa_tagihan' => '0',
            ],
        ]);

        $sales = Sale::where('imported_sales_reference_number', 'DISC-1')->get();
        $this->assertCount(2, $sales, 'Both owner documents must be created (no false mismatch)');

        // Document discount is allocated, summing back to the single source discount of 15000.
        $this->assertEqualsWithDelta(15000, round($sales->sum('discount_amount'), 2), 0.01);
        foreach ($sales as $sale) {
            $this->assertEqualsWithDelta(7500, (float) $sale->discount_amount, 0.01);
            $this->assertEqualsWithDelta(92500, (float) $sale->total_amount, 0.01);
        }

        // Owner document totals reconcile to the source invoice total of 185000.
        $this->assertEqualsWithDelta(185000, round($sales->sum('total_amount'), 2), 0.01);
        $this->assertEqualsWithDelta(185000, round($sales->sum('paid_amount'), 2), 0.01);
    }

    // Regression — Jumlah Pemotongan (non-cash settlement credit) reconciles the invoice:
    // cash Pembayaran + deduction + outstanding = Total. The cash payment row records the
    // Pembayaran amount only, while the header paid_amount includes the deduction so paid + due = total.
    public function test_jumlah_pemotongan_reconciles_and_records_cash_payment_only(): void
    {
        // Total 1,000,000 = cash 700,000 + deduction 300,000 + outstanding 0.
        $this->process([
            [
                'no_faktur' => 'PEM-1', 'produk' => 'MONITOR SAMPLE', 'tag' => 'rahmat',
                'harga_satuan' => '1000000', 'kuantitas' => '1',
                'source_total' => '1000000', 'pembayaran' => '700000',
                'jumlah_pemotongan' => '300000', 'sisa_tagihan' => '0',
            ],
        ]);

        $sale = $this->sale('PEM-1');
        $this->assertNotNull($sale, 'Sale should be created despite the deduction');

        $this->assertEqualsWithDelta(1000000, (float) $sale->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $sale->due_amount, 0.01);

        // Two active payment rows: a cash row for Pembayaran and a non-cash deduction credit.
        $payments = SalePayment::where('sale_id', $sale->id)
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->get();
        $this->assertCount(2, $payments);
        $this->assertEqualsWithDelta(1000000, (float) $payments->sum('amount'), 0.01);

        $cashRow = $payments->firstWhere('payment_method', 'CASH');
        $this->assertNotNull($cashRow, 'A cash payment row for Pembayaran must exist');
        $this->assertEqualsWithDelta(700000, (float) $cashRow->amount, 0.01);

        $deductionRow = $payments->firstWhere('payment_method', 'POTONGAN');
        $this->assertNotNull($deductionRow, 'A non-cash deduction credit row must exist');
        $this->assertEqualsWithDelta(300000, (float) $deductionRow->amount, 0.01);
    }
    // Regression — 16994: a real-world invoice where the header tax total (Total Pajak) differs from
    // the sum of per-line taxes (Jumlah Pajak) by ~0.08 rupiah. The importer must accept this
    // precision drift within the 1.00 SOURCE_TOTAL_TOLERANCE and persist the rounded total.
    public function test_invoice_with_precision_drift_in_tax_is_accepted_within_tolerance(): void
    {
        $this->process([
            [
                'no_faktur' => '16994', 'produk' => 'MONITOR', 'tag' => 'rahmat',
                'harga_satuan' => '6837837.83783', 'kuantitas' => '1', 'pajak' => '750279.500269',
                'diskon' => '17114.414414',
                'source_total' => '7571002.999992', 'pembayaran' => '7571002.999992', 'sisa_tagihan' => '0',
            ],
        ]);

        $sale = $this->sale('16994');
        $this->assertNotNull($sale, '16994-style invoice should reconcile and import despite 0.08 precision drift');

        $this->assertEqualsWithDelta(7571002.92, (float) $sale->total_amount, 0.01);
        $this->assertEqualsWithDelta(7571002.92, (float) $sale->paid_amount, 0.01);
        // And discount_amount is recorded as the discount allocated
        $this->assertEqualsWithDelta(17114.41, (float) $sale->discount_amount, 0.01);
    }
}

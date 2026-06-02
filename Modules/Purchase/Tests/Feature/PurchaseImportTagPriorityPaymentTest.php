<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportTagPriorityPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    /** @var array<string, Setting> */
    private array $settings = [];
    private PurchaseImportService $service;

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

        $this->service = new PurchaseImportService();
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

    private function makeBatch(): PurchaseImportBatch
    {
        return PurchaseImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => 'test.csv',
            'file_sha256' => md5(uniqid()),
            'status' => PurchaseImportBatch::STATUS_QUEUED,
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
        ]);
    }

    private function makeRow(PurchaseImportBatch $batch, array $overrides = [], int $rowNumber = 1): PurchaseImportRow
    {
        $defaults = [
            'tanggal' => '01/01/2024',
            'no_faktur' => 'INV-001',
            'supplier' => 'Supplier A',
            'produk' => 'MONITOR SAMPLE',
            'satuan' => 'PCS',
            'kuantitas' => '1',
            'harga_satuan' => '100000',
            'tarif_pajak' => '0',
            'diskon_persen' => '0',
            'pajak' => '0',
            'sisa_tagihan' => '0',
            'biaya_pengiriman' => '0',
            'tag' => '',
        ];

        return PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => $rowNumber,
            'status' => PurchaseImportRow::STATUS_PENDING,
            'raw_json' => array_merge($defaults, $overrides),
        ]);
    }

    private function purchase(string $invoice): ?Purchase
    {
        return Purchase::where('supplier_purchase_number', $invoice)->first();
    }

    // 1.1 — mapped Tag overrides *, TP, and unmarked markers for non-Daizu rows
    public function test_mapped_tag_overrides_markers(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, ['no_faktur' => 'INV-AST', 'produk' => '* MONITOR SAMPLE', 'tag' => 'perdana'], 1);
        $this->makeRow($batch, ['no_faktur' => 'INV-TP', 'produk' => 'MONITOR SAMPLE TP', 'tag' => 'cv tiga nusa'], 2);
        $this->makeRow($batch, ['no_faktur' => 'INV-PLAIN', 'produk' => 'MONITOR SAMPLE', 'tag' => 'rahmat'], 3);

        $this->service->processBatch($batch);

        $this->assertEquals($this->settings['PERDANA']->id, $this->purchase('INV-AST')->setting_id);
        $this->assertEquals($this->settings['CV TIGA NUSA COMPUTER']->id, $this->purchase('INV-TP')->setting_id);
        $this->assertEquals($this->settings['WHITE KNIGHT COMPUTER']->id, $this->purchase('INV-PLAIN')->setting_id);
    }

    // 1.3 — unmapped/blank tag falls back to marker while preserving tag metadata
    public function test_unmapped_tag_falls_back_to_marker_and_preserves_metadata(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, ['no_faktur' => 'INV-UNMAPPED', 'produk' => 'MONITOR SAMPLE TP', 'tag' => 'some-random-label'], 1);
        $this->makeRow($batch, ['no_faktur' => 'INV-BLANK', 'produk' => '* MONITOR SAMPLE', 'tag' => ''], 2);

        $this->service->processBatch($batch);

        $unmapped = $this->purchase('INV-UNMAPPED');
        $this->assertEquals($this->settings['CV TOP IT INTERNUSA']->id, $unmapped->setting_id);
        $this->assertContainsTag($unmapped, 'some-random-label');

        $blank = $this->purchase('INV-BLANK');
        $this->assertEquals($this->settings['CV TIGA NUSA COMPUTER']->id, $blank->setting_id);
    }

    private function assertContainsTag(Purchase $purchase, string $tag): void
    {
        $tags = $purchase->tags()->pluck('name')->map(fn ($n) => strtolower($n))->all();
        $this->assertContains(strtolower($tag), $tags, "Expected tag '{$tag}' to be synced");
    }

    // 1.4 — Daizu product overrides mapped tag and marker
    public function test_daizu_product_overrides_tag_and_marker(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, ['no_faktur' => 'INV-DZ', 'produk' => '* KEDELAI IMPORT TP', 'tag' => 'perdana'], 1);

        $this->service->processBatch($batch);

        $this->assertEquals($this->settings['DAIZU KEDELAI']->id, $this->purchase('INV-DZ')->setting_id);
    }

    // 1.5 — duplicate check uses effective owner: changed raw tag same owner skipped
    public function test_duplicate_with_changed_raw_tag_same_owner_is_skipped(): void
    {
        $batch1 = $this->makeBatch();
        $this->makeRow($batch1, ['no_faktur' => 'INV-DUP', 'tag' => 'cv tiga nusa', 'produk' => 'MONITOR SAMPLE']);
        $this->service->processBatch($batch1);
        $first = $this->purchase('INV-DUP');
        $this->assertNotNull($first);

        // Re-import with a different raw tag that still resolves to CV TIGA NUSA (via * marker)
        $batch2 = $this->makeBatch();
        $this->makeRow($batch2, ['no_faktur' => 'INV-DUP', 'tag' => '', 'produk' => '* MONITOR SAMPLE']);
        $this->service->processBatch($batch2);

        $row = PurchaseImportRow::where('batch_id', $batch2->id)->first();
        $this->assertEquals(PurchaseImportRow::STATUS_SKIPPED, $row->status);
        $this->assertEquals($first->id, $row->purchase_id);
    }

    // 1.5 — changed mapped tag different owner is NOT skipped under old owner
    public function test_duplicate_with_changed_mapped_tag_different_owner_creates_new(): void
    {
        $batch1 = $this->makeBatch();
        $this->makeRow($batch1, ['no_faktur' => 'INV-DUP2', 'tag' => 'cv tiga nusa', 'produk' => 'MONITOR SAMPLE']);
        $this->service->processBatch($batch1);
        $first = $this->purchase('INV-DUP2');

        $batch2 = $this->makeBatch();
        $this->makeRow($batch2, ['no_faktur' => 'INV-DUP2', 'tag' => 'rahmat', 'produk' => 'MONITOR SAMPLE']);
        $this->service->processBatch($batch2);

        $row = PurchaseImportRow::where('batch_id', $batch2->id)->first();
        $this->assertEquals(PurchaseImportRow::STATUS_PROCESSED, $row->status);
        $this->assertNotEquals($first->id, $row->purchase_id);
        $new = Purchase::find($row->purchase_id);
        $this->assertEquals($this->settings['WHITE KNIGHT COMPUTER']->id, $new->setting_id);
    }

    // 1.6 — tagged invoice with zero-total unmarked rows stays in tag owner group, no mismatch
    public function test_tagged_invoice_keeps_zero_total_rows_in_tag_owner_group(): void
    {
        $batch = $this->makeBatch();
        // Priced bundle parent + zero-priced component, both tagged cv tiga nusa, mixed markers
        $this->makeRow($batch, [
            'no_faktur' => 'JL-2008', 'produk' => 'BUNDLE PARENT',
            'harga_satuan' => '100000', 'kuantitas' => '1',
            'tag' => 'cv tiga nusa', 'source_total' => '100000', 'sisa_tagihan' => '0',
        ], 1);
        $this->makeRow($batch, [
            'no_faktur' => 'JL-2008', 'produk' => 'BUNDLE COMPONENT TP',
            'harga_satuan' => '0', 'kuantitas' => '2',
            'tag' => 'cv tiga nusa', 'source_total' => '100000', 'sisa_tagihan' => '0',
        ], 2);

        $this->service->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'JL-2008')->get();
        $this->assertCount(1, $purchases, 'Both rows must stay in the single mapped-tag owner document');
        $purchase = $purchases->first();
        $this->assertEquals($this->settings['CV TIGA NUSA COMPUTER']->id, $purchase->setting_id);
        $this->assertEquals(2, $purchase->purchaseDetails()->count());

        $rows = PurchaseImportRow::where('batch_id', $batch->id)->get();
        foreach ($rows as $row) {
            $this->assertEquals(PurchaseImportRow::STATUS_PROCESSED, $row->status, $row->error_message ?? '');
        }
    }

    // 1.8 — partial-payment split owner: paid/due sum back to source
    public function test_partial_payment_split_owner_sums_back_to_source(): void
    {
        $batch = $this->makeBatch();
        // Two owner groups (tag-based) on one invoice, total 300000, paid 120000
        $this->makeRow($batch, [
            'no_faktur' => 'SPLIT-1', 'produk' => 'PROD A', 'harga_satuan' => '100000', 'kuantitas' => '1',
            'tag' => 'cv tiga nusa', 'source_total' => '300000', 'pembayaran' => '120000', 'sisa_tagihan' => '180000',
        ], 1);
        $this->makeRow($batch, [
            'no_faktur' => 'SPLIT-1', 'produk' => 'PROD B', 'harga_satuan' => '200000', 'kuantitas' => '1',
            'tag' => 'cv top it', 'source_total' => '300000', 'pembayaran' => '120000', 'sisa_tagihan' => '180000',
        ], 2);

        $this->service->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'SPLIT-1')->get();
        $this->assertCount(2, $purchases);

        $totalPaid = round($purchases->sum('paid_amount'), 2);
        $totalDue = round($purchases->sum('due_amount'), 2);
        $this->assertEqualsWithDelta(120000, $totalPaid, 0.01);
        $this->assertEqualsWithDelta(180000, $totalDue, 0.01);

        // Pro-rata: 1/3 and 2/3
        $nusa = $purchases->firstWhere('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id);
        $top = $purchases->firstWhere('setting_id', $this->settings['CV TOP IT INTERNUSA']->id);
        $this->assertEqualsWithDelta(40000, (float) $nusa->paid_amount, 0.01);
        $this->assertEqualsWithDelta(80000, (float) $top->paid_amount, 0.01);
    }

    // 1.9 — zero-total owner group: zero paid/due, no payment row, stock preserved
    public function test_zero_total_owner_group_has_no_payment_but_keeps_stock(): void
    {
        $batch = $this->makeBatch();
        // Positive group (perdana via plain marker default) + zero-total group (TP marker)
        $this->makeRow($batch, [
            'no_faktur' => 'ZT-1', 'produk' => 'PAID PROD', 'harga_satuan' => '100000', 'kuantitas' => '1',
            'tag' => '', 'source_total' => '100000', 'pembayaran' => '100000', 'sisa_tagihan' => '0',
        ], 1);
        $this->makeRow($batch, [
            'no_faktur' => 'ZT-1', 'produk' => 'FREE PROD TP', 'harga_satuan' => '0', 'kuantitas' => '3',
            'tag' => '', 'source_total' => '100000', 'pembayaran' => '100000', 'sisa_tagihan' => '0',
        ], 2);

        $this->service->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'ZT-1')->get()->keyBy('setting_id');
        $paid = $purchases->get($this->settings['PERDANA']->id);
        $zero = $purchases->get($this->settings['CV TOP IT INTERNUSA']->id);

        $this->assertNotNull($zero, 'Zero-total owner document must be created');
        $this->assertEqualsWithDelta(0, (float) $zero->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $zero->due_amount, 0.01);
        $this->assertEquals(0, PurchasePayment::where('purchase_id', $zero->id)->count());

        $this->assertEqualsWithDelta(100000, (float) $paid->paid_amount, 0.01);
        $this->assertEquals(1, PurchasePayment::where('purchase_id', $paid->id)->count());

        // Stock/transaction preserved for the zero-total group
        $this->assertGreaterThan(0, Transaction::where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->where('type', 'BUY')->count());
    }

    // 3.8 — source total mismatch invalidates all groups, creates nothing
    public function test_source_total_mismatch_invalidates_all_groups(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, [
            'no_faktur' => 'BAD-1', 'produk' => 'PROD A', 'harga_satuan' => '100000', 'kuantitas' => '1',
            'tag' => 'cv tiga nusa', 'source_total' => '999999', 'sisa_tagihan' => '0',
        ], 1);
        $this->makeRow($batch, [
            'no_faktur' => 'BAD-1', 'produk' => 'PROD B', 'harga_satuan' => '200000', 'kuantitas' => '1',
            'tag' => 'cv top it', 'source_total' => '999999', 'sisa_tagihan' => '0',
        ], 2);

        $this->service->processBatch($batch);

        $this->assertEquals(0, Purchase::where('supplier_purchase_number', 'BAD-1')->count());
        $rows = PurchaseImportRow::where('batch_id', $batch->id)->get();
        foreach ($rows as $row) {
            $this->assertEquals(PurchaseImportRow::STATUS_INVALID, $row->status);
        }
    }

    // Regression — split-owner invoice with a repeated document-level Diskon must allocate the
    // discount pro-rata (not subtract it once per owner group), so the source total reconciles
    // and the persisted headers' discounts sum back to the source discount.
    public function test_split_owner_document_discount_is_allocated_not_double_counted(): void
    {
        $batch = $this->makeBatch();
        // Lines 100000 + 100000, repeated Diskon 15000, source Total 185000 (= 200000 - 15000).
        $this->makeRow($batch, [
            'no_faktur' => 'DISC-1', 'produk' => 'PROD A', 'harga_satuan' => '100000', 'kuantitas' => '1',
            'tag' => 'cv tiga nusa', 'diskon' => '15000',
            'source_total' => '185000', 'pembayaran' => '185000', 'sisa_tagihan' => '0',
        ], 1);
        $this->makeRow($batch, [
            'no_faktur' => 'DISC-1', 'produk' => 'PROD B', 'harga_satuan' => '100000', 'kuantitas' => '1',
            'tag' => 'cv top it', 'diskon' => '15000',
            'source_total' => '185000', 'pembayaran' => '185000', 'sisa_tagihan' => '0',
        ], 2);

        $this->service->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'DISC-1')->get();
        $this->assertCount(2, $purchases, 'Both owner documents must be created (no false mismatch)');

        // Document discount is allocated, summing back to the single source discount of 15000.
        $this->assertEqualsWithDelta(15000, round($purchases->sum('discount_amount'), 2), 0.01);
        // Each owner gets half of the equal-weighted discount.
        foreach ($purchases as $purchase) {
            $this->assertEqualsWithDelta(7500, (float) $purchase->discount_amount, 0.01);
            $this->assertEqualsWithDelta(92500, (float) $purchase->total_amount, 0.01);
        }

        // Owner document totals reconcile to the source invoice total of 185000.
        $this->assertEqualsWithDelta(185000, round($purchases->sum('total_amount'), 2), 0.01);
        $this->assertEqualsWithDelta(185000, round($purchases->sum('paid_amount'), 2), 0.01);
    }

    // Regression — Jumlah Pemotongan (non-cash settlement credit) reconciles the invoice:
    // cash Pembayaran + deduction + outstanding = Total. The cash payment row records the
    // Pembayaran amount only, while the header paid_amount includes the deduction so paid + due = total.
    public function test_jumlah_pemotongan_reconciles_and_records_cash_payment_only(): void
    {
        $batch = $this->makeBatch();
        // Total 1,000,000 = cash 700,000 + deduction 300,000 + outstanding 0.
        $this->makeRow($batch, [
            'no_faktur' => 'PEM-1', 'produk' => 'MONITOR SAMPLE', 'tag' => 'rahmat',
            'harga_satuan' => '1000000', 'kuantitas' => '1',
            'source_total' => '1000000', 'pembayaran' => '700000',
            'jumlah_pemotongan' => '300000', 'sisa_tagihan' => '0',
        ], 1);

        $this->service->processBatch($batch);

        $purchase = $this->purchase('PEM-1');
        $this->assertNotNull($purchase, 'Purchase should be created despite the deduction');

        // Header reflects cash + credit; paid + due reconciles to total.
        $this->assertEqualsWithDelta(1000000, (float) $purchase->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $purchase->due_amount, 0.01);
        $this->assertEquals('PAID', $purchase->payment_status);

        // Two active payment rows: a cash row for Pembayaran and a non-cash deduction credit.
        // Reports derive paid from active payment rows, so both must exist and sum to the total.
        $payments = PurchasePayment::where('purchase_id', $purchase->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
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

    // Regression — split-owner invoice with cash + deduction must not drift per owner. Cash and
    // deduction are allocated and due is derived as the residual, so each owner document satisfies
    // paid + due == total exactly, while invoice-level sums still reconcile.
    public function test_split_owner_with_deduction_keeps_paid_plus_due_equal_to_total_per_owner(): void
    {
        $batch = $this->makeBatch();
        // Uneven line totals 3333 + 6667 = 10000; cash 6000 + deduction 1300 + outstanding 2700.
        $this->makeRow($batch, [
            'no_faktur' => 'DRIFT-1', 'produk' => 'PROD A', 'harga_satuan' => '3333', 'kuantitas' => '1',
            'tag' => 'cv tiga nusa',
            'source_total' => '10000', 'pembayaran' => '6000', 'jumlah_pemotongan' => '1300', 'sisa_tagihan' => '2700',
        ], 1);
        $this->makeRow($batch, [
            'no_faktur' => 'DRIFT-1', 'produk' => 'PROD B', 'harga_satuan' => '6667', 'kuantitas' => '1',
            'tag' => 'cv top it',
            'source_total' => '10000', 'pembayaran' => '6000', 'jumlah_pemotongan' => '1300', 'sisa_tagihan' => '2700',
        ], 2);

        $this->service->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'DRIFT-1')->get();
        $this->assertCount(2, $purchases);

        // Per-owner invariant: paid + due reconciles to that owner's total exactly (no cent drift).
        foreach ($purchases as $purchase) {
            $this->assertEqualsWithDelta(
                (float) $purchase->total_amount,
                (float) $purchase->paid_amount + (float) $purchase->due_amount,
                0.001,
                "Owner document {$purchase->id} paid + due must equal its total"
            );
        }

        // Invoice-level sums still reconcile to the source values.
        $this->assertEqualsWithDelta(10000, round($purchases->sum('total_amount'), 2), 0.01);
        $this->assertEqualsWithDelta(7300, round($purchases->sum('paid_amount'), 2), 0.01); // cash 6000 + deduction 1300
        $this->assertEqualsWithDelta(2700, round($purchases->sum('due_amount'), 2), 0.01);
    }

    // Regression — a tiny owner group must never be over-settled into a negative due_amount.
    // Reviewer case: totals [0.14, 14.14], cash 10.71, deduction 3.57 (fully paid, outstanding 0).
    public function test_tiny_owner_group_with_deduction_never_persists_negative_due(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, [
            'no_faktur' => 'TINY-1', 'produk' => 'PROD A', 'harga_satuan' => '0.14', 'kuantitas' => '1',
            'tag' => 'cv tiga nusa',
            'source_total' => '14.28', 'pembayaran' => '10.71', 'jumlah_pemotongan' => '3.57', 'sisa_tagihan' => '0',
        ], 1);
        $this->makeRow($batch, [
            'no_faktur' => 'TINY-1', 'produk' => 'PROD B', 'harga_satuan' => '14.14', 'kuantitas' => '1',
            'tag' => 'cv top it',
            'source_total' => '14.28', 'pembayaran' => '10.71', 'jumlah_pemotongan' => '3.57', 'sisa_tagihan' => '0',
        ], 2);

        $this->service->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'TINY-1')->get();
        $this->assertCount(2, $purchases);

        foreach ($purchases as $purchase) {
            $this->assertGreaterThanOrEqual(0, (float) $purchase->due_amount, "due_amount must not be negative for document {$purchase->id}");
            // Active payment rows for an owner must not exceed its document total.
            $activePaid = PurchasePayment::where('purchase_id', $purchase->id)
                ->where('status', PurchasePayment::STATUS_ACTIVE)
                ->sum('amount') / 100; // amounts persisted as cents
            $this->assertLessThanOrEqual((float) $purchase->total_amount + 0.001, (float) $activePaid);
            $this->assertEqualsWithDelta(
                (float) $purchase->total_amount,
                (float) $purchase->paid_amount + (float) $purchase->due_amount,
                0.001
            );
        }

        $this->assertEqualsWithDelta(14.28, round($purchases->sum('total_amount'), 2), 0.01);
        $this->assertEqualsWithDelta(14.28, round($purchases->sum('paid_amount'), 2), 0.01); // fully settled
        $this->assertEqualsWithDelta(0, round($purchases->sum('due_amount'), 2), 0.01);
    }

    // Regression — JL00158527: an all-zero-line invoice whose only value is document shipping must
    // carry the shipping on its single owner group so the source total reconciles (gross 0 +
    // shipping 4000 == Total 4000), and import as fully paid.
    public function test_zero_line_invoice_with_only_shipping_reconciles_and_imports(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, [
            'no_faktur' => 'SHIP-ONLY-1', 'produk' => 'MONITOR SAMPLE', 'tag' => 'rahmat',
            'harga_satuan' => '0', 'kuantitas' => '1', 'pajak' => '0',
            'diskon' => '0', 'biaya_pengiriman' => '4000',
            'source_total' => '4000', 'pembayaran' => '4000', 'sisa_tagihan' => '0',
        ], 1);

        $this->service->processBatch($batch);

        $purchase = $this->purchase('SHIP-ONLY-1');
        $this->assertNotNull($purchase, 'Purchase should be created (shipping must reconcile the zero-line invoice)');
        $this->assertEqualsWithDelta(4000, (float) $purchase->total_amount, 0.01);
        $this->assertEqualsWithDelta(4000, (float) $purchase->shipping_amount, 0.01);
        $this->assertEqualsWithDelta(4000, (float) $purchase->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $purchase->due_amount, 0.01);
    }

    // Regression — invoice 11023: a fractional quantity (23.7 KG) must not be truncated to 23,
    // or the calculated total drifts from the source. Total = 870000 + 875000 + 435000 + 230000
    // + 230000 + 23.7*12500 (296250) = 2936250.
    public function test_fractional_quantity_is_not_truncated_and_invoice_reconciles(): void
    {
        $batch = $this->makeBatch();
        $lines = [
            ['MONITOR A', '60', '14500'],
            ['MONITOR B', '100', '8750'],
            ['MONITOR C', '30', '14500'],
            ['MONITOR D', '40', '5750'],
            ['MONITOR E', '40', '5750'],
            ['CABLE ROLL F', '23.7', '12500'],
        ];
        foreach ($lines as $i => [$produk, $qty, $harga]) {
            $this->makeRow($batch, [
                'no_faktur' => '11023', 'produk' => $produk, 'tag' => 'rahmat',
                'harga_satuan' => $harga, 'kuantitas' => $qty, 'pajak' => '0',
                'source_total' => '2936250', 'pembayaran' => '2936250', 'sisa_tagihan' => '0',
            ], $i + 1);
        }

        $this->service->processBatch($batch);

        $purchase = $this->purchase('11023');
        $this->assertNotNull($purchase, 'Invoice 11023 should import (fractional qty must reconcile)');
        $this->assertEqualsWithDelta(2936250, (float) $purchase->total_amount, 0.01);
        $this->assertEqualsWithDelta(2936250, (float) $purchase->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $purchase->due_amount, 0.01);

        // The fractional line preserves 23.7 rather than truncating to 23.
        $fractionalDetail = $purchase->purchaseDetails()->where('product_name', 'like', '%CABLE ROLL F%')->first();
        $this->assertNotNull($fractionalDetail);
        $this->assertEqualsWithDelta(23.7, (float) $fractionalDetail->quantity, 0.001);
    }
}

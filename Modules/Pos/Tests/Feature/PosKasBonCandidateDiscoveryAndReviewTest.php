<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosKasBonReviewService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosKasBonCandidateDiscoveryAndReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $currentCashier;
    protected User $previousCashier;
    protected Setting $setting1;
    protected Setting $setting2;
    protected PosTerminal $terminal;
    protected PosSession $activeSession;
    protected PosSession $previousSession;
    protected Customer $customerA;
    protected Customer $customerB;
    protected PaymentMethod $cashMethod;
    protected Unit $unit;
    protected Category $category;
    protected Product $product1;
    protected Product $product2;
    protected Location $location1;
    protected Location $location2;
    protected PosKasBonReviewService $reviewService;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Toko Cabang Satu',
            'company_email' => 'toko1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'toko1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->setting2 = Setting::firstOrCreate(['id' => 2], [
            'company_name' => 'Toko Cabang Dua',
            'company_email' => 'toko2@example.com',
            'company_phone' => '0812',
            'company_address' => 'Addr 2',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'toko2@example.com',
            'footer_text' => 'F2',
            'document_prefix' => 'D2',
            'purchase_prefix_document' => 'P2',
            'sale_prefix_document' => 'S2',
            'pos_enabled' => true,
        ]);

        $this->location1 = Location::create(['name' => 'Gudang 1', 'setting_id' => $this->setting1->id]);
        $this->location2 = Location::create(['name' => 'Gudang 2', 'setting_id' => $this->setting2->id]);

        $this->currentCashier = User::factory()->create(['name' => 'Kasir Sekarang']);
        $this->previousCashier = User::factory()->create(['name' => 'Kasir Sebelumnya']);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Terminal Kasir 1',
            'code' => 'T01',
            'is_active' => true,
        ]);

        $this->terminal2 = PosTerminal::create([
            'setting_id' => $this->setting2->id,
            'name' => 'Terminal Kasir 2',
            'code' => 'T02',
            'is_active' => true,
        ]);

        $this->previousSession = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->previousCashier->id,
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDays(1),
            'status' => PosSession::STATUS_CLOSED,
            'active_marker' => null,
            'expected_cash_total' => 500000,
        ]);

        $this->activeSession = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->currentCashier->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'expected_cash_total' => 100000,
        ]);

        $coa = ChartOfAccount::create([
            'setting_id' => $this->setting1->id,
            'account_number' => '1101-CASH',
            'name' => 'Kas Toko 1',
            'category' => 'Kas & Bank',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Cash',
            'is_cash' => true,
            'is_active' => true,
            'coa_id' => $coa->id,
        ]);

        $this->unit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->category = Category::create([
            'category_code' => 'CAT01',
            'category_name' => 'Kategori Umum',
            'created_by' => $this->currentCashier->id,
            'setting_id' => $this->setting1->id,
        ]);

        $this->product1 = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Beras Pandan Wangi 5kg',
            'product_code' => 'BRS-01',
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'product_cost' => 60000,
            'product_price' => 75000,
            'product_quantity' => 100,
        ]);

        $this->product2 = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Minyak Goreng 2L',
            'product_code' => 'MYK-02',
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'product_cost' => 30000,
            'product_price' => 35000,
            'product_quantity' => 100,
        ]);

        $this->customerA = Customer::create([
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '081299990001',
            'customer_email' => 'budi@example.com',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl. Merdeka No. 1',
        ]);

        $this->customerB = Customer::create([
            'customer_name' => 'Siti Aminah',
            'customer_phone' => '081299990002',
            'customer_email' => 'siti@example.com',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Jl. Pahlawan No. 2',
        ]);

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.kasBon.collect', 'web');

        $this->reviewService = new PosKasBonReviewService();
    }

    protected function createCompleteKasBonFixture(
        Setting $setting,
        Customer $customer,
        User $cashier,
        PosSession $session,
        string $trxCode,
        string $receiptNumber,
        float $totalAmount,
        float $downPayment,
        ?string $note = null,
        bool $isDebt = true,
        ?Setting $splitOwnerSetting = null
    ): array {
        $trx = PosTransaction::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'code' => $trxCode,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
            'note' => $note,
        ]);

        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $trx->id,
            'line_no' => 1,
            'product_id' => $this->product1->id,
            'product_name_snapshot' => $this->product1->product_name,
            'product_code_snapshot' => $this->product1->product_code,
            'qty' => 1,
            'unit_price' => $totalAmount,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
        ]);

        $dueAmount = max(0.0, round($totalAmount - $downPayment, 2));
        $paymentStatus = $dueAmount <= 0.005 ? 'Paid' : ($downPayment > 0.005 ? 'Partial' : 'Unpaid');

        $saleOwnerSetting = $splitOwnerSetting ?? $setting;

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-' . $trxCode,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'paid_amount' => $downPayment,
            'due_amount' => $dueAmount,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => $paymentStatus,
            'payment_method' => 'Cash',
            'setting_id' => $saleOwnerSetting->id,
            'note' => $note,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->product_name,
            'product_code' => $this->product1->product_code,
            'quantity' => 1,
            'price' => $totalAmount,
            'unit_price' => $totalAmount,
            'sub_total' => $totalAmount,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        if ($downPayment > 0) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $downPayment,
                'date' => now()->toDateString(),
                'reference' => 'SP-DP-' . $trxCode,
                'payment_method_id' => $this->cashMethod->id,
                'payment_method' => 'Cash',
                'status' => SalePayment::STATUS_ACTIVE,
            ]);
        }

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $session->terminal_id,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => 'idemp-' . $trxCode,
            'payload_hash' => hash('sha256', $trxCode),
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => $downPayment,
            'debt_amount' => $dueAmount,
            'receipt_number' => $receiptNumber,
            'note' => $note,
            'metadata' => [
                'is_debt' => $isDebt,
            ],
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_' . $trxCode,
            'tax_bucket' => 'notax',
            'sale_id' => $sale->id,
            'source_setting_id' => $saleOwnerSetting->id,
            'source_location_id' => $saleOwnerSetting->id === 1 ? $this->location1->id : $this->location2->id,
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => $downPayment,
        ]);

        return [
            'transaction' => $trx,
            'checkout' => $checkout,
            'sale' => $sale,
            'detail' => $detail,
            'line' => $line1,
        ];
    }

    public function test_candidates_query_discovers_previous_cashier_and_session_debt(): void
    {
        // Debt created by previous cashier in closed session
        $kb1 = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerA,
            $this->previousCashier,
            $this->previousSession,
            'TRX-KB-PREV-01',
            'RCP-PREV-01',
            150000,
            50000,
            'Kas bon lama beras'
        );

        $this->currentCashier->givePermissionTo(['pos.access', 'pos.kasBon.collect']);

        $response = $this->actingAs($this->currentCashier)
            ->withSession(['setting_id' => $this->setting1->id])
            ->getJson(route('pos.kas-bon.candidates'));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('TRX-KB-PREV-01', $data[0]['code']);
        $this->assertEquals('RCP-PREV-01', $data[0]['receipt_number']);
        $this->assertEqualsIgnoringCase('Budi Santoso', $data[0]['customer_name']);
        $this->assertEquals(150000.0, (float) $data[0]['original_total']);
        $this->assertEquals(150000.0, (float) $data[0]['current_total']);
        $this->assertEquals(50000.0, (float) $data[0]['effective_paid']);
        $this->assertEquals(100000.0, (float) $data[0]['live_due']);
    }

    public function test_candidates_excludes_ordinary_pos_and_fully_paid_and_other_business(): void
    {
        // 1. Debt in setting 1 (should appear)
        $validKb = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerA,
            $this->currentCashier,
            $this->activeSession,
            'TRX-KB-VALID',
            'RCP-VALID',
            200000,
            0,
            'Valid debt'
        );

        // 2. Ordinary POS checkout (paid in full, is_debt = false) -> should be excluded
        $ordinaryPos = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerB,
            $this->currentCashier,
            $this->activeSession,
            'TRX-ORD-POS',
            'RCP-ORD-POS',
            100000,
            100000,
            'Ordinary cash checkout',
            false
        );

        // 3. Fully paid debt (down payment = total) -> should be excluded
        $paidDebt = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerB,
            $this->currentCashier,
            $this->activeSession,
            'TRX-PAID-DEBT',
            'RCP-PAID-DEBT',
            80000,
            80000,
            'Debt already fully settled',
            true
        );

        // 4. Debt in Setting 2 (cross-business) -> should be excluded from Setting 1
        $otherBizSession = PosSession::create([
            'setting_id' => $this->setting2->id,
            'terminal_id' => $this->terminal2->id,
            'cashier_user_id' => $this->previousCashier->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'expected_cash_total' => 0,
        ]);
        $otherBizDebt = $this->createCompleteKasBonFixture(
            $this->setting2,
            $this->customerA,
            $this->previousCashier,
            $otherBizSession,
            'TRX-OTHER-BIZ',
            'RCP-OTHER-BIZ',
            300000,
            0,
            'Debt in setting 2',
            true
        );

        $paginator = $this->reviewService->getCandidates($this->setting1->id);
        $items = collect($paginator->items());

        $this->assertCount(1, $items);
        $this->assertEquals('TRX-KB-VALID', $items->first()['code']);
    }

    public function test_candidates_includes_split_owner_transaction_in_active_setting(): void
    {
        // POS header belongs to setting 1, but child Sale belongs to setting 2 (split owner)
        $splitOwnerDebt = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerA,
            $this->currentCashier,
            $this->activeSession,
            'TRX-SPLIT-OWNER',
            'RCP-SPLIT-01',
            120000,
            20000,
            'Split owner kas bon',
            true,
            $this->setting2 // Child sale owned by setting 2
        );

        $paginator = $this->reviewService->getCandidates($this->setting1->id);
        $items = collect($paginator->items());

        $this->assertCount(1, $items);
        $this->assertEquals('TRX-SPLIT-OWNER', $items->first()['code']);
        $this->assertEquals(100000.0, (float) $items->first()['live_due']);
    }

    public function test_candidates_search_over_every_displayed_column(): void
    {
        $kb1 = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerA,
            $this->currentCashier,
            $this->activeSession,
            'TRX-CODE-ALPHA',
            'RCP-ALPHA-99',
            250000,
            50000, // debt: 200000
            'Catatan Khusus Beras Wangi'
        );

        $kb2 = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerB,
            $this->currentCashier,
            $this->activeSession,
            'TRX-CODE-BETA',
            'RCP-BETA-77',
            175000,
            25000, // debt: 150000
            'Catatan Minyak Murah'
        );

        // 1. Search by transaction code
        $res = $this->reviewService->getCandidates($this->setting1->id, 'ALPHA');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-ALPHA', $res->items()[0]['code']);

        // 2. Search by receipt number
        $res = $this->reviewService->getCandidates($this->setting1->id, 'BETA-77');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-BETA', $res->items()[0]['code']);

        // 3. Search by customer name
        $res = $this->reviewService->getCandidates($this->setting1->id, 'Siti');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-BETA', $res->items()[0]['code']);

        // 4. Search by note
        $res = $this->reviewService->getCandidates($this->setting1->id, 'Beras Wangi');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-ALPHA', $res->items()[0]['code']);

        // 5. Search by original total (formatted and canonical)
        $res = $this->reviewService->getCandidates($this->setting1->id, '250.000');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-ALPHA', $res->items()[0]['code']);

        $res = $this->reviewService->getCandidates($this->setting1->id, 'Rp 175.000');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-BETA', $res->items()[0]['code']);

        // 6. Search by paid amount
        $res = $this->reviewService->getCandidates($this->setting1->id, '50000');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-ALPHA', $res->items()[0]['code']);

        // 7. Search by remaining debt (e.g. 150000)
        $res = $this->reviewService->getCandidates($this->setting1->id, '150.000');
        $this->assertCount(1, $res->items());
        $this->assertEquals('TRX-CODE-BETA', $res->items()[0]['code']);
    }

    public function test_review_returns_current_bill_projection_and_fingerprint(): void
    {
        $kb = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerA,
            $this->currentCashier,
            $this->activeSession,
            'TRX-REVIEW-01',
            'RCP-REV-01',
            150000,
            50000,
            'Catatan tagihan'
        );

        $this->currentCashier->givePermissionTo(['pos.access', 'pos.kasBon.collect']);

        $response = $this->actingAs($this->currentCashier)
            ->withSession(['setting_id' => $this->setting1->id])
            ->getJson(route('pos.kas-bon.review', $kb['transaction']->id));

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('TRX-REVIEW-01', $data['code']);
        $this->assertEquals('RCP-REV-01', $data['receipt_number']);
        $this->assertEquals(150000.0, (float) $data['original_total']);
        $this->assertEquals(150000.0, (float) $data['current_total']);
        $this->assertEquals(50000.0, (float) $data['effective_paid']);
        $this->assertEquals(100000.0, (float) $data['live_due']);
        $this->assertNotEmpty($data['lines']);
        $this->assertNotEmpty($data['fingerprint']);
        $this->assertTrue($data['is_eligible']);
    }

    public function test_review_reflects_modified_sale_price_and_changes_fingerprint(): void
    {
        $kb = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerA,
            $this->currentCashier,
            $this->activeSession,
            'TRX-REVIEW-PRICE-MOD',
            'RCP-MOD-01',
            150000,
            50000
        );

        $initialReview = $this->reviewService->review($kb['transaction']);
        $this->assertEquals(150000.0, $initialReview['current_total']);
        $this->assertEquals(100000.0, $initialReview['live_due']);
        $initialFingerprint = $initialReview['fingerprint'];

        // Now modify the Sale and SaleDetail price (e.g. price adjusted from 150k to 180k)
        $kb['detail']->update([
            'price' => 180000,
            'unit_price' => 180000,
            'sub_total' => 180000,
        ]);
        $kb['sale']->update([
            'total_amount' => 180000,
            'due_amount' => 130000,
        ]);

        $updatedReview = $this->reviewService->review($kb['transaction']->fresh());
        $this->assertEquals(180000.0, $updatedReview['current_total']);
        $this->assertEquals(130000.0, $updatedReview['live_due']);
        $this->assertNotEquals($initialFingerprint, $updatedReview['fingerprint']);
    }

    public function test_review_blocks_ambiguous_or_ineligible_mapping(): void
    {
        $kb = $this->createCompleteKasBonFixture(
            $this->setting1,
            $this->customerA,
            $this->currentCashier,
            $this->activeSession,
            'TRX-INELIGIBLE',
            'RCP-INELIGIBLE',
            100000,
            0
        );

        // Archive the sale or mark it cancelled
        $kb['sale']->update(['status' => 'Cancelled']);

        $this->expectException(ValidationException::class);
        $this->reviewService->review($kb['transaction']->fresh());
    }
}

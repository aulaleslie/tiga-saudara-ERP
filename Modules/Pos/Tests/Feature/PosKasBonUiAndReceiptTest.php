<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTemporaryPaymentImage;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Unit;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosKasBonUiAndReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected User $cashier;
    protected User $otherUser;
    protected Customer $customer;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $transferMethod;
    protected PaymentMethod $debtMethod;
    protected Product $product;
    protected Location $location;

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

        $this->setting = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Toko Kas Bon Utama',
            'company_email' => 'kasbon@example.com',
            'company_phone' => '08123456789',
            'company_address' => 'Jl. Kasir No. 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'kasbon@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'KB',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
            'pos_enabled' => true,
        ]);

        $this->location = Location::create(['name' => 'Toko Utama', 'setting_id' => $this->setting->id]);

        $coa = ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'account_number' => '1101-CASH',
            'name' => 'Kas Toko',
            'category' => 'Kas & Bank',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'is_cash' => true,
            'is_active' => true,
            'coa_id' => $coa->id,
        ]);

        $this->transferMethod = PaymentMethod::create([
            'name' => 'Bank Transfer',
            'is_cash' => false,
            'is_active' => true,
            'coa_id' => $coa->id,
        ]);

        $this->debtMethod = PaymentMethod::create([
            'name' => 'Kas Bon Piutang',
            'is_cash' => false,
            'is_active' => true,
            'coa_id' => $coa->id,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->cashMethod->id,
            'is_enabled' => true,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->transferMethod->id,
            'is_enabled' => true,
        ]);

        $this->cashier = User::factory()->create(['name' => 'Budi Kasir']);
        $this->otherUser = User::factory()->create(['name' => 'User Biasa']);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $category = Category::create([
            'category_code' => 'CAT',
            'category_name' => 'Umum',
            'created_by' => $this->cashier->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Barang Kas Bon',
            'product_code' => 'PRD-KB',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 100,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Kasir Counter 1',
            'code' => 'K01',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
        ]);

        \Modules\Pos\Entities\PosSessionCashEvent::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'event_type' => \Modules\Pos\Entities\PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => \Modules\Pos\Entities\PosSessionCashEvent::DIRECTION_IN,
            'amount' => 100000,
            'occurred_at' => now(),
            'performed_by' => $this->cashier->id,
        ]);

        $this->customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Pelanggan Setia',
            'customer_email' => 'setia@example.com',
            'customer_phone' => '0812999888',
        ]);

        // Permissions
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.sell', 'web');
        Permission::findOrCreate('pos.kasBon.collect', 'web');
        Permission::findOrCreate('pos.receipts.reprint', 'web');

        $this->cashier->givePermissionTo(['pos.access', 'pos.sell', 'pos.kasBon.collect']);
    }

    protected function createKasBonTransaction(float $totalAmount = 150000, float $downPayment = 50000): array
    {
        $trx = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'code' => 'TRX-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'note' => 'Catatan Kas Bon Belanja',
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $trx->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'product_code_snapshot' => $this->product->product_code,
            'qty' => 1,
            'unit_price' => $totalAmount,
            'subtotal' => $totalAmount,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-' . $trx->code,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'paid_amount' => $downPayment,
            'due_amount' => $totalAmount - $downPayment,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => $downPayment > 0 ? 'Partial' : 'Unpaid',
            'payment_method' => 'Kasbon',
            'setting_id' => $this->setting->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
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
                'reference' => 'PAY-DP-' . uniqid(),
                'payment_method' => $this->cashMethod->name,
                'payment_method_id' => $this->cashMethod->id,
                'status' => SalePayment::STATUS_ACTIVE,
                'pos_session_id' => $this->session->id,
                'user_id' => $this->cashier->id,
                'setting_id' => $this->setting->id,
            ]);
            $sale->reconcileFromActivePayments();
        }

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'pos_transaction_id' => $trx->id,
            'receipt_number' => 'RCP-' . uniqid(),
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => 'idemp-' . $trx->code,
            'payload_hash' => hash('sha256', $trx->code),
            'subtotal' => $totalAmount,
            'tax_total' => 0,
            'discount_total' => 0,
            'grand_total' => $totalAmount,
            'paid_total' => $downPayment,
            'tender_amount' => $downPayment,
            'change_amount' => 0,
            'debt_amount' => $totalAmount - $downPayment,
            'metadata' => [
                'is_debt' => true,
            ],
            'meta' => [
                'is_debt' => true,
                'settlement' => [
                    'is_debt' => true,
                    'paid_amount' => $downPayment,
                    'remaining_balance' => $totalAmount - $downPayment,
                ],
            ],
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'split_key' => 'DEFAULT',
            'tax_bucket' => 'NONE',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => $downPayment,
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        return [
            'transaction' => $trx,
            'sale' => $sale,
            'checkout' => $checkout,
        ];
    }

    public function test_candidates_endpoint_returns_data_and_searches_columns(): void
    {
        $kb = $this->createKasBonTransaction(200000, 50000);

        // Request candidates as authorized cashier
        $res = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.candidates'));

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.code', $kb['transaction']->code);
        $res->assertJsonPath('data.0.live_due', 150000);

        // Search by customer name
        $searchRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.candidates', ['search' => 'Setia']));

        $searchRes->assertOk();
        $searchRes->assertJsonCount(1, 'data');

        // Search by numeric remaining debt
        $numRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.candidates', ['search' => '150000']));

        $numRes->assertOk();
        $numRes->assertJsonCount(1, 'data');

        // Search non-matching text
        $missRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.candidates', ['search' => 'NonExistent']));

        $missRes->assertOk();
        $missRes->assertJsonCount(0, 'data');
    }

    public function test_payment_methods_endpoint_excludes_debt_methods(): void
    {
        $res = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.payment-methods'));

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $methods = $res->json('methods');

        // Verify Cash and Bank Transfer exist
        $methodNames = array_map('strtoupper', array_column($methods, 'name'));
        $this->assertContains('CASH', $methodNames);
        $this->assertContains('BANK TRANSFER', $methodNames);

        // Verify Kas Bon / Piutang is excluded!
        $this->assertNotContains('KAS BON PIUTANG', $methodNames);
    }

    public function test_review_endpoint_returns_authoritative_bill_and_fingerprint(): void
    {
        $kb = $this->createKasBonTransaction(180000, 60000);

        $res = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.review', ['transaction_id' => $kb['transaction']->id]));

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.code', $kb['transaction']->code);
        $res->assertJsonPath('data.current_total', 180000);
        $res->assertJsonPath('data.effective_paid', 60000);
        $res->assertJsonPath('data.live_due', 120000);
        $this->assertNotEmpty($res->json('data.fingerprint'));

        // Assert that line items correctly map projection qty and price (not defaulting to 0)
        $lines = $res->json('data.lines');
        $this->assertNotEmpty($lines);
        $this->assertEquals(1, $lines[0]['quantity']);
        $this->assertEquals(1, $lines[0]['qty']);
        $this->assertEquals(180000, (float) $lines[0]['unit_price']);
        $this->assertEquals(180000, (float) $lines[0]['price']);
        $this->assertEquals(180000, (float) $lines[0]['sub_total']);
    }

    public function test_collect_endpoint_handles_settlement_with_tender_and_change(): void
    {
        $kb = $this->createKasBonTransaction(100000, 0);

        // Get review first
        $reviewRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.review', ['transaction_id' => $kb['transaction']->id]));
        $fingerprint = $reviewRes->json('data.fingerprint');

        // Partial payment: pay 50k with 100k cash tender -> 50k change
        $collectPayload = [
            'fingerprint' => $fingerprint,
            'amount_to_pay' => 50000,
            'methods' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => 50000,
                    'note' => 'Cicilan kedua',
                ],
            ],
            'idempotency_key' => 'ui-test-' . uniqid(),
            'note' => 'Cicilan pertama',
        ];

        $res = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.kas-bon.collect', ['transaction_id' => $kb['transaction']->id]), $collectPayload);

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('amount_collected', 50000);
        $res->assertJsonPath('tender_total', 50000);
        $res->assertJsonPath('change_total', 0);
        $res->assertJsonPath('remaining_debt', 50000);
        $this->assertNotEmpty($res->json('receipt_url'));

        // Drawer expected cash increased by net cash 50,000 (100k opening + 50k net collection = 150k)
        $this->session->refresh();
        $this->assertEquals(150000, (float) $this->session->expected_cash_total);
    }

    public function test_receipt_view_renders_immutable_snapshot_and_logs_print_audit(): void
    {
        $kb = $this->createKasBonTransaction(120000, 20000);

        $reviewRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.review', ['transaction_id' => $kb['transaction']->id]));
        $fingerprint = $reviewRes->json('data.fingerprint');

        $collectRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.kas-bon.collect', ['transaction_id' => $kb['transaction']->id]), [
                'fingerprint' => $fingerprint,
                'amount_to_pay' => 100000,
                'methods' => [
                    ['payment_method_id' => $this->cashMethod->id, 'amount' => 60000, 'note' => 'Cicilan kedua'],
                    ['payment_method_id' => $this->transferMethod->id, 'amount' => 40000, 'note' => 'Transfer BCA', 'reference' => 'TRF-12345'],
                ],
                'idempotency_key' => 'receipt-test-' . uniqid(),
            ]);

        $collectionId = $collectRes->json('collection_id');
        $collectionRef = $collectRes->json('reference');

        // Request printable receipt view
        $receiptViewRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.kas-bon.collections.receipt', ['collection_id' => $collectionId]));

        $receiptViewRes->assertOk();
        $content = $receiptViewRes->getContent();

        // Verify HTML contains required receipt elements
        $this->assertStringContainsString('STRUK PEMBAYARAN KAS BON', strtoupper($content));
        $this->assertStringContainsString($collectionRef, $content);
        $this->assertStringContainsString($kb['transaction']->code, $content);
        $this->assertStringContainsStringIgnoringCase('Pelanggan Setia', $content);
        $this->assertStringContainsStringIgnoringCase('Budi Kasir', $content);
        $this->assertStringContainsStringIgnoringCase('Cash', $content);
        $this->assertStringContainsStringIgnoringCase('Bank Transfer', $content);

        // Verify audit log for initial print
        $this->assertDatabaseHas('pos_receipt_print_logs', [
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $kb['transaction']->id,
            'print_type' => PosReceiptPrintLog::TYPE_PRINT,
            'printed_by' => $this->cashier->id,
        ]);

        // Subsequent opening of the receipt endpoint without pos.receipts.reprint must be forbidden (403)
        $unauthorizedSecondRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.kas-bon.collections.receipt', ['collection_id' => $collectionId]));
        $unauthorizedSecondRes->assertForbidden();

        // Grant pos.receipts.reprint permission
        $this->cashier->givePermissionTo('pos.receipts.reprint');

        // Subsequent opening of the receipt endpoint with permission succeeds, renders reprint badge, and logs REPRINT
        $authorizedSecondRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.kas-bon.collections.receipt', ['collection_id' => $collectionId]));
        $authorizedSecondRes->assertOk();
        $this->assertStringContainsString('SALINAN / REPRINT STRUK', $authorizedSecondRes->getContent());

        $this->assertDatabaseHas('pos_receipt_print_logs', [
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $kb['transaction']->id,
            'print_type' => PosReceiptPrintLog::TYPE_REPRINT,
            'printed_by' => $this->cashier->id,
        ]);
    }

    public function test_receipt_reprint_requires_permission_renders_reprint_badge_and_logs_audit(): void
    {
        $kb = $this->createKasBonTransaction(75000, 0);

        $reviewRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.review', ['transaction_id' => $kb['transaction']->id]));
        $fingerprint = $reviewRes->json('data.fingerprint');

        $collectRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.kas-bon.collect', ['transaction_id' => $kb['transaction']->id]), [
                'fingerprint' => $fingerprint,
                'amount_to_pay' => 75000,
                'methods' => [
                    ['payment_method_id' => $this->cashMethod->id, 'amount' => 75000, 'tender_amount' => 75000],
                ],
                'idempotency_key' => 'reprint-perm-test-' . uniqid(),
            ]);
        $collectionId = $collectRes->json('collection_id');

        // Cashier does not have reprint permission yet
        $unauthorizedReprintRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.kas-bon.collections.receipt.reprint', ['collection_id' => $collectionId]));
        $unauthorizedReprintRes->assertForbidden();

        // Grant pos.receipts.reprint to cashier
        $this->cashier->givePermissionTo('pos.receipts.reprint');

        // Now authorized reprint
        $authorizedReprintRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.kas-bon.collections.receipt.reprint', ['collection_id' => $collectionId]));

        $authorizedReprintRes->assertOk();
        $content = $authorizedReprintRes->getContent();
        $this->assertStringContainsString('SALINAN / REPRINT STRUK', $content);

        // Verify audit log for reprint
        $this->assertDatabaseHas('pos_receipt_print_logs', [
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $kb['transaction']->id,
            'print_type' => PosReceiptPrintLog::TYPE_REPRINT,
            'printed_by' => $this->cashier->id,
        ]);
    }

    public function test_receipt_content_remains_stable_even_after_subsequent_sale_payment_or_price_change(): void
    {
        $kb = $this->createKasBonTransaction(100000, 0);

        $reviewRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.review', ['transaction_id' => $kb['transaction']->id]));
        $fingerprint = $reviewRes->json('data.fingerprint');

        // Partial collection of 40,000. Sisa hutang at collection time is 60,000.
        $collectRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.kas-bon.collect', ['transaction_id' => $kb['transaction']->id]), [
                'fingerprint' => $fingerprint,
                'amount_to_pay' => 40000,
                'methods' => [
                    ['payment_method_id' => $this->cashMethod->id, 'amount' => 40000, 'tender_amount' => 40000],
                ],
                'idempotency_key' => 'stability-test-' . uniqid(),
            ]);
        $collectionId = $collectRes->json('collection_id');

        // Later, Sales module receives another payment outside POS of 60,000, making debt 0
        SalePayment::create([
            'sale_id' => $kb['sale']->id,
            'amount' => 60000,
            'date' => now()->toDateString(),
            'reference' => 'EXTERNAL-PAY-' . uniqid(),
            'payment_method' => $this->transferMethod->name,
            'payment_method_id' => $this->transferMethod->id,
            'status' => SalePayment::STATUS_ACTIVE,
            'user_id' => $this->cashier->id,
            'setting_id' => $this->setting->id,
        ]);
        $kb['sale']->reconcileFromActivePayments();
        $this->assertEquals(0, (float) $kb['sale']->live_due_amount);

        // Grant reprint permission
        $this->cashier->givePermissionTo('pos.receipts.reprint');

        // When reprinting the earlier partial collection receipt, it MUST still show remaining debt of 60,000!
        $reprintRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.kas-bon.collections.receipt.reprint', ['collection_id' => $collectionId]));

        $reprintRes->assertOk();
        $content = $reprintRes->getContent();

        // Must display the recorded immutable snapshot facts (40k collected, 60k remaining)
        $this->assertStringContainsString('40.000', $content);
        $this->assertStringContainsString('60.000', $content);
    }

    public function test_pos_sell_view_renders_bayar_kas_bon_button_for_authorized_cashier(): void
    {
        $res = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.sell'));

        $res->assertOk();
        $content = $res->getContent();

        // Check for compact action container, button, modal, script, and clean posting note
        $this->assertStringContainsString('class="pos-nav-actions"', $content);
        $this->assertStringContainsString('id="pos-btn-bayar-kas-bon"', $content);
        $this->assertStringContainsString('id="pos-nav-menu-dropdown"', $content);
        $this->assertStringContainsString('id="pos-kas-bon-modal"', $content);
        $this->assertStringContainsString('pos-kas-bon-collection.js', $content);
        $this->assertStringContainsString('id="pos-shell-posting-note"', $content);
        $this->assertStringNotContainsString('>pos-shell-posting-note<', $content);

        // Check revised payment modal structure
        $this->assertStringContainsString('id="pos-kb-calc-total-payment"', $content);
        $this->assertStringContainsString('id="pos-kb-calc-remaining-debt"', $content);
        $this->assertStringContainsString('Keterangan', $content);
        $this->assertStringNotContainsString('id="pos-kb-input-amount"', $content);
        $this->assertStringNotContainsString('id="pos-kb-btn-pay-full"', $content);
        $this->assertStringNotContainsString('id="pos-kb-calc-remaining"', $content);
        $this->assertStringNotContainsString('Tender / Bukti', $content);
        $this->assertStringNotContainsString('id="pos-kb-calc-cash-tender"', $content);
        $this->assertStringNotContainsString('id="pos-kb-calc-change"', $content);

        // Check unauthorized user without pos.kasBon.collect
        $this->cashier->revokePermissionTo('pos.kasBon.collect');

        $unauthRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('pos.sell'));

        $unauthRes->assertOk();
        $unauthContent = $unauthRes->getContent();
        $this->assertStringContainsString('class="pos-nav-actions"', $unauthContent);
        $this->assertStringContainsString('id="pos-nav-menu-dropdown"', $unauthContent);
        $this->assertStringNotContainsString('id="pos-btn-bayar-kas-bon"', $unauthContent);
        $this->assertStringNotContainsString('>pos-shell-posting-note<', $unauthContent);
    }

    public function test_collect_endpoint_idempotent_retry_succeeds_even_after_session_is_closed(): void
    {
        $kb = $this->createKasBonTransaction(100000, 20000);

        $reviewRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.review', ['transaction_id' => $kb['transaction']->id]));
        $fingerprint = $reviewRes->json('data.fingerprint');

        $idempotencyKey = 'idemp-closed-' . uniqid();
        $collectPayload = [
            'fingerprint' => $fingerprint,
            'amount_to_pay' => 40000,
            'methods' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => 40000,
                    'tender_amount' => 50000,
                ],
            ],
            'idempotency_key' => $idempotencyKey,
            'note' => 'Bayar sebelum tutup sesi',
        ];

        // 1. Initial collection while session is open
        $res1 = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.kas-bon.collect', ['transaction_id' => $kb['transaction']->id]), $collectPayload);

        $res1->assertOk();
        $res1->assertJsonPath('success', true);
        $this->assertFalse($res1->json('replayed') ?? false);

        // 2. Now close the session
        $this->session->update([
            'status' => PosSession::STATUS_CLOSED,
            'closed_at' => now(),
            'active_marker' => null,
        ]);

        // 3. Retry with identical idempotency key and payload after session closure
        $res2 = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.kas-bon.collect', ['transaction_id' => $kb['transaction']->id]), $collectPayload);

        $res2->assertOk();
        $res2->assertJsonPath('success', true);
        $this->assertTrue($res2->json('replayed'));
        $this->assertEquals($res1->json('collection_id'), $res2->json('collection_id'));
    }

    public function test_collect_endpoint_with_valid_attachment_token_succeeds_and_attaches_to_sale_payment(): void
    {
        $kb = $this->createKasBonTransaction(100000, 0);

        Storage::fake('local');
        $fakeFile = UploadedFile::fake()->image('transfer_receipt.jpg', 600, 400);
        $tempPath = $fakeFile->storeAs('pos/temp_payment_images', 'kb-test-token-456.jpg');

        PosTemporaryPaymentImage::create([
            'token' => 'kb-test-token-456',
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'cashier_id' => $this->cashier->id,
            'cart_token' => 'kas-bon-' . $kb['transaction']->id,
            'path' => $tempPath,
            'original_name' => 'transfer_receipt.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'expires_at' => now()->addHour(),
        ]);

        $reviewRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('pos.kas-bon.review', ['transaction_id' => $kb['transaction']->id]));
        $fingerprint = $reviewRes->json('data.fingerprint');

        $collectRes = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.kas-bon.collect', ['transaction_id' => $kb['transaction']->id]), [
                'fingerprint' => $fingerprint,
                'amount_to_pay' => 100000,
                'methods' => [
                    [
                        'payment_method_id' => $this->transferMethod->id,
                        'amount' => 100000,
                        'reference' => 'TF-123456',
                        'attachment' => 'kb-test-token-456',
                    ],
                ],
                'idempotency_key' => 'attach-test-' . uniqid(),
            ]);

        $collectRes->assertOk();
        $collectRes->assertJsonPath('success', true);

        // Verify that child SalePayment was created and media attached
        $payment = SalePayment::where('sale_id', $kb['sale']->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(100000, (float) $payment->amount);
        $this->assertNotEmpty($payment->getMedia('attachments'));
    }

    public function test_pos_kas_bon_collection_script_wording_and_canonical_state_preservation(): void
    {
        $jsPath = public_path('js/pos-kas-bon-collection.js');
        $this->assertFileExists($jsPath);
        $js = file_get_contents($jsPath);

        // Verify wording: no Uang Diterima label, Keterangan placeholder for cash and non-cash
        $this->assertStringNotContainsString('Uang Diterima</label>', $js);
        $this->assertStringContainsString('placeholder="Keterangan"', $js);
        $this->assertStringNotContainsString('placeholder="Uang Diterima"', $js);
        $this->assertStringNotContainsString('placeholder="Ref / Ket"', $js);

        // Verify state preservation logic exists
        $this->assertStringContainsString('syncCurrentDomToState()', $js);
        $this->assertStringContainsString('toCanonicalAmount(', $js);
        $this->assertStringContainsString('FinancialInput.setCanonicalValue', $js);
    }
}



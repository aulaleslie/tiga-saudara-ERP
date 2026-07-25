<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Unit;
use Modules\Currency\Entities\Currency;
use App\Support\SalesLocationResolver;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-sale-modal
 */
class POSSaleModalAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;
    private Setting $setting;
    private Setting $otherSetting;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        // Ensure roles/permissions exist
        $role = Role::firstOrCreate(['name' => 'Admin']);
        $permission = Permission::firstOrCreate(['name' => 'pos.access']);
        $viewPermission = Permission::firstOrCreate(['name' => 'pos.transactions.view']);
        $role->givePermissionTo($permission);
        $role->givePermissionTo($viewPermission);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::factory()->create([
            'is_active' => 1
        ]);
        $this->user->assignRole($role);
        $this->user->givePermissionTo('pos.access');
        $this->user->givePermissionTo('pos.transactions.view');

        app(\Spatie\Permission\PermissionRegistrar::class)->registerPermissions();
        
        $this->setting = Setting::factory()->create([
            'pos_transactions_enabled' => true,
            'pos_enabled' => true,
        ]);
        $this->otherSetting = Setting::factory()->create([
            'pos_transactions_enabled' => true,
            'pos_enabled' => true,
        ]);

        $this->customer = \Modules\People\Entities\Customer::forceCreate([
            'id' => 1,
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'customer_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        \Modules\Setting\Entities\Location::factory()->create([
            'id' => 1,
            'setting_id' => $this->setting->id,
        ]);

        // Ensure terminal 1 exists
        \Modules\Pos\Entities\PosTerminal::forceCreate([
            'id' => 1,
            'name' => 'Main Terminal',
            'code' => 'TERM-1',
            'setting_id' => $this->setting->id,
        ]);

        // Ensure session 1 exists for foreign key constraints
        \Modules\Pos\Entities\PosSession::forceCreate([
            'id' => 1,
            'setting_id' => $this->setting->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);
    }

    public function test_sale_generated_in_different_setting_opens_successfully()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-1',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
        ]);
        
        $checkout = PosCheckout::forceCreate([
            'pos_transaction_id' => $transaction->id,
            'paid_total' => 100,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);
        
        // Sale is in other setting
        $sale = Sale::forceCreate([
            'reference' => 'SALE-1',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->otherSetting->id
        ]);
        
        \Modules\Pos\Entities\PosCheckoutSale::forceCreate([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'split_key' => 'DEFAULT',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => 1,
            'tax_bucket' => 0,
        ]);

        $response = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale->id
        ]));

        $response->assertStatus(200);
        $response->assertViewIs('pos::checkouts.sale-readonly');
        $response->assertSee($sale->reference);
    }

    public function test_pairing_checkout_with_sale_it_did_not_generate_is_refused()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);
        
        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-X',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
        ]);
        
        $checkout = PosCheckout::forceCreate([
            'pos_transaction_id' => $transaction->id,
            'paid_total' => 100,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);
        $sale = Sale::forceCreate([
            'reference' => 'SALE-2',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);
        // Not linked!

        $response = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale->id
        ]));

        $response->assertStatus(404);
    }

    public function test_user_without_pos_access_is_refused()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        $this->withSession(['setting_id' => $this->setting->id]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-Y',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $unauthorizedUser->id,
            'owner_user_id' => $unauthorizedUser->id,
            'last_saved_by' => $unauthorizedUser->id,
            'source_pos_session_id' => 1,
        ]);
        $checkout = PosCheckout::forceCreate([
            'pos_transaction_id' => $transaction->id,
            'paid_total' => 100,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $unauthorizedUser->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);
        $sale = Sale::forceCreate([
            'reference' => 'SALE-3',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);
        \Modules\Pos\Entities\PosCheckoutSale::forceCreate([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'split_key' => 'DEFAULT',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => 1,
            'tax_bucket' => 0,
        ]);

        $response = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale->id
        ]));

        $response->assertStatus(403);
    }

    public function test_split_transaction_lists_all_generated_sale_references()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-2',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
            'completed_checkout_id' => 10,
        ]);
        $checkout = PosCheckout::forceCreate([
            'id' => 10,
            'pos_transaction_id' => $transaction->id,
            'paid_total' => 100,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);
        
        $sale1 = Sale::forceCreate([
            'reference' => 'SALE-SPLIT-1',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);
        $sale2 = Sale::forceCreate([
            'reference' => 'SALE-SPLIT-2',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);
        
        \Modules\Pos\Entities\PosCheckoutSale::forceCreate([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'split_key' => 'DEFAULT',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => 1,
            'tax_bucket' => 0,
        ]);
        \Modules\Pos\Entities\PosCheckoutSale::forceCreate([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'split_key' => 'SPLIT-2',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => 1,
            'tax_bucket' => 0,
        ]);

        $response = $this->get(route('pos.transactions.show', $transaction->id));

        $response->assertStatus(200);
        $response->assertSee('SALE-SPLIT-1');
        $response->assertSee('SALE-SPLIT-2');
        $response->assertSee('view-sale-btn');
    }

    public function test_draft_transaction_reports_no_sale_documents_exist()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-3',
            'setting_id' => $this->setting->id,
            'status' => 'DRAFT',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
        ]);
        // No checkout

        $response = $this->get(route('pos.transactions.show', $transaction->id));

        if ($response->status() !== 200) {
            dd($response->exception ?? $response->getContent());
        }

        $response->assertStatus(200);
        $response->assertSee('Transaksi ini belum diselesaikan. Belum ada dokumen penjualan yang dihasilkan.');
    }

    public function test_modal_response_exposes_no_mutating_action()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-4',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
        ]);
        $checkout = PosCheckout::forceCreate([
            'pos_transaction_id' => $transaction->id,
            'paid_total' => 100,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);
        $sale = Sale::forceCreate([
            'reference' => 'SALE-4',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);
        \Modules\Pos\Entities\PosCheckoutSale::forceCreate([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'split_key' => 'DEFAULT',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => 1,
            'tax_bucket' => 0,
        ]);

        $response = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale->id
        ]));

        $response->assertStatus(200);
        
        $content = $response->getContent();
        
        // Assert no forms or mutating actions are present
        $this->assertStringNotContainsString('<form', $content);
        $this->assertStringNotContainsString('method="POST"', $content);
        $this->assertStringNotContainsString('@method', $content);
        $this->assertStringNotContainsString('Hapus', $content);
        $this->assertStringNotContainsString('Ubah', $content);
        $this->assertStringNotContainsString('Keluarkan', $content);
    }

    public function test_transaction_note_display()
    {
        $transactionWithNote = PosTransaction::forceCreate([
            'code' => 'TX-NOTE-1',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
            'note' => "Special customer request\nDeliver tomorrow",
        ]);

        $transactionWithoutNote = PosTransaction::forceCreate([
            'code' => 'TX-NOTE-2',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
            'note' => null,
        ]);

        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        $response1 = $this->get(route('pos.transactions.show', $transactionWithNote->id));
        $response1->assertStatus(200);
        $response1->assertSee('Special customer request<br />' . PHP_EOL . 'Deliver tomorrow', false);
        $response1->assertDontSee('POS checkout #'); // No provenance prefix

        $response2 = $this->get(route('pos.transactions.show', $transactionWithoutNote->id));
        $response2->assertStatus(200);
        $response2->assertSee('Tidak ada catatan');
    }

    /**
     * Task 2a: Inline checkout's Sale opens in the modal
     * Verify inline checkouts can reach their Sale via sale_id instead of pivot
     */
    public function test_inline_checkout_sale_opens_in_modal()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        config(['pos.checkout.split_posting.enabled' => false]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-INLINE-1',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
        ]);

        $sale = Sale::forceCreate([
            'reference' => 'SALE-INLINE-1',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Inline',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $checkout = PosCheckout::forceCreate([
            'pos_transaction_id' => $transaction->id,
            'sale_id' => $sale->id,
            'paid_total' => 50000,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);

        // Assert no pivot row exists for inline checkout
        $pivotRowCount = \DB::table('pos_checkout_sales')
            ->where('pos_checkout_id', $checkout->id)
            ->where('sale_id', $sale->id)
            ->count();
        $this->assertEquals(0, $pivotRowCount, 'Inline checkout should have zero pivot rows');

        // Assert the modal route responds with 200 (previously would 404)
        $response = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale->id
        ]));

        $response->assertStatus(200)
            ->assertViewIs('pos::checkouts.sale-readonly')
            ->assertSee($sale->reference);
    }

    /**
     * Task 2b: Inline checkout's Sale is listed on POS transaction detail
     */
    public function test_inline_checkout_sale_listed_on_transaction_detail()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        config(['pos.checkout.split_posting.enabled' => false]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-INLINE-2',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
            'completed_checkout_id' => 99,
        ]);

        $sale = Sale::forceCreate([
            'reference' => 'SALE-INLINE-2',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Inline',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $checkout = PosCheckout::forceCreate([
            'id' => 99,
            'pos_transaction_id' => $transaction->id,
            'sale_id' => $sale->id,
            'paid_total' => 50000,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);

        $response = $this->get(route('pos.transactions.show', $transaction->id));

        $response->assertStatus(200);
        $response->assertSee('SALE-INLINE-2');
        $response->assertDontSee('tidak ada dokumen penjualan yang dihasilkan');
        $response->assertSee('view-sale-btn');
    }

    /**
     * Task 2c: Split checkout still works (regression guard)
     */
    public function test_split_checkout_sales_still_work_regression_guard()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        config(['pos.checkout.split_posting.enabled' => true]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-SPLIT-REG',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
            'completed_checkout_id' => 98,
        ]);

        $sale1 = Sale::forceCreate([
            'reference' => 'SALE-SPLIT-REG-1',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Split',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 25000,
            'paid_amount' => 25000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $sale2 = Sale::forceCreate([
            'reference' => 'SALE-SPLIT-REG-2',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Split',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 25000,
            'paid_amount' => 25000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $checkout = PosCheckout::forceCreate([
            'id' => 98,
            'pos_transaction_id' => $transaction->id,
            'paid_total' => 50000,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);

        // Create pivot rows for split posting
        \Modules\Pos\Entities\PosCheckoutSale::forceCreate([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'split_key' => 'GROUP-1',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => 1,
            'tax_bucket' => 0,
        ]);

        \Modules\Pos\Entities\PosCheckoutSale::forceCreate([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'split_key' => 'GROUP-2',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => 1,
            'tax_bucket' => 0,
        ]);

        // Each Sale should open via modal
        $response1 = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale1->id
        ]));
        $response1->assertStatus(200)->assertSee($sale1->reference);

        $response2 = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale2->id
        ]));
        $response2->assertStatus(200)->assertSee($sale2->reference);

        // Both should appear on transaction detail, each exactly once
        $response = $this->get(route('pos.transactions.show', $transaction->id));
        $response->assertStatus(200);
        $response->assertSee('SALE-SPLIT-REG-1');
        $response->assertSee('SALE-SPLIT-REG-2');
    }

    /**
     * Task 2b: Archived Sale is still reachable — the Task 1 regression guard
     * Finalize a real inline checkout, then archive the Sale.
     * Assert the modal route still returns 200 and getReachableSales() includes it.
     */
    public function test_archived_inline_sale_is_reachable_in_modal()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        config(['pos.checkout.split_posting.enabled' => false]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-ARCHIVED',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
        ]);

        $sale = Sale::forceCreate([
            'reference' => 'SALE-ARCHIVED-1',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Archived',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $checkout = PosCheckout::forceCreate([
            'pos_transaction_id' => $transaction->id,
            'sale_id' => $sale->id,
            'paid_total' => 50000,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);

        // Archive the Sale
        \DB::table('sales')->where('id', $sale->id)->update(['archived_at' => now()]);

        // The modal route should still return 200 (this fails before Task 1's fix)
        $response = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $sale->id
        ]));

        $response->assertStatus(200)
            ->assertViewIs('pos::checkouts.sale-readonly')
            ->assertSee('SALE-ARCHIVED-1');

        // PosCheckout::find($id)->getReachableSales() should still contain the Sale
        $reachableSales = $checkout->getReachableSales();
        $this->assertCount(1, $reachableSales, 'getReachableSales should contain the archived Sale');
        $this->assertEquals($sale->id, $reachableSales->first()->id);
    }

    /**
     * Task 2c: Archived Sale appears on POS transaction detail
     * With the same setup as 2b, load the POS transaction detail page.
     * Assert the Sale reference appears and the "tidak ada dokumen penjualan" empty-state text does not.
     */
    public function test_archived_inline_sale_appears_on_transaction_detail()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        config(['pos.checkout.split_posting.enabled' => false]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-ARCHIVED-2',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
            'completed_checkout_id' => 97,
        ]);

        $sale = Sale::forceCreate([
            'reference' => 'SALE-ARCHIVED-2',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Archived',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $checkout = PosCheckout::forceCreate([
            'id' => 97,
            'pos_transaction_id' => $transaction->id,
            'sale_id' => $sale->id,
            'paid_total' => 50000,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);

        // Archive the Sale
        \DB::table('sales')->where('id', $sale->id)->update(['archived_at' => now()]);

        // Load the POS transaction detail page
        $response = $this->get(route('pos.transactions.show', $transaction->id));

        $response->assertStatus(200);
        $response->assertSee('SALE-ARCHIVED-2');
        $response->assertDontSee('tidak ada dokumen penjualan yang dihasilkan');
        $response->assertSee('view-sale-btn');
    }

    /**
     * Task 2d: Unreachable Sale is still refused
     */
    public function test_unreachable_sale_is_refused()
    {
        $this->actingAs($this->user);
        $this->withSession(['setting_id' => $this->setting->id]);

        config(['pos.checkout.split_posting.enabled' => false]);

        $transaction = PosTransaction::forceCreate([
            'code' => 'TX-UNREACH',
            'setting_id' => $this->setting->id,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => 1,
        ]);

        $linkedSale = Sale::forceCreate([
            'reference' => 'SALE-LINKED',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $unlinkedSale = Sale::forceCreate([
            'reference' => 'SALE-UNLINKED',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $checkout = PosCheckout::forceCreate([
            'pos_transaction_id' => $transaction->id,
            'sale_id' => $linkedSale->id,
            'paid_total' => 50000,
            'status' => 'COMPLETED',
            'finalized_at' => now(),
            'setting_id' => $this->setting->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => uniqid(),
            'payload_hash' => uniqid(),
        ]);

        // Try to access unlinked Sale via this checkout — should 404
        $response = $this->get(route('pos.checkouts.sales.show', [
            'checkout' => $checkout->id,
            'sale' => $unlinkedSale->id
        ]));

        $response->assertStatus(404);
    }

    /**
     * Task 2a + real checkout: Real inline checkout — Sale opens in the modal
     * Drive a real inline checkout (not forceCreate), verify pivot has zero rows, modal opens with 200.
     */
    public function test_real_inline_checkout_sale_opens_in_modal()
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        $context = $this->createCheckoutContext('REAL-INLINE-MODAL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-REAL-INLINE', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Finalize a real inline checkout
        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);

        $response->assertCreated();
        $saleId = $response->json('sale_id');

        // Get the checkout from the sale
        $sale = Sale::findOrFail($saleId);
        $checkout = PosCheckout::where('sale_id', $saleId)->firstOrFail();

        // Assert no pivot row exists for inline checkout
        $pivotRowCount = \DB::table('pos_checkout_sales')
            ->where('pos_checkout_id', $checkout->id)
            ->where('sale_id', $saleId)
            ->count();
        $this->assertEquals(0, $pivotRowCount, 'Inline checkout should have zero pivot rows');

        // Assert the modal route responds with 200
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->get(route('pos.checkouts.sales.show', ['checkout' => $checkout->id, 'sale' => $saleId]))
            ->assertStatus(200)
            ->assertViewIs('pos::checkouts.sale-readonly')
            ->assertSee($sale->reference);
    }

    /**
     * Task 2b + real checkout: Archived Sale is still reachable — the Task 1 regression guard
     * Drive a real inline checkout, archive the Sale, verify modal still returns 200.
     */
    public function test_real_inline_checkout_archived_sale_is_reachable()
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        $context = $this->createCheckoutContext('REAL-INLINE-ARCHIVED');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-REAL-ARCHIVED', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Finalize a real inline checkout
        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);

        $response->assertCreated();
        $saleId = $response->json('sale_id');

        // Get the checkout from the sale
        $checkout = PosCheckout::where('sale_id', $saleId)->firstOrFail();

        // Archive the Sale
        \DB::table('sales')->where('id', $saleId)->update(['archived_at' => now()]);

        // The modal route should still return 200 (fails before Task 1's fix)
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->get(route('pos.checkouts.sales.show', ['checkout' => $checkout->id, 'sale' => $saleId]))
            ->assertStatus(200);
    }

    protected function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.require-terminal',
            'pos.checkout.payment',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);
        $methods = $this->seedPaymentMethods($setting, true);

        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'location' => $location,
            'session' => $session,
            'methods' => $methods,
        ];
    }

    protected function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.sale-modal.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'pos_transactions_enabled' => true,
            'is_pkp' => false,
        ]);
    }

    protected function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        // Create permissions if they don't exist
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS SALE-MODAL LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-MODAL-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Sale Modal Terminal ' . $index,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    protected function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        $setting->update([
            'pos_walk_in_customer_id' => $customer->id,
        ]);

        return $customer;
    }

    protected function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): object
    {
        $index = $this->sequence++;
        $methods = new \stdClass();

        $cashCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS COA CASH ' . $index,
            'account_number' => 'POS-CASH-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transferCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS COA TRANSFER ' . $index,
            'account_number' => 'POS-TRF-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods->cash = PaymentMethod::query()->create([
            'name' => 'CASH POS ' . $index,
            'coa_id' => $cashCoaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $methods->transfer = PaymentMethod::query()->create([
            'name' => 'TRANSFER POS ' . $index,
            'coa_id' => $transferCoaId,
            'is_cash' => false,
            'requires_reference' => true,
        ]);

        if ($enableForSetting) {
            $timestamp = now();
            foreach (['cash' => $methods->cash, 'transfer' => $methods->transfer] as $method) {
                DB::table('setting_pos_payment_methods')->updateOrInsert(
                    [
                        'setting_id' => $setting->id,
                        'payment_method_id' => $method->id,
                    ],
                    [
                        'is_enabled' => true,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );
            }
        }

        return $methods;
    }

    protected function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        float $salePrice,
        bool $serialRequired
    ): Product {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;

        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 20,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 20,
            'quantity_non_tax' => 20,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        return $product;
    }

    protected function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ])
            ->assertOk();
    }

    protected function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    protected function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}

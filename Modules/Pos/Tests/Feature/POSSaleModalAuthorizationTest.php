<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
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

    protected function setUp(): void
    {
        parent::setUp();
        
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
}

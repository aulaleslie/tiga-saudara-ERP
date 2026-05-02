<?php

namespace Tests\Feature\Livewire\PosReturn;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosReturn\PosReturnCreateForm;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Modules\Setting\Entities\Setting;

class PosReturnCreateFormTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $admin;
    protected $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        Permission::findOrCreate('pos.returns.create', 'web');

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('pos.returns.create');

        $this->clerk = User::factory()->create();
    }

    /** @test */
    public function it_renders_lookup_input_for_authorized_user()
    {
        $this->actingAs($this->admin);

        Livewire::test(PosReturnCreateForm::class)
            ->assertStatus(200)
            ->assertSeeHtml('id="identifier"');
    }

    /** @test */
    public function it_denies_access_for_unauthorized_user()
    {
        $this->actingAs($this->clerk);

        Livewire::test(PosReturnCreateForm::class)
            ->assertStatus(403);
    }

    /** @test */
    public function it_submits_return_form()
    {
        $this->actingAs($this->admin);
        session(['setting_id' => $this->setting->id]);

        // Setup a transaction (Manual setup as in Staleness test)
        $product = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'PRD-TEST',
            'product_quantity' => 100,
            'product_cost' => 50,
            'product_price' => 100,
            'product_unit' => 'pc',
            'product_stock_alert' => 10,
        ]);
        
        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);

        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Terminal',
            'code' => 'TRM-001',
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->admin->id,
            'opened_at' => now(),
            'status' => \Modules\Pos\Entities\PosSession::STATUS_OPEN,
            'active_marker' => 1,
        ]);

        $transaction = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SUBMIT',
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
            'owner_user_id' => $this->admin->id,
            'last_saved_by' => $this->admin->id,
            'source_pos_session_id' => $session->id,
        ]);
        
        $checkout = \Modules\Pos\Entities\PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->admin->id,
            'status' => \Modules\Pos\Entities\PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-SUBMIT',
            'idempotency_key' => 'IDEM-SUBMIT',
            'payload_hash' => 'HASH-SUBMIT',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = \Modules\People\Entities\Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SUBMIT',
        ]);

        \Modules\Pos\Entities\PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-SUBMIT',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', 'TXN-SUBMIT')
            ->call('lookup')
            ->assertSet('posTransactionId', $transaction->id)
            ->set('quantities.' . $saleDetail->id, 5)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('pos_returns', [
            'pos_transaction_id' => $transaction->id,
            'total_amount' => 500,
        ]);
    }
}

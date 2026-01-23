<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\People\Entities\Supplier;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EditRulesTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 1. Create Currency first as Setting depends on it
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // 2. Create Setting
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@test.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@test.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address',
        ]);
        
        session(['setting_id' => $this->setting->id]); // Important for scopes

        // 3. Create User
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $this->actingAs($this->user);

        // 4. Create Supplier and Customer
        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '08123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '08123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);
        
        // 5. Setup Permissions
        Permission::create(['name' => 'purchases.edit']);
        Permission::create(['name' => 'purchases.delete']);
        Permission::create(['name' => 'sales.edit']);
        Permission::create(['name' => 'sales.delete']);
        Permission::create(['name' => 'purchaseReturns.edit']);
        Permission::create(['name' => 'purchaseReturns.delete']);
        Permission::create(['name' => 'saleReturns.edit']);
        Permission::create(['name' => 'saleReturns.delete']);
    }

    /** @test */
    public function purchase_edit_blocked_if_received()
    {
        $purchase = Purchase::forceCreate([
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => $this->supplier->id,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'reference' => 'PO-TEST',
            'supplier_purchase_number' => 'SUP-PO-TEST',
            'tax_ref_no' => 'TAX-TEST',
        ]);

        $this->get(route('purchases.edit', $purchase))->assertStatus(403);
    }

    /** @test */
    public function sale_edit_blocked_if_dispatched()
    {
        $sale = Sale::forceCreate([
            'date' => now(),
            'due_date' => now(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'reference' => 'SO-TEST',
        ]);

        $this->get(route('sales.edit', $sale))->assertStatus(403);
    }

    /** @test */
    public function purchase_return_edit_blocked_if_dispatched()
    {
        $return = PurchaseReturn::forceCreate([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'approval_status' => 'pending',
            'status' => 'Pending Approval',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'reference' => 'PR-TEST',
            'return_dispatched_at' => now(),
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        $this->get(route('purchase-returns.edit', $return))->assertStatus(403);
    }

    /** @test */
    public function sale_return_edit_blocked_if_received()
    {
        $return = SaleReturn::forceCreate([
            'date' => now(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'approval_status' => 'pending',
            'status' => 'Pending Approval',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'reference' => 'SR-TEST',
            'received_at' => now(),
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        $this->get(route('sale-returns.edit', $return))->assertStatus(403);
    }
}

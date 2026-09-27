<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\SalesReturn\Entities\SaleReturn;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ArchiveControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        
        // Setup permissions
        Permission::create(['name' => 'purchases.archive']);
        Permission::create(['name' => 'sales.archive']);
        Permission::create(['name' => 'purchaseReturns.archive']);
        Permission::create(['name' => 'saleReturns.archive']);
        
        $this->user->givePermissionTo([
            'purchases.archive',
            'sales.archive',
            'purchaseReturns.archive',
            'saleReturns.archive'
        ]);
        
        \Illuminate\Support\Facades\Cache::forget('settings_1');
        session(['setting_id' => 1]);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);
        
        \Modules\Setting\Entities\Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@test.com',
            'footer_text' => 'Test Footer Text',
        ]);
        
        \Illuminate\Support\Facades\Cache::put('settings_1', \Modules\Setting\Entities\Setting::find(1));
    }

    public function test_purchase_archive_action()
    {
        $purchase = Purchase::create([
            'date' => now(),
            'reference' => 'PR-TEST',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(30),
            'setting_id' => 1,
        ]);

        $response = $this->put(route('purchases.archive', $purchase->id));

        $response->assertRedirect(route('purchases.index'));
        $this->assertTrue($purchase->fresh()->isArchived());
    }

    public function test_purchase_archive_action_blocked_if_received()
    {
        $purchase = Purchase::create([
            'date' => now(),
            'reference' => 'PR-TEST',
            'status' => 'RECEIVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(30),
            'setting_id' => 1,
        ]);

        $response = $this->put(route('purchases.archive', $purchase->id));

        $response->assertStatus(403);
        $this->assertFalse($purchase->fresh()->isArchived());
    }

    public function test_sale_archive_action()
    {
        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-TEST',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'customer_name' => 'Test Customer',
            'setting_id' => 1,
        ]);

        $response = $this->put(route('sales.archive', $sale->id));

        $response->assertRedirect(route('sales.index'));
        $this->assertTrue($sale->fresh()->isArchived());
    }

    public function test_purchase_return_archive_action()
    {
        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-TEST',
            'approval_status' => 'approved',
            'status' => 'Completed',
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_method' => 'Cash',
            'supplier_name' => 'Test Supplier',
            'setting_id' => 1,
        ]);

        $response = $this->put(route('purchase-returns.archive', $return->id));

        $response->assertRedirect(route('purchase-returns.index'));
        $this->assertTrue($return->fresh()->isArchived());
    }

    public function test_sale_return_archive_action()
    {
        $return = SaleReturn::create([
            'date' => now(),
            'reference' => 'SLRN-TEST',
            'approval_status' => 'approved',
            'status' => 'Completed',
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_method' => 'Cash',
            'customer_name' => 'Test Customer',
            'setting_id' => 1,
        ]);

        $response = $this->put(route('sale-returns.archive', $return->id));

        $response->assertRedirect(route('sale-returns.index'));
        $this->assertTrue($return->fresh()->isArchived());
    }

    public function test_archived_sale_can_be_viewed()
    {
        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123456789',
            'customer_email' => 'test@example.com',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-VIEW-TEST',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'setting_id' => 1,
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);

        // Permission to view sales is needed
        Permission::create(['name' => 'sales.show']);
        $this->user->givePermissionTo('sales.show');
        
        // Also need access permissions usually
        Permission::create(['name' => 'sales.access']);
        $this->user->givePermissionTo('sales.access');

        $response = $this->withSession(['setting_id' => 1])->get(route('sales.show', $sale->id));

        $response->assertStatus(200);
    }
}

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
        
        session(['setting_id' => 1]);
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
}

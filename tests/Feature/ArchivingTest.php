<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\SalesReturn\Entities\SaleReturn;
use Tests\TestCase;

class ArchivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_purchase_archiving()
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

        $this->assertCount(1, Purchase::all());
        $this->assertFalse($purchase->isArchived());

        $purchase->update([
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);

        $this->assertTrue($purchase->fresh()->isArchived());
        $this->assertCount(0, Purchase::all());
        $this->assertCount(1, Purchase::archived()->get());
        $this->assertCount(1, Purchase::withoutGlobalScopes()->get());
    }

    public function test_sale_archiving()
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

        $this->assertCount(1, Sale::all());

        $sale->update(['archived_at' => now()]);

        $this->assertCount(0, Sale::all());
        $this->assertCount(1, Sale::archived()->get());
    }

    public function test_purchase_return_archiving()
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

        $this->assertCount(1, PurchaseReturn::all());

        $return->update(['archived_at' => now()]);

        $this->assertCount(0, PurchaseReturn::all());
        $this->assertCount(1, PurchaseReturn::archived()->get());
    }

    public function test_sale_return_archiving()
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

        $this->assertCount(1, SaleReturn::all());

        $return->update(['archived_at' => now()]);

        $this->assertCount(0, SaleReturn::all());
        $this->assertCount(1, SaleReturn::archived()->get());
    }
}

<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReceivingCompletionGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchases.receive', 'web');
        Permission::findOrCreate('purchases.receive.approval', 'web');

        $this->user = \App\Models\User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo(['purchases.receive', 'purchases.receive.approval']);

        $this->setting = \Modules\Setting\Entities\Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        // Create base unit for tests
        \Modules\Setting\Entities\Unit::firstOrCreate(
            ['name' => 'PCS'],
            ['short_name' => 'pcs']
        );
    }

    public function test_storeReceive_rejects_completed_purchase()
    {
        $purchase = $this->createCompletedPurchase();

        $response = $this->actingAs($this->user)->post(
            route('purchases.storeReceive', $purchase),
            [
                'received' => [1 => 5],
                'location_id' => \Modules\Setting\Entities\Location::first()->id,
            ]
        );

        // The request should be rejected and redirected back (302)
        $response->assertStatus(302);
        // The purchase should remain in RECEIVED status, not RECEIVED PARTIALLY
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);
    }

    public function test_approveReceiving_rejects_for_completed_purchase()
    {
        $purchase = $this->createCompletedPurchase();
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'status' => 'PENDING',
            'location_id' => \Modules\Setting\Entities\Location::first()->id,
        ]);

        $response = $this->actingAs($this->user)->post(
            route('receivings.approve', $receivedNote),
            [],
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertStatus(422);
        $this->assertFalse($receivedNote->fresh()->isApproved());
    }

    public function test_storeReceive_succeeds_for_partially_received()
    {
        $purchase = $this->createPartiallyReceivedPurchase();

        $response = $this->actingAs($this->user)->post(
            route('purchases.storeReceive', $purchase),
            [
                'received' => [$purchase->purchaseDetails->first()->id => 2],
                'location_id' => \Modules\Setting\Entities\Location::first()->id,
            ]
        );

        $response->assertStatus(302);
    }

    private function createPartiallyReceivedPurchase()
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'base_unit_id' => \Modules\Setting\Entities\Unit::first()->id,
            'setting_id' => $this->setting->id,
            'product_cost' => 500,
            'product_price' => 1000,
        ]);

        $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->setting->id]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'RECEIVED PARTIALLY',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        return $purchase;
    }

    private function createCompletedPurchase()
    {
        $purchase = $this->createPartiallyReceivedPurchase();
        $purchase->update(['status' => Purchase::STATUS_RECEIVED]);
        return $purchase;
    }
}

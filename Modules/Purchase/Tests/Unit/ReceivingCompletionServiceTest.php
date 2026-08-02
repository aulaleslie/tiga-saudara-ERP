<?php

namespace Modules\Purchase\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;
use Modules\Purchase\Services\PurchaseReceivingCompletionService;
use Tests\TestCase;

class ReceivingCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseReceivingCompletionService $service;
    protected \App\Models\User $user;
    protected \Modules\Setting\Entities\Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->service = app(PurchaseReceivingCompletionService::class);
        $this->user = \App\Models\User::factory()->create(['is_active' => 1]);
        $this->setting = \Modules\Setting\Entities\Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        // Create base unit for tests
        \Modules\Setting\Entities\Unit::firstOrCreate(
            ['name' => 'PCS'],
            ['short_name' => 'pcs']
        );
    }

    public function test_single_line_shortfall_preview()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        $preview = $this->service->preview($purchase);

        $this->assertArrayHasKey('retained', $preview);
        $this->assertCount(1, $preview['retained']);
        $this->assertCount(0, $preview['removed']);
        $this->assertEquals(5, $preview['retained'][0]['retained_quantity']);
    }

    public function test_mixed_line_case_preview()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
            ['quantity' => 8, 'approved_received' => 0, 'should_exist' => false],
        ]);

        $preview = $this->service->preview($purchase);

        $this->assertCount(1, $preview['retained']);
        $this->assertCount(1, $preview['removed']);
    }

    public function test_single_line_completion()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        $completion = $this->service->complete(
            $purchase,
            'Supplier could not deliver remaining items',
            $this->user->id
        );

        $this->assertNotNull($completion);
        $purchase->refresh();
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->status);
        $this->assertEquals(5, $purchase->purchaseDetails->first()->quantity);
    }

    public function test_completion_preserves_received_note_identity()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        $originalDetailId = $purchase->purchaseDetails->first()->id;

        $this->service->complete(
            $purchase,
            'Test reason',
            $this->user->id
        );

        $updatedDetail = PurchaseDetail::find($originalDetailId);
        $this->assertNotNull($updatedDetail);
        $this->assertEquals(5, $updatedDetail->quantity);
    }

    public function test_completion_removes_unreceived_line_without_history()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
            ['quantity' => 8, 'approved_received' => 0, 'should_exist' => false],
        ]);

        $unreceivingDetailId = $purchase->purchaseDetails->where('quantity', 8)->first()->id;

        $this->service->complete(
            $purchase,
            'Test reason',
            $this->user->id
        );

        $this->assertNull(PurchaseDetail::find($unreceivingDetailId));
    }

    public function test_completion_rejects_overpaid_purchase()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 10000,
            'status' => 'ACTIVE',
            'payment_method' => 'CASH',
            'date' => now(),
            'reference' => 'PAY-' . uniqid(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('overpaid');

        $this->service->complete(
            $purchase,
            'Test reason',
            $this->user->id
        );
    }

    public function test_completion_creates_audit_record()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        $completion = $this->service->complete(
            $purchase,
            'Supplier shortfall',
            $this->user->id
        );

        $this->assertEquals($purchase->id, $completion->purchase_id);
        $this->assertEquals($this->user->id, $completion->actor_user_id);
        $this->assertEquals('Supplier shortfall', $completion->reason);
        $this->assertNotNull($completion->source_snapshot);
        $this->assertNotNull($completion->final_snapshot);
        $this->assertNotNull($completion->financial_before_after);
    }

    public function test_completion_rejects_archived_purchase()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);
        $purchase->update(['archived_at' => now(), 'archived_by' => $this->user->id]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('archived');

        $this->service->complete($purchase, 'Test reason', $this->user->id);
    }

    public function test_completion_rejects_non_partial_status()
    {
        $purchase = $this->createPurchaseWithDetails(
            [['quantity' => 10, 'approved_received' => 5, 'should_exist' => true]],
            Purchase::STATUS_APPROVED
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('RECEIVED PARTIALLY');

        $this->service->complete($purchase, 'Test reason', $this->user->id);
    }

    public function test_completion_rejects_without_approved_receipt()
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

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no approved');

        $this->service->complete($purchase, 'Test reason', $this->user->id);
    }

    public function test_completion_rejects_with_pending_notes()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'status' => 'PENDING',
            'location_id' => \Modules\Setting\Entities\Location::first()->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('pending');

        $this->service->complete($purchase, 'Test reason', $this->user->id);
    }

    public function test_completion_rejects_without_shortfall()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 10, 'should_exist' => true],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no outstanding shortfall');

        $this->service->complete($purchase, 'Test reason', $this->user->id);
    }

    public function test_completion_recalculates_line_totals_for_reduced_quantity()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        $completion = $this->service->complete(
            $purchase,
            'Supplier shortfall',
            $this->user->id
        );

        $purchase->refresh();
        $detail = $purchase->purchaseDetails->first();

        $this->assertEquals(5, $detail->quantity);
        $this->assertEquals(5000, $detail->sub_total);
        $this->assertEquals(5000, $purchase->total_amount);
    }

    public function test_completion_ignores_old_subtotal_and_recalculates()
    {
        $purchase = $this->createPurchaseWithDetails([
            ['quantity' => 10, 'approved_received' => 5, 'should_exist' => true],
        ]);

        $originalDetail = $purchase->purchaseDetails->first();
        $this->assertEquals(10000, $originalDetail->sub_total);

        $completion = $this->service->complete(
            $purchase,
            'Supplier shortfall',
            $this->user->id
        );

        $purchase->refresh();
        $detail = $purchase->purchaseDetails->first();

        $this->assertEquals(5, $detail->quantity);
        $this->assertEquals(5000, $detail->sub_total);
        $this->assertNotEquals(10000, $detail->sub_total);
        $this->assertEquals(5000, $purchase->total_amount);
    }


    private function createPurchaseWithDetails(array $details, string $status = Purchase::STATUS_RECEIVED_PARTIALLY)
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

        $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->setting->id]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => $status,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => count($details) * 10000,
            'paid_amount' => 0,
            'due_amount' => count($details) * 10000,
            'setting_id' => $this->setting->id,
        ]);

        foreach ($details as $idx => $detail) {
            $product = Product::create([
                'product_name' => "Product $idx",
                'product_code' => "TEST-00$idx",
                'base_unit_id' => \Modules\Setting\Entities\Unit::first()->id,
                'setting_id' => $this->setting->id,
                'product_cost' => 500,
            'product_price' => 1000,
            ]);

            $purchaseDetail = PurchaseDetail::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'quantity' => $detail['quantity'],
                'unit_price' => 1000,
                'price' => 1000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => $detail['quantity'] * 1000,
                'product_tax_amount' => 0,
                'tax_id' => null,
            ]);

            if ($detail['approved_received'] > 0) {
                $receivedNote = ReceivedNote::create([
                    'po_id' => $purchase->id,
                    'date' => now(),
                    'status' => 'APPROVED',
                    'approved_at' => now(),
                    'approved_by' => $this->user->id,
                    'location_id' => $location->id,
                ]);

                ReceivedNoteDetail::create([
                    'received_note_id' => $receivedNote->id,
                    'po_detail_id' => $purchaseDetail->id,
                    'quantity_received' => $detail['approved_received'],
                ]);
            }
        }

        return $purchase->refresh();
    }
}

<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Services\UomNormalizationEligibilityService;
use Modules\Purchase\Services\UomNormalizationExecutionService;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UomNormalizationEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $authorizedUser;
    private Setting $setting;
    private Location $location;
    private Product $product;
    private Unit $boxUnit;
    private Unit $pcsUnit;
    private ProductUnitConversion $conversion;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('purchases.received.uom-normalize', 'web');
        Permission::findOrCreate('purchases.received.correct', 'web');
        Permission::findOrCreate('purchases.update', 'web');
        Permission::findOrCreate('sales.create', 'web');

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['purchases.received.uom-normalize', 'purchases.update', 'sales.create']);

        $this->authorizedUser = User::factory()->create(['is_active' => 1]);
        $this->authorizedUser->assignRole($role);

        $this->setting = Setting::factory()->create();
        $this->authorizedUser->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->location = Location::create([
            'name' => 'Gudang Utama',
            'setting_id' => $this->setting->id,
        ]);

        $this->pcsUnit = Unit::firstOrCreate(
            ['name' => 'PCS'],
            ['short_name' => 'PCS']
        );

        $this->boxUnit = Unit::firstOrCreate(
            ['name' => 'BOX'],
            ['short_name' => 'BOX']
        );

        $this->product = Product::create([
            'product_name' => 'End To End Product',
            'product_code' => 'E2E-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        $this->targetUnit = $this->boxUnit;
        $this->factor = 12;
    }

    public function test_multi_purchase_normalization_e2e()
    {
        // 1. Create Purchase 1 (5 BOX)
        [$purchase1, $pd1, $rn1, $rnd1] = $this->createReceivedPurchase(5, 50000); // 5 BOX, 10,000 per BOX

        // 2. Create Purchase 2 (10 BOX)
        [$purchase2, $pd2, $rn2, $rnd2] = $this->createReceivedPurchase(10, 105000); // 10 BOX, 10,500 per BOX

        // Total stock is 15 BOX
        $this->assertEquals(15, (float) $this->product->fresh()->product_quantity);
        $totalBuyTxnsBefore = Transaction::where('product_id', $this->product->id)->where('type', 'BUY')->count();
        $this->assertEquals(2, $totalBuyTxnsBefore);

        // Calculate original average cost: (5 * 10k + 10 * 10.5k) / 15 = 155k / 15 = 10,333.33
        $this->assertEquals(10333.33, round((float) $this->product->priceForSetting($this->setting->id)->average_purchase_price, 2));

        // 3. Normalize Purchase 1 & Purchase 2 together (5 BOX + 10 BOX = 15 BOX * 12 = 180 PCS)
        $service = app(UomNormalizationExecutionService::class);
        $result1 = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$pd1->id, $pd2->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Normalize PO1 and PO2'
        );
        $this->assertTrue($result1['success']);

        // Assert Purchase 1 updated
        $this->assertEquals(60.0, (float) $pd1->fresh()->quantity);
        $this->assertEquals(60.0, (float) $rnd1->fresh()->quantity_received);

        // Transaction updated in place (no new rows)
        $totalBuyTxnsAfter1 = Transaction::where('product_id', $this->product->id)->where('type', 'BUY')->count();
        $this->assertEquals(2, $totalBuyTxnsAfter1);

        $txn1 = Transaction::where('received_note_detail_id', $rnd1->id)->first();
        $this->assertEquals(60.0, (float) $txn1->quantity);

        // Assert Purchase 2 updated
        $this->assertEquals(120.0, (float) $pd2->fresh()->quantity);
        $this->assertEquals(120.0, (float) $rnd2->fresh()->quantity_received);


        // Total stock should now be 180 PCS (60 + 120)
        $this->assertEquals(180.0, (float) $this->product->fresh()->product_quantity);

        // Calculate new average cost: (60 * (50k/60) + 120 * (105k/120)) / 180 = 155k / 180 = 861.11
        $newAvgCost = round(155000 / 180, 2);
        $this->assertEquals($newAvgCost, round((float) $this->product->priceForSetting($this->setting->id)->average_purchase_price, 2));

        // No new compensation transactions added
        $totalBuyTxnsAfter2 = Transaction::where('product_id', $this->product->id)->where('type', 'BUY')->count();
        $this->assertEquals(2, $totalBuyTxnsAfter2);
    }

    public function test_sale_draft_does_not_block_but_completed_sale_blocks()
    {
        [$purchase, $pd, $rn, $rnd] = $this->createReceivedPurchase(5, 50000);

        // Create a PENDING (Draft) Sale
        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'setting_id' => $this->setting->id,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SO-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => 'PENDING',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 15000,
            'paid_amount' => 0,
            'due_amount' => 15000,
            'setting_id' => $this->setting->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'Fixed',
            'product_tax_amount' => 0,
        ]);

        // Attempt normalization (should succeed because Sale is PENDING)
        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$pd->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Should pass with pending sale'
        );
        $this->assertTrue($result['success']);

        // Now change sale to DISPATCHED
        $sale->update(['status' => 'DISPATCHED']);
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => -1,
            'current_quantity' => 59,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Sold',
            'type' => 'SELL',
            'previous_quantity' => 60,
            'after_quantity' => 59,
            'previous_quantity_at_location' => 60,
            'after_quantity_at_location' => 59,
            'quantity_non_tax' => -1,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Second purchase
        [$purchase2, $pd2, $rn2, $rnd2] = $this->createReceivedPurchase(5, 50000);

        // Normalization should now fail due to dispatched sale / SELL transaction
        $this->expectException(\RuntimeException::class);
        $service->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$pd2->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Should fail due to sale'
        );
    }

    public function test_completed_pos_direct_product_blocks_normalization()
    {
        [$purchase, $pd, $rn, $rnd] = $this->createReceivedPurchase(5, 50000);
        $this->product->update(['stock_managed' => true]);

        // Create completed POS transaction

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting->id,
            'cashier_user_id' => $this->authorizedUser->id,
            'user_id' => $this->authorizedUser->id,
            'status' => 'OPEN',
            'created_by' => $this->authorizedUser->id,
            'owner_user_id' => $this->authorizedUser->id,
            'last_saved_by' => $this->authorizedUser->id,
        ]);

        $txn = \Modules\Pos\Entities\PosTransaction::create([
            'source_pos_session_id' => $session->id,
            'setting_id' => $this->setting->id,
            'code' => 'TXN-POS-DIR',
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->authorizedUser->id,
            'owner_user_id' => $this->authorizedUser->id,
            'last_saved_by' => $this->authorizedUser->id,
            'created_at' => now()->addMinutes(10), // after receipt
        ]);

        \Modules\Pos\Entities\PosTransactionLine::create([
            'product_name_snapshot' => 'Test Product',
            'product_code_snapshot' => 'TP-001',
            'pos_transaction_id' => $txn->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'qty' => 1,
            'unit_price' => 15000,
            'subtotal' => 15000,
            'uom_id' => $this->product->base_uom_id,
        ]);

        $service = app(UomNormalizationEligibilityService::class);
        $factor = (float) $this->factor;
        $targetUnit = $this->targetUnit;


        $result = $service->generatePreview(
            $this->product->fresh(),
            $targetUnit, $factor,
            collect([$pd->id]),
            $this->setting->id
        );

        $this->assertFalse($result['eligible'], 'Preview should be ineligible due to POS transaction');
        $this->assertStringContainsString('transaksi POS yang menghalangi', implode(' ', $result['errors']));
        $this->assertStringContainsString('transaksi POS', implode(' ', $result['errors']));

        $execService = app(UomNormalizationExecutionService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('menghalangi');
        $execService->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$pd->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Should fail'
        );
    }

    public function test_completed_pos_bundle_component_blocks_normalization()
    {
        [$purchase, $pd, $rn, $rnd] = $this->createReceivedPurchase(5, 50000);

        // Create bundle parent
        $parentProduct = Product::create([
            'product_name' => 'Bundle Parent',
            'product_code' => 'BNDL-001',
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'setting_id' => $this->setting->id,
            'name' => 'Test Bundle',
        ]);

        \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]);

        // Create POS txn with parent product

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting->id,
            'cashier_user_id' => $this->authorizedUser->id,
            'user_id' => $this->authorizedUser->id,
            'status' => 'OPEN',
            'created_by' => $this->authorizedUser->id,
            'owner_user_id' => $this->authorizedUser->id,
            'last_saved_by' => $this->authorizedUser->id,
        ]);

        $txn = \Modules\Pos\Entities\PosTransaction::create([
            'source_pos_session_id' => $session->id,
            'setting_id' => $this->setting->id,
            'code' => 'TXN-POS-BND',
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->authorizedUser->id,
            'owner_user_id' => $this->authorizedUser->id,
            'last_saved_by' => $this->authorizedUser->id,
            'created_at' => now()->addMinutes(10),
        ]);

        \Modules\Pos\Entities\PosTransactionLine::create([
            'product_name_snapshot' => 'Test Product',
            'product_code_snapshot' => 'TP-001',
            'pos_transaction_id' => $txn->id,
            'line_no' => 1,
            'product_id' => $parentProduct->id,
            'qty' => 1,
            'unit_price' => 50000,
            'subtotal' => 50000,
            'uom_id' => $this->product->base_uom_id,
        ]);

        $service = app(UomNormalizationEligibilityService::class);
        $result = $service->generatePreview(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$pd->id]),
            $this->setting->id
        );

        $this->assertFalse($result['eligible'], 'Preview should block bundle component');

        $execService = app(UomNormalizationExecutionService::class);
        $this->expectException(\RuntimeException::class);
        $execService->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$pd->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Should fail'
        );
    }

    public function test_pos_non_blocking_state_matrix()
    {
        [$purchase, $pd, $rn, $rnd] = $this->createReceivedPurchase(5, 50000);

        $parentProduct = Product::create([
            'product_name' => 'Bundle Parent 2',
            'product_code' => 'BNDL-002',
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'setting_id' => $this->setting->id,
            'name' => 'Test Bundle 2',
        ]);

        \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]);

        $states = [
            \Modules\Pos\Entities\PosTransaction::STATUS_DRAFT,
            \Modules\Pos\Entities\PosTransaction::STATUS_LOADED,
            \Modules\Pos\Entities\PosTransaction::STATUS_CANCELLED,
        ];

        foreach ($states as $state) {

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting->id,
            'cashier_user_id' => $this->authorizedUser->id,
            'user_id' => $this->authorizedUser->id,
            'status' => 'OPEN',
            'created_by' => $this->authorizedUser->id,
            'owner_user_id' => $this->authorizedUser->id,
            'last_saved_by' => $this->authorizedUser->id,
        ]);

        $txn = \Modules\Pos\Entities\PosTransaction::create([
            'source_pos_session_id' => $session->id,
                'setting_id' => $this->setting->id,
                'code' => 'TXN-POS-' . $state,
                'created_by' => $this->authorizedUser->id,
            'owner_user_id' => $this->authorizedUser->id,
            'last_saved_by' => $this->authorizedUser->id,
                'status' => $state,
                'created_at' => now()->addMinutes(10),
            ]);

            \Modules\Pos\Entities\PosTransactionLine::create([
                'product_name_snapshot' => 'Test Product',
                'product_code_snapshot' => 'TP-001',
                'pos_transaction_id' => $txn->id,
                'line_no' => 1,
                'product_id' => $this->product->id,
                'qty' => 1,
                'unit_price' => 15000,
            ]);

            \Modules\Pos\Entities\PosTransactionLine::create([
                'product_name_snapshot' => 'Test Product',
                'product_code_snapshot' => 'TP-001',
                'pos_transaction_id' => $txn->id,
                'line_no' => 2,
                'product_id' => $parentProduct->id,
                'qty' => 1,
                'unit_price' => 50000,
            ]);
        }

        $service = app(UomNormalizationEligibilityService::class);
        $factor = (float) $this->factor;
        $targetUnit = $this->targetUnit;


        $result = $service->generatePreview(
            $this->product->fresh(),
            $targetUnit, $factor,
            collect([$pd->id]),
            $this->setting->id
        );

        $this->assertTrue($result['eligible'], 'Non-completed states should not block');
    }

    public function test_base_uom_hpp_last_purchase_price_assertion()
    {
        // Deterministic conversion 1 BOX = 12 PCS
        // Purchase amount makes source-UOM price and base-UOM unit cost different.
        // E.g. Buy 5 BOX at 60,000 per BOX.
        // BOX price = 60,000. Base (PCS) unit cost = 5,000.
        [$purchase1, $pd1, $rn1, $rnd1] = $this->createReceivedPurchase(5, 300000); // 5 BOX, 60k per BOX

        // Add second receipt to cover chronological distinct receipts
        [$purchase2, $pd2, $rn2, $rnd2] = $this->createReceivedPurchase(10, 540000); // 10 BOX, 54k per BOX
        // Latest receipt is $purchase2 (54k/BOX, should be 4.5k/PCS)
        // Original last_purchase_price would be 54,000.

        $execService = app(UomNormalizationExecutionService::class);
        $result = $execService->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$pd1->id, $pd2->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Normalize for HPP assertion'
        );

        $this->assertTrue($result['success']);

        $price = $this->product->priceForSetting($this->setting->id);

        // Assert last purchase price is normalized latest receipt cost
        // 54000 / 12 = 4500
        $this->assertEquals(4500, (float) $price->last_purchase_price);
        $this->assertNotEquals(54000, (float) $price->last_purchase_price);

        // average purchase price matches weighted calculation
        // Total cost: 300k + 540k = 840k. Total PCS: (5*12) + (10*12) = 60 + 120 = 180.
        // 840,000 / 180 = 4666.6666...
        $this->assertEquals(round(840000 / 180, 2), round((float) $price->average_purchase_price, 2));
    }
    private function createReceivedPurchase(float $quantity, float $subTotal): array
    {
        $supplier = \Modules\People\Entities\Supplier::firstOrCreate(
            ['supplier_email' => 'test@example.com'],
            [
                'supplier_name' => 'Test Supplier',
                'supplier_phone' => '123',
                'city' => 'Jakarta',
                'country' => 'Indonesia',
                'address' => 'Test',
                'setting_id' => $this->setting->id,
            ]
        );

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-UOM-E2E-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => $subTotal,
            'paid_amount' => $subTotal,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        $unitPrice = $quantity > 0 ? $subTotal / $quantity : 0;

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'price' => $unitPrice,
            'sub_total' => $subTotal,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DO-' . uniqid(),
            'internal_invoice_number' => 'INV-' . uniqid(),
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => $quantity,
        ]);

        $currentStock = (float) $this->product->product_quantity;

        // Create product stock at location
        $productStock = ProductStock::firstOrCreate(
            ['product_id' => $this->product->id, 'location_id' => $this->location->id],
            ['quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]
        );
        $productStock->increment('quantity', $quantity);
        $productStock->increment('quantity_non_tax', $quantity);

        // Update product quantity
        $this->product->increment('product_quantity', $quantity);

        // Create the BUY transaction with provenance link
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => $quantity,
            'current_quantity' => $currentStock + $quantity,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian #' . $purchase->reference . ' (Disetujui)',
            'type' => 'BUY',
            'previous_quantity' => $currentStock,
            'after_quantity' => $currentStock + $quantity,
            'previous_quantity_at_location' => $currentStock, // simplified for test
            'after_quantity_at_location' => $currentStock + $quantity, // simplified
            'quantity_non_tax' => $quantity,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $receivedNoteDetail->id,
        ]);

        // Mark as approved
        $receivedNote->update([
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $this->authorizedUser->id,
        ]);

        // Calculate average cost
        $totalCost = ($currentStock * ((float) optional($this->product->priceForSetting($this->setting->id))->average_purchase_price ?? 0)) + $subTotal;
        $newStock = $currentStock + $quantity;
        $avgCost = $newStock > 0 ? $totalCost / $newStock : 0;

        ProductPrice::updateOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => $avgCost, 'last_purchase_price' => $unitPrice],
        );

        return [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail];
    }
}

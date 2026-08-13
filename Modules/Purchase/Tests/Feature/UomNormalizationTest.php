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
use Modules\Purchase\Entities\UomNormalizationBatch;
use Modules\Purchase\Entities\UomNormalizationLine;
use Modules\Purchase\Services\LegacyTransactionResolver;
use Modules\Purchase\Services\UomNormalizationEligibilityService;
use Modules\Purchase\Services\UomNormalizationExecutionService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UomNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $authorizedUser;
    private User $unauthorizedUser;
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

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('purchases.received.uom-normalize');
        $role->givePermissionTo('purchases.update');

        $this->authorizedUser = User::factory()->create(['is_active' => 1]);
        $this->authorizedUser->assignRole($role);

        $unauthorizedRole = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        $this->unauthorizedUser = User::factory()->create(['is_active' => 1]);
        $this->unauthorizedUser->assignRole($unauthorizedRole);

        $this->setting = Setting::factory()->create();
        $this->authorizedUser->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        $this->unauthorizedUser->settings()->attach($this->setting->id, ['role_id' => $unauthorizedRole->id]);

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
            'product_name' => 'Test Product',
            'product_code' => 'TP-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        $this->conversion = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12, // 1 BOX = 12 PCS
        ]);
    }

    // ─── Provenance Tests (2.4) ──────────────────────────────────────────

    public function test_new_receiving_approval_links_transaction_to_receiving_detail()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // After approval, the transaction should have the provenance link
        $transaction = Transaction::where('product_id', $this->product->id)
            ->where('type', 'BUY')
            ->where('received_note_detail_id', $receivedNoteDetail->id)
            ->first();

        $this->assertNotNull($transaction, 'BUY transaction should have received_note_detail_id set');
        $this->assertEquals($receivedNoteDetail->id, $transaction->received_note_detail_id);
    }

    // ─── Legacy Transaction Resolver Tests (2.4) ─────────────────────────

    public function test_resolver_matches_unique_legacy_transaction()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // Simulate legacy: remove the provenance link
        Transaction::where('received_note_detail_id', $receivedNoteDetail->id)
            ->update(['received_note_detail_id' => null]);

        $resolver = app(LegacyTransactionResolver::class);
        $result = $resolver->resolve($receivedNoteDetail->fresh());

        $this->assertEquals(LegacyTransactionResolver::RESULT_MATCHED, $result['status']);
        $this->assertNotNull($result['transaction']);
    }

    public function test_resolver_returns_missing_when_no_transaction_found()
    {
        // Create a receiving detail without any matching transaction
        $purchase = $this->createPurchase();
        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);
        $receivedNote = $this->createReceivedNote($purchase);
        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        // No transaction exists
        $resolver = app(LegacyTransactionResolver::class);
        $result = $resolver->resolve($receivedNoteDetail);

        $this->assertEquals(LegacyTransactionResolver::RESULT_MISSING, $result['status']);
        $this->assertNull($result['transaction']);
    }

    public function test_resolver_returns_ambiguous_when_multiple_candidates()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // Remove provenance link
        Transaction::where('received_note_detail_id', $receivedNoteDetail->id)
            ->update(['received_note_detail_id' => null]);

        // Create a duplicate BUY transaction with same evidence
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 20,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian #' . $purchase->reference . ' (Disetujui)',
            'type' => 'BUY',
            'previous_quantity' => 10,
            'after_quantity' => 20,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 20,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'created_at' => now(), // Same time window
        ]);

        $resolver = app(LegacyTransactionResolver::class);
        $result = $resolver->resolve($receivedNoteDetail->fresh());

        $this->assertEquals(LegacyTransactionResolver::RESULT_AMBIGUOUS, $result['status']);
        $this->assertNull($result['transaction']);
        $this->assertGreaterThan(1, $result['candidates']->count());
    }

    // ─── Eligibility Tests (3.6) ─────────────────────────────────────────

    public function test_eligibility_rejects_non_stock_managed_product()
    {
        $this->product->update(['stock_managed' => false]);

        $service = app(UomNormalizationEligibilityService::class);
        $result = $service->validateBatchSelection(
            $this->product->fresh(),
            $this->conversion,
            collect([1]),
            $this->setting->id
        );

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_eligibility_validates_correct_conversion()
    {
        $service = app(UomNormalizationEligibilityService::class);

        // Create a purchase detail for this product
        $purchase = $this->createPurchase();
        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);

        $result = $service->validateBatchSelection(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->setting->id
        );

        $this->assertTrue($result['valid']);
    }

    public function test_eligibility_blocks_wrong_conversion_product()
    {
        $otherProduct = Product::create([
            'product_name' => 'Other Product',
            'product_code' => 'OTHER-001',
            'product_cost' => 500,
            'product_price' => 1000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        $service = app(UomNormalizationEligibilityService::class);
        $result = $service->validateBatchSelection(
            $otherProduct,
            $this->conversion, // belongs to $this->product, not $otherProduct
            collect([1]),
            $this->setting->id
        );

        $this->assertFalse($result['valid']);
    }

    public function test_receipt_completion_check_detects_incomplete_lines()
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);

        // Create a receiving note with partial quantity
        $receivedNote = $this->createReceivedNote($purchase);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 5, // Only 5 of 10
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);

        $service = app(UomNormalizationEligibilityService::class);
        $result = $service->checkReceiptCompletion(collect([$purchaseDetail->id]));

        $this->assertFalse($result['all_complete']);
        $this->assertContains($purchaseDetail->id, $result['incomplete_lines']);
    }

    public function test_receipt_completion_passes_fully_received_lines()
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);

        $receivedNote = $this->createReceivedNote($purchase);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10, // Full quantity
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);

        $service = app(UomNormalizationEligibilityService::class);
        $result = $service->checkReceiptCompletion(collect([$purchaseDetail->id]));

        $this->assertTrue($result['all_complete']);
    }

    public function test_stock_history_blocks_when_sell_transactions_exist()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);
        
        // Create a SELL transaction for this product, after the receipt
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => -5,
            'current_quantity' => 5,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Sold',
            'type' => 'SELL',
            'previous_quantity' => 10,
            'after_quantity' => 5,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 5,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'created_at' => now()->addMinutes(5),
        ]);

        $service = app(UomNormalizationEligibilityService::class);
        $result = $service->checkStockHistory($this->product, $this->setting->id, collect([$receivedNoteDetail]));

        $this->assertFalse($result['eligible']);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_stock_history_eligible_when_only_buy_transactions_exist()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);
        
        // Only BUY transactions exist (BUY is not in the blocking list), after the receipt
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 20,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Purchased Again',
            'type' => 'BUY',
            'previous_quantity' => 10,
            'after_quantity' => 20,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 20,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'created_at' => now()->addMinutes(5),
        ]);

        $service = app(UomNormalizationEligibilityService::class);
        $result = $service->checkStockHistory($this->product, $this->setting->id, collect([$receivedNoteDetail]));

        $this->assertTrue($result['eligible']);
    }

    // ─── Execution Tests (4.7) ───────────────────────────────────────────

    public function test_execution_normalizes_quantities_correctly()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // Verify initial state
        $this->assertEquals(10, (float) $purchaseDetail->fresh()->quantity);
        $this->assertEquals(10, (float) $this->product->fresh()->product_quantity);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'BOX konversi belum terdaftar saat penerimaan',
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['batch']);
        $this->assertEquals(UomNormalizationBatch::STATUS_EXECUTED, $result['batch']->status);

        // Verify normalized quantities: 10 BOX × 12 = 120 PCS
        $purchaseDetail->refresh();
        $this->assertEquals(120.000, (float) $purchaseDetail->quantity);

        $receivedNoteDetail->refresh();
        $this->assertEquals(120.000, (float) $receivedNoteDetail->quantity_received);

        // Verify product stock increased by delta (120 - 10 = 110)
        $this->assertEquals(120.000, (float) $this->product->fresh()->product_quantity);
    }

    public function test_execution_preserves_monetary_values()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $originalSubTotal = (float) $purchaseDetail->sub_total;
        $originalTaxAmount = (float) $purchaseDetail->product_tax_amount;
        $originalUnitPrice = (float) $purchaseDetail->unit_price;
        $originalPurchaseTotal = (float) $purchase->total_amount;

        $service = app(UomNormalizationExecutionService::class);
        $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test reason',
        );

        $purchaseDetail->refresh();
        $purchase->refresh();

        // Monetary values must not change
        $this->assertEquals($originalSubTotal, (float) $purchaseDetail->sub_total);
        $this->assertEquals($originalTaxAmount, (float) $purchaseDetail->product_tax_amount);
        $this->assertEquals($originalPurchaseTotal, (float) $purchase->total_amount);
    }

    public function test_execution_updates_buy_transaction_in_place()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $originalTransaction = Transaction::where('received_note_detail_id', $receivedNoteDetail->id)->first();
        $originalTxnId = $originalTransaction->id;

        $service = app(UomNormalizationExecutionService::class);
        $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test reason',
        );

        // Transaction should be updated in-place (same ID)
        $updatedTransaction = Transaction::find($originalTxnId);
        $this->assertNotNull($updatedTransaction);
        $this->assertEquals(120.000, (float) $updatedTransaction->quantity);
        $this->assertEquals('BUY', $updatedTransaction->type);

        // No new compensation transaction should exist
        $totalBuyTxns = Transaction::where('product_id', $this->product->id)
            ->where('type', 'BUY')
            ->count();
        $this->assertEquals(1, $totalBuyTxns);
    }

    public function test_execution_creates_immutable_audit_records()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test audit reason',
        );

        $batch = $result['batch'];
        $this->assertNotNull($batch->executed_at);
        $this->assertNotNull($batch->cost_outcome);
        $this->assertEquals($this->authorizedUser->id, $batch->actor_user_id);
        $this->assertEquals('Test audit reason', $batch->reason);
        $this->assertEquals(12, (float) $batch->conversion_factor);

        $lines = UomNormalizationLine::where('batch_id', $batch->id)->get();
        $this->assertCount(1, $lines);
        $this->assertEquals(10.000, (float) $lines->first()->source_quantity);
        $this->assertEquals(120.000, (float) $lines->first()->normalized_quantity);
        $this->assertNotNull($lines->first()->transaction_snapshot_before);
        $this->assertNotNull($lines->first()->transaction_snapshot_after);
    }

    public function test_execution_rejects_without_reason()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            '',
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('wajib', $result['error']);
    }

    public function test_execution_prevents_double_normalization()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $service = app(UomNormalizationExecutionService::class);

        // First execution should succeed
        $result1 = $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'First normalization',
        );
        $this->assertTrue($result1['success']);

        // Second execution on same lines should fail
        $this->expectException(\RuntimeException::class);
        $service->execute(
            $this->product->fresh(),
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Second normalization attempt',
        );
    }

    // ─── Authorization Tests (5.5) ───────────────────────────────────────

    public function test_authorized_user_can_access_normalization_form()
    {
        $purchase = $this->createPurchase();
        $purchase->update(['status' => Purchase::STATUS_RECEIVED]);

        $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.uom-normalize.edit', $purchase->id))
            ->assertStatus(200);
    }

    public function test_unauthorized_user_cannot_access_normalization_form()
    {
        $purchase = $this->createPurchase();
        $purchase->update(['status' => Purchase::STATUS_RECEIVED]);

        $this->actingAs($this->unauthorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.uom-normalize.edit', $purchase->id))
            ->assertStatus(403);
    }

    public function test_normalization_denied_for_non_received_purchase()
    {
        $purchase = $this->createPurchase();
        $purchase->update(['status' => Purchase::STATUS_APPROVED]); // Not received

        $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.uom-normalize.edit', $purchase->id))
            ->assertStatus(403);
    }

    public function test_normalization_denied_for_wrong_setting()
    {
        $otherSetting = Setting::factory()->create();
        $purchase = $this->createPurchase();
        $purchase->update([
            'status' => Purchase::STATUS_RECEIVED,
            'setting_id' => $otherSetting->id, // Different setting
        ]);

        $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.uom-normalize.edit', $purchase->id))
            ->assertStatus(403);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function createPurchase(): Purchase
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

        return Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-UOM-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);
    }

    private function createPurchaseDetail(Purchase $purchase, float $quantity): PurchaseDetail
    {
        return PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => $quantity * 10000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);
    }

    private function createReceivedNote(Purchase $purchase): ReceivedNote
    {
        return ReceivedNote::create([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DO-' . uniqid(),
            'internal_invoice_number' => 'INV-' . uniqid(),
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
    }

    /**
     * Create a fully received and approved purchase with stock and transaction updates.
     */
    private function createReceivedPurchase(float $quantity): array
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = $this->createPurchaseDetail($purchase, $quantity);
        $receivedNote = $this->createReceivedNote($purchase);
        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => $quantity,
        ]);

        // Create product stock at location
        $productStock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'quantity_tax' => 0,
            'quantity_non_tax' => $quantity,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Update product quantity
        $this->product->update(['product_quantity' => $quantity]);

        // Create the BUY transaction with provenance link
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => $quantity,
            'current_quantity' => $quantity,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian #' . $purchase->reference . ' (Disetujui)',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => $quantity,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $quantity,
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

        // Create ProductPrice
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
        );

        return [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail];
    }

    // ─── Regression Tests ───────────────────────────────────────────────

    public function test_execution_blocks_cross_product_payload()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);
        
        $otherProduct = Product::create([
            'product_name' => 'Other Product',
            'product_code' => 'OTHER-001',
            'product_cost' => 500,
            'product_price' => 1000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'base_unit_id' => $this->pcsUnit->id,
            'unit_id' => $this->pcsUnit->id,
        ]);

        $purchaseDetailOther = $this->createPurchaseDetail($purchase, 10);
        $purchaseDetailOther->update(['product_id' => $otherProduct->id]);

        $service = app(UomNormalizationExecutionService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bukan untuk produk ini');
        $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id, $purchaseDetailOther->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
    }

    public function test_execution_blocks_cross_setting_payload()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);
        
        $otherSetting = Setting::factory()->create();
        $purchase->update(['setting_id' => $otherSetting->id]);

        $service = app(UomNormalizationExecutionService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bukan dari cabang (setting) aktif');
        $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
    }

    public function test_execution_blocks_duplicate_detail_ids()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $service = app(UomNormalizationExecutionService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplikat');
        $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id, $purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
    }

    public function test_execution_blocks_serial_required_product_without_serial_rows()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);
        
        $this->product->update(['serial_number_required' => true]);

        $service = app(UomNormalizationExecutionService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('serial-tracked tidak dapat dinormalisasi');
        $service->execute(
            $this->product->fresh(),
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
    }

    public function test_post_receipt_transfer_adjustment_return_blocking_during_execution()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // Create an ADJ transaction after receipt
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => -2,
            'current_quantity' => 8,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Adjust',
            'type' => 'ADJ',
            'previous_quantity' => 10,
            'after_quantity' => 8,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 8,
            'quantity_non_tax' => -2,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'created_at' => now()->addMinutes(1),
        ]);

        $service = app(UomNormalizationExecutionService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transaksi inventori yang menghalangi');
        $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
    }

    public function test_pos_draft_loaded_cancelled_non_blocking()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // POS Drafts do not create Transaction rows. So we just verify execution passes.
        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->conversion,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
        $this->assertTrue($result['success']);
    }

    public function test_chronological_snapshots_selected_A_unselected_B_selected_C()
    {
        // 1. Receipt A (Selected)
        [$purchaseA, $pdA, $rnA, $rndA] = $this->createReceivedPurchase(10);
        $txnA = Transaction::where('received_note_detail_id', $rndA->id)->first();
        $txnA->update(['created_at' => now()->subMinutes(30)]);

        // 2. Receipt B (Unselected)
        [$purchaseB, $pdB, $rnB, $rndB] = $this->createReceivedPurchase(5);
        $txnB = Transaction::where('received_note_detail_id', $rndB->id)->first();
        $txnB->update(['created_at' => now()->subMinutes(20), 'previous_quantity' => 10, 'after_quantity' => 15, 'current_quantity' => 15, 'previous_quantity_at_location' => 10, 'after_quantity_at_location' => 15]);
        $this->product->update(['product_quantity' => 15]);

        // 3. Receipt C (Selected)
        [$purchaseC, $pdC, $rnC, $rndC] = $this->createReceivedPurchase(10);
        $txnC = Transaction::where('received_note_detail_id', $rndC->id)->first();
        $txnC->update(['created_at' => now()->subMinutes(10), 'previous_quantity' => 15, 'after_quantity' => 25, 'current_quantity' => 25, 'previous_quantity_at_location' => 15, 'after_quantity_at_location' => 25]);
        $this->product->update(['product_quantity' => 25]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product->fresh(),
            $this->conversion,
            collect([$pdA->id, $pdC->id]), // B is omitted
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );

        $this->assertTrue($result['success']);

        // Verify Txn A: Normalized 10 -> 120. Prev 0, After 120
        $txnAFresh = $txnA->fresh();
        $this->assertEquals(0, $txnAFresh->previous_quantity);
        $this->assertEquals(120, $txnAFresh->after_quantity);
        $this->assertEquals(120, $txnAFresh->quantity);

        // Verify Txn B: Unselected 5. Prev should be 120 (since A gave +110), After should be 125.
        $txnBFresh = $txnB->fresh();
        $this->assertEquals(5, $txnBFresh->quantity); // unchanged
        $this->assertEquals(120, $txnBFresh->previous_quantity);
        $this->assertEquals(125, $txnBFresh->after_quantity);

        // Verify Txn C: Normalized 10 -> 120. Prev should be 125, After should be 245.
        $txnCFresh = $txnC->fresh();
        $this->assertEquals(120, $txnCFresh->quantity);
        $this->assertEquals(125, $txnCFresh->previous_quantity);
        $this->assertEquals(245, $txnCFresh->after_quantity);
    }

    public function test_multiple_receipts_on_one_purchase_detail_distinct_locations()
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = $this->createPurchaseDetail($purchase, 20);
        
        $location2 = Location::create([
            'name' => 'Gudang Dua',
            'setting_id' => $this->setting->id,
        ]);

        // Receipt 1
        $receivedNote1 = $this->createReceivedNote($purchase);
        $receivedNoteDetail1 = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote1->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);
        
        $productStock1 = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 10,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $receivedNoteDetail1->id,
            'created_at' => now()->subMinutes(10),
        ]);
        $receivedNote1->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subMinutes(10)]);

        // Receipt 2
        $receivedNote2 = $this->createReceivedNote($purchase);
        $receivedNote2->update(['location_id' => $location2->id]);
        $receivedNoteDetail2 = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote2->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);
        
        $productStock2 = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location2->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 20,
            'broken_quantity' => 0,
            'location_id' => $location2->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 10,
            'after_quantity' => 20,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $receivedNoteDetail2->id,
            'created_at' => now()->subMinutes(5),
        ]);
        $receivedNote2->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subMinutes(5)]);

        $this->product->update(['product_quantity' => 20]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product->fresh(),
            $this->conversion,
            collect([$purchaseDetail->id]), // Selects both receipts automatically through relation
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );

        $this->assertTrue($result['success']);
        
        // Product total is 240
        $this->assertEquals(240, (float) $this->product->fresh()->product_quantity);
        // Location 1 is 120
        $this->assertEquals(120, (float) $productStock1->fresh()->quantity);
        // Location 2 is 120
        $this->assertEquals(120, (float) $productStock2->fresh()->quantity);
    }
}

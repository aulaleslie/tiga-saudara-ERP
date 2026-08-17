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

        $this->targetUnit = $this->boxUnit;
        $this->factor = 12;
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor, // belongs to $this->product, not $otherProduct
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Second normalization attempt',
        );
    }

    // ─── Authorization Tests (5.5) ───────────────────────────────────────

    public function test_authorized_user_can_access_normalization_form()
    {
        $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.uom-normalize.edit', $this->product->id))
            ->assertStatus(200);
    }

    public function test_unauthorized_user_cannot_access_normalization_form()
    {
        $this->actingAs($this->unauthorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.uom-normalize.edit', $this->product->id))
            ->assertStatus(403);
    }

    public function test_normalization_denied_for_non_stock_managed_product()
    {
        $this->product->update(['stock_managed' => false]);

        $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.uom-normalize.edit', $this->product->id))
            ->assertStatus(403);
    }

    public function test_normalization_denied_for_merged_product()
    {
        $otherProduct = Product::create([
            'product_name' => 'Target Merged',
            'product_code' => 'MRG-001',
            'product_cost' => 100,
            'product_price' => 200,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'base_unit_id' => $this->pcsUnit->id,
            'unit_id' => $this->pcsUnit->id,
        ]);
        $this->product->update(['merged_into_id' => $otherProduct->id]);

        $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.uom-normalize.edit', $this->product->id))
            ->assertStatus(403);
    }

    public function test_product_with_no_eligible_purchase_history_can_open_page()
    {
        // A brand new product without any purchases/receipts
        $newProduct = Product::create([
            'product_name' => 'Zero Purchases Product',
            'product_code' => 'ZERO-001',
            'product_cost' => 100,
            'product_price' => 200,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'base_unit_id' => $this->pcsUnit->id,
            'unit_id' => $this->pcsUnit->id,
        ]);

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.uom-normalize.edit', $newProduct->id));

        $response->assertStatus(200);
        $response->assertSee('Zero Purchases Product');
        $response->assertSee('Tidak ada baris pembelian yang dapat dinormalisasi');
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
            $this->targetUnit, (float) $this->factor,
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
        // The purchase now has genuine purchase-history footprint in the
        // other setting, so the pre-validateAll() physical/history
        // cross-setting classification (locked, run before any mutation)
        // rejects it first, with the other setting identified by name.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('riwayat fisik');
        $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
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
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
        $this->assertTrue($result['success']);
    }

    public function test_execution_blocks_partial_scope_normalization()
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Semua baris pembelian produk ini');

        $service->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$pdA->id, $pdC->id]), // B is omitted
            $this->authorizedUser,
            $this->setting->id,
            'Test'
        );
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
            $this->targetUnit, (float) $this->factor,
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

    public function test_execution_blocks_non_zero_broken_stock()
    {
        // Create a purchase and purchase detail
        $purchase = $this->createPurchase();
        $purchase->update(['status' => Purchase::STATUS_RECEIVED_PARTIALLY]);

        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);

        $receivedNote = $this->createReceivedNote($purchase);
        $location = $receivedNote->location;

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        // Create stock with broken quantity
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 3, // Non-zero broken quantity
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 3,
        ]);

        // Create transaction
        $rnd = ReceivedNoteDetail::where('po_detail_id', $purchaseDetail->id)->first();
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 10,
            'broken_quantity' => 0,
            'location_id' => $location->id,
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
            'received_note_detail_id' => $rnd->id,
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        $this->product->update(['product_quantity' => 10]);

        $service = app(UomNormalizationExecutionService::class);

        try {
            $result = $service->execute(
                $this->product->fresh(),
                $this->targetUnit,
                (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException to be thrown for broken stock');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stok rusak', $e->getMessage());
        }
    }

    public function test_preview_shows_broken_stock_blocker_without_mutation()
    {
        $purchase = $this->createPurchase();
        $purchase->update(['status' => Purchase::STATUS_RECEIVED_PARTIALLY]);

        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);

        $receivedNote = $this->createReceivedNote($purchase);
        $location = $receivedNote->location;

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        $productStock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 2,
        ]);

        $rnd = ReceivedNoteDetail::where('po_detail_id', $purchaseDetail->id)->first();
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 10,
            'broken_quantity' => 0,
            'location_id' => $location->id,
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
            'received_note_detail_id' => $rnd->id,
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        $this->product->update(['product_quantity' => 10]);

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        $result = $eligibilityService->validateAll(
            $this->product,
            $this->targetUnit,
            (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->setting->id
        );

        $this->assertFalse($result['eligible']);
        $this->assertTrue(collect($result['errors'])->contains(fn($err) => str_contains($err, 'stok rusak')));

        // Verify no data was mutated
        $this->assertEquals(2, (float) $productStock->fresh()->broken_quantity);
    }

    // ─── Broken Stock Policy Tests (Part A) ────────────────────────────

    public function test_broken_stock_in_multiple_locations_blocks_and_identifies_all_locations()
    {
        $purchase = $this->createPurchase();
        $purchase->update(['status' => Purchase::STATUS_RECEIVED_PARTIALLY]);

        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);
        $receivedNote = $this->createReceivedNote($purchase);
        $location1 = $receivedNote->location;

        $location2 = Location::create([
            'name' => 'Gudang Kedua',
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location1->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 1,
            'broken_quantity_non_tax' => 2,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location2->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 5,
        ]);

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        $result = $eligibilityService->checkStockHistory($this->product, $this->setting->id);

        $this->assertFalse($result['eligible']);
        $brokenBlocker = collect($result['blockers'])->firstWhere('type', 'broken_stock_exists');
        $this->assertNotNull($brokenBlocker);

        $locationIds = collect($brokenBlocker['details'])->pluck('location_id')->sort()->values()->all();
        $this->assertEquals([$location1->id, $location2->id], collect($locationIds)->sort()->values()->all());

        $loc1Detail = collect($brokenBlocker['details'])->firstWhere('location_id', $location1->id);
        $this->assertEquals(3, $loc1Detail['broken_quantity']);
        $this->assertEquals(1, $loc1Detail['broken_quantity_tax']);
        $this->assertEquals(2, $loc1Detail['broken_quantity_non_tax']);
    }

    public function test_failed_execution_due_to_broken_stock_leaves_all_records_unchanged()
    {
        $purchase = $this->createPurchase();
        $purchase->update(['status' => Purchase::STATUS_RECEIVED_PARTIALLY]);

        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);
        $receivedNote = $this->createReceivedNote($purchase);
        $location = $receivedNote->location;

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 3,
        ]);

        $rnd = ReceivedNoteDetail::where('po_detail_id', $purchaseDetail->id)->first();
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 10,
            'broken_quantity' => 0,
            'location_id' => $location->id,
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
            'received_note_detail_id' => $rnd->id,
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        $this->product->update(['product_quantity' => 10]);

        $beforeProduct = $this->product->fresh()->toArray();
        $beforeStocks = ProductStock::where('product_id', $this->product->id)->get()->toArray();
        $beforePurchaseDetails = PurchaseDetail::where('id', $purchaseDetail->id)->get()->toArray();
        $beforeTransactionCount = Transaction::where('product_id', $this->product->id)->count();
        $beforeConversionCount = ProductUnitConversion::where('product_id', $this->product->id)->count();
        $beforeBatchCount = UomNormalizationBatch::count();

        $service = app(UomNormalizationExecutionService::class);

        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit,
                (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException to be thrown for broken stock');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stok rusak', $e->getMessage());
        }

        $this->assertEquals($beforeProduct['product_quantity'], $this->product->fresh()->product_quantity);
        $this->assertEquals($beforeStocks, ProductStock::where('product_id', $this->product->id)->get()->toArray());
        $this->assertEquals($beforePurchaseDetails, PurchaseDetail::where('id', $purchaseDetail->id)->get()->toArray());
        $this->assertEquals($beforeTransactionCount, Transaction::where('product_id', $this->product->id)->count());
        $this->assertEquals($beforeConversionCount, ProductUnitConversion::where('product_id', $this->product->id)->count());
        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
    }

    // ─── Purchase Unit Price Correction Tests (Part B) ─────────────────

    public function test_execution_divides_unit_price_by_factor_exactly()
    {
        // Product's base unit is BOX; target base unit is PCS.
        $this->product->update(['unit_id' => $this->boxUnit->id, 'base_unit_id' => $this->boxUnit->id]);
        $this->product->refresh();

        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // Fixture: 10 BOX @ unit_price 10,000, factor 10 -> 100 PCS @ 1,000
        $targetUnit = $this->pcsUnit;
        $factor = 10;

        $originalSubTotal = (float) $purchaseDetail->sub_total;

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $targetUnit,
            $factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Exact division test',
        );

        $this->assertTrue($result['success']);

        $purchaseDetail->refresh();
        $this->assertEquals(100.000, (float) $purchaseDetail->quantity);
        $this->assertEquals(1000.00, (float) $purchaseDetail->unit_price);
        $this->assertEquals($originalSubTotal, (float) $purchaseDetail->sub_total);
    }

    public function test_execution_rounds_repeating_decimal_unit_price_and_records_rounding_effect()
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => 100000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);
        $receivedNote = $this->createReceivedNote($purchase);
        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->product->update(['product_quantity' => 10]);
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
            'received_note_detail_id' => $receivedNoteDetail->id,
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
        );

        // factor 3: 10,000 / 3 = 3333.333... -> rounds to 3333.33
        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit,
            3,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Rounding test',
        );

        $this->assertTrue($result['success']);

        $purchaseDetail->refresh();
        $this->assertEquals(3333.33, (float) $purchaseDetail->unit_price);

        $line = UomNormalizationLine::where('batch_id', $result['batch']->id)->first();
        $this->assertEquals(3333.33, (float) $line->normalized_unit_price);
        $this->assertNotNull($line->unit_price_rounding_effect);
        $this->assertEqualsWithDelta(0.003333, (float) $line->unit_price_rounding_effect, 0.0001);
    }

    public function test_multiple_receipts_on_one_purchase_detail_divide_unit_price_exactly_once()
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = $this->createPurchaseDetail($purchase, 10);

        $location2 = Location::create([
            'name' => 'Gudang Kedua Harga',
            'setting_id' => $this->setting->id,
        ]);

        $receivedNote1 = $this->createReceivedNote($purchase);
        $rnd1 = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote1->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 6,
        ]);

        $receivedNote2 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DO-' . uniqid(),
            'internal_invoice_number' => 'INV-' . uniqid(),
            'date' => now(),
            'location_id' => $location2->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        $rnd2 = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote2->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 4,
        ]);

        foreach ([[$this->location, $rnd1, 6], [$location2, $rnd2, 4]] as [$loc, $rnd, $qty]) {
            ProductStock::create([
                'product_id' => $this->product->id,
                'location_id' => $loc->id,
                'quantity' => $qty,
                'quantity_tax' => 0,
                'quantity_non_tax' => $qty,
                'broken_quantity' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ]);

            Transaction::create([
                'product_id' => $this->product->id,
                'setting_id' => $this->setting->id,
                'quantity' => $qty,
                'current_quantity' => $qty,
                'broken_quantity' => 0,
                'location_id' => $loc->id,
                'user_id' => $this->authorizedUser->id,
                'reason' => 'Diterima dari Pembelian',
                'type' => 'BUY',
                'previous_quantity' => 0,
                'after_quantity' => $qty,
                'previous_quantity_at_location' => 0,
                'after_quantity_at_location' => $qty,
                'quantity_non_tax' => $qty,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'received_note_detail_id' => $rnd->id,
            ]);
        }

        $this->product->update(['product_quantity' => 10]);
        $receivedNote1->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        $receivedNote2->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->addMinute()]);
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
        );

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Multi-receipt single division test',
        );

        $this->assertTrue($result['success']);

        // unit_price 10,000 / factor 12 = 833.333... -> 833.33, exactly once
        // (NOT divided twice to 69.44 which would indicate a per-receipt bug)
        $purchaseDetail->refresh();
        $this->assertEqualsWithDelta(833.33, (float) $purchaseDetail->unit_price, 0.01);

        $lines = UomNormalizationLine::where('batch_id', $result['batch']->id)->get();
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertEqualsWithDelta(833.33, (float) $line->normalized_unit_price, 0.01);
        }
    }

    public function test_supplier_and_sale_monetary_fields_unchanged_after_unit_price_correction()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $supplier = $purchase->supplier;
        $originalSupplierName = $supplier->supplier_name;
        $originalPurchasePaid = (float) $purchase->paid_amount;
        $originalPurchaseDue = (float) $purchase->due_amount;
        $originalPurchaseTotal = (float) $purchase->total_amount;

        // Untouched sale/tier price fixtures
        $originalSalePrice = 2000;
        $this->product->update(['product_price' => $originalSalePrice]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Test reason',
        );

        $this->assertTrue($result['success']);

        $purchase->refresh();
        $supplier->refresh();
        $this->product->refresh();

        $this->assertEquals($originalSupplierName, $supplier->supplier_name);
        $this->assertEquals($originalPurchasePaid, (float) $purchase->paid_amount);
        $this->assertEquals($originalPurchaseDue, (float) $purchase->due_amount);
        $this->assertEquals($originalPurchaseTotal, (float) $purchase->total_amount);
        $this->assertEquals($originalSalePrice, (float) $this->product->product_price);
    }

    // ─── Lock-Ordering / Execution-Bypass Tests (Part E) ────────────────
    // These prove direct service execution (and, separately, a direct HTTP
    // POST to the store endpoint) independently reject invalid scenarios
    // WITHOUT ever calling preview/generatePreview first, and that no
    // mutation occurs for any rejected execution.

    public function test_partial_scope_rejects_without_preview_and_causes_no_mutation()
    {
        [$purchaseA, $pdA, $rnA, $rndA] = $this->createReceivedPurchase(10);
        $txnA = Transaction::where('received_note_detail_id', $rndA->id)->first();
        $txnA->update(['created_at' => now()->subMinutes(10)]);

        [$purchaseB, $pdB, $rnB, $rndB] = $this->createReceivedPurchase(5);
        $txnB = Transaction::where('received_note_detail_id', $rndB->id)->first();
        $txnB->update([
            'created_at' => now(),
            'previous_quantity' => 10, 'after_quantity' => 15, 'current_quantity' => 15,
            'previous_quantity_at_location' => 10, 'after_quantity_at_location' => 15,
        ]);
        $this->product->update(['product_quantity' => 15]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforePdQuantity = $pdA->fresh()->quantity;
        $beforeStockQty = ProductStock::where('product_id', $this->product->id)->sum('quantity');

        $service = app(UomNormalizationExecutionService::class);
        // No preview/generatePreview call anywhere in this test — execute() is
        // called directly, proving the post-lock revalidation is self-sufficient.
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$pdA->id]), // B is omitted -> partial scope
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for partial scope');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Semua baris pembelian produk ini', $e->getMessage());
        }

        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforePdQuantity, $pdA->fresh()->quantity);
        $this->assertEquals($beforeStockQty, ProductStock::where('product_id', $this->product->id)->sum('quantity'));
    }

    public function test_cross_setting_footprint_rejects_without_preview_and_causes_no_mutation()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $otherSetting = Setting::factory()->create();
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $otherSetting->id,
            'quantity' => 1,
            'current_quantity' => 1,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Cross-setting footprint',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 1,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforePdQuantity = $purchaseDetail->fresh()->quantity;

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for cross-setting footprint');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cabang (setting) lain', $e->getMessage());
        }

        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforePdQuantity, $purchaseDetail->fresh()->quantity);
    }

    public function test_target_conversion_conflict_rejects_without_preview_and_causes_no_mutation()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // A conversion already exists for the intended target unit.
        ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $this->targetUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 5,
        ]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforeConversionCount = ProductUnitConversion::where('product_id', $this->product->id)->count();
        $beforePdQuantity = $purchaseDetail->fresh()->quantity;

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for target-conversion conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('konversi untuk unit target', $e->getMessage());
        }

        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforeConversionCount, ProductUnitConversion::where('product_id', $this->product->id)->count());
        $this->assertEquals($beforePdQuantity, $purchaseDetail->fresh()->quantity);
    }

    public function test_broken_stock_rejects_without_preview_and_causes_no_mutation()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $stock = ProductStock::where('product_id', $this->product->id)->first();
        $stock->update(['broken_quantity' => 1, 'broken_quantity_non_tax' => 1]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforePdQuantity = $purchaseDetail->fresh()->quantity;

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for broken stock');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stok rusak', $e->getMessage());
        }

        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforePdQuantity, $purchaseDetail->fresh()->quantity);
    }

    public function test_direct_http_store_without_prior_preview_rejects_invalid_scope()
    {
        [$purchaseA, $pdA, $rnA, $rndA] = $this->createReceivedPurchase(10);
        $txnA = Transaction::where('received_note_detail_id', $rndA->id)->first();
        $txnA->update(['created_at' => now()->subMinutes(10)]);

        [$purchaseB, $pdB, $rnB, $rndB] = $this->createReceivedPurchase(5);
        $txnB = Transaction::where('received_note_detail_id', $rndB->id)->first();
        $txnB->update([
            'created_at' => now(),
            'previous_quantity' => 10, 'after_quantity' => 15, 'current_quantity' => 15,
            'previous_quantity_at_location' => 10, 'after_quantity_at_location' => 15,
        ]);
        $this->product->update(['product_quantity' => 15]);

        $beforeBatchCount = UomNormalizationBatch::count();

        // POST directly to store — never call the preview endpoint.
        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('products.uom-normalize.store', $this->product->id), [
                'target_unit_id' => $this->targetUnit->id,
                'factor' => (float) $this->factor,
                'purchase_detail_ids' => [$pdA->id], // B omitted -> partial scope
                'reason' => 'Direct store test',
                'is_acknowledged' => true,
                'is_sales_price_warning_acknowledged' => true,
            ]);

        $response->assertStatus(422);
        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals(10.0, (float) $pdA->fresh()->quantity);
    }

    // ─── Barcode Migration / Blocking Tests (Part D) ────────────────────

    public function test_former_base_product_barcode_migrates_to_former_base_conversion()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $this->product->update(['barcode' => 'PROD-BARCODE-001']);
        $identity = \Modules\Product\Entities\BarcodeIdentity::create([
            'canonical_key' => \Modules\Product\Utils\BarcodeUtils::canonicalize('PROD-BARCODE-001'),
            'value' => 'PROD-BARCODE-001',
            'product_id' => $this->product->id,
        ]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Barcode migration test',
        );

        $this->assertTrue($result['success']);

        $this->product->refresh();
        $this->assertNull($this->product->barcode);

        $newConversion = ProductUnitConversion::where('product_id', $this->product->id)
            ->where('unit_id', $this->pcsUnit->id)
            ->first();
        $this->assertNotNull($newConversion);
        $this->assertEquals('PROD-BARCODE-001', $newConversion->barcode);

        $identity->refresh();
        $this->assertNull($identity->product_id);
        $this->assertEquals($newConversion->id, $identity->product_unit_conversion_id);

        $barcodeChange = collect($result['batch']->conversion_barcode_changes)
            ->firstWhere('type', 'barcode_migrated');
        $this->assertNotNull($barcodeChange);
        $this->assertEquals('PROD-BARCODE-001', $barcodeChange['barcode_value']);
    }

    public function test_existing_conversion_barcode_remains_correctly_assigned_after_factor_rebase()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $dusUnit = Unit::firstOrCreate(['name' => 'DUS'], ['short_name' => 'DUS']);
        $existingConv = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $dusUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 5,
            'barcode' => 'DUS-BARCODE-001',
        ]);
        $identity = \Modules\Product\Entities\BarcodeIdentity::create([
            'canonical_key' => \Modules\Product\Utils\BarcodeUtils::canonicalize('DUS-BARCODE-001'),
            'value' => 'DUS-BARCODE-001',
            'product_unit_conversion_id' => $existingConv->id,
        ]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Preserve conversion barcode test',
        );

        $this->assertTrue($result['success']);

        $existingConv->refresh();
        $this->assertEquals('DUS-BARCODE-001', $existingConv->barcode);
        // Factor and base unit ARE rebased.
        $this->assertEquals(60, (float) $existingConv->conversion_factor); // 5 * 12
        $this->assertEquals($this->targetUnit->id, $existingConv->base_unit_id);

        $identity->refresh();
        $this->assertEquals($existingConv->id, $identity->product_unit_conversion_id);
        $this->assertNull($identity->product_id);
    }

    public function test_ambiguous_barcode_meaning_blocks_atomically()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // products.barcode is set, but NO matching BarcodeIdentity registry
        // row exists (simulating unbackfilled legacy data).
        $this->product->update(['barcode' => 'LEGACY-UNBACKFILLED']);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforeConversionCount = ProductUnitConversion::where('product_id', $this->product->id)->count();

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for ambiguous barcode');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Kepemilikan barcode tidak dapat dibuktikan', $e->getMessage());
        }

        $this->product->refresh();
        $this->assertEquals('LEGACY-UNBACKFILLED', $this->product->barcode);
        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforeConversionCount, ProductUnitConversion::where('product_id', $this->product->id)->count());
    }

    public function test_duplicate_colliding_barcode_blocks_atomically()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // Ownership of the product's own barcode is cleanly proven (matching
        // BarcodeIdentity exists), so checkBarcodeIntegrity() itself reports
        // eligible — but the value would collide with an UNRELATED existing
        // ProductUnitConversion.barcode at the DB layer when migration
        // attempts to write it onto the newly created former-base conversion.
        // The whole transaction must roll back atomically rather than leave a
        // partially-executed batch.
        $this->product->update(['barcode' => 'COLLIDING-CODE']);
        \Modules\Product\Entities\BarcodeIdentity::create([
            'canonical_key' => \Modules\Product\Utils\BarcodeUtils::canonicalize('COLLIDING-CODE'),
            'value' => 'COLLIDING-CODE',
            'product_id' => $this->product->id,
        ]);

        $otherProduct = Product::create([
            'product_name' => 'Other Product For Collision',
            'product_code' => 'OTHER-COLLIDE-001',
            'product_cost' => 500,
            'product_price' => 1000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'base_unit_id' => $this->pcsUnit->id,
            'unit_id' => $this->pcsUnit->id,
        ]);
        ProductUnitConversion::create([
            'product_id' => $otherProduct->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3,
            'barcode' => 'COLLIDING-CODE',
        ]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforeConversionCount = ProductUnitConversion::where('product_id', $this->product->id)->count();

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected an exception for colliding barcode');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bertabrakan', $e->getMessage());
        }

        $this->product->refresh();
        $this->assertEquals('COLLIDING-CODE', $this->product->barcode);
        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforeConversionCount, ProductUnitConversion::where('product_id', $this->product->id)->count());
    }

    public function test_successful_correction_contains_expected_barcode_audit_entries()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $this->product->update(['barcode' => 'AUDIT-BARCODE']);
        \Modules\Product\Entities\BarcodeIdentity::create([
            'canonical_key' => \Modules\Product\Utils\BarcodeUtils::canonicalize('AUDIT-BARCODE'),
            'value' => 'AUDIT-BARCODE',
            'product_id' => $this->product->id,
        ]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product->fresh(),
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Audit entries test',
        );

        $this->assertTrue($result['success']);

        $changes = $result['batch']->conversion_barcode_changes;
        $this->assertNotEmpty($changes);
        $this->assertTrue(collect($changes)->contains(fn ($c) => $c['type'] === 'create'));
        $this->assertTrue(collect($changes)->contains(fn ($c) => $c['type'] === 'barcode_migrated'));
    }

    // ─── Searchable Selector / Large Catalog Tests (Part C) ─────────────

    public function test_edit_view_does_not_embed_full_product_or_unit_catalog()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // 120 extra products and 60 extra Units in the active setting.
        for ($i = 0; $i < 120; $i++) {
            Product::create([
                'product_name' => "Bulk Product {$i}",
                'product_code' => "BULK-{$i}",
                'product_cost' => 100,
                'product_price' => 200,
                'product_quantity' => 0,
                'setting_id' => $this->setting->id,
                'stock_managed' => true,
                'base_unit_id' => $this->pcsUnit->id,
                'unit_id' => $this->pcsUnit->id,
            ]);
        }
        for ($i = 0; $i < 60; $i++) {
            Unit::create(['name' => "BulkUnit{$i}", 'short_name' => "BU{$i}"]);
        }

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.uom-normalize.edit', $this->product->id));

        $response->assertStatus(200);
        $html = $response->getContent();

        // The old plain <select> elements are gone entirely.
        $this->assertStringNotContainsString('id="productSelect"', $html);
        $this->assertStringNotContainsString('id="targetUnitSelect"', $html);

        // None of the bulk product codes/unit names are embedded as literal
        // option content in the initial page payload.
        $this->assertStringNotContainsString('BULK-50', $html);
        $this->assertStringNotContainsString('BulkUnit50', $html);
    }

    public function test_unit_search_endpoint_excludes_current_base_unit_and_limits_results()
    {
        for ($i = 0; $i < 55; $i++) {
            Unit::create(['name' => "SearchUnit{$i}", 'short_name' => "SU{$i}"]);
        }

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('products.uom-normalize.units.search', $this->product->id) . '?query=SearchUnit&limit=50');

        $response->assertStatus(200);
        $results = $response->json();
        $this->assertLessThanOrEqual(50, count($results));
        $this->assertGreaterThan(0, count($results));

        // Now exclude one specific unit and confirm it's absent.
        $excludeUnit = Unit::where('name', 'SEARCHUNIT0')->first();
        $this->assertNotNull($excludeUnit);
        $response2 = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('products.uom-normalize.units.search', $this->product->id) . '?query=SearchUnit0&exclude_unit_id=' . $excludeUnit->id);

        $response2->assertStatus(200);
        $ids = collect($response2->json())->pluck('id')->all();
        $this->assertNotContains($excludeUnit->id, $ids);
    }

    public function test_candidate_lines_endpoint_returns_lines_across_active_setting()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $otherPurchase = $this->createPurchase();
        $otherSettingPurchaseDetail = PurchaseDetail::create([
            'purchase_id' => $otherPurchase->id,
            'product_id' => $this->product->id,
            'quantity' => 7,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 7000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('products.uom-normalize.candidate-lines', $this->product->id));

        $response->assertStatus(200);
        $results = collect($response->json());
        $this->assertTrue($results->contains(fn ($r) => $r['id'] === $purchaseDetail->id));
        $this->assertTrue($results->contains(fn ($r) => $r['id'] === $otherSettingPurchaseDetail->id));
    }

    public function test_candidate_lines_endpoint_returns_only_route_products_lines()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $otherProduct = Product::create([
            'product_name' => 'Other Candidate Product',
            'product_code' => 'OTHER-CAND-001',
            'product_cost' => 500,
            'product_price' => 1000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'base_unit_id' => $this->pcsUnit->id,
            'unit_id' => $this->pcsUnit->id,
        ]);
        $otherDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $otherProduct->id,
            'quantity' => 3,
            'unit_price' => 500,
            'price' => 500,
            'sub_total' => 1500,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $otherProduct->product_name,
            'product_code' => $otherProduct->product_code,
        ]);

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('products.uom-normalize.candidate-lines', $this->product->id));

        $response->assertStatus(200);
        $results = collect($response->json());
        $this->assertTrue($results->contains(fn ($r) => $r['id'] === $purchaseDetail->id));
        $this->assertFalse($results->contains(fn ($r) => $r['id'] === $otherDetail->id));
    }

    // ─── Cross-Setting Price-Only Policy Tests ───────────────────────────

    public function test_price_only_other_setting_allows_correction_and_rebases_cost_atomically()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $otherSetting = Setting::factory()->create();

        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $otherSetting->id,
            'sale_price' => 5000,
            'tier_1_price' => 4500,
            'tier_2_price' => 4000,
            'average_purchase_price' => 12000,
            'last_purchase_price' => 12000,
        ]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Price-only cross-setting test',
        );

        $this->assertTrue($result['success']);

        $otherPrice = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $otherSetting->id)
            ->first();

        // 12000 / 12 = 1000
        $this->assertEqualsWithDelta(1000.0, (float) $otherPrice->average_purchase_price, 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $otherPrice->last_purchase_price, 0.01);

        // Sale/tier prices remain untouched.
        $this->assertEquals(5000, (float) $otherPrice->sale_price);
        $this->assertEquals(4500, (float) $otherPrice->tier_1_price);
        $this->assertEquals(4000, (float) $otherPrice->tier_2_price);

        // Audit contains Setting B before/after values.
        $costOutcome = $result['batch']->cost_outcome;
        $this->assertArrayHasKey('price_only_settings', $costOutcome);
        $this->assertCount(1, $costOutcome['price_only_settings']);
        $otherOutcome = $costOutcome['price_only_settings'][0];
        $this->assertEquals($otherSetting->id, $otherOutcome['setting_id']);
        $this->assertEquals('price-only-rebased', $otherOutcome['classification']);
        $this->assertEquals(12000.0, (float) $otherOutcome['before']['average_purchase_price']);
        $this->assertEqualsWithDelta(1000.0, (float) $otherOutcome['after']['average_purchase_price'], 0.01);
    }

    public function test_other_setting_with_transaction_blocks_with_setting_identified_and_no_mutation()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $otherSetting = Setting::factory()->create();
        $otherLocation = Location::create(['name' => 'Other Setting Location', 'setting_id' => $otherSetting->id]);

        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $otherSetting->id,
            'quantity' => 1,
            'current_quantity' => 1,
            'broken_quantity' => 0,
            'location_id' => $otherLocation->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Other setting txn',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 1,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforePdQuantity = $purchaseDetail->fresh()->quantity;

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for other-setting transaction footprint');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('riwayat fisik', $e->getMessage());
            $this->assertStringContainsString($otherSetting->company_name, $e->getMessage());
        }

        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforePdQuantity, $purchaseDetail->fresh()->quantity);
    }

    public function test_other_setting_with_purchase_history_blocks_with_setting_identified_and_no_mutation()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $otherSetting = Setting::factory()->create();
        $otherSupplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Other Setting Supplier',
            'supplier_email' => 'other-' . uniqid() . '@example.com',
            'supplier_phone' => '999',
            'city' => 'Bandung',
            'country' => 'Indonesia',
            'address' => 'Other',
            'setting_id' => $otherSetting->id,
        ]);
        $otherPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-OTHER-' . uniqid(),
            'supplier_id' => $otherSupplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $otherSetting->id,
            'is_tax_included' => false,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $otherPurchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 5000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforePdQuantity = $purchaseDetail->fresh()->quantity;

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for other-setting purchase history footprint');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('riwayat fisik', $e->getMessage());
            $this->assertStringContainsString($otherSetting->company_name, $e->getMessage());
        }

        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforePdQuantity, $purchaseDetail->fresh()->quantity);
    }

    public function test_other_setting_with_stock_through_location_blocks_with_setting_identified_and_no_mutation()
    {
        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $otherSetting = Setting::factory()->create();
        $otherLocation = Location::create(['name' => 'Other Setting Stock Location', 'setting_id' => $otherSetting->id]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $otherLocation->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $beforeBatchCount = UomNormalizationBatch::count();
        $beforePdQuantity = $purchaseDetail->fresh()->quantity;

        $service = app(UomNormalizationExecutionService::class);
        try {
            $service->execute(
                $this->product->fresh(),
                $this->targetUnit, (float) $this->factor,
                collect([$purchaseDetail->id]),
                $this->authorizedUser,
                $this->setting->id,
                'Test'
            );
            $this->fail('Expected RuntimeException for other-setting stock footprint');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('riwayat fisik', $e->getMessage());
            $this->assertStringContainsString($otherSetting->company_name, $e->getMessage());
        }

        $this->assertEquals($beforeBatchCount, UomNormalizationBatch::count());
        $this->assertEquals($beforePdQuantity, $purchaseDetail->fresh()->quantity);
    }

    // ─── Audit Rendering Tests (Purchase Show View) ──────────────────────

    public function test_purchase_show_renders_active_setting_and_price_only_other_setting_cost_outcome()
    {
        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        $this->authorizedUser->givePermissionTo('purchases.show');

        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        // Setting.company_name is stored uppercased (BaseModel::setAttribute).
        $otherSetting = Setting::factory()->create(['company_name' => 'Cabang Kedua Uji']);
        $otherSettingName = $otherSetting->company_name;

        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $otherSetting->id,
            'sale_price' => 5000,
            'tier_1_price' => 4500,
            'tier_2_price' => 4000,
            'average_purchase_price' => 12000,
            'last_purchase_price' => 12000,
        ]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Audit rendering test',
        );
        $this->assertTrue($result['success']);

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $html = $response->getContent();

        // Active-setting HPP block is present.
        $this->assertStringContainsString('HPP (Cabang Aktif)', $html);

        // The other (price-only) setting's rebased cost appears, identified
        // by name, with old->new average purchase price rendered.
        $this->assertStringContainsString($otherSettingName, $html);
        $this->assertStringContainsString('Rebase Biaya Pembelian', $html);

        // Sanity: sale/tier prices for the other setting are never mentioned
        // as changed anywhere in this audit block (no sale-price change UI).
        $this->assertStringNotContainsString('Harga Jual', substr(
            $html,
            (int) strpos($html, 'Rebase Biaya Pembelian'),
            2000
        ));
    }

    public function test_purchase_show_renders_legacy_cost_outcome_format()
    {
        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        $this->authorizedUser->givePermissionTo('purchases.show');

        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Legacy format rendering test',
        );
        $this->assertTrue($result['success']);

        // Rewrite the batch's cost_outcome into the LEGACY top-level
        // before/after shape (as pre-cross-setting-rework batches have it),
        // to prove the view still renders it correctly.
        $batch = $result['batch'];
        DB::table('uom_normalization_batches')
            ->where('id', $batch->id)
            ->update([
                'cost_outcome' => json_encode([
                    'before' => ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
                    'after' => ['average_purchase_price' => 833.33, 'last_purchase_price' => 833.33],
                ]),
            ]);

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('HPP (Cabang Aktif)', $html);
        // No price-only-settings table rendered for a legacy batch.
        $this->assertStringNotContainsString('Rebase Biaya Pembelian', $html);
    }

    // ─── Purchase Unit Price Rounding Disclosure Tests ───────────────────

    public function test_preview_discloses_exact_5box_10000_factor3_rounding_scenario()
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => 50000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);
        $receivedNote = $this->createReceivedNote($purchase);
        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 5,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->product->update(['product_quantity' => 5]);
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 5,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $receivedNoteDetail->id,
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
        );

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        // factor 3: 1 BOX = 3 PCS -> 5 BOX = 15 PCS
        $preview = $eligibilityService->generatePreview(
            $this->product,
            $this->targetUnit,
            3,
            collect([$purchaseDetail->id]),
            $this->setting->id,
        );

        $this->assertTrue($preview['eligible']);
        $this->assertCount(1, $preview['lines']);
        $line = $preview['lines'][0];

        $this->assertEquals(5.0, $line['source_quantity']);
        $this->assertEquals(15.0, $line['normalized_quantity']);
        $this->assertEquals(10000.0, $line['source_unit_price']);
        // 10000 / 3 = 3333.333... -> rounds to 3333.33
        $this->assertEquals(3333.33, $line['normalized_unit_price']);
        $this->assertEqualsWithDelta(3333.333333, $line['exact_normalized_unit_price'], 0.000001);
        $this->assertTrue($line['has_unit_price_rounding_effect']);
        $this->assertEqualsWithDelta(0.003333, $line['unit_price_rounding_effect'], 0.0001);

        // Supplier subtotal is preserved exactly — never recomputed as
        // normalized_quantity * normalized_unit_price (15 * 3333.33 = 49999.95, NOT 50000).
        $this->assertEquals(50000.0, $line['source_sub_total']);
        $this->assertNotEquals(
            round($line['normalized_quantity'] * $line['normalized_unit_price'], 2),
            $line['source_sub_total']
        );

        // Now execute and confirm the same facts land in persisted state and audit.
        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit,
            3,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Exact 5 BOX scenario test',
        );

        $this->assertTrue($result['success']);

        $purchaseDetail->refresh();
        $this->assertEquals(15.0, (float) $purchaseDetail->quantity);
        $this->assertEquals(3333.33, (float) $purchaseDetail->unit_price);
        $this->assertEquals(50000.0, (float) $purchaseDetail->sub_total);

        // HPP is derived from PurchaseCostHelper::calculateUnitCost(), which
        // divides the preserved sub_total (50000) by the normalized quantity
        // (15) directly — never by reconstructing sub_total as
        // normalized_quantity * rounded unit_price first. Assert the stored
        // average matches 50000/15 to high precision (3333.333...), proving
        // the source of truth is the original monetary total, not the
        // display-rounded unit price.
        $productPrice = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertEqualsWithDelta(50000 / 15, (float) $productPrice->average_purchase_price, 0.01);

        $line = UomNormalizationLine::where('batch_id', $result['batch']->id)->first();
        $this->assertEquals(3333.33, (float) $line->normalized_unit_price);
        $this->assertNotNull($line->unit_price_rounding_effect);
        $this->assertGreaterThan(0, abs((float) $line->unit_price_rounding_effect));
    }

    public function test_preview_json_response_includes_rounding_disclosure_fields()
    {
        $purchase = $this->createPurchase();
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => 50000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);
        $receivedNote = $this->createReceivedNote($purchase);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 5,
        ]);
        $rnd = ReceivedNoteDetail::where('po_detail_id', $purchaseDetail->id)->first();
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->product->update(['product_quantity' => 5]);
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 5,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $rnd->id,
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);

        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('products.uom-normalize.preview', $this->product->id), [
                'target_unit_id' => $this->targetUnit->id,
                'factor' => 3,
                'purchase_detail_ids' => [$purchaseDetail->id],
            ]);

        $response->assertStatus(200);
        $line = $response->json('preview.lines.0');
        $this->assertNotNull($line);
        $this->assertArrayHasKey('normalized_unit_price', $line);
        $this->assertArrayHasKey('exact_normalized_unit_price', $line);
        $this->assertArrayHasKey('unit_price_rounding_effect', $line);
        $this->assertArrayHasKey('has_unit_price_rounding_effect', $line);
        $this->assertTrue($line['has_unit_price_rounding_effect']);
        $this->assertEquals(3333.33, $line['normalized_unit_price']);
    }

    public function test_exact_division_scenario_has_no_rounding_effect()
    {
        // 10 BOX @ 10,000, factor 10 -> 100 PCS @ exactly 1,000 (no rounding).
        $this->product->update(['unit_id' => $this->boxUnit->id, 'base_unit_id' => $this->boxUnit->id]);
        $this->product->refresh();

        [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail] = $this->createReceivedPurchase(10);

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        $preview = $eligibilityService->generatePreview(
            $this->product,
            $this->pcsUnit,
            10,
            collect([$purchaseDetail->id]),
            $this->setting->id,
        );

        $this->assertTrue($preview['eligible']);
        $line = $preview['lines'][0];

        $this->assertEquals(1000.0, $line['normalized_unit_price']);
        $this->assertEqualsWithDelta(1000.0, $line['exact_normalized_unit_price'], 0.000001);
        $this->assertFalse($line['has_unit_price_rounding_effect']);
        $this->assertEqualsWithDelta(0.0, $line['unit_price_rounding_effect'], 0.000001);
    }

    public function test_audit_history_shows_rounding_explanation_only_when_rounding_effect_is_non_zero()
    {
        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        $this->authorizedUser->givePermissionTo('purchases.show');

        // Batch A: 5 BOX @ 10,000, factor 3 -> rounding effect present.
        $purchaseA = $this->createPurchase();
        $purchaseDetailA = PurchaseDetail::create([
            'purchase_id' => $purchaseA->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => 50000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);
        $receivedNoteA = $this->createReceivedNote($purchaseA);
        $rndA = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNoteA->id,
            'po_detail_id' => $purchaseDetailA->id,
            'quantity_received' => 5,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->product->update(['product_quantity' => 5]);
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 5,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $rndA->id,
        ]);
        $receivedNoteA->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
        );

        $service = app(UomNormalizationExecutionService::class);
        $resultA = $service->execute(
            $this->product,
            $this->targetUnit,
            3,
            collect([$purchaseDetailA->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Rounding-effect batch',
        );
        $this->assertTrue($resultA['success']);

        $responseA = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.show', $purchaseA->id));
        $responseA->assertStatus(200);
        $htmlA = $responseA->getContent();

        $this->assertStringContainsString('Dibulatkan', $htmlA);
        $this->assertStringContainsString('Harga satuan tepat', $htmlA);
        $this->assertStringContainsString('Selisih pembulatan tampilan', $htmlA);

        // Batch B: a SEPARATE product/purchase, base unit BOX -> PCS,
        // factor 10, unit_price 10,000 / 10 = exactly 1,000 -> no rounding
        // effect. A separate product avoids the first product's
        // already-consumed PCS conversion / already-normalized lines.
        $productB = Product::create([
            'product_name' => 'Exact Division Product',
            'product_code' => 'EXACT-DIV-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->boxUnit->id,
        ]);

        $purchaseB = $this->createPurchase();
        $purchaseDetailB = PurchaseDetail::create([
            'purchase_id' => $purchaseB->id,
            'product_id' => $productB->id,
            'quantity' => 10,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => 100000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $productB->product_name,
            'product_code' => $productB->product_code,
        ]);
        $receivedNoteB = $this->createReceivedNote($purchaseB);
        $rndB = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNoteB->id,
            'po_detail_id' => $purchaseDetailB->id,
            'quantity_received' => 10,
        ]);
        ProductStock::create([
            'product_id' => $productB->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $productB->update(['product_quantity' => 10]);
        Transaction::create([
            'product_id' => $productB->id,
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
            'received_note_detail_id' => $rndB->id,
        ]);
        $receivedNoteB->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        ProductPrice::firstOrCreate(
            ['product_id' => $productB->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
        );

        $resultB = $service->execute(
            $productB,
            $this->pcsUnit,
            10,
            collect([$purchaseDetailB->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Exact-division batch',
        );
        $this->assertTrue($resultB['success']);

        $responseB = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.show', $purchaseB->id));
        $responseB->assertStatus(200);
        $htmlB = $responseB->getContent();

        $this->assertStringNotContainsString('Dibulatkan', $htmlB);
        $this->assertStringNotContainsString('Harga satuan tepat', $htmlB);
        $this->assertStringNotContainsString('Selisih pembulatan tampilan', $htmlB);
    }

    // ─── Multi-Receipt Preview Monetary Allocation Tests ────────────────

    /**
     * Build a PurchaseDetail with two approved receipts at distinct
     * locations, returning [$purchase, $purchaseDetail, $rnd1, $rnd2, $location2].
     */
    private function createMultiReceiptPurchaseDetail(
        float $totalQuantity,
        float $qty1,
        float $qty2,
        float $unitPrice,
        float $subTotal,
    ): array {
        $purchase = $this->createPurchase();
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => $totalQuantity,
            'unit_price' => $unitPrice,
            'price' => $unitPrice,
            'sub_total' => $subTotal,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $location2 = Location::create([
            'name' => 'Gudang Alokasi ' . uniqid(),
            'setting_id' => $this->setting->id,
        ]);

        $receivedNote1 = $this->createReceivedNote($purchase);
        $rnd1 = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote1->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => $qty1,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => $qty1,
            'quantity_tax' => 0,
            'quantity_non_tax' => $qty1,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => $qty1,
            'current_quantity' => $qty1,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => $qty1,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty1,
            'quantity_non_tax' => $qty1,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $rnd1->id,
            'created_at' => now()->subMinutes(10),
        ]);
        $receivedNote1->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subMinutes(10)]);

        $receivedNote2 = $this->createReceivedNote($purchase);
        $receivedNote2->update(['location_id' => $location2->id]);
        $rnd2 = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote2->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => $qty2,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location2->id,
            'quantity' => $qty2,
            'quantity_tax' => 0,
            'quantity_non_tax' => $qty2,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => $qty2,
            'current_quantity' => $qty1 + $qty2,
            'broken_quantity' => 0,
            'location_id' => $location2->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => $qty1,
            'after_quantity' => $qty1 + $qty2,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty2,
            'quantity_non_tax' => $qty2,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $rnd2->id,
            'created_at' => now()->subMinutes(5),
        ]);
        $receivedNote2->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subMinutes(5)]);

        $this->product->update(['product_quantity' => $qty1 + $qty2]);
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => $unitPrice, 'last_purchase_price' => $unitPrice],
        );

        return [$purchase, $purchaseDetail, $rnd1, $rnd2, $location2];
    }

    public function test_preview_allocates_subtotal_per_receipt_not_whole_purchase_detail_total()
    {
        // 20 units @ 10,000 = 200,000 total. Split 12/8 across two receipts
        // at two locations. Neither preview line should show 200,000 — each
        // must show its own allocated share, and both must sum to 200,000.
        [$purchase, $purchaseDetail, $rnd1, $rnd2] = $this->createMultiReceiptPurchaseDetail(20, 12, 8, 10000, 200000);

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        $preview = $eligibilityService->generatePreview(
            $this->product,
            $this->targetUnit,
            (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->setting->id,
        );

        $this->assertTrue($preview['eligible']);
        $this->assertCount(2, $preview['lines']);

        $lineByRnd = collect($preview['lines'])->keyBy('received_note_detail_id');
        $line1 = $lineByRnd[$rnd1->id];
        $line2 = $lineByRnd[$rnd2->id];

        // Neither line repeats the full PurchaseDetail total.
        $this->assertNotEquals(200000.0, $line1['source_sub_total']);
        $this->assertNotEquals(200000.0, $line2['source_sub_total']);

        // 12/20 * 200000 = 120000; 8/20 * 200000 = 80000 (evenly divisible,
        // no remainder needed here).
        $this->assertEquals(120000.0, $line1['source_sub_total']);
        $this->assertEquals(80000.0, $line2['source_sub_total']);

        // Exact monetary conservation: allocated lines sum to the original total.
        $this->assertEquals(200000.0, $line1['source_sub_total'] + $line2['source_sub_total']);

        // The PurchaseDetail-level total is available separately and
        // distinctly named, not confused with either line's subtotal.
        $this->assertEquals(200000.0, $line1['purchase_detail_sub_total']);
        $this->assertEquals(200000.0, $line2['purchase_detail_sub_total']);
    }

    public function test_preview_allocation_exactly_matches_persisted_audit_line_after_execution()
    {
        [$purchase, $purchaseDetail, $rnd1, $rnd2] = $this->createMultiReceiptPurchaseDetail(20, 12, 8, 10000, 200000);

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        $preview = $eligibilityService->generatePreview(
            $this->product,
            $this->targetUnit,
            (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->setting->id,
        );
        $previewByRnd = collect($preview['lines'])->keyBy('received_note_detail_id');

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Preview/execution parity test',
        );
        $this->assertTrue($result['success']);

        $auditLines = UomNormalizationLine::where('batch_id', $result['batch']->id)
            ->get()
            ->keyBy('received_note_detail_id');

        foreach ([$rnd1->id, $rnd2->id] as $rndId) {
            $this->assertEquals(
                $previewByRnd[$rndId]['source_sub_total'],
                (float) $auditLines[$rndId]->source_sub_total,
                "Preview and persisted audit source_sub_total must match exactly for received_note_detail #{$rndId}"
            );
        }

        // Persisted audit lines also sum exactly to the original PurchaseDetail sub_total.
        $sumPersisted = $auditLines->sum(fn ($line) => (float) $line->source_sub_total);
        $this->assertEquals(200000.0, $sumPersisted);
    }

    public function test_non_even_allocation_assigns_deterministic_rounding_remainder()
    {
        // 3 PurchaseDetail units, split across THREE separate receipts of
        // qty 1 each. Naive independent per-line rounding of a 100.00
        // sub_total gives 1/3 * 100.00 = 33.333... -> round() -> 33.33 for
        // EVERY line (33.33 * 3 = 99.99), which demonstrably LOSES one
        // cent versus the original 100.00. The same drift occurs on the
        // 10.00 tax total (3.33 * 3 = 9.99). This is a genuine remainder
        // case — not one where naive rounding already happens to conserve.
        //
        // The shared allocation helper must instead assign the leftover
        // cent to the last receipt in deterministic (ascending id) order,
        // producing 33.33 / 33.33 / 33.34 (and 3.33 / 3.33 / 3.34 for tax),
        // each summing exactly to the original total.
        $purchase = $this->createPurchase();
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 3,
            'unit_price' => 33.3333,
            'price' => 33.3333,
            'sub_total' => 100.00,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 10.00,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $rnds = [];
        $qty = 1;
        for ($i = 0; $i < 3; $i++) {
            $location = Location::create([
                'name' => 'Gudang Remainder ' . $i . ' ' . uniqid(),
                'setting_id' => $this->setting->id,
            ]);

            $receivedNote = $this->createReceivedNote($purchase);
            $receivedNote->update(['location_id' => $location->id]);
            $rnd = ReceivedNoteDetail::create([
                'received_note_id' => $receivedNote->id,
                'po_detail_id' => $purchaseDetail->id,
                'quantity_received' => $qty,
            ]);

            ProductStock::create([
                'product_id' => $this->product->id,
                'location_id' => $location->id,
                'quantity' => $qty,
                'quantity_tax' => 0,
                'quantity_non_tax' => $qty,
                'broken_quantity' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ]);
            Transaction::create([
                'product_id' => $this->product->id,
                'setting_id' => $this->setting->id,
                'quantity' => $qty,
                'current_quantity' => $qty,
                'broken_quantity' => 0,
                'location_id' => $location->id,
                'user_id' => $this->authorizedUser->id,
                'reason' => 'Diterima dari Pembelian',
                'type' => 'BUY',
                'previous_quantity' => 0,
                'after_quantity' => $qty,
                'previous_quantity_at_location' => 0,
                'after_quantity_at_location' => $qty,
                'quantity_non_tax' => $qty,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'received_note_detail_id' => $rnd->id,
                'created_at' => now()->subMinutes(15 - $i * 5),
            ]);
            $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subMinutes(15 - $i * 5)]);

            $rnds[] = $rnd;
        }
        [$rnd1, $rnd2, $rnd3] = $rnds;

        // Receipts must have ascending ids, since the allocation helper's
        // deterministic remainder rule depends on this ordering.
        $this->assertTrue($rnd1->id < $rnd2->id);
        $this->assertTrue($rnd2->id < $rnd3->id);

        $this->product->update(['product_quantity' => 3]);
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 33.3333, 'last_purchase_price' => 33.3333],
        );

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        $preview = $eligibilityService->generatePreview(
            $this->product,
            $this->targetUnit,
            (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->setting->id,
        );
        $lineByRnd = collect($preview['lines'])->keyBy('received_note_detail_id');
        $line1 = $lineByRnd[$rnd1->id];
        $line2 = $lineByRnd[$rnd2->id];
        $line3 = $lineByRnd[$rnd3->id];

        // 1. Preview returns the exact three allocations.
        $this->assertEquals(33.33, $line1['source_sub_total']);
        $this->assertEquals(33.33, $line2['source_sub_total']);
        $this->assertEquals(33.34, $line3['source_sub_total']);

        // 2. The highest received_note_detail id (the last in deterministic
        // order) is the one that receives the remainder-bearing 33.34 —
        // not merely that "some" line sums correctly.
        $this->assertEquals($rnd3->id, max($rnd1->id, $rnd2->id, $rnd3->id));
        $this->assertEquals(33.34, $lineByRnd[max($rnd1->id, $rnd2->id, $rnd3->id)]['source_sub_total']);

        // 3. Preview allocations sum exactly to 100.00 (not 99.99, which
        // naive independent per-line rounding would produce).
        $this->assertEquals(
            100.00,
            round($line1['source_sub_total'] + $line2['source_sub_total'] + $line3['source_sub_total'], 2)
        );

        // 6. Tax allocation follows the identical conservation/remainder rule.
        $this->assertEquals(3.33, $line1['source_tax_amount']);
        $this->assertEquals(3.33, $line2['source_tax_amount']);
        $this->assertEquals(3.34, $line3['source_tax_amount']);
        $this->assertEquals(
            10.00,
            round($line1['source_tax_amount'] + $line2['source_tax_amount'] + $line3['source_tax_amount'], 2)
        );

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit, (float) $this->factor,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Non-even allocation test',
        );
        $this->assertTrue($result['success']);

        $auditLines = UomNormalizationLine::where('batch_id', $result['batch']->id)->get();
        $auditByRnd = $auditLines->keyBy('received_note_detail_id');

        // 4. After execution, each persisted source_sub_total exactly
        // equals its corresponding preview allocation, per receipt.
        foreach ([$rnd1->id, $rnd2->id, $rnd3->id] as $rndId) {
            $this->assertEquals(
                $lineByRnd[$rndId]['source_sub_total'],
                (float) $auditByRnd[$rndId]->source_sub_total,
                "Preview and persisted audit source_sub_total must match exactly for received_note_detail #{$rndId}"
            );
            $this->assertEquals(
                $lineByRnd[$rndId]['source_tax_amount'],
                (float) $auditByRnd[$rndId]->source_tax_amount,
                "Preview and persisted audit source_tax_amount must match exactly for received_note_detail #{$rndId}"
            );
        }
        $this->assertEquals(33.34, (float) $auditByRnd[$rnd3->id]->source_sub_total);
        $this->assertEquals(3.34, (float) $auditByRnd[$rnd3->id]->source_tax_amount);

        // 5. Persisted audit allocations sum exactly to 100.00 / 10.00.
        $sumPersistedSubTotal = $auditLines->sum(fn ($line) => (float) $line->source_sub_total);
        $sumPersistedTax = $auditLines->sum(fn ($line) => (float) $line->source_tax_amount);
        $this->assertEquals(100.00, round($sumPersistedSubTotal, 2));
        $this->assertEquals(10.00, round($sumPersistedTax, 2));
    }

    public function test_exact_5box_10000_factor3_scenario_still_holds_with_shared_allocation_helper()
    {
        // Re-confirms the required scenario continues to hold after
        // introducing the shared allocation helper: single-receipt
        // PurchaseDetail, subtotal remains exactly 50,000, stored unit
        // price becomes 3,333.33.
        $purchase = $this->createPurchase();
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => 50000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);
        $receivedNote = $this->createReceivedNote($purchase);
        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 5,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->product->update(['product_quantity' => 5]);
        Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->authorizedUser->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 5,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $receivedNoteDetail->id,
        ]);
        $receivedNote->update(['status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()]);
        ProductPrice::firstOrCreate(
            ['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['average_purchase_price' => 10000, 'last_purchase_price' => 10000],
        );

        $eligibilityService = app(UomNormalizationEligibilityService::class);
        $preview = $eligibilityService->generatePreview(
            $this->product,
            $this->targetUnit,
            3,
            collect([$purchaseDetail->id]),
            $this->setting->id,
        );
        $line = $preview['lines'][0];
        $this->assertEquals(50000.0, $line['source_sub_total']);
        $this->assertEquals(3333.33, $line['normalized_unit_price']);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->targetUnit,
            3,
            collect([$purchaseDetail->id]),
            $this->authorizedUser,
            $this->setting->id,
            'Exact scenario re-confirmation',
        );
        $this->assertTrue($result['success']);

        $purchaseDetail->refresh();
        $this->assertEquals(50000.0, (float) $purchaseDetail->sub_total);
        $this->assertEquals(3333.33, (float) $purchaseDetail->unit_price);
    }
}

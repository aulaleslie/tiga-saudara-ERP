<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Purchase returns keep eligibility, persisted quantities, stock reversal,
 * and MODIFY_PURCHASE valuation strictly in canonical (base-unit) decimal
 * quantity, matching the decimal-safe receiving path. Entered-unit context
 * is display-only elsewhere (Task 7.1); the return pipeline itself has no
 * unit-conversion concept and always operates on canonical quantity.
 */
class PurchaseReturnDecimalQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_quantity' => 0,
            'product_cost' => 10000,
            'product_price' => 12000,
            'product_unit' => 'pcs',
            'product_stock_alert' => 0,
            'setting_id' => 1,
        ]);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => 1,
        ]);

        session(['setting_id' => 1]);
        Gate::define('purchaseReturnSettlements.approve', fn () => true);
        Gate::define('purchaseReturnSettlements.receive', fn () => true);
        Gate::define('purchaseReturns.dispatchApproval', fn () => true);
        Gate::define('purchaseReturns.approval', fn () => true);
    }

    private function makePurchaseWithConversionReceipt(): array
    {
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-CONV-' . uniqid(),
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            0, 1000, 1000,
            'Received', 'Unpaid',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);

        // Ordered 1.000 canonical PCS (e.g. from a converted BOX line), priced Rp1,000.
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Received across three fractional receipts that discriminate float drift:
        // 0.001 + 0.063 + 0.936 = exactly 1.000, but 0.1+0.2-style drift would not.
        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'external_delivery_number' => 'GRN-' . uniqid(),
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        foreach (['0.001', '0.063', '0.936'] as $chunk) {
            ReceivedNoteDetail::create([
                'received_note_id' => $receivedNote->id,
                'po_detail_id' => $detail->id,
                'quantity_received' => $chunk,
            ]);
        }

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->product->update(['product_quantity' => 1]);

        return [$purchase, $detail];
    }

    public function test_partial_decimal_return_reduces_purchase_detail_and_received_quantities_exactly(): void
    {
        [$purchase, $purchaseDetail] = $this->makePurchaseWithConversionReceipt();

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-DEC-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'total_amount' => 688,
            'due_amount' => 688,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);

        // Partial, decimal return: 0.688 of the 1.000 canonical quantity.
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => '0.688',
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 688,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $this->assertSame('0.688', (string) $returnDetail->fresh()->quantity);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 688,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        // Canonical purchase-detail quantity reduced by exactly 0.688, not truncated to an integer.
        $purchaseDetail->refresh();
        $this->assertSame('0.312', (string) $purchaseDetail->quantity);

        // Received quantities reduced from the most recent approved receipt first:
        // 0.936 - 0.688 = 0.248 remains; the earlier 0.001 and 0.063 receipts are untouched.
        $received = ReceivedNoteDetail::where('po_detail_id', $purchaseDetail->id)
            ->orderByDesc('id')
            ->pluck('quantity_received')
            ->map(fn ($q) => (string) $q)
            ->values()
            ->all();

        $this->assertEquals(['0.248', '0.063', '0.001'], $received);
    }

    public function test_over_return_beyond_received_canonical_quantity_is_rejected(): void
    {
        // Ordered 2.000 canonical, but only 1.000 has actually been received across the
        // three fractional receipts (0.001 + 0.063 + 0.936). Nominal is kept within the
        // purchase's total so the unrelated nominal-vs-total guard does not also fire,
        // isolating the quantity-eligibility check under test.
        [$purchase, $purchaseDetail] = $this->makePurchaseWithConversionReceipt();
        $purchaseDetail->update(['quantity' => '2.000']);
        $purchase->update(['total_amount' => 2000]);

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-OVER-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 1500,
            'due_amount' => 1500,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);

        // Return quantity (1.500) exceeds what has actually been received (1.000),
        // even though it is within the ordered quantity (2.000).
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => '1.500',
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1500,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('melebihi kuantitas diterima', session('error'));

        // Nothing was mutated: the purchase detail and received quantities are untouched.
        $this->assertSame('2.000', (string) $purchaseDetail->fresh()->quantity);
        $this->assertSame('SUBMITTED', $settlementItem->fresh()->status);
    }

    public function test_exact_boundary_return_of_full_canonical_quantity_is_allowed(): void
    {
        [$purchase, $purchaseDetail] = $this->makePurchaseWithConversionReceipt();

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-EXACT-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);

        // Returning precisely the full ordered/received canonical quantity (1.000) must be allowed.
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => '1.000',
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        $purchaseDetail->refresh();
        $this->assertSame('0.000', (string) $purchaseDetail->quantity);

        // Fully returned: the source purchase is archived.
        $this->assertNotNull($purchase->fresh()->archived_at);
    }

    public function test_legacy_integer_quantity_purchase_line_returns_correctly(): void
    {
        // Legacy purchase line: pre-decimal, whole-number canonical quantity, no fractional receipts.
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-LEGACY-' . uniqid(),
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            0, 100000, 100000,
            'Received', 'Unpaid',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'external_delivery_number' => 'GRN-LEGACY-' . uniqid(),
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-LEGACY-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 20000,
            'due_amount' => 20000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);

        // Legacy-style whole-number return quantity, persisted through the now-decimal column.
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $this->assertSame('2.000', (string) $returnDetail->fresh()->quantity);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 20000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        $purchaseDetail->refresh();
        $this->assertSame('8.000', (string) $purchaseDetail->quantity);
        $this->assertSame('8.000', (string) ReceivedNoteDetail::where('po_detail_id', $purchaseDetail->id)->value('quantity_received'));
    }

    public function test_dispatch_approval_reverses_decimal_stock_across_priority_buckets(): void
    {
        $product = $this->product;
        $product->update(['product_quantity' => 0]);

        // Stock split across broken and good buckets to exercise the priority-order deduction.
        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 1.5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1.0,
            'broken_quantity' => 0.5,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0.5,
        ]);

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-DISPATCH-DEC-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => 'Pending',
            'approval_status' => 'approved',
            'payment_status' => 'Unpaid',
            'return_dispatch_status' => 'pending_approval',
        ]);

        // Decimal return quantity of 0.750: 0.5 comes from broken (non-tax), 0.25 from good stock.
        PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => '0.750',
            'location_id' => $this->location->id,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 750,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-returns.dispatch-approve', $return->id));
        $response->assertStatus(302);

        $stock->refresh();
        $product->refresh();

        // Priority 1 (broken_quantity_non_tax): fully drained, 0.5 taken.
        $this->assertEquals(0.0, (float) $stock->broken_quantity_non_tax);
        $this->assertEqualsWithDelta(0.0, (float) $stock->broken_quantity, 0.0001);
        // Priority 3 (quantity_non_tax, good stock): remaining 0.25 taken.
        $this->assertEqualsWithDelta(0.75, (float) $stock->quantity_non_tax, 0.0001);
        // The aggregate `quantity` field tracks only the good-stock bucket, so it drops
        // by the 0.25 good-stock portion alone (1.5 - 0.25 = 1.25), not the full 0.75.
        $this->assertEqualsWithDelta(1.25, (float) $stock->quantity, 0.0001);
        // Product-level good-stock quantity decremented only by the good-stock portion (0.25).
        $this->assertEqualsWithDelta(-0.25, (float) $product->product_quantity, 0.0001);
    }

    public function test_stock_approval_gate_rejects_decimal_quantity_exceeding_available_stock(): void
    {
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 0.5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0.5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-APPROVAL-DEC-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => 'Pending',
            'approval_status' => 'pending',
            'payment_status' => 'Unpaid',
        ]);

        // Requesting a return of 0.6 when only 0.5 is in stock must be rejected, not
        // silently accepted through int-truncated comparison (0.6 < 0.5 would be masked as 0 < 0).
        PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => '0.600',
            'location_id' => $this->location->id,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 600,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-returns.approve', $return->id));
        $response->assertStatus(302);

        $this->assertNotEquals('approved', strtolower($return->fresh()->approval_status));
    }

    private function makeConversionUnits(): array
    {
        $pcsUnit = \Modules\Setting\Entities\Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $boxUnit = \Modules\Setting\Entities\Unit::create(['name' => 'BOX', 'short_name' => 'box', 'operator' => '*', 'operation_value' => 1]);

        return [$pcsUnit, $boxUnit];
    }

    public function test_partial_return_rebases_entered_unit_snapshot_when_evenly_divisible(): void
    {
        [$pcsUnit, $boxUnit] = $this->makeConversionUnits();
        $this->product->update(['unit_id' => $pcsUnit->id, 'base_unit_id' => $pcsUnit->id]);

        $conversion = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-SNAP-' . uniqid(),
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            0, 24000, 24000,
            'Received', 'Unpaid',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);

        // 2 BOX = 24 PCS, Rp1,000/PCS = Rp12,000/BOX.
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'external_delivery_number' => 'GRN-SNAP-' . uniqid(),
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 24,
        ]);

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-SNAP-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 12000,
            'due_amount' => 12000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);

        // Return 12 PCS (1 BOX worth) canonical: an evenly-divisible partial return.
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 12,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 12000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 12000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        $purchaseDetail->refresh();
        $this->assertSame('12.000', (string) $purchaseDetail->quantity);
        // Entered-unit snapshot rebased: 12 PCS / factor 12 = exactly 1 BOX, not the stale 2.
        $this->assertSame('1.000', (string) $purchaseDetail->entered_quantity);
        $this->assertSame('BOX', $purchaseDetail->unit_name);
        $this->assertEquals(12000, (float) $purchaseDetail->entered_unit_price);
        $this->assertEquals(12000, (float) $purchaseDetail->sub_total);
    }

    public function test_partial_return_invalidates_entered_unit_snapshot_when_not_evenly_divisible(): void
    {
        [$pcsUnit, $boxUnit] = $this->makeConversionUnits();
        $this->product->update(['unit_id' => $pcsUnit->id, 'base_unit_id' => $pcsUnit->id]);

        $conversion = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-SNAPBAD-' . uniqid(),
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            0, 24000, 24000,
            'Received', 'Unpaid',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);

        // 2 BOX = 24 PCS, Rp1,000/PCS.
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'external_delivery_number' => 'GRN-SNAPBAD-' . uniqid(),
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 24,
        ]);

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-SNAPBAD-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 5000,
            'due_amount' => 5000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);

        // Return 5 PCS canonical: 24 - 5 = 19 PCS remains, which is not a whole/clean
        // BOX count (19 / 12 does not fit the supported 3-decimal precision cleanly
        // is false here since 19/12 = 1.58333... — exercise the invalidation path).
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 5000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        $this->actingAs(User::factory()->create());
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        $purchaseDetail->refresh();
        $this->assertSame('19.000', (string) $purchaseDetail->quantity);
        // Cannot cleanly represent 19 PCS as a BOX count: snapshot invalidated rather
        // than shown stale (2 BOX) or silently rounded.
        $this->assertNull($purchaseDetail->entered_quantity);
        $this->assertNull($purchaseDetail->entered_unit_price);
        $this->assertNull($purchaseDetail->unit_name);
        $this->assertNull($purchaseDetail->purchase_unit_id);
        $this->assertNull($purchaseDetail->product_unit_conversion_id);
        $this->assertNull($purchaseDetail->conversion_factor);
        // Legacy-style fallback now applies: effective accessors read canonical values.
        $this->assertEquals(19.0, (float) $purchaseDetail->effective_entered_quantity);
        $this->assertSame('PCS', $purchaseDetail->effective_unit_name);
    }

    public function test_broken_stock_receipt_accepts_decimal_quantity_matching_return_line(): void
    {
        $returnDetailQty = '0.750';

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-BROKEN-DEC-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'total_amount' => 750,
            'paid_amount' => 0,
            'due_amount' => 750,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'payment_status' => 'Unpaid',
        ]);

        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => $returnDetailQty,
            'location_id' => $this->location->id,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 750,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'BROKEN_STOCK',
            'nominal' => 750,
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $this->actingAs(User::factory()->create());

        // A quantity that neither equals nor truncates-to the exact 0.750 owed must
        // still be rejected: 0.751 rounds to the "same" integer as 0.750 under a
        // naive integer comparison, but is not exactly equal at decimal-3 precision.
        $mismatch = $this->post(route('purchase-return-settlements.item.receive', $settlementItem->id), [
            'location_id' => $this->location->id,
            'received_quantity' => '0.751',
            'note' => 'Wrong amount',
        ]);
        $mismatch->assertSessionHasErrors('received_quantity');
        $this->assertSame(PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE, $settlementItem->fresh()->status);

        // The exact decimal quantity owed must be accepted.
        $response = $this->post(route('purchase-return-settlements.item.receive', $settlementItem->id), [
            'location_id' => $this->location->id,
            'received_quantity' => $returnDetailQty,
            'note' => 'Marked broken',
        ]);
        $response->assertSessionHas('success');

        $settlementItem->refresh();
        $this->assertSame(PurchaseReturnItemSettlement::STATUS_RECEIVED, $settlementItem->status);
        $this->assertSame('0.750', (string) $settlementItem->received_quantity);

        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEqualsWithDelta(0.25, (float) $stock->quantity_non_tax, 0.0001);
        $this->assertEqualsWithDelta(0.75, (float) $stock->broken_quantity_non_tax, 0.0001);
        $this->assertEqualsWithDelta(0.75, (float) $stock->broken_quantity, 0.0001);
    }

    public function test_product_repair_receipt_moves_decimal_stock_between_locations(): void
    {
        $otherLocation = Location::create(['name' => 'Repair Depot', 'setting_id' => 1]);

        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-REPAIR-DEC-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'payment_status' => 'Unpaid',
        ]);

        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => '0.500',
            'location_id' => $this->location->id,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'PRODUCT_REPAIR',
            'nominal' => 500,
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $this->actingAs(User::factory()->create());

        // A decimal received quantity (0.500) must be accepted, not rejected by the
        // former `integer` rule.
        $response = $this->post(route('purchase-return-settlements.item.receive', $settlementItem->id), [
            'location_id' => $otherLocation->id,
            'received_quantity' => '0.500',
            'note' => 'Repaired and returned',
        ]);
        $response->assertSessionHas('success');

        $settlementItem->refresh();
        $this->assertSame(PurchaseReturnItemSettlement::STATUS_RECEIVED, $settlementItem->status);
        $this->assertSame('0.500', (string) $settlementItem->received_quantity);

        $sourceStock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEqualsWithDelta(0.5, (float) $sourceStock->quantity_non_tax, 0.0001);

        $targetStock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $otherLocation->id)
            ->first();
        $this->assertNotNull($targetStock);
        $this->assertEqualsWithDelta(0.5, (float) $targetStock->quantity_non_tax, 0.0001);
        $this->assertEqualsWithDelta(0.5, (float) $targetStock->quantity, 0.0001);
    }
}

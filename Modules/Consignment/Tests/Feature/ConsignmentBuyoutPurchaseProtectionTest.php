<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException;
use Modules\Purchase\Services\PurchasePaymentStoreService;
use Modules\Purchase\Services\PurchaseSourceGuard;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Source protections (6.4), payments/administrative maintenance (6.5) and status-endpoint authorization (6.6)
 * for CONSIGNMENT_BUYOUT Purchases, always compared with legacy CONSIGNMENT_BILLING Purchases.
 */
class ConsignmentBuyoutPurchaseProtectionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    private User $user;
    private Purchase $buyoutPurchase;
    private Purchase $legacyPurchase;
    private ConsignmentBuyout $buyout;
    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuyoutFixtures();
        config(['consignment.buyouts.finalization_enabled' => true]);

        $this->user = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.acquire_unsold',
            'consignments.allocations.approve', 'consignments.buyouts.finalize', 'consignments.billing.convert'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->user->givePermissionTo($p);
        }
        $this->user->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);

        // Finalized buyout: 4 of 10 units acquired at 100 => Purchase of 400.
        $dest = Location::create(['setting_id' => $this->setting->id, 'name' => 'Dest', 'is_consignment' => false]);
        $other = Location::create(['setting_id' => $this->setting->id, 'name' => 'Other', 'is_consignment' => false]);
        $r = $this->receipt($this->supplier, '10');
        \DB::table('consignment_receiving_details')->where('id', $r->id)->update(['unit_dpp' => 100, 'unit_cost' => 100]);
        $this->stock('10');
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $other->id, 'quantity' => 10, 'quantity_tax' => 0,
            'quantity_non_tax' => 10, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);

        $life = new ConsignmentBuyoutLifecycleService();
        $b = $life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $dest->id,
            'base_quantity' => '4', 'buckets' => ['NON_TAXED' => '4']]], $this->user,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10', 'supplier_invoice_number' => 'INV-P1']]);
        $this->buyout = $life->approve($life->submit($b, $this->user), $this->user);
        $this->buyoutPurchase = (new ConsignmentBuyoutFinalizationService())->finalize($this->buyout, $this->user);

        $this->legacyPurchase = Purchase::create([
            'setting_id' => $this->setting->id, 'date' => now(), 'due_date' => now()->addDays(30), 'reference' => 'PUR-LEGACY-1',
            'supplier_id' => $this->supplier->id, 'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING, 'payment_method' => 'Bank Transfer',
            'total_amount' => 1000.0, 'sub_total' => 1000.0, 'paid_amount' => 0.0, 'due_amount' => 1000.0,
            'status' => Purchase::STATUS_RECEIVED, 'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
        ]);

        $coa = ChartOfAccount::create(['setting_id' => $this->setting->id, 'account_number' => 'COA-' . uniqid(), 'name' => 'Cash in Bank', 'category' => 'Kas & Bank']);
        $this->method = PaymentMethod::create(['name' => 'Bank Transfer', 'coa_id' => $coa->id, 'is_active' => true]);
    }

    private function both(): array
    {
        return ['buyout' => $this->buyoutPurchase->fresh(), 'legacy' => $this->legacyPurchase->fresh()];
    }

    // ------------------------------------------------------------------------------------------- 6.4

    /** @test */
    public function ordinary_lifecycle_operations_are_denied_for_both_consignment_financial_sources(): void
    {
        $this->assertTrue($this->buyoutPurchase->fresh()->isConsignmentBuyout());
        $this->assertFalse($this->buyoutPurchase->fresh()->isConsignmentBilling());
        $this->assertTrue($this->buyoutPurchase->fresh()->isConsignmentFinancial());

        foreach ($this->both() as $label => $purchase) {
            foreach (['assertReceivingAllowed', 'assertCommercialEditAllowed', 'assertReturnAllowed', 'assertDeletionAllowed'] as $guard) {
                try {
                    PurchaseSourceGuard::$guard($purchase);
                    $this->fail("{$label}: {$guard} allowed");
                } catch (PurchaseSourceOperationNotAllowedException $e) {
                    $this->assertTrue(true);
                }
            }
            $this->assertSame(Purchase::EDIT_MODE_NONE, $purchase->resolveEditMode($this->user));
            // Payments and reporting-date maintenance stay allowed.
            PurchaseSourceGuard::assertPaymentAllowed($purchase);
            PurchaseSourceGuard::assertReportingDateOverrideAllowed($purchase);
        }
    }

    /** @test */
    public function commercial_header_and_detail_evidence_is_immutable_for_both_sources(): void
    {
        $legacyDetail = PurchaseDetail::create(['purchase_id' => $this->legacyPurchase->id, 'product_id' => $this->product->id, 'product_name' => 'W',
            'product_code' => 'W', 'quantity' => 1, 'price' => 1000, 'unit_price' => 1000, 'sub_total' => 1000, 'product_discount_amount' => 0,
            'product_discount_type' => 'fixed', 'product_tax_amount' => 0]);
        $buyoutDetail = $this->buyoutPurchase->purchaseDetails()->first();

        foreach ([['buyout', $this->buyoutPurchase, $buyoutDetail], ['legacy', $this->legacyPurchase, $legacyDetail]] as [$label, $purchase, $detail]) {
            foreach (['total_amount' => 1, 'supplier_id' => 999, 'source_type' => Purchase::SOURCE_ORDINARY, 'status' => Purchase::STATUS_APPROVED, 'tax_amount' => 5] as $field => $value) {
                try {
                    $purchase->fresh()->update([$field => $value]);
                    $this->fail("{$label}: header {$field} was mutable");
                } catch (\DomainException $e) {
                    $this->assertStringContainsString($field, $e->getMessage());
                }
            }
            try {
                $detail->update(['quantity' => 99]);
                $this->fail("{$label}: detail was mutable");
            } catch (PurchaseSourceOperationNotAllowedException $e) {
                $this->assertTrue(true);
            }
            try {
                $detail->delete();
                $this->fail("{$label}: detail was deletable");
            } catch (PurchaseSourceOperationNotAllowedException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertEquals(400.0, (float) $this->buyoutPurchase->fresh()->total_amount);
        $this->assertSame(1, $this->buyoutPurchase->purchaseDetails()->count());
    }

    // ------------------------------------------------------------------------------------------- 6.5

    /** @test */
    public function partial_and_full_payments_and_administrative_maintenance_move_no_inventory_or_approved_evidence(): void
    {
        $svc = new PurchasePaymentStoreService();
        $stockBefore = \DB::table('product_stocks')->orderBy('id')->get(['quantity', 'quantity_non_tax'])->toArray();
        $counts = [
            \DB::table('consignment_buyout_movements')->count(), \DB::table('transactions')->count(), \DB::table('consignment_buyout_cost_events')->count(),
        ];
        $linesBefore = $this->buyout->lines()->orderBy('id')->get(['base_quantity', 'accepted_snapshot'])->toArray();
        $hashBefore = $this->buyout->fresh()->approved_revision_hash;

        $svc->store(purchase: $this->buyoutPurchase->fresh(), actor: $this->user, data: [
            'date' => now()->format('Y-m-d'), 'reference' => 'PAY-B1', 'amount' => 150.0, 'payment_method_id' => $this->method->id]);
        $p = $this->buyoutPurchase->fresh();
        $this->assertEquals([150.0, 250.0, Purchase::PAYMENT_STATUS_PARTIAL], [(float) $p->paid_amount, (float) $p->due_amount, $p->payment_status]);

        // Over-payment is rejected under the live balance.
        try {
            $svc->store(purchase: $p, actor: $this->user, data: [
                'date' => now()->format('Y-m-d'), 'reference' => 'PAY-B2', 'amount' => 999.0, 'payment_method_id' => $this->method->id]);
            $this->fail('overpayment accepted');
        } catch (\DomainException $e) {
            $this->assertTrue(true);
        }

        $svc->store(purchase: $this->buyoutPurchase->fresh(), actor: $this->user, data: [
            'date' => now()->format('Y-m-d'), 'reference' => 'PAY-B3', 'amount' => 250.0, 'payment_method_id' => $this->method->id]);
        $p = $this->buyoutPurchase->fresh();
        $this->assertEquals([400.0, 0.0, Purchase::PAYMENT_STATUS_PAID], [(float) $p->paid_amount, (float) $p->due_amount, $p->payment_status]);

        // Administrative maintenance is allowed (note, reference, tax ref, dates) and never touches commercial evidence.
        $p->update(['note' => 'Dibayar lunas', 'supplier_purchase_number' => 'INV-P1-REV', 'tax_ref_no' => 'FP-001',
            'reporting_date' => '2026-11-12', 'due_date' => '2026-12-01']);
        $this->assertSame('INV-P1-REV', $this->buyoutPurchase->fresh()->supplier_purchase_number);

        $this->assertEquals($stockBefore, \DB::table('product_stocks')->orderBy('id')->get(['quantity', 'quantity_non_tax'])->toArray(), 'payment moves no stock');
        $this->assertSame($counts, [\DB::table('consignment_buyout_movements')->count(), \DB::table('transactions')->count(), \DB::table('consignment_buyout_cost_events')->count()]);
        $this->assertEquals($linesBefore, $this->buyout->lines()->orderBy('id')->get(['base_quantity', 'accepted_snapshot'])->toArray(), 'approval evidence untouched');
        $this->assertSame($hashBefore, $this->buyout->fresh()->approved_revision_hash);
        $this->assertTrue((new ConsignmentBuyoutLifecycleService())->approvedHashIsCurrent($this->buyout->fresh()));
        $this->assertSame('FINALIZED', $this->buyout->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------- 6.6

    /** @test */
    public function unauthorized_status_changes_return_403_and_change_nothing_for_both_sources(): void
    {
        $nobody = User::factory()->create();
        $nobody->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);

        foreach ($this->both() as $label => $purchase) {
            $before = Purchase::find($purchase->id)->only(['status', 'total_amount', 'due_amount', 'source_type', 'payment_status']);
            $this->actingAs($nobody)->withSession(['setting_id' => $this->setting->id])
                ->patch(route('purchases.updateStatus', $purchase), ['status' => Purchase::STATUS_APPROVED])
                ->assertForbidden();
            $this->assertEquals($before, Purchase::find($purchase->id)->only(['status', 'total_amount', 'due_amount', 'source_type', 'payment_status']), $label);
        }
    }

    /** @test */
    public function authorized_status_changes_are_business_rejections_with_ordinary_handling_and_no_mutation(): void
    {
        $approver = User::factory()->create();
        $approver->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        foreach (['purchases.approval', 'purchases.update'] as $p) {
            Permission::findOrCreate($p, 'web');
            $approver->givePermissionTo($p);
        }

        foreach ($this->both() as $label => $purchase) {
            $before = Purchase::find($purchase->id)->only(['status', 'total_amount', 'source_type']);
            $response = $this->actingAs($approver)->withSession(['setting_id' => $this->setting->id])
                ->patch(route('purchases.updateStatus', $purchase), ['status' => Purchase::STATUS_APPROVED]);
            $response->assertRedirect();          // ordinary business-error handling, not a 403/500
            $this->assertNotSame(403, $response->getStatusCode());
            $this->assertEquals($before, Purchase::find($purchase->id)->only(['status', 'total_amount', 'source_type']), $label);
        }
    }
}

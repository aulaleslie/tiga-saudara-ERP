<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use App\Services\MonetaryEdit\MonetaryEditException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException;
use Modules\Purchase\Services\PurchaseMonetaryEditService;
use Modules\Purchase\Services\PurchaseSourceGuard;
use Modules\Setting\Entities\Location;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Standard monetary editing on generated consignment Purchases (billing and buyout): the narrow exception reprices
 * existing rows in place under the standard rules, and every other source protection and evidence stays intact.
 */
class ConsignmentGeneratedPurchaseMonetaryEditTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    private User $user;
    private Purchase $buyoutPurchase;
    private Purchase $legacyPurchase;
    private ConsignmentBuyout $buyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuyoutFixtures();
        config(['consignment.buyouts.finalization_enabled' => true]);

        $this->user = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.acquire_unsold',
            'consignments.allocations.approve', 'consignments.buyouts.finalize', 'consignments.billing.convert',
            'purchases.update', 'purchases.received.monetary.edit'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->user->givePermissionTo($p);
        }
        $this->user->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $dest = Location::create(['setting_id' => $this->setting->id, 'name' => 'Dest', 'is_consignment' => false]);
        $r = $this->receipt($this->supplier, '10');
        DB::table('consignment_receiving_details')->where('id', $r->id)->update(['unit_dpp' => 100, 'unit_cost' => 100]);
        $this->stock('10');
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);

        $life = new ConsignmentBuyoutLifecycleService();
        $b = $life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $dest->id,
            'base_quantity' => '4', 'buckets' => ['NON_TAXED' => '4']]], $this->user,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10']]);
        $this->buyout = $life->approve($life->submit($b, $this->user), $this->user);
        $this->buyoutPurchase = (new ConsignmentBuyoutFinalizationService())->finalize($this->buyout, $this->user);

        $this->legacyPurchase = Purchase::create([
            'setting_id' => $this->setting->id, 'date' => now(), 'due_date' => now()->addDays(30), 'reference' => 'PUR-LEGACY-M1',
            'supplier_id' => $this->supplier->id, 'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING, 'payment_method' => 'Bank Transfer',
            'total_amount' => 1000.0, 'sub_total' => 1000.0, 'paid_amount' => 0.0, 'due_amount' => 1000.0,
            'status' => Purchase::STATUS_RECEIVED, 'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
        ]);
        // Historical multi-row document: two details for the same product must both survive.
        foreach ([600, 400] as $amount) {
            PurchaseDetail::create(['purchase_id' => $this->legacyPurchase->id, 'product_id' => $this->product->id, 'product_name' => 'W',
                'product_code' => 'W', 'quantity' => 1, 'price' => $amount, 'unit_price' => $amount, 'sub_total' => $amount,
                'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0]);
        }
    }

    private function cart(Purchase $purchase, array $prices = [], array $qty = [], array $product = []): \Illuminate\Support\Collection
    {
        return $purchase->fresh('purchaseDetails')->purchaseDetails->map(function (PurchaseDetail $d) use ($prices, $qty, $product) {
            $q = $qty[$d->id] ?? (float) $d->quantity;
            $price = $prices[$d->id] ?? (float) $d->unit_price;
            $pid = $product[$d->id] ?? $d->product_id;

            return (object) [
                'id' => $pid, 'name' => 'Row ' . $d->id, 'qty' => $q, 'price' => $price,
                'options' => collect([
                    PurchaseMonetaryEditService::DETAIL_ID_OPTION => $d->id, 'product_id' => $pid, 'unit_price' => $price,
                    'sub_total' => round($q * $price, 2), 'sub_total_before_tax' => round($q * $price, 2), 'product_tax_amount' => 0.0,
                    'product_discount' => 0, 'product_discount_type' => 'fixed', 'code' => 'CODE',
                ]),
            ];
        })->values();
    }

    private function apply(Purchase $purchase, $cart, float $discount = 0): Purchase
    {
        return app(PurchaseMonetaryEditService::class)->apply($purchase->fresh(), $cart, [
            'global_discount' => $discount, 'global_discount_type' => 'fixed', 'shipping' => 0,
        ]);
    }

    private function evidence(): array
    {
        return json_decode(json_encode([
            'stock' => ProductStock::orderBy('id')->get(['quantity', 'quantity_tax', 'quantity_non_tax'])->toArray(),
            'price' => ProductPrice::orderBy('id')->get(['average_purchase_price', 'last_purchase_price'])->toArray(),
            'lines' => $this->buyout->lines()->orderBy('id')->get(['base_quantity', 'accepted_snapshot', 'original_snapshot'])->toArray(),
            'lineage' => DB::table('consignment_buyout_purchase_lineages')->orderBy('id')->get(['purchase_detail_id', 'base_quantity', 'original_amount', 'accepted_amount', 'inventory_cost_amount'])->toArray(),
            'counts' => [DB::table('transactions')->count(), DB::table('consignment_buyout_cost_events')->count(), DB::table('consignment_buyout_movements')->count(),
                DB::table('product_serial_numbers')->count(), DB::table('purchases')->count()],
            'hash' => $this->buyout->fresh()->approved_revision_hash,
        ]), true);
    }

    /** @test */
    public function both_generated_purchase_types_resolve_monetary_mode_only_with_standard_authority(): void
    {
        $nobody = User::factory()->create();
        $nobody->givePermissionTo('purchases.update');
        foreach ([$this->buyoutPurchase, $this->legacyPurchase] as $p) {
            $this->assertSame(Purchase::EDIT_MODE_MONETARY_ONLY, $p->fresh()->resolveEditMode($this->user));
            $this->assertSame(Purchase::EDIT_MODE_NONE, $p->fresh()->resolveEditMode($nobody), 'missing purchases.received.monetary.edit');
            $this->get(route('purchases.edit', $p->id))->assertOk()->assertSee('id="consignment-monetary-notice"', false);
        }
        // Buyout/conversion permissions alone grant nothing.
        $converter = User::factory()->create();
        foreach (['consignments.buyouts.finalize', 'consignments.billing.convert'] as $perm) {
            $converter->givePermissionTo($perm);
        }
        $this->assertSame(Purchase::EDIT_MODE_NONE, $this->buyoutPurchase->fresh()->resolveEditMode($converter));
        $this->actingAs($converter)->get(route('purchases.edit', $this->buyoutPurchase->id))->assertForbidden();
    }

    /** @test */
    public function buyout_purchase_is_repriced_in_place_without_touching_source_stock_or_cost(): void
    {
        $before = $this->evidence();
        $detail = $this->buyoutPurchase->purchaseDetails()->sole();

        $this->apply($this->buyoutPurchase, $this->cart($this->buyoutPurchase, [$detail->id => 90.0]), 10);

        $p = $this->buyoutPurchase->fresh();
        $this->assertSame([$detail->id], $p->purchaseDetails()->pluck('id')->all());
        $this->assertEquals(90.0, (float) $detail->fresh()->unit_price);
        $this->assertEquals(4.0, (float) $detail->fresh()->quantity);
        $this->assertEquals(350.0, (float) $p->total_amount);
        $this->assertEquals(350.0, (float) $p->due_amount);
        $this->assertSame(Purchase::SOURCE_CONSIGNMENT_BUYOUT, $p->source_type);
        $this->assertSame($before, $this->evidence(), 'source, stock, cost and lineage evidence are unchanged');
        $this->assertTrue((new ConsignmentBuyoutLifecycleService())->approvedHashIsCurrent($this->buyout->fresh()));

        // A finalization retry after the edit returns the same Purchase and writes nothing.
        $again = (new ConsignmentBuyoutFinalizationService())->finalize($this->buyout->fresh(), $this->user);
        $this->assertSame($p->id, $again->id);
        $this->assertEquals(350.0, (float) $again->total_amount);
        $this->assertSame($before, $this->evidence());
    }

    /** @test */
    public function historical_multi_row_billing_purchase_keeps_rows_and_reconciles_payments(): void
    {
        $this->legacyPurchase->forceFill([])->save();
        DB::table('purchases')->where('id', $this->legacyPurchase->id)->update(['paid_amount' => 300, 'due_amount' => 700, 'payment_status' => Purchase::PAYMENT_STATUS_PARTIAL]);
        DB::table('purchase_payments')->insert(['purchase_id' => $this->legacyPurchase->id, 'amount' => 300, 'date' => now()->toDateString(),
            'reference' => 'PAY-L1', 'payment_method' => 'Cash', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        [$a, $b] = $this->legacyPurchase->purchaseDetails()->orderBy('id')->pluck('id')->all();

        $this->apply($this->legacyPurchase, $this->cart($this->legacyPurchase, [$a => 500.0]));
        $p = $this->legacyPurchase->fresh();
        $this->assertSame([$a, $b], $p->purchaseDetails()->orderBy('id')->pluck('id')->all(), 'rows not merged');
        $this->assertEquals([900.0, 300.0, 600.0, Purchase::PAYMENT_STATUS_PARTIAL],
            [(float) $p->total_amount, (float) $p->paid_amount, (float) $p->due_amount, $p->payment_status]);
        $this->assertSame(1, DB::table('purchase_payments')->where('purchase_id', $p->id)->count());

        // Below the paid amount fails atomically.
        try {
            $this->apply($this->legacyPurchase, $this->cart($this->legacyPurchase, [$a => 100.0, $b => 100.0]));
            $this->fail('total below paid accepted');
        } catch (MonetaryEditException $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(900.0, (float) $this->legacyPurchase->fresh()->total_amount);
    }

    /** @test */
    public function protected_changes_are_rejected_and_other_source_restrictions_remain(): void
    {
        $detail = $this->buyoutPurchase->purchaseDetails()->sole();
        $before = $this->evidence();
        $otherProductId = (int) $this->product->id + 1000;

        foreach ([['qty', [$detail->id => 3.0]], ['product', [$detail->id => $otherProductId]]] as [$kind, $change]) {
            try {
                $this->apply($this->buyoutPurchase, $kind === 'qty' ? $this->cart($this->buyoutPurchase, [], $change) : $this->cart($this->buyoutPurchase, [], [], $change));
                $this->fail("{$kind} change accepted");
            } catch (MonetaryEditException $e) {
                $this->assertTrue(true);
            }
        }
        // An extra row (membership change) is rejected.
        $cart = $this->cart($this->buyoutPurchase);
        $extra = clone $cart->first();
        $extra->options = $extra->options->merge([PurchaseMonetaryEditService::DETAIL_ID_OPTION => null]);
        try {
            $this->apply($this->buyoutPurchase, $cart->push($extra));
            $this->fail('row added');
        } catch (MonetaryEditException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame($before, $this->evidence());
        $this->assertEquals(400.0, (float) $this->buyoutPurchase->fresh()->total_amount);

        // Outside the authorized service the model guards still refuse monetary and identity writes alike.
        foreach (['total_amount' => 1, 'supplier_id' => 999, 'setting_id' => 999] as $field => $value) {
            try {
                $this->buyoutPurchase->fresh()->update([$field => $value]);
                $this->fail("{$field} mutable outside the scope");
            } catch (\DomainException $e) {
                $this->assertTrue(true);
            }
        }
        // Even inside the scope, identity columns and deletion stay blocked.
        Purchase::withMonetaryEditScope($this->buyoutPurchase->id, function () use ($detail) {
            foreach (['supplier_id' => 999, 'source_type' => Purchase::SOURCE_ORDINARY, 'status' => Purchase::STATUS_APPROVED] as $field => $value) {
                try {
                    $this->buyoutPurchase->fresh()->update([$field => $value]);
                    $this->fail("{$field} mutable inside the scope");
                } catch (\DomainException $e) {
                    $this->assertTrue(true);
                }
            }
            foreach (['quantity' => 99, 'product_id' => 999] as $field => $value) {
                try {
                    $detail->fresh()->update([$field => $value]);
                    $this->fail("detail {$field} mutable inside the scope");
                } catch (PurchaseSourceOperationNotAllowedException $e) {
                    $this->assertTrue(true);
                }
            }
            try {
                $detail->fresh()->delete();
                $this->fail('detail deletable inside the scope');
            } catch (PurchaseSourceOperationNotAllowedException $e) {
                $this->assertTrue(true);
            }
        });

        foreach ([$this->buyoutPurchase, $this->legacyPurchase] as $p) {
            foreach (['assertReceivingAllowed', 'assertCommercialEditAllowed', 'assertReturnAllowed', 'assertDeletionAllowed'] as $guard) {
                try {
                    PurchaseSourceGuard::$guard($p->fresh());
                    $this->fail("{$guard} allowed");
                } catch (PurchaseSourceOperationNotAllowedException $e) {
                    $this->assertTrue(true);
                }
            }
        }
        // The full-edit HTTP update endpoint stays closed.
        $this->put(route('purchases.update', $this->buyoutPurchase->id), [])->assertStatus(422);
        $this->assertEquals(400.0, (float) $this->buyoutPurchase->fresh()->total_amount);
    }

    /** @test */
    public function reversed_buyout_purchase_is_not_monetarily_editable(): void
    {
        Purchase::withBuyoutReversalScope($this->buyoutPurchase->id, fn () => $this->buyoutPurchase->fresh()->update(['status' => Purchase::STATUS_CANCELLED]));
        $this->assertSame(Purchase::EDIT_MODE_NONE, $this->buyoutPurchase->fresh()->resolveEditMode($this->user));
    }
}

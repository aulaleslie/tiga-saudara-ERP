<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * POS records approved, audit-only dispatch evidence for non-stock content, owned by the
 * first configured POS sales location, with no inventory effects of any kind.
 */
class PosNonStockDispatchAuditTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Owner-split posting is the path where non-stock ownership is observable.
        config(['pos.checkout.split_posting.enabled' => true]);
    }

    public function test_service_only_checkout_is_dispatched_with_approved_audit_detail_and_no_inventory_effects(): void
    {
        $setting = $this->createSetting('AUDIT SERVICE ONLY');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'AUDIT SVC');

        $service = $this->createServiceProduct($setting, 'SRV-A1', 'Jasa Instalasi', 150000, $cashier->id);

        $this->addLine($cashier, $setting, $service->id, 2);
        $paymentMethod = $this->createPaymentMethod($setting, '2001');
        $this->selectCustomer($cashier, $setting);

        $response = $this->finalize($cashier, $setting, $paymentMethod, 300000);
        $response->assertCreated();
        $response->assertJsonPath('status', 'POSTED');

        $saleId = (int) $response->json('sale_id');
        $sale = Sale::query()->findOrFail($saleId);

        // Immediately dispatched, with an approved Dispatch.
        $this->assertSame(Sale::STATUS_DISPATCHED, $sale->status);
        $dispatch = Dispatch::query()->where('sale_id', $saleId)->firstOrFail();
        $this->assertSame(Dispatch::STATUS_APPROVED, $dispatch->status);
        $this->assertNotNull($dispatch->approved_at);

        // Approved audit-only DispatchDetail at the configured first source location.
        $detail = DispatchDetail::query()
            ->where('sale_id', $saleId)
            ->where('product_id', $service->id)
            ->firstOrFail();

        $this->assertSame((int) $dispatch->id, (int) $detail->dispatch_id);
        $this->assertSame(2, (int) $detail->dispatched_quantity);
        $this->assertSame($location->id, (int) $detail->location_id);
        $this->assertNull($detail->serial_numbers);

        // No inventory effects whatsoever.
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $service->id]);
        $this->assertDatabaseMissing('transactions', ['product_id' => $service->id]);
        $this->assertDatabaseMissing('sales_order_serial_tracking', ['sale_id' => $saleId]);
        $this->assertSame(0, (int) $service->fresh()->product_quantity);
    }

    public function test_replaying_service_checkout_does_not_duplicate_sale_dispatch_or_audit_detail(): void
    {
        $setting = $this->createSetting('AUDIT IDEMPOTENT');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'AUDIT IDEM');

        $service = $this->createServiceProduct($setting, 'SRV-A2', 'Jasa Servis', 100000, $cashier->id);

        $this->addLine($cashier, $setting, $service->id, 1);
        $paymentMethod = $this->createPaymentMethod($setting, '2002');
        $this->selectCustomer($cashier, $setting);

        $idempotencyKey = (string) Str::uuid();

        $first = $this->finalize($cashier, $setting, $paymentMethod, 100000, $idempotencyKey);
        $first->assertCreated();
        $saleId = (int) $first->json('sale_id');

        $salesBefore = Sale::query()->count();
        $dispatchesBefore = Dispatch::query()->count();
        $detailsBefore = DispatchDetail::query()->count();

        // Replay with the original key and matching payload.
        $this->finalize($cashier, $setting, $paymentMethod, 100000, $idempotencyKey);

        $this->assertSame($salesBefore, Sale::query()->count());
        $this->assertSame($dispatchesBefore, Dispatch::query()->count());
        $this->assertSame($detailsBefore, DispatchDetail::query()->count());
        $this->assertSame(1, DispatchDetail::query()->where('product_id', $service->id)->count());
        $this->assertSame(1, DispatchDetail::query()->where('sale_id', $saleId)->count());
    }

    public function test_mixed_stock_and_service_checkout_reconciles_payments_and_keeps_stock_behavior(): void
    {
        $setting = $this->createSetting('AUDIT MIXED');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'AUDIT MIX');

        $service = $this->createServiceProduct($setting, 'SRV-A3', 'Jasa Rakit', 50000, $cashier->id);
        $ram = $this->createStockedProduct($setting, $location, 'RAM-A3', 'RAM 8GB', 10, 200000, $cashier->id);

        $this->addLine($cashier, $setting, $service->id, 1);
        $this->addLine($cashier, $setting, $ram->id, 2);

        $paymentMethod = $this->createPaymentMethod($setting, '2003');
        $this->selectCustomer($cashier, $setting);

        $grandTotal = 50000 + (2 * 200000);
        $response = $this->finalize($cashier, $setting, $paymentMethod, $grandTotal);
        $response->assertCreated();

        // Every generated Sale reconciles to the one checkout total.
        $saleIds = $this->checkoutSaleIds($response);
        $this->assertNotEmpty($saleIds);
        $postedTotal = (float) Sale::query()->whereIn('id', $saleIds)->sum('total_amount');
        $this->assertEqualsWithDelta($grandTotal, $postedTotal, 0.01);

        $paidTotal = (float) DB::table('sale_payments')->whereIn('sale_id', $saleIds)->sum('amount');
        $this->assertEqualsWithDelta($grandTotal, $paidTotal, 0.01);

        foreach (Sale::query()->whereIn('id', $saleIds)->get() as $sale) {
            $this->assertSame(Sale::STATUS_DISPATCHED, $sale->status);
        }

        // Service: audit detail, zero inventory effect.
        $serviceDetail = DispatchDetail::query()->where('product_id', $service->id)->firstOrFail();
        $this->assertSame(1, (int) $serviceDetail->dispatched_quantity);
        $this->assertDatabaseMissing('transactions', ['product_id' => $service->id]);

        // RAM: unchanged normal allocation, dispatch and deduction.
        $ramDetail = DispatchDetail::query()->where('product_id', $ram->id)->firstOrFail();
        $this->assertSame(2, (int) $ramDetail->dispatched_quantity);
        $this->assertSame($location->id, (int) $ramDetail->location_id);
        $this->assertSame(8, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
        $this->assertDatabaseHas('transactions', [
            'product_id' => $ram->id,
            'quantity' => -2,
            'type' => 'DISPATCH',
        ]);
    }

    public function test_service_parent_bundle_with_stock_managed_ram_component_splits_responsibilities(): void
    {
        $setting = $this->createSetting('AUDIT BUNDLE');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'AUDIT BUN');

        // Non-stock service parent, stock-managed RAM component at 2 per bundle.
        $serviceParent = $this->createServiceProduct($setting, 'SRV-BUN', 'Paket Upgrade', 300000, $cashier->id);
        $ram = $this->createStockedProduct($setting, $location, 'RAM-BUN', 'RAM 16GB', 10, 100000, $cashier->id);

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $serviceParent->id,
            'name' => 'PAKET UPGRADE',
            'bundle_sale_price' => 300000,
            'price' => 300000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $ram->id,
            'quantity' => 2,
        ]);

        // Sell 3 bundles => RAM quantity must be 3 * 2 = 6.
        $this->addLine($cashier, $setting, $serviceParent->id, 3, $bundle->id);

        $paymentMethod = $this->createPaymentMethod($setting, '2004');
        $this->selectCustomer($cashier, $setting);

        $grandTotal = 3 * 300000;
        $response = $this->finalize($cashier, $setting, $paymentMethod, $grandTotal);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);

        // No double counting: the checkout total is posted exactly once across all groups.
        $postedTotal = (float) Sale::query()->whereIn('id', $saleIds)->sum('total_amount');
        $this->assertEqualsWithDelta($grandTotal, $postedTotal, 0.01);

        // Non-stock parent gets an audit detail, with no inventory effects.
        $parentDetails = DispatchDetail::query()->where('product_id', $serviceParent->id)->get();
        $this->assertCount(1, $parentDetails);
        $this->assertSame(3, (int) $parentDetails->first()->dispatched_quantity);
        $this->assertDatabaseMissing('transactions', ['product_id' => $serviceParent->id]);
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $serviceParent->id]);

        // RAM component: quantity multiplied by bundle qty, deducted exactly once.
        $ramDispatched = (int) DispatchDetail::query()->where('product_id', $ram->id)->sum('dispatched_quantity');
        $this->assertSame(6, $ramDispatched);
        $this->assertSame(4, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $location->id)
            ->value('quantity'));

        $ramMovement = (int) DB::table('transactions')->where('product_id', $ram->id)->sum('quantity');
        $this->assertSame(-6, $ramMovement, 'RAM stock must move exactly once for the whole bundle quantity.');

        // Same owner and tax bucket for both => a single combined owner group.
        $this->assertCount(1, $saleIds, 'Matching source and tax bucket must combine into one owner group.');
    }

    public function test_service_parent_and_ram_component_with_distinct_owners_create_separate_sales(): void
    {
        $terminalSetting = $this->createSetting('AUDIT SPLIT TERMINAL');
        $ownerSetting = $this->createSetting('AUDIT SPLIT OWNER');

        [$cashier, $terminalLocation] = $this->createCashierAndOpenSession($terminalSetting, 'AUDIT SPL');

        // The non-stock owner is another business, first in the configured POS order.
        $ownerLocation = Location::create([
            'name' => 'SPLIT OWNER LOC ' . $this->sequence++,
            'setting_id' => $ownerSetting->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $ownerLocation->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $terminalLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($terminalSetting->id);

        $serviceParent = $this->createServiceProduct($terminalSetting, 'SRV-SPL', 'Paket Jasa', 300000, $cashier->id);
        // RAM stock lives at the terminal-owned location, so allocation picks a different owner.
        $ram = $this->createStockedProduct($terminalSetting, $terminalLocation, 'RAM-SPL', 'RAM 32GB', 10, 100000, $cashier->id);

        $bundle = ProductBundle::create([
            'setting_id' => $terminalSetting->id,
            'parent_product_id' => $serviceParent->id,
            'name' => 'PAKET JASA SPLIT',
            'bundle_sale_price' => 300000,
            'price' => 300000,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $ram->id,
            'quantity' => 2,
        ]);

        $this->addLine($cashier, $terminalSetting, $serviceParent->id, 3, $bundle->id);
        $paymentMethod = $this->createPaymentMethod($terminalSetting, '2006');
        $this->selectCustomer($cashier, $terminalSetting);

        $grandTotal = 3 * 300000;
        $response = $this->finalize($cashier, $terminalSetting, $paymentMethod, $grandTotal);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);

        // Distinct owners => separate Sales, still reconciling to the one checkout total.
        $this->assertGreaterThan(1, count($saleIds), 'Distinct owners must produce separate owner-group Sales.');
        $postedTotal = (float) Sale::query()->whereIn('id', $saleIds)->sum('total_amount');
        $this->assertEqualsWithDelta($grandTotal, $postedTotal, 0.01);

        $paidTotal = (float) DB::table('sale_payments')->whereIn('sale_id', $saleIds)->sum('amount');
        $this->assertEqualsWithDelta($grandTotal, $paidTotal, 0.01);

        // Parent audit detail belongs to the configured first source.
        $parentDetail = DispatchDetail::query()->where('product_id', $serviceParent->id)->firstOrFail();
        $this->assertSame($ownerLocation->id, (int) $parentDetail->location_id);
        $this->assertSame(3, (int) $parentDetail->dispatched_quantity);
        $this->assertSame(
            $ownerSetting->id,
            (int) Sale::query()->findOrFail((int) $parentDetail->sale_id)->setting_id
        );

        // RAM deducts once at its own allocation source only.
        $this->assertSame(6, (int) DispatchDetail::query()->where('product_id', $ram->id)->sum('dispatched_quantity'));
        $this->assertSame(-6, (int) DB::table('transactions')->where('product_id', $ram->id)->sum('quantity'));
        $this->assertSame(4, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $terminalLocation->id)
            ->value('quantity'));
        $this->assertDatabaseMissing('transactions', ['product_id' => $serviceParent->id]);
    }

    public function test_service_content_is_owned_by_first_configured_location_business_not_the_terminal(): void
    {
        $terminalSetting = $this->createSetting('AUDIT TERMINAL BIZ');
        $ownerSetting = $this->createSetting('AUDIT OWNER BIZ');

        [$cashier, $terminalLocation] = $this->createCashierAndOpenSession($terminalSetting, 'AUDIT OWN');

        $ownerLocation = Location::create([
            'name' => 'OWNER LOC ' . $this->sequence++,
            'setting_id' => $ownerSetting->id,
        ]);

        // Put the other business's location first in the configured POS order.
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $ownerLocation->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $terminalLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($terminalSetting->id);

        $service = $this->createServiceProduct($terminalSetting, 'SRV-OWN', 'Jasa Antar', 75000, $cashier->id);

        $this->addLine($cashier, $terminalSetting, $service->id, 1);
        $paymentMethod = $this->createPaymentMethod($terminalSetting, '2005');
        $this->selectCustomer($cashier, $terminalSetting);

        $response = $this->finalize($cashier, $terminalSetting, $paymentMethod, 75000);
        $response->assertCreated();

        $detail = DispatchDetail::query()->where('product_id', $service->id)->firstOrFail();
        $this->assertSame($ownerLocation->id, (int) $detail->location_id);

        $sale = Sale::query()->findOrFail((int) $detail->sale_id);
        $this->assertSame($ownerSetting->id, (int) $sale->setting_id, 'The non-stock Sale must belong to the first configured location business.');
        $this->assertNotSame($terminalSetting->id, (int) $sale->setting_id);
    }

    public function test_non_stock_ownership_uses_configured_source_with_split_posting_disabled(): void
    {
        // Inline posting handles the whole cart directly; ownership must still be correct.
        config(['pos.checkout.split_posting.enabled' => false]);

        $terminalSetting = $this->createSetting('AUDIT INLINE TERMINAL');
        $ownerSetting = $this->createSetting('AUDIT INLINE OWNER');

        [$cashier, $terminalLocation] = $this->createCashierAndOpenSession($terminalSetting, 'AUDIT INL');

        // Configured location #1 belongs to a different business than the terminal.
        $ownerLocation = Location::create([
            'name' => 'INLINE OWNER LOC ' . $this->sequence++,
            'setting_id' => $ownerSetting->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $ownerLocation->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $terminalLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($terminalSetting->id);

        $service = $this->createServiceProduct($terminalSetting, 'SRV-INL', 'Jasa Inline', 125000, $cashier->id);

        $this->addLine($cashier, $terminalSetting, $service->id, 1);
        $paymentMethod = $this->createPaymentMethod($terminalSetting, '2007');
        $this->selectCustomer($cashier, $terminalSetting);

        $response = $this->finalize($cashier, $terminalSetting, $paymentMethod, 125000);
        $response->assertCreated();

        $detail = DispatchDetail::query()->where('product_id', $service->id)->firstOrFail();
        $sale = Sale::query()->findOrFail((int) $detail->sale_id);

        // Sale ownership and audit location both come from configured location #1.
        $this->assertSame(
            $ownerSetting->id,
            (int) $sale->setting_id,
            'With split posting disabled the non-stock Sale must still belong to configured location #1 business.'
        );
        $this->assertNotSame($terminalSetting->id, (int) $sale->setting_id);
        $this->assertSame($ownerLocation->id, (int) $detail->location_id);
        $this->assertNotSame($terminalLocation->id, (int) $detail->location_id);
        $this->assertSame(Sale::STATUS_DISPATCHED, $sale->status);
    }

    public function test_snapshot_claiming_non_stock_cannot_make_stock_managed_product_skip_deduction(): void
    {
        $setting = $this->createSetting('AUDIT CONFLICT A');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'AUDIT CFA');

        // Added while non-stock, so the cart snapshot captures stock_managed = false.
        $service = $this->createServiceProduct($setting, 'SRV-CFA', 'Produk Konflik A', 100000, $cashier->id);
        $this->addLine($cashier, $setting, $service->id, 2);

        // The product is truly stock-managed: the snapshot's non-stock claim is now false.
        $this->divergePersistedClassification($service->id, true);
        ProductStock::create([
            'product_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        $paymentMethod = $this->createPaymentMethod($setting, '2008');
        $this->selectCustomer($cashier, $setting);

        $response = $this->finalize($cashier, $setting, $paymentMethod, 200000);

        // The snapshot must not buy a free pass around inventory posting.
        $response->assertStatus(422);

        // No audit detail was written for what is really an inventory product, and stock
        // was neither deducted nor silently bypassed.
        $this->assertDatabaseMissing('dispatch_details', ['product_id' => $service->id]);
        $this->assertSame(10, (int) ProductStock::query()
            ->where('product_id', $service->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
    }

    public function test_snapshot_claiming_stock_managed_cannot_force_service_through_inventory_handling(): void
    {
        $setting = $this->createSetting('AUDIT CONFLICT B');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'AUDIT CFB');

        // Added while stock-managed, so the snapshot captures stock_managed = true.
        $ram = $this->createStockedProduct($setting, $location, 'RAM-CFB', 'Produk Konflik B', 10, 90000, $cashier->id);
        $this->addLine($cashier, $setting, $ram->id, 1);

        // The product is truly non-stock: the snapshot's stock-managed claim is now false.
        $this->divergePersistedClassification($ram->id, false);

        $paymentMethod = $this->createPaymentMethod($setting, '2009');
        $this->selectCustomer($cashier, $setting);

        $response = $this->finalize($cashier, $setting, $paymentMethod, 90000);

        $response->assertStatus(422);

        // Non-stock content was never dragged through inventory handling.
        $this->assertDatabaseMissing('transactions', ['product_id' => $ram->id]);
        $this->assertDatabaseMissing('dispatch_details', ['product_id' => $ram->id]);
        $this->assertSame(10, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
    }

    public function test_mixed_owner_bundle_splits_into_two_sales_with_split_posting_disabled(): void
    {
        // A multi-owner checkout must not collapse into one terminal-owned Sale.
        config(['pos.checkout.split_posting.enabled' => false]);

        // Terminal business (and RAM allocation owner) = Business B.
        $businessB = $this->createSetting('FORCED SPLIT BIZ B');
        // First configured POS sales location = Business A.
        $businessA = $this->createSetting('FORCED SPLIT BIZ A');

        [$cashier, $locationB] = $this->createCashierAndOpenSession($businessB, 'FORCED BUN');

        $locationA = Location::create([
            'name' => 'FORCED SPLIT LOC A ' . $this->sequence++,
            'setting_id' => $businessA->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $businessB->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $businessB->id, 'location_id' => $locationB->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($businessB->id);

        // Non-stock service parent + stock-managed RAM component (2 per bundle).
        $serviceParent = $this->createServiceProduct($businessB, 'SRV-FSB', 'Paket Jasa Split', 300000, $cashier->id);
        $ram = $this->createStockedProduct($businessB, $locationB, 'RAM-FSB', 'RAM Split', 10, 100000, $cashier->id);

        $bundle = ProductBundle::create([
            'setting_id' => $businessB->id,
            'parent_product_id' => $serviceParent->id,
            'name' => 'PAKET JASA FORCED SPLIT',
            'bundle_sale_price' => 300000,
            'price' => 300000,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $ram->id,
            'quantity' => 2,
        ]);

        $this->addLine($cashier, $businessB, $serviceParent->id, 3, $bundle->id);
        $paymentMethod = $this->createPaymentMethod($businessB, '2010');
        $this->selectCustomer($cashier, $businessB);

        $grandTotal = 3 * 300000;
        $response = $this->finalize($cashier, $businessB, $paymentMethod, $grandTotal);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);

        // Exactly two owner groups despite split posting being disabled.
        $this->assertCount(2, $saleIds, 'A multi-owner checkout must split even with split posting disabled.');

        // Service parent revenue and audit evidence belong to Business A.
        $serviceDetail = DispatchDetail::query()->where('product_id', $serviceParent->id)->firstOrFail();
        $this->assertSame($locationA->id, (int) $serviceDetail->location_id);
        $this->assertSame(3, (int) $serviceDetail->dispatched_quantity);

        $serviceSale = Sale::query()->findOrFail((int) $serviceDetail->sale_id);
        $this->assertSame($businessA->id, (int) $serviceSale->setting_id);

        // RAM revenue and inventory dispatch belong to Business B.
        $ramDetail = DispatchDetail::query()->where('product_id', $ram->id)->firstOrFail();
        $this->assertSame($locationB->id, (int) $ramDetail->location_id);
        $this->assertSame(6, (int) DispatchDetail::query()->where('product_id', $ram->id)->sum('dispatched_quantity'));

        $ramSale = Sale::query()->findOrFail((int) $ramDetail->sale_id);
        $this->assertSame($businessB->id, (int) $ramSale->setting_id);
        $this->assertNotSame((int) $serviceSale->id, (int) $ramSale->id);

        // RAM stock deducted exactly once at Business B's allocation location.
        $this->assertSame(4, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $locationB->id)
            ->value('quantity'));
        $this->assertSame(-6, (int) DB::table('transactions')->where('product_id', $ram->id)->sum('quantity'));

        // No inventory side effects of any kind for the service parent.
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $serviceParent->id]);
        $this->assertDatabaseMissing('transactions', ['product_id' => $serviceParent->id]);
        $this->assertDatabaseMissing('sales_order_serial_tracking', ['sale_id' => $serviceSale->id]);

        // Totals and payments reconcile exactly to the one POS checkout.
        $this->assertEqualsWithDelta($grandTotal, (float) Sale::query()->whereIn('id', $saleIds)->sum('total_amount'), 0.01);
        $this->assertEqualsWithDelta(
            $grandTotal,
            (float) DB::table('sale_payments')->whereIn('sale_id', $saleIds)->sum('amount'),
            0.01
        );
    }

    public function test_plain_mixed_cart_splits_by_owner_with_split_posting_disabled(): void
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        $businessB = $this->createSetting('FORCED PLAIN BIZ B');
        $businessA = $this->createSetting('FORCED PLAIN BIZ A');

        [$cashier, $locationB] = $this->createCashierAndOpenSession($businessB, 'FORCED PLN');

        $locationA = Location::create([
            'name' => 'FORCED PLAIN LOC A ' . $this->sequence++,
            'setting_id' => $businessA->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $businessB->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $businessB->id, 'location_id' => $locationB->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($businessB->id);

        // Separate service line (Business A) and stock line (Business B).
        $service = $this->createServiceProduct($businessB, 'SRV-FPL', 'Jasa Plain', 50000, $cashier->id);
        $ram = $this->createStockedProduct($businessB, $locationB, 'RAM-FPL', 'RAM Plain', 10, 200000, $cashier->id);

        $this->addLine($cashier, $businessB, $service->id, 1);
        $this->addLine($cashier, $businessB, $ram->id, 2);

        $paymentMethod = $this->createPaymentMethod($businessB, '2011');
        $this->selectCustomer($cashier, $businessB);

        $grandTotal = 50000 + (2 * 200000);
        $response = $this->finalize($cashier, $businessB, $paymentMethod, $grandTotal);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);
        $this->assertCount(2, $saleIds, 'Service and stock content with different owners must produce two Sales.');

        $serviceDetail = DispatchDetail::query()->where('product_id', $service->id)->firstOrFail();
        $this->assertSame($locationA->id, (int) $serviceDetail->location_id);
        $this->assertSame(
            $businessA->id,
            (int) Sale::query()->findOrFail((int) $serviceDetail->sale_id)->setting_id
        );

        $ramDetail = DispatchDetail::query()->where('product_id', $ram->id)->firstOrFail();
        $this->assertSame($locationB->id, (int) $ramDetail->location_id);
        $this->assertSame(
            $businessB->id,
            (int) Sale::query()->findOrFail((int) $ramDetail->sale_id)->setting_id
        );

        // Stock behavior unchanged; service causes no inventory movement.
        $this->assertSame(8, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $locationB->id)
            ->value('quantity'));
        $this->assertDatabaseMissing('transactions', ['product_id' => $service->id]);

        // Exact reconciliation to the one checkout total.
        $this->assertEqualsWithDelta($grandTotal, (float) Sale::query()->whereIn('id', $saleIds)->sum('total_amount'), 0.01);
        $this->assertEqualsWithDelta(
            $grandTotal,
            (float) DB::table('sale_payments')->whereIn('sale_id', $saleIds)->sum('amount'),
            0.01
        );
    }

    public function test_stock_only_cart_with_split_posting_disabled_stays_a_single_inline_sale(): void
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        $setting = $this->createSetting('INLINE STOCK ONLY');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'INLINE STK');

        $ram = $this->createStockedProduct($setting, $location, 'RAM-INL', 'RAM Inline', 10, 150000, $cashier->id);

        $this->addLine($cashier, $setting, $ram->id, 2);
        $paymentMethod = $this->createPaymentMethod($setting, '2012');
        $this->selectCustomer($cashier, $setting);

        $response = $this->finalize($cashier, $setting, $paymentMethod, 300000);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);

        $this->assertCount(1, $saleIds, 'A stock-only cart must retain existing single-Sale inline behavior.');
        $this->assertSame($setting->id, (int) Sale::query()->findOrFail($saleIds[0])->setting_id);
        $this->assertSame(8, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
    }

    public function test_stock_only_cart_spanning_two_sources_stays_inline_with_split_posting_disabled(): void
    {
        // Stock-only carts must keep historical inline behavior even when the planner
        // would produce several allocation groups.
        config(['pos.checkout.split_posting.enabled' => false]);

        $terminalSetting = $this->createSetting('INLINE MULTI SRC B');
        $otherSetting = $this->createSetting('INLINE MULTI SRC A');

        [$cashier, $locationB] = $this->createCashierAndOpenSession($terminalSetting, 'INLINE MSR');

        $locationA = Location::create([
            'name' => 'INLINE MULTI SRC LOC A ' . $this->sequence++,
            'setting_id' => $otherSetting->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $locationB->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($terminalSetting->id);

        // One stock product held at two different owners' locations.
        $ram = $this->createStockedProduct($terminalSetting, $locationA, 'RAM-MSR', 'RAM Multi Sumber', 2, 100000, $cashier->id);
        ProductStock::create([
            'product_id' => $ram->id,
            'location_id' => $locationB->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);
        $ram->update(['product_quantity' => 7]);

        // Draw more than location A alone can supply, forcing a second allocation source.
        $this->addLine($cashier, $terminalSetting, $ram->id, 4);
        $paymentMethod = $this->createPaymentMethod($terminalSetting, '2014');
        $this->selectCustomer($cashier, $terminalSetting);

        $response = $this->finalize($cashier, $terminalSetting, $paymentMethod, 400000);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);

        $this->assertCount(1, $saleIds, 'A stock-only cart must post inline even across multiple allocation sources.');
        $this->assertSame($terminalSetting->id, (int) Sale::query()->findOrFail($saleIds[0])->setting_id);

        // Guard against a vacuous pass: the cart really did draw from both sources.
        $sourceLocationIds = DispatchDetail::query()
            ->where('product_id', $ram->id)
            ->pluck('location_id')
            ->unique()
            ->values()
            ->all();
        $this->assertCount(2, $sourceLocationIds, 'This case must genuinely span two allocation sources.');

        // Allocation and deduction across both sources are unchanged.
        $this->assertSame(4, (int) DispatchDetail::query()->where('product_id', $ram->id)->sum('dispatched_quantity'));
        $this->assertSame(-4, (int) DB::table('transactions')->where('product_id', $ram->id)->sum('quantity'));

        $remaining = (int) ProductStock::query()->where('product_id', $ram->id)->sum('quantity');
        $this->assertSame(3, $remaining, 'Total stock must fall by exactly the sold quantity.');
    }

    public function test_single_non_terminal_owner_group_posts_one_sale_under_that_business(): void
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        // Terminal is Business B, but both the configured non-stock source and the stock
        // allocation resolve to Business A, so the plan is a single non-terminal group.
        $businessB = $this->createSetting('SINGLE GROUP BIZ B');
        $businessA = $this->createSetting('SINGLE GROUP BIZ A');

        [$cashier, $locationB] = $this->createCashierAndOpenSession($businessB, 'SINGLE GRP');

        $locationA = Location::create([
            'name' => 'SINGLE GROUP LOC A ' . $this->sequence++,
            'setting_id' => $businessA->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $businessB->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        // The terminal's own location is not an eligible sales source here, so stock
        // allocation must also come from Business A.
        SettingSaleLocation::query()
            ->where('setting_id', $businessB->id)
            ->where('location_id', $locationB->id)
            ->update(['is_enabled' => false]);
        SalesLocationResolver::forget($businessB->id);

        $service = $this->createServiceProduct($businessB, 'SRV-SGR', 'Jasa Satu Grup', 50000, $cashier->id);
        // Stock lives at Business A's location, matching the configured non-stock source.
        $ram = $this->createStockedProduct($businessB, $locationA, 'RAM-SGR', 'RAM Satu Grup', 10, 100000, $cashier->id);

        $this->addLine($cashier, $businessB, $service->id, 1);
        $this->addLine($cashier, $businessB, $ram->id, 1);

        $paymentMethod = $this->createPaymentMethod($businessB, '2015');
        $this->selectCustomer($cashier, $businessB);

        $grandTotal = 150000;
        $response = $this->finalize($cashier, $businessB, $paymentMethod, $grandTotal);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);

        // One group, but it belongs to Business A, not the terminal.
        $this->assertCount(1, $saleIds);
        $sale = Sale::query()->findOrFail($saleIds[0]);
        $this->assertSame($businessA->id, (int) $sale->setting_id, 'A single non-terminal group must be owned by that business, not the terminal.');
        $this->assertNotSame($businessB->id, (int) $sale->setting_id);

        // Both details use Business A's configured source location.
        $this->assertSame($locationA->id, (int) DispatchDetail::query()
            ->where('product_id', $service->id)
            ->value('location_id'));
        $this->assertSame($locationA->id, (int) DispatchDetail::query()
            ->where('product_id', $ram->id)
            ->value('location_id'));

        // Stock mutates exactly once; the service causes no inventory movement.
        $this->assertSame(9, (int) ProductStock::query()
            ->where('product_id', $ram->id)
            ->where('location_id', $locationA->id)
            ->value('quantity'));
        $this->assertSame(-1, (int) DB::table('transactions')->where('product_id', $ram->id)->sum('quantity'));
        $this->assertDatabaseMissing('transactions', ['product_id' => $service->id]);
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $service->id]);

        $this->assertEqualsWithDelta($grandTotal, (float) $sale->total_amount, 0.01);
    }

    public function test_mixed_cart_sharing_one_owner_and_bucket_stays_a_single_sale(): void
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        $setting = $this->createSetting('INLINE SAME OWNER');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'INLINE SAME');

        // Non-stock source and stock allocation source are the same location and bucket.
        $service = $this->createServiceProduct($setting, 'SRV-SAME', 'Jasa Sama', 50000, $cashier->id);
        $ram = $this->createStockedProduct($setting, $location, 'RAM-SAME', 'RAM Sama', 10, 100000, $cashier->id);

        $this->addLine($cashier, $setting, $service->id, 1);
        $this->addLine($cashier, $setting, $ram->id, 1);

        $paymentMethod = $this->createPaymentMethod($setting, '2013');
        $this->selectCustomer($cashier, $setting);

        $response = $this->finalize($cashier, $setting, $paymentMethod, 150000);
        $response->assertCreated();

        $saleIds = $this->checkoutSaleIds($response);

        $this->assertCount(1, $saleIds, 'Identical owner, location and tax bucket may remain one Sale.');
        $this->assertSame($location->id, (int) DispatchDetail::query()
            ->where('product_id', $service->id)
            ->value('location_id'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Diverge the persisted product classification from the one captured in the cart
     * snapshot when the line was added. This is the same divergence a crafted snapshot
     * produces, and posting must resolve it from the database, not the snapshot.
     */
    private function divergePersistedClassification(int $productId, bool $stockManaged): void
    {
        DB::table('products')->where('id', $productId)->update(['stock_managed' => $stockManaged]);
    }

    /**
     * @return array<int, int>
     */
    private function checkoutSaleIds(\Illuminate\Testing\TestResponse $response): array
    {
        // Use the complete one-checkout-to-many-Sales mapping, never the top-level
        // sale_id, which represents the first split group only.
        $checkoutId = (int) ($response->json('checkout_id') ?? $response->json('checkout.id') ?? 0);

        if ($checkoutId <= 0) {
            $saleId = (int) $response->json('sale_id');
            $checkoutId = (int) DB::table('pos_checkout_sales')->where('sale_id', $saleId)->value('pos_checkout_id');
        }

        $mapped = DB::table('pos_checkout_sales')
            ->where('pos_checkout_id', $checkoutId)
            ->pluck('sale_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($mapped !== []) {
            return $mapped;
        }

        return array_values(array_filter([(int) $response->json('sale_id')]));
    }

    private function addLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): void
    {
        $payload = ['product_id' => $productId, 'qty' => $qty];
        if ($bundleId !== null) {
            $payload['bundle_id'] = $bundleId;
        }

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), $payload)
            ->assertOk();
    }

    private function selectCustomer(User $cashier, Setting $setting): Customer
    {
        $customer = Customer::factory()->create(['setting_id' => $setting->id]);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])
            ->assertOk();

        return $customer;
    }

    private function finalize(
        User $cashier,
        Setting $setting,
        PaymentMethod $paymentMethod,
        float $amount,
        ?string $idempotencyKey = null
    ): \Illuminate\Testing\TestResponse {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
                'payments' => [
                    [
                        'payment_method_id' => $paymentMethod->id,
                        'amount_paid' => $amount,
                    ],
                ],
            ]);
    }

    private function createPaymentMethod(Setting $setting, string $accountNumber): PaymentMethod
    {
        $coa = ChartOfAccount::create([
            'setting_id' => $setting->id,
            'name' => 'Cash Account',
            'account_number' => $accountNumber . '-' . $this->sequence++,
            'category' => 'Kas & Bank',
        ]);

        $paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        DB::table('setting_pos_payment_methods')->updateOrInsert(
            ['setting_id' => $setting->id, 'payment_method_id' => $paymentMethod->id],
            ['is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return $paymentMethod;
    }

    /**
     * @return array{0:User,1:Location,2:PosSession}
     */
    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $role = Role::firstOrCreate(['name' => $roleSuffix . ' CASHIER ' . $this->sequence++]);
        foreach (['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment'] as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission]);
        }
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);

        $cashier = User::factory()->create();
        $cashier->assignRole($role);
        $cashier->settings()->attach($setting->id, ['role_id' => $role->id]);

        $location = Location::create([
            'name' => 'AUDIT LOC ' . $this->sequence++,
            'setting_id' => $setting->id,
        ]);
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-AUD-' . $this->sequence++,
            'name' => 'POS Audit Terminal',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $location, $session];
    }

    private function createServiceProduct(
        Setting $setting,
        string $code,
        string $name,
        float $salePrice,
        int $createdBy
    ): Product {
        return $this->createProduct($setting, $code, $name, $salePrice, $createdBy, stockManaged: false);
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        int $availableQty,
        float $salePrice,
        int $createdBy
    ): Product {
        $product = $this->createProduct(
            $setting,
            $code,
            $name,
            $salePrice,
            $createdBy,
            stockManaged: true,
            quantity: $availableQty
        );

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $availableQty,
            'quantity_non_tax' => $availableQty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        return $product->fresh();
    }

    private function createProduct(
        Setting $setting,
        string $code,
        string $name,
        float $salePrice,
        int $createdBy,
        bool $stockManaged,
        int $quantity = 0
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'CAT-AUD-' . $setting->id],
            [
                'category_name' => 'Kategori Audit ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code . '-' . $this->sequence++,
            'barcode' => $code . '-BC-' . $this->sequence++,
            'product_quantity' => $quantity,
            'product_cost' => $stockManaged ? 10000 : 0,
            'product_price' => $salePrice,
            'product_unit' => $stockManaged ? 'PCS' : 'JASA',
            'product_stock_alert' => 0,
            'stock_managed' => $stockManaged,
            'is_sold' => true,
            'serial_number_required' => false,
        ]);

        ProductPrice::updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => $stockManaged ? 10000 : 0,
            'average_purchase_price' => $stockManaged ? 10000 : 0,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        return $product->fresh();
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . $this->sequence++ . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id') ?? Currency::factory()->create()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);
    }
}

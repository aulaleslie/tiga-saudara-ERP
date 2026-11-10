<?php

namespace Modules\Consignment\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\People\Entities\Supplier;
use Tests\TestCase;

/**
 * Bundle + split-owner POS checkout (stock owned by another business) versus buyout reservations.
 * Deterministic SQLite integration, not a concurrency proof.
 */
class ConsignmentBuyoutPosBundleReservationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config(['pos.checkout.split_posting.enabled' => true]);

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function createBundleSplitContext(): array
    {
        $setting = $this->createSetting('POS BUNDLE SPLIT TERMINAL', 'TNC', 'JL');
        $sourceSetting = $this->createSetting('POS BUNDLE SPLIT SOURCE', 'TOP', 'JL');
        
        $cashier = $this->createUserForSetting($setting, 'pos split cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
        ]);

        $sourceLocation = Location::create([
            'name' => 'BUNDLE SPLIT SOURCE LOC ' . $this->sequence++,
            'setting_id' => $sourceSetting->id,
        ]);

        [$terminal, $locations] = $this->createTerminalAndSaleLocations($setting, $sourceLocation);
        $methods = $this->seedPaymentMethods($setting, true);
        $session = $this->openSession($setting, $terminal, $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create([
            'name' => 'VAT 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $child = $this->createProduct($setting, 'CHILD PRODUCT', 0, false, $tax->id);
        ProductStock::create([
            'product_id' => $child->id,
            'location_id' => $sourceLocation->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        $parent = $this->createProduct($setting, 'SERIAL PARENT', 100000, true, $tax->id);
        ProductStock::create([
            'product_id' => $parent->id,
            'location_id' => $sourceLocation->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        $serials = [];
        for ($i = 1; $i <= 5; $i++) {
            $serials[] = ProductSerialNumber::create([
                'product_id' => $parent->id,
                'location_id' => $sourceLocation->id,
                'serial_number' => "SN-{$i}-" . $this->sequence++,
                'status' => 'ACTIVE',
                'tax_id' => $tax->id,
            ]);
        }

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'SERIAL BUNDLE',
            'bundle_sale_price' => 150000, 'price' => 50000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 1,
        ]);

        return [
            'setting' => $setting,
            'source_setting' => $sourceSetting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'session' => $session,
            'methods' => $methods,
            'customer' => $customer,
            'source_location' => $sourceLocation,
            'product' => $parent,
            'child' => $child,
            'bundle' => $bundle,
            'serials' => $serials,
            'tax' => $tax,
        ];
    }

    private function createMultiSourceBundleSplitContext(): array
    {
        $setting = $this->createSetting('POS MULTI SOURCE BUNDLE', 'TNC', 'JL');
        $sourceSetting = $this->createSetting('POS MULTI SOURCE BUNDLE SOURCE', 'TOP', 'JL');
        
        $cashier = $this->createUserForSetting($setting, 'pos split cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
        ]);

        $locA = Location::create([
            'name' => 'MULTI SOURCE LOC A ' . $this->sequence++,
            'setting_id' => $setting->id,
        ]);

        $locB = Location::create([
            'name' => 'MULTI SOURCE LOC B ' . $this->sequence++,
            'setting_id' => $sourceSetting->id,
        ]);

        // Register both locations on terminal setting
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locB->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-MULTI-' . $this->sequence++,
            'name' => 'POS Multi Terminal',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => false,
        ]);

        $methods = $this->seedPaymentMethods($setting, true);
        $session = $this->openSession($setting, $terminal, $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create([
            'name' => 'VAT 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $child = $this->createProduct($setting, 'CHILD PRODUCT', 0, false, $tax->id);
        // Child stock is only in Loc A
        ProductStock::create([
            'product_id' => $child->id,
            'location_id' => $locA->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        $parent = $this->createProduct($setting, 'SERIAL PARENT', 100000, true, $tax->id);
        // Parent stock split across Loc A and Loc B
        ProductStock::create([
            'product_id' => $parent->id,
            'location_id' => $locA->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);
        ProductStock::create([
            'product_id' => $parent->id,
            'location_id' => $locB->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        $serials = [];
        $serials[] = ProductSerialNumber::create([
            'product_id' => $parent->id,
            'location_id' => $locA->id,
            'serial_number' => "SN-A-" . $this->sequence++,
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);
        $serials[] = ProductSerialNumber::create([
            'product_id' => $parent->id,
            'location_id' => $locB->id,
            'serial_number' => "SN-B-" . $this->sequence++,
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'SERIAL BUNDLE',
            'bundle_sale_price' => 150000, 'price' => 50000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 1,
        ]);

        return [
            'setting' => $setting,
            'source_setting' => $sourceSetting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'session' => $session,
            'methods' => $methods,
            'customer' => $customer,
            'loc_a' => $locA,
            'loc_b' => $locB,
            'product' => $parent,
            'child' => $child,
            'bundle' => $bundle,
            'serials' => $serials,
            'tax' => $tax,
        ];
    }

    private function createProduct(Setting $setting, string $name, float $price, bool $serialRequired = false, ?int $taxId = null): Product
    {
        $unit = Unit::firstOrCreate(['name' => 'PCS', 'short_name' => 'PCS']);
        $category = Category::firstOrCreate([
            'category_code' => 'CAT-' . $this->sequence++,
            'category_name' => 'CAT NAME ' . $this->sequence++,
            'setting_id' => $setting->id,
            'created_by' => User::first()->id ?? User::factory()->create()->id,
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => 'PC-' . $this->sequence++,
            'product_price' => $price,
            'product_cost' => $price * 0.5,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $price,
            'sale_tax_id' => $taxId,
        ]);

        return $product;
    }

    private function createSetting(string $name, string $documentPrefix, string $salePrefix): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => $this->sequence++ . '@example.com',
            'company_phone' => '0812345678',
            'company_address' => 'Address',
            'default_currency_id' => Currency::first()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => $documentPrefix,
            'sale_prefix_document' => $salePrefix,
            'pos_enabled' => true,
            'pos_transactions_enabled' => true,
            'is_pkp' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalAndSaleLocations(Setting $setting, Location $sourceLocation): array
    {
        $locationA = Location::create([
            'name' => 'LOC A ' . $this->sequence++,
            'setting_id' => $setting->id,
        ]);

        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $sourceLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-' . $this->sequence++,
            'name' => 'Terminal',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => false,
        ]);

        return [$terminal, [$locationA, $sourceLocation]];
    }

    private function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA ' . $this->sequence++,
            'account_number' => 'ACC-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create([
            'name' => 'CASH ' . $this->sequence++,
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        if ($enableForSetting) {
            DB::table('setting_pos_payment_methods')->insert([
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
                'is_enabled' => true,
            ]);
        }

        return ['cash' => $method];
    }

    private function openSession(Setting $setting, PosTerminal $terminal, User $cashier)
    {
        return app(PosSessionLifecycleService::class)->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );
    }

    private function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        return $customer;
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): int
    {
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
                'bundle_id' => $bundleId,
            ]);

        $response->assertOk();
        
        $lines = $response->json('cart_snapshot.lines');
        return (int) end($lines)['line_id'];
    }

    private function assignSerials(User $cashier, Setting $setting, int $lineId, array $serials): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => $serials,
            ])
            ->assertOk();
    }

    private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
    private function buyoutForChild(array $ctx, string $qty)
    {
        $owner = $ctx['source_setting'];
        $supplier = Supplier::create(['setting_id' => $owner->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $receival = ConsignmentReceival::create(['setting_id' => $owner->id, 'supplier_id' => $supplier->id, 'reference' => 'CR-B',
            'receival_number' => 'CR-B', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $owner->id, 'location_id' => $ctx['source_location']->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => 'CR-B', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $ctx['child']->id,
            'product_name' => 'C', 'product_code' => 'C', 'quantity' => 20, 'unit_cost' => 1, 'unit_dpp' => 1,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $detail = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $ctx['child']->id, 'quantity_received' => 20, 'received_base_quantity' => 20, 'unit_cost' => 1, 'unit_dpp' => 1]);
        $dest = Location::create(['name' => 'STD DEST ' . $this->sequence++, 'setting_id' => $owner->id, 'is_consignment' => false]);

        $user = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::findOrCreate($p, 'web');
            $user->givePermissionTo($p);
        }
        $user->settings()->attach($owner->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($owner->id, $supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $detail->id, 'destination_location_id' => $dest->id,
            'base_quantity' => $qty, 'buckets' => ['TAXED' => $qty],
        ]], $user);

        return [$svc, $svc->approve($svc->submit($b, $user), $user), $user];
    }

    private function checkoutBundle(array $ctx, string $key, bool $retrySameCart = false)
    {
        if ($retrySameCart) {
            return $this->finalize($ctx['cashier'], $ctx['setting'], [
                'idempotency_key' => $key,
                'payment' => ['payment_method_id' => $ctx['methods']['cash']->id, 'amount_paid' => 200000],
            ]);
        }
        $lineId = $this->addCartLine($ctx['cashier'], $ctx['setting'], $ctx['product']->id, 1, $ctx['bundle']->id);
        $this->assignSerials($ctx['cashier'], $ctx['setting'], $lineId, [$ctx['serials'][0]->serial_number]);
        $this->selectCustomerInCart($ctx['cashier'], $ctx['setting'], $ctx['customer']);

        return $this->finalize($ctx['cashier'], $ctx['setting'], [
            'idempotency_key' => $key,
            'payment' => ['payment_method_id' => $ctx['methods']['cash']->id, 'amount_paid' => 200000],
        ]);
    }

    private function childStock(array $ctx): float
    {
        return (float) ProductStock::where('product_id', $ctx['child']->id)->where('location_id', $ctx['source_location']->id)->value('quantity');
    }

    public function test_bundle_component_reserved_by_the_owning_business_blocks_checkout_and_rolls_back_everything(): void
    {
        $ctx = $this->createBundleSplitContext();
        [$svc, $buyout, $user] = $this->buyoutForChild($ctx, '20'); // every component unit reserved

        $resp = $this->checkoutBundle($ctx, 'K-BO-BUNDLE-1');
        $this->assertNotSame(201, $resp->status(), $resp->getContent());
        $this->assertSame(20.0, $this->childStock($ctx));
        $this->assertSame(10.0, (float) ProductStock::where('product_id', $ctx['product']->id)->where('location_id', $ctx['source_location']->id)->value('quantity'));
        $this->assertSame('ACTIVE', $ctx['serials'][0]->fresh()->status);
        $this->assertSame(0, \Modules\Sale\Entities\Dispatch::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(2, $buyout->reservations()->where('status', 'ACTIVE')->count());

        // Cancellation releases the reservation and the same checkout shape now posts.
        $svc->cancel($buyout, 'release', $user);
        $ok = $this->checkoutBundle($ctx, 'K-BO-BUNDLE-2', true); // the same cart is retried after the release
        $this->assertSame(201, $ok->status(), $ok->getContent());
        $this->assertSame(19.0, $this->childStock($ctx));
    }

    public function test_bundle_checkout_uses_unreserved_component_stock_without_touching_the_reservation(): void
    {
        $ctx = $this->createBundleSplitContext();
        [, $buyout] = $this->buyoutForChild($ctx, '19'); // one component unit stays sellable

        $ok = $this->checkoutBundle($ctx, 'K-BO-BUNDLE-3');
        $this->assertSame(201, $ok->status(), $ok->getContent());
        $this->assertSame(19.0, $this->childStock($ctx));
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());
        $this->assertSame('SOLD', $ctx['serials'][0]->fresh()->status);
    }

    public function test_coordination_rows_cover_the_owning_business_and_every_consumed_product(): void
    {
        $ctx = $this->createBundleSplitContext();
        $ok = $this->checkoutBundle($ctx, 'K-BO-BUNDLE-4');
        $this->assertSame(201, $ok->status(), $ok->getContent());

        $pairs = DB::table('consignment_inventory_coordinations')->get(['setting_id', 'product_id'])
            ->map(fn ($r) => $r->setting_id . ':' . $r->product_id)->all();
        foreach ([$ctx['product']->id, $ctx['child']->id] as $pid) {
            $this->assertContains($ctx['source_setting']->id . ':' . $pid, $pairs, 'owner business + consumed product must be coordinated');
        }
    }
}

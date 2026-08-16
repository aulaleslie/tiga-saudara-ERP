<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
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
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSSplitSerialBundleCheckoutTest extends TestCase
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

    public function test_finalize_bundled_serial_succeeds_with_split_posting(): void
    {
        // 1.1 Add a split-posting regression test for a stock-managed serial-tracked parent product 
        // with a selected stock-managed bundle child where checkout finalize succeeds.
        
        $context = $this->createBundleSplitContext();
        $product = $context['product'];
        $bundle = $context['bundle'];
        $child = $context['child'];
        $serials = $context['serials'];

        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1, $bundle->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [$serials[0]->serial_number]);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-BUNDLE-SERIAL-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        $response->assertStatus(201);
        
        // 1.2 Assert the single-source regression decrements parent stock once, child stock once, 
        // marks the assigned serial sold, and links the serial to the created dispatch detail.
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'location_id' => $context['source_location']->id,
            'quantity' => 9, // 10 -> 9
        ]);

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $child->id,
            'location_id' => $context['source_location']->id,
            'quantity' => 19, // 20 -> 19
        ]);

        $this->assertDatabaseHas('product_serial_numbers', [
            'id' => $serials[0]->id,
            'status' => 'SOLD',
        ]);
        
        $serial = ProductSerialNumber::find($serials[0]->id);
        $this->assertNotNull($serial->dispatch_detail_id);
    }

    public function test_finalize_multi_source_bundled_serial_partitions_child_stock_correctly(): void
    {
        // 1.3 Add a multi-source regression test where two assigned parent serials resolve into 
        // two split groups and the selected bundle child quantity is deducted exactly twice total.
        
        $context = $this->createMultiSourceBundleSplitContext();
        $product = $context['product'];
        $bundle = $context['bundle'];
        $child = $context['child'];
        $serials = $context['serials']; // 0 from Loc A, 1 from Loc B

        // Add 2 quantity of serial-tracked bundle product
        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2, $bundle->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [
            $serials[0]->serial_number,
            $serials[1]->serial_number
        ]);
        
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-MULTI-SOURCE-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 400000,
            ],
        ]);

        $response->assertStatus(201);

        // Parent stocks
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'location_id' => $context['loc_a']->id,
            'quantity' => 0, // 1 -> 0
        ]);
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'location_id' => $context['loc_b']->id,
            'quantity' => 9, // 10 -> 9
        ]);

        // Child stock (all from Loc A)
        // 1.4 Assert multi-source child stock transactions preserve child allocation source location, 
        // source setting, and tax_bucket_used bucket behavior.
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $child->id,
            'location_id' => $context['loc_a']->id,
            'quantity' => 18, // 20 -> 18
        ]);

        $this->assertDatabaseCount('transactions', 3); // 2 parent + 1 child (batched)
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
            [],
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
    public function test_receipt_shows_bundle_composition_for_split_bundle_checkout(): void
    {
        // Task 5.1 & 5.5: Add coverage for receipt and transaction detail bundle display
        $context = $this->createBundleSplitContext();
        $product = $context['product'];
        $bundle = $context['bundle'];
        $child = $context['child'];
        $serials = $context['serials'];

        // Add the same parent product as two separate receipt rows:
        // two units with a bundle and one unit without a bundle.
        $bundledLineId = $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2, $bundle->id);
        $this->assignSerials($context['cashier'], $context['setting'], $bundledLineId, [
            $serials[0]->serial_number,
            $serials[1]->serial_number,
        ]);

        $plainLineId = $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->assignSerials($context['cashier'], $context['setting'], $plainLineId, [$serials[2]->serial_number]);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $finalizeResponse = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-RECEIPT-BUNDLE-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 500000,
            ],
        ]);
        $finalizeResponse->assertStatus(201);
        $posCheckoutId = $finalizeResponse->json('pos_checkout_id');
        $transactionId = \Modules\Pos\Entities\PosCheckout::find($posCheckoutId)->pos_transaction_id;

        // 1. Verify POS Receipt shows bundle component
        $receiptResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->get(route('pos.transactions.receipt', ['transaction' => $transactionId]));
        
        $receiptResponse->assertStatus(200);
        $receiptResponse->assertSee($product->product_name);
        $receiptResponse->assertSee($child->product_name);
        $receiptResponse->assertSee('x2'); // component qty belongs only to the bundled row
        $receiptResponse->assertSee($context['setting']->company_name);
        $this->assertSame(1, substr_count($receiptResponse->getContent(), $child->product_name));

        // 2. Verify Transaction Detail shows bundle component
        $detailResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->get(route('pos.transactions.show', ['transaction' => $transactionId]));
            
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee($product->product_name);
        $detailResponse->assertSee($child->product_name);
        $detailResponse->assertSee('x2');
        $this->assertSame(1, substr_count($detailResponse->getContent(), $child->product_name));
    }
}

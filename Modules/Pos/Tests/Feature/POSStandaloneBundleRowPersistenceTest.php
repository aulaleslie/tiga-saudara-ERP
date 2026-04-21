<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
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
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSStandaloneBundleRowPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_finalize_pos_checkout_persists_bundle_rows(): void
    {
        $context = $this->createBundleContext();
        $product = $context['product'];
        $bundle = $context['bundle'];
        $child = $context['child'];

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1, $bundle->id);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-BUNDLE-PERSIST-001',
            'cart_token' => (string) \Illuminate\Support\Str::uuid(),
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201);
        $saleId = $response->json('sale_id');

        // Assert sale_bundle_items row exists and is linked to sale and detail
        $bundleRows = SaleBundleItem::where('sale_id', $saleId)->get();
        $this->assertCount(1, $bundleRows);
        
        $row = $bundleRows->first();
        $this->assertEquals($bundle->id, $row->bundle_id);
        $this->assertEquals($child->id, $row->product_id);
        $this->assertEquals($child->product_name, $row->name);
        $this->assertEquals(1, $row->quantity);
        $this->assertEquals(0, $row->price);
        $this->assertEquals(0, $row->sub_total);
        $this->assertEquals($context['tax']->id, $row->tax_id);
        $this->assertNotNull($row->sale_detail_id);
        $this->assertStringStartsWith('pos-0-', $row->line_group_key);
    }

    public function test_finalize_pos_checkout_with_multiple_bundle_items_persists_all_rows(): void
    {
        $context = $this->createBundleContext(2); // Bundle with 2 children
        $product = $context['product'];
        $bundle = $context['bundle'];

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2, $bundle->id);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-BUNDLE-PERSIST-002',
            'cart_token' => (string) \Illuminate\Support\Str::uuid(),
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        $response->assertStatus(201);
        $saleId = $response->json('sale_id');

        // Assert sale_bundle_items rows exist (2 items x 2 bundle qty = 2 rows in sale_bundle_items table, but depends on schema design)
        // In our implementation, we create one SaleBundleItem per component entry in cart line.
        // If qty is 2, and bundle has 2 items, we still create 2 rows in sale_bundle_items, each with qty * component_qty.
        $bundleRows = SaleBundleItem::where('sale_id', $saleId)->get();
        $this->assertCount(2, $bundleRows);
        
        foreach ($bundleRows as $row) {
            $this->assertEquals(2, $row->quantity); // 2 qty x 1 item qty
        }
    }

    private function createBundleContext(int $childCount = 1): array
    {
        $setting = $this->createSetting('POS BUNDLE PERSIST', 'TNC', 'JL');
        
        $cashier = $this->createUserForSetting($setting, 'pos cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $location = Location::create([
            'name' => 'BUNDLE PERSIST LOC',
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-PERSIST',
            'name' => 'Terminal',
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

        $tax = Tax::query()->create([
            'name' => 'VAT 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $parent = $this->createProduct($setting, 'BUNDLE PARENT', 100000);
        ProductStock::create([
            'product_id' => $parent->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'TEST BUNDLE',
            'price' => 50000,
        ]);

        $child = null;
        for ($i = 0; $i < $childCount; $i++) {
            $child = $this->createProduct($setting, "CHILD PRODUCT {$i}", 0);
            ProductStock::create([
                'product_id' => $child->id,
                'location_id' => $location->id,
                'quantity' => 20,
                'quantity_tax' => 20,
                'quantity_non_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
                'tax_id' => $tax->id,
            ]);

            ProductBundleItem::create([
                'bundle_id' => $bundle->id,
                'product_id' => $child->id,
                'quantity' => 1,
            ]);
        }

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'session' => $session,
            'methods' => $methods,
            'customer' => $customer,
            'product' => $parent,
            'child' => $child, // Returns the last child
            'bundle' => $bundle,
            'tax' => $tax,
        ];
    }

    private function createProduct(Setting $setting, string $name, float $price): Product
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
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $price,
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

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
                'bundle_id' => $bundleId,
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
}

<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\PosReceiptService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductBundleSelfComponentAndOneLevelRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Customer $customer;
    private Location $location;
    private Tax $tax;
    private PaymentTerm $paymentTerm;
    private Category $category;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Gate::before(fn() => true);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Test St',
            'pos_enabled' => true,
            'is_pkp' => true,
        ]);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'SUPERADMIN-' . $this->setting->id]);
        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->actingAs($this->user);
        Session::put('setting_id', $this->setting->id);

        $this->paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->setting->update(['pos_walk_in_customer_id' => $this->customer->id]);

        $this->category = Category::create([
            'category_name' => 'Category A',
            'category_code' => 'CAT-A',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $this->tax = Tax::create(['name' => 'PPN 11%', 'value' => 11]);
    }

    /**
     * 5.1 Authoring test proving parent product can be its own component
     * and a component product may own bundles without validation failure.
     */
    public function test_authoring_permits_self_component_and_bundle_capable_components(): void
    {
        $parentProduct = Product::create([
            'product_name' => 'Self Parent',
            'product_code' => 'SELF-01',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 2000,
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'stock_managed' => true,
        ]);

        $componentWithOwnBundle = Product::create([
            'product_name' => 'Component With Bundle',
            'product_code' => 'COMP-WB',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'stock_managed' => true,
        ]);

        // Component has its own bundle definition
        ProductBundle::create([
            'parent_product_id' => $componentWithOwnBundle->id,
            'setting_id' => $this->setting->id,
            'name' => 'Child Bundle',
            'bundle_sale_price' => 1500,
            'is_active' => true,
        ]);

        $payload = [
            'name' => 'Bundle With Self and Capable Component',
            'bundle_sale_price' => 5000,
            'items' => [
                [
                    'product_id' => $parentProduct->id, // Self-component
                    'quantity' => 1,
                    'informational_item_price' => 2000,
                ],
                [
                    'product_id' => $componentWithOwnBundle->id, // Bundle-capable component
                    'quantity' => 2,
                    'informational_item_price' => 1000,
                ],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('products.bundle.store', $parentProduct->id), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.show', $parentProduct->id));

        $bundle = ProductBundle::where('parent_product_id', $parentProduct->id)->first();
        $this->assertNotNull($bundle);
        $this->assertCount(2, $bundle->items);
        $this->assertTrue($bundle->items->contains('product_id', $parentProduct->id));
        $this->assertTrue($bundle->items->contains('product_id', $componentWithOwnBundle->id));
    }

    /**
     * 5.2 Focused normal Sales coverage proving only direct items expand
     * and a stock-managed self-component with quantity one produces parent plus component demand for two units.
     */
     public function test_normal_sales_dispatch_demand_for_self_component(): void
    {
        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        Permission::firstOrCreate(['name' => 'salesDispatches.approval']);
        $this->user->givePermissionTo(['sales.dispatch', 'salesDispatches.approval']);

        $product = Product::create([
            'product_name' => 'Self Product',
            'product_code' => 'SP-01',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 5000,
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $sale = Sale::create([
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-SELF-BUNDLE',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Self component: quantity 1
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => $product->id,
            'bundle_id' => 123,
            'bundle_item_id' => 1,
            'name' => $product->product_name,
            'price' => 0,
            'quantity' => 1,
            'sub_total' => 0,
        ]);

        // Dispatch composite keys:
        // 1. Parent demand: product_id . '-0-0' or product_id . '--0'
        $parentKey = $product->id . '--0';
        // 2. Component demand: product_id . '--123'
        $componentKey = $product->id . '--123';

        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [
                $parentKey => 1,
                $componentKey => 1,
            ],
            'selectedLocations' => [
                $parentKey => $this->location->id,
                $componentKey => $this->location->id,
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // 1. Verify pending dispatch has both distinct demand rows
        $dispatch = Dispatch::with('details')->where('sale_id', $sale->id)->latest('id')->first();
        $this->assertNotNull($dispatch);
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch->status);
        $this->assertCount(2, $dispatch->details);

        $this->assertDatabaseHas('dispatch_details', [
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'bundle_id' => null,
        ]);

        $this->assertDatabaseHas('dispatch_details', [
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'bundle_id' => 123,
        ]);

        // 2. Approve dispatch through route('dispatches.approve', $dispatch)
        $approveResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('dispatches.approve', $dispatch));

        $approveResponse->assertRedirect();
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch->status);

        // 3. Assert relevant ProductStock decreases from 10 to 8
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(8, $stock->quantity);
        $this->assertEquals(8, $stock->quantity_non_tax);

        // 4. Assert product-level quantity decreases from 10 to 8
        $product->refresh();
        $this->assertEquals(8, $product->product_quantity);
    }

    /**
     * 5.3 Focused POS coverage proving component-owned bundles are not recursively fetched
     * and stock-managed self-component demand deducts the parent and direct component quantities exactly once each.
     */
    public function test_pos_bundle_one_level_expansion_and_self_component_checkout(): void
    {
        config(['pos.checkout.split_posting.enabled' => true]);

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $terminal = PosTerminal::create([
            'name' => 'Main Terminal',
            'code' => 'TRM-01',
            'setting_id' => $this->setting->id,
            'is_active' => true,
        ]);

        \Modules\Pos\Entities\PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        \Modules\Setting\Entities\SettingSaleLocation::create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_mode' => 'active',
        ]);

        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas Toko',
            'account_number' => 'ACC-001',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);
        $method = PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);
        DB::table('setting_pos_payment_methods')->insert([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $method->id,
            'is_enabled' => true,
        ]);

        app(\Modules\Pos\Services\PosSessionLifecycleService::class)->openSession(
            $this->setting->id,
            $terminal->id,
            $this->user->id,
            100000.0,
            ['100000' => 1],
            $this->user->id
        );

        // Product A (Self-bundled)
        $productA = Product::create([
            'product_name' => 'Self Product POS',
            'product_code' => 'SP-POS',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 50000,
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $productA->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => $this->tax->id,
        ]);

        ProductPrice::create([
            'product_id' => $productA->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 50000,
            'sale_tax_id' => $this->tax->id,
        ]);

        // Component Product B (has its own bundle definition)
        $productB = Product::create([
            'product_name' => 'Nested Capable POS',
            'product_code' => 'NC-POS',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 20000,
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $productB->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => $this->tax->id,
        ]);

        ProductPrice::create([
            'product_id' => $productB->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 20000,
            'sale_tax_id' => $this->tax->id,
        ]);

        // Child bundle on Product B
        ProductBundle::create([
            'parent_product_id' => $productB->id,
            'setting_id' => $this->setting->id,
            'name' => 'B Sub Bundle',
            'bundle_sale_price' => 25000,
            'is_active' => true,
        ]);

        // Bundle on Product A: Contains self (Product A) qty 1 + Product B qty 1
        $bundleA = ProductBundle::create([
            'parent_product_id' => $productA->id,
            'setting_id' => $this->setting->id,
            'name' => 'Parent Bundle with Self and B',
            'bundle_sale_price' => 80000,
            'is_active' => true,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundleA->id,
            'product_id' => $productA->id,
            'quantity' => 1,
            'informational_item_price' => 50000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundleA->id,
            'product_id' => $productB->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        // Add bundle to cart
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productA->id,
                'qty' => 1,
                'bundle_id' => $bundleA->id,
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $this->customer->id,
            ])
            ->assertOk();

        // Finalize checkout
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-POS-SELF-' . uniqid(),
                'payment' => [
                    'payment_method_id' => $method->id,
                    'amount_paid' => 80000,
                ],
            ]);

        // Verify Product A stock deduction:
        // Product A was parent (qty 1) + direct component (qty 1) -> exactly 2 units deducted (10 -> 8)
        $stockA = ProductStock::where('product_id', $productA->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(8, $stockA->quantity);
        $this->assertEquals(8, $stockA->quantity_tax);
        $this->assertEquals(0, $stockA->quantity_non_tax);

        $productA->refresh();
        $this->assertEquals(8, $productA->product_quantity);

        // Verify Product B stock deduction:
        // Product B was direct component only (qty 1) -> 10 -> 9
        $stockB = ProductStock::where('product_id', $productB->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(9, $stockB->quantity);
        $this->assertEquals(9, $stockB->quantity_tax);

        $productB->refresh();
        $this->assertEquals(9, $productB->product_quantity);
    }
}

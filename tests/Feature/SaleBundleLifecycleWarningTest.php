<?php

namespace Tests\Feature;

use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class SaleBundleLifecycleWarningTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $user;
    private Customer $customer;
    private Product $parent;
    private Product $component;
    private ProductBundle $bundle;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn() => true);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Sales Test Co',
            'company_email' => 'sales@example.com',
            'company_phone' => '0811111111',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'company_address' => 'Addr',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Session::put('setting_id', $this->setting->id);
        Session::put('user_settings', collect([$this->setting]));

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        $category = Category::create([
            'category_code' => 'CAT1',
            'category_name' => 'Cat 1',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->parent = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Parent Bundle Item',
            'product_code' => 'P-01',
            'product_quantity' => 10,
            'product_cost' => 10000,
            'product_price' => 50000,
            'sale_price' => 50000,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'is_sold' => true,
        ]);

        $this->component = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Child Component Item',
            'product_code' => 'C-01',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'sale_price' => 10000,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'is_sold' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->parent->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductStock::create([
            'product_id' => $this->component->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $this->bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $this->parent->id,
            'name' => 'Sales Bundle A',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);

        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $this->bundle->id,
            'product_id' => $this->component->id,
            'quantity' => 2,
        ]);

        $term = PaymentTerm::create(['name' => 'COD', 'longevity' => 0]);
        $this->customer = Customer::create([
            'customer_name' => 'Customer A',
            'customer_email' => 'a@example.com',
            'customer_phone' => '08123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
            'setting_id' => $this->setting->id,
            'payment_term_id' => $term->id,
        ]);
    }

    public function test_sales_edit_form_warns_on_inactive_bundle_without_blocking_load(): void
    {
        $bundleItem = $this->bundle->items()->first();

        // 1. Create a sale with bundle details and bundle items
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'reference' => 'SO-TEST-001',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->parent->id,
            'product_name' => $this->parent->product_name,
            'product_code' => $this->parent->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $this->component->id,
            'name' => $this->component->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // 2. Deactivate the bundle definition
        $this->bundle->update(['is_active' => false]);

        // 3. Mount EditForm Livewire component -> flashes warning
        Cart::instance('sale')->destroy();
        Livewire::test(\App\Livewire\Sale\EditForm::class, ['sale' => $sale])
            ->assertSet('lifecycleWarning', 'Terdapat 1 paket produk dalam penjualan ini dengan perubahan status atau kedaluwarsa. Data tersimpan tetap dipertahankan.')
            ->assertSee('Peringatan Paket Produk');

        // Cart is populated correctly from captured snapshot
        $this->assertEquals(1, Cart::instance('sale')->count());
        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertEquals($this->bundle->id, $cartItem->options->bundle_id);
    }

    public function test_dispatch_creation_and_approval_executes_against_persisted_bundle_components(): void
    {
        $bundleItem = $this->bundle->items()->first();

        // 1. Create Sale with bundle items
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'reference' => 'SO-TEST-002',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->parent->id,
            'product_name' => $this->parent->product_name,
            'product_code' => $this->parent->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $this->component->id,
            'name' => $this->component->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // 2. Delete bundle definition completely
        $this->bundle->delete();

        // 3. First attempt to store dispatch without acknowledgement -> warned, no dispatch created
        $compositeKey = $this->component->id . '--' . $this->bundle->id;
        $firstAttempt = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [
                $compositeKey => 2,
            ],
            'selectedLocations' => [
                $compositeKey => $this->location->id,
            ],
            'location_id' => $this->location->id,
            'acknowledge_lifecycle_warning' => 0,
        ]);
        $firstAttempt->assertSessionHas('lifecycle_warning');
        $this->assertDatabaseMissing('dispatches', ['sale_id' => $sale->id]);

        // 4. Acknowledged retry -> succeeds and creates dispatch
        $acknowledgedAttempt = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [
                $compositeKey => 2,
            ],
            'selectedLocations' => [
                $compositeKey => $this->location->id,
            ],
            'location_id' => $this->location->id,
            'acknowledge_lifecycle_warning' => 1,
        ]);
        $acknowledgedAttempt->assertSessionHasNoErrors();

        $dispatch = Dispatch::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertCount(1, $dispatch->details);

        // 5. First attempt to approve dispatch without acknowledgement -> warned, status remains pending
        $firstApprove = $this->post(route('dispatches.approve', $dispatch), [
            'acknowledge_lifecycle_warning' => 0,
        ]);
        $firstApprove->assertSessionHas('lifecycle_warning');
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch->status);

        // 6. Acknowledged retry to approve -> succeeds and deducts component stock from persisted snapshot
        $componentStockBefore = ProductStock::where('product_id', $this->component->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');

        $approveResponse = $this->post(route('dispatches.approve', $dispatch), [
            'acknowledge_lifecycle_warning' => 1,
        ]);
        $approveResponse->assertSessionHasNoErrors();

        $componentStockAfter = ProductStock::where('product_id', $this->component->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');

        $this->assertEquals($componentStockBefore - 2, $componentStockAfter);
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch->status);
    }

    public function test_sale_approval_requires_acknowledgement_and_succeeds_on_retry(): void
    {
        $bundleItem = $this->bundle->items()->first();

        // 1. Create Sale in WAITING_APPROVAL status
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'status' => Sale::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'reference' => 'SO-TEST-APPROVAL',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->parent->id,
            'product_name' => $this->parent->product_name,
            'product_code' => $this->parent->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $this->component->id,
            'name' => $this->component->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // 2. Deactivate the bundle definition
        $this->bundle->update(['is_active' => false]);

        // 3. Approval without acknowledgement -> warned, status remains WAITING_APPROVAL
        $unackResponse = $this->patch(route('sales.updateStatus', $sale), [
            'status' => Sale::STATUS_APPROVED,
            'acknowledge_lifecycle_warning' => 0,
        ]);
        $unackResponse->assertSessionHas('lifecycle_warning');
        $sale->refresh();
        $this->assertEquals(Sale::STATUS_WAITING_APPROVAL, $sale->status);

        // 4. Approval with acknowledgement -> succeeds, status updated to APPROVED
        $ackResponse = $this->patch(route('sales.updateStatus', $sale), [
            'status' => Sale::STATUS_APPROVED,
            'acknowledge_lifecycle_warning' => 1,
        ]);
        $ackResponse->assertSessionHasNoErrors();
        $sale->refresh();
        $this->assertEquals(Sale::STATUS_APPROVED, $sale->status);
    }

    public function test_lifecycle_warning_modal_partial_renders_cleanly_without_duplicate_fallbacks(): void
    {
        // 1. Render without session warning -> empty output, no errors
        Session::forget('lifecycle_warning');
        $noWarningHtml = view('sale::partials.lifecycle-warning-modal')->render();
        $this->assertSame('', trim($noWarningHtml));

        // 2. Render with session warning -> contains clean delegation, no duplicate dynamicForm
        Session::flash('lifecycle_warning', [
            'target_type' => 'sale_approval',
            'sale_id' => 123,
            'status' => 'APPROVED',
            'message' => 'Status paket telah berubah.',
            'items' => [
                ['product_name' => 'Bundle Item', 'reason' => 'Inactive'],
            ],
        ]);

        $warningHtml = view('sale::partials.lifecycle-warning-modal')->render();
        $this->assertStringContainsString('handleSalesLifecycleWarning', $warningHtml);
        $this->assertStringNotContainsString('dynamicForm', $warningHtml);
        $this->assertStringNotContainsString('document.body.appendChild', $warningHtml);
        $this->assertEquals(1, substr_count($warningHtml, '<script>'));
        $this->assertEquals(1, substr_count($warningHtml, '</script>'));
    }

    public function test_sales_store_warns_on_inactive_bundle_and_persists_on_acknowledgement(): void
    {
        // 1. Prepare cart with bundle
        Cart::instance('sale')->destroy();
        $bundleItem = $this->bundle->items()->first();

        Cart::instance('sale')->add([
            'id' => $this->parent->id,
            'name' => $this->parent->product_name,
            'qty' => 1,
            'price' => 50000,
            'weight' => 1,
            'options' => [
                'code' => $this->parent->product_code,
                'sub_total' => 50000,
                'unit_price' => 50000,
                'bundle_id' => $this->bundle->id,
                'bundle_name' => $this->bundle->name,
                'bundle_price' => 50000,
                'bundle_items' => [
                    [
                        'bundle_item_id' => $bundleItem->id,
                        'product_id' => $this->component->id,
                        'name' => $this->component->product_name,
                        'quantity' => 2,
                        'price' => 0,
                    ]
                ],
            ]
        ]);

        // 2. Deactivate bundle
        $this->bundle->update(['is_active' => false]);

        // 3. Store without acknowledgement -> warned
        $postData = [
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'note' => 'Test',
        ];

        $unackResponse = $this->post(route('sales.store'), array_merge($postData, [
            'acknowledge_lifecycle_warning' => 0,
        ]));
        $unackResponse->assertSessionHas('lifecycle_warning');
        $this->assertDatabaseMissing('sales', ['customer_id' => $this->customer->id]);

        // 4. Store with acknowledgement -> succeeds
        $ackResponse = $this->post(route('sales.store'), array_merge($postData, [
            'acknowledge_lifecycle_warning' => 1,
        ]));
        $ackResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['customer_id' => $this->customer->id]);
    }

    public function test_sales_update_warns_and_persists_on_acknowledgement(): void
    {
        $bundleItem = $this->bundle->items()->first();

        // 1. Create a draft sale
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'reference' => 'SO-TEST-UPD',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->parent->id,
            'product_name' => $this->parent->product_name,
            'product_code' => $this->parent->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $this->component->id,
            'name' => $this->component->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // 2. Set cart for update
        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => $this->parent->id,
            'name' => $this->parent->product_name,
            'qty' => 1,
            'price' => 50000,
            'weight' => 1,
            'options' => [
                'code' => $this->parent->product_code,
                'sub_total' => 50000,
                'unit_price' => 50000,
                'bundle_id' => $this->bundle->id,
                'bundle_name' => $this->bundle->name,
                'bundle_price' => 50000,
                'bundle_items' => [
                    [
                        'bundle_item_id' => $bundleItem->id,
                        'product_id' => $this->component->id,
                        'name' => $this->component->product_name,
                        'quantity' => 2,
                        'price' => 0,
                    ]
                ],
            ]
        ]);

        // 3. Deactivate bundle
        $this->bundle->update(['is_active' => false]);

        $putData = [
            'reference' => $sale->reference,
            'status' => Sale::STATUS_DRAFTED,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'note' => 'Updated Note',
        ];

        // 4. Update without acknowledgement -> warned
        $unackResponse = $this->put(route('sales.update', $sale), array_merge($putData, [
            'acknowledge_lifecycle_warning' => 0,
        ]));
        $unackResponse->assertSessionHas('lifecycle_warning');

        // 5. Update with acknowledgement -> succeeds
        $ackResponse = $this->put(route('sales.update', $sale), array_merge($putData, [
            'acknowledge_lifecycle_warning' => 1,
        ]));
        $ackResponse->assertSessionHasNoErrors();
        $sale->refresh();
        $this->assertEquals('UPDATED NOTE', $sale->note);
    }

    public function test_new_sales_persistence_stores_informational_allocation_while_price_remains_zero(): void
    {
        $bundleItem = $this->bundle->items()->first();
        $bundleItem->update(['informational_item_price' => 35000]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => $this->parent->id,
            'name' => $this->parent->product_name,
            'qty' => 1,
            'price' => 50000,
            'weight' => 1,
            'options' => [
                'code' => $this->parent->product_code,
                'sub_total' => 50000,
                'unit_price' => 50000,
                'bundle_id' => $this->bundle->id,
                'bundle_name' => $this->bundle->name,
                'bundle_price' => 50000,
                'bundle_items' => [
                    [
                        'bundle_item_id' => $bundleItem->id,
                        'product_id' => $this->component->id,
                        'name' => $this->component->product_name,
                        'quantity' => 2,
                        'price' => 0,
                        'informational_item_price' => 35000,
                    ]
                ],
            ]
        ]);

        $postData = [
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'note' => 'Test Informational',
            'acknowledge_lifecycle_warning' => 1,
        ];

        $response = $this->post(route('sales.store'), $postData);
        $response->assertSessionHasNoErrors();

        $savedItem = SaleBundleItem::where('bundle_id', $this->bundle->id)->latest('id')->first();
        $this->assertNotNull($savedItem);
        $this->assertEquals(0, (float) $savedItem->price);
        $this->assertEquals(0, (float) $savedItem->sub_total);
        $this->assertEquals(35000, (float) $savedItem->informational_item_price);
    }

    public function test_sales_warning_on_administrator_changing_saved_informational_allocation(): void
    {
        $bundleItem = $this->bundle->items()->first();
        $bundleItem->update(['informational_item_price' => 20000]);

        // Create sale with captured informational_item_price = 20000
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'reference' => 'SO-TEST-INFO-DRIFT',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->parent->id,
            'product_name' => $this->parent->product_name,
            'product_code' => $this->parent->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $this->component->id,
            'name' => $this->component->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
            'informational_item_price' => 20000,
        ]);

        // Administrator changes bundle's saved informational allocation
        $bundleItem->update(['informational_item_price' => 40000]);

        // Hydrate cart for editing
        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => $this->parent->id,
            'name' => $this->parent->product_name,
            'qty' => 1,
            'price' => 50000,
            'weight' => 1,
            'options' => [
                'code' => $this->parent->product_code,
                'sub_total' => 50000,
                'unit_price' => 50000,
                'bundle_id' => $this->bundle->id,
                'bundle_name' => $this->bundle->name,
                'bundle_price' => 50000,
                'bundle_items' => [
                    [
                        'bundle_item_id' => $bundleItem->id,
                        'product_id' => $this->component->id,
                        'name' => $this->component->product_name,
                        'quantity' => 2,
                        'price' => 0,
                        'informational_item_price' => 20000,
                    ]
                ],
            ]
        ]);

        $putData = [
            'reference' => $sale->reference,
            'status' => Sale::STATUS_DRAFTED,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'note' => 'Drift test',
        ];

        // Unacknowledged update triggers warning
        $unackResponse = $this->put(route('sales.update', $sale), array_merge($putData, [
            'acknowledge_lifecycle_warning' => 0,
        ]));
        $unackResponse->assertSessionHas('lifecycle_warning');

        // Acknowledged update retains the old captured allocation (20000) instead of refreshing to 40000
        $ackResponse = $this->put(route('sales.update', $sale), array_merge($putData, [
            'acknowledge_lifecycle_warning' => 1,
        ]));
        $ackResponse->assertSessionHasNoErrors();

        $savedItem = SaleBundleItem::where('sale_id', $sale->id)->first();
        $this->assertEquals(20000, (float) $savedItem->informational_item_price);
    }

    public function test_legacy_sales_row_with_null_informational_allocation_does_not_produce_false_warning(): void
    {
        $bundleItem = $this->bundle->items()->first();
        $bundleItem->update(['informational_item_price' => 25000]);

        // Legacy row with null informational_item_price
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'reference' => 'SO-TEST-LEGACY-NULL',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->parent->id,
            'product_name' => $this->parent->product_name,
            'product_code' => $this->parent->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $this->component->id,
            'name' => $this->component->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
            'informational_item_price' => null,
        ]);

        // Approval of legacy sale should not produce false informational allocation warning
        $response = $this->patch(route('sales.updateStatus', $sale), [
            'status' => Sale::STATUS_APPROVED,
            'acknowledge_lifecycle_warning' => 0,
        ]);

        $response->assertSessionHasNoErrors();
        $sale->refresh();
        $this->assertEquals(Sale::STATUS_APPROVED, $sale->status);
    }
}

<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSBundleDraftLifecycleWarningTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $cashier;
    private PosTerminal $terminal;
    private Location $location;
    private PosSession $session;
    private Product $parent;
    private Product $child;
    private ProductBundle $bundle;
    private PaymentMethod $paymentMethod;

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
            'company_name' => 'POS Warning Test Co',
            'company_email' => 'warning@example.com',
            'company_phone' => '08123456789',
            'company_address' => 'Address',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'POS',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'pos_transactions_enabled' => true,
            'is_pkp' => false,
        ]);

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.checkout.payment',
        ] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        $role = Role::create(['name' => 'CASHIER-' . $this->setting->id]);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.transactions.save', 'pos.transactions.load', 'pos.checkout.payment']);

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole($role);
        $this->cashier->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main POS Warehouse',
        ]);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal 1',
            'code' => 'T1',
            'sales_location_id' => $this->location->id,
            'is_active' => true,
        ]);

        \Modules\Pos\Entities\PosTerminalPolicy::create([
            'terminal_id' => $this->terminal->id,
            'allow_offline_orders' => false,
            'allow_discount_override' => true,
            'allow_price_override' => true,
        ]);

        $category = Category::create([
            'category_code' => 'CAT1',
            'category_name' => 'Category 1',
            'setting_id' => $this->setting->id,
            'created_by' => $this->cashier->id,
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
            'product_name' => 'Parent Bundle Product',
            'product_code' => 'PARENT-01',
            'barcode' => 'BAR-P-01',
            'product_quantity' => 100,
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        $this->child = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Child Component',
            'product_code' => 'CHILD-01',
            'barcode' => 'BAR-C-01',
            'product_quantity' => 100,
            'product_cost' => 10000,
            'product_price' => 20000,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $this->parent->id,
            'location_id' => $this->location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductStock::create([
            'product_id' => $this->child->id,
            'location_id' => $this->location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $this->parent->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000,
        ]);

        ProductPrice::create([
            'product_id' => $this->child->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 20000,
        ]);

        $this->bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $this->parent->id,
            'name' => 'Standard Bundle',
            'is_active' => true,
            'bundle_sale_price' => 100000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $this->bundle->id,
            'product_id' => $this->child->id,
            'quantity' => 1,
        ]);

        $coa = ChartOfAccount::create([
            'name' => 'Cash Account',
            'account_number' => '1001-POS',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->paymentMethod->id,
            'is_enabled' => true,
        ]);

        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $this->session = $sessionLifecycle->openSession(
            $this->setting->id,
            $this->terminal->id,
            $this->cashier->id,
            100000,
            ['100000' => 1],
            $this->cashier->id
        );
    }

    public function test_loading_draft_with_inactive_bundle_requires_acknowledgement(): void
    {
        $this->actingAs($this->cashier)->withSession(['setting_id' => $this->setting->id]);

        // 1. Add line to cart and save as draft
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $this->parent->id,
            'qty' => 1,
            'bundle_id' => $this->bundle->id,
        ])->assertOk();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);
        $transactionId = $saveResponse->json('transaction.id');

        // 2. Disable the bundle definition
        $this->bundle->update(['is_active' => false]);

        // 3. Attempt load without acknowledgement -> 422 with BUNDLE_LIFECYCLE_WARNING
        $loadResponse = $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]));
        $loadResponse->assertStatus(422)
            ->assertJsonPath('code', 'BUNDLE_LIFECYCLE_WARNING')
            ->assertJsonPath('warning.code', 'BUNDLE_LIFECYCLE_WARNING');

        // 4. Retry with acknowledge_lifecycle_warning: true -> succeeds with snapshot hydrated
        $ackResponse = $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]), [
            'acknowledge_lifecycle_warning' => true,
        ]);
        $ackResponse->assertOk();

        // 5. Verify snapshot contains the bundle lines
        $snapshot = $this->getJson(route('pos.sell.cart.show'))->json('cart_snapshot');
        $this->assertEquals($this->bundle->id, $snapshot['lines'][0]['bundle_id']);
        $this->assertCount(1, $snapshot['lines'][0]['bundle_items']);
    }

    public function test_checkout_preflight_and_finalize_with_inactive_bundle_handles_acknowledgement(): void
    {
        $this->actingAs($this->cashier)->withSession(['setting_id' => $this->setting->id]);

        // Create a customer
        $customer = \Modules\People\Entities\Customer::factory()->create(['setting_id' => $this->setting->id]);

        // Select customer in cart
        $this->patchJson(route('pos.sell.cart.customer.update'), [
            'customer_id' => $customer->id,
        ])->assertOk();

        // Add line to cart
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $this->parent->id,
            'qty' => 1,
            'bundle_id' => $this->bundle->id,
        ])->assertOk();

        // Deactivate bundle
        $this->bundle->update(['is_active' => false]);

        // 1. Preflight without acknowledgement -> 422 with BUNDLE_LIFECYCLE_WARNING under details.warning
        $preflightResponse = $this->postJson(route('pos.sell.checkout.preflight'));
        $preflightResponse->assertStatus(422)
            ->assertJsonPath('code', 'BUNDLE_LIFECYCLE_WARNING')
            ->assertJsonPath('details.warning.code', 'BUNDLE_LIFECYCLE_WARNING')
            ->assertJsonStructure(['details' => ['warning' => ['code', 'message', 'items']]]);

        // 2. Preflight with acknowledgement -> OK
        $ackPreflight = $this->postJson(route('pos.sell.checkout.preflight'), [
            'acknowledge_lifecycle_warning' => true,
        ]);
        $ackPreflight->assertOk();

        // 3. Finalize without acknowledgement -> 422 with BUNDLE_LIFECYCLE_WARNING
        $finalizeWithoutAck = $this->postJson(route('pos.sell.checkout.finalize'), [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'acknowledge_lifecycle_warning' => false,
            'payment' => [
                'payment_method_id' => $this->paymentMethod->id,
                'amount_paid' => 100000,
            ],
        ]);
        $finalizeWithoutAck->assertStatus(422)
            ->assertJsonPath('code', 'BUNDLE_LIFECYCLE_WARNING');

        // 4. Finalize with acknowledgement -> 200 OK and checkout posted from persisted snapshot
        $finalizeIdempotencyKey = (string) \Illuminate\Support\Str::uuid();
        $finalizeWithAck = $this->postJson(route('pos.sell.checkout.finalize'), [
            'idempotency_key' => $finalizeIdempotencyKey,
            'acknowledge_lifecycle_warning' => true,
            'payment' => [
                'payment_method_id' => $this->paymentMethod->id,
                'amount_paid' => 100000,
            ],
        ]);
        $finalizeWithAck->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonStructure(['pos_checkout_id', 'receipt_number', 'sale_id']);

        $checkout = PosCheckout::find($finalizeWithAck->json('pos_checkout_id'));
        $this->assertNotNull($checkout);
        $this->assertEquals(PosCheckout::STATUS_POSTED, $checkout->status);
    }

    public function test_acknowledged_preflight_still_enforces_stock_hard_gates(): void
    {
        $this->actingAs($this->cashier)->withSession(['setting_id' => $this->setting->id]);

        $customer = \Modules\People\Entities\Customer::factory()->create(['setting_id' => $this->setting->id]);
        $this->patchJson(route('pos.sell.cart.customer.update'), [
            'customer_id' => $customer->id,
        ])->assertOk();

        // Add bundle line requiring 1 child component
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $this->parent->id,
            'qty' => 1,
            'bundle_id' => $this->bundle->id,
        ])->assertOk();

        // Deactivate bundle
        $this->bundle->update(['is_active' => false]);

        // Reduce child component stock to 0
        ProductStock::where('product_id', $this->child->id)
            ->where('location_id', $this->location->id)
            ->update(['quantity' => 0, 'quantity_non_tax' => 0]);

        // Preflight with acknowledge_lifecycle_warning: true must STILL fail due to stock hard gate
        $preflightResponse = $this->postJson(route('pos.sell.checkout.preflight'), [
            'acknowledge_lifecycle_warning' => true,
        ]);
        $preflightResponse->assertStatus(422);
        $this->assertTrue(
            $preflightResponse->json('code') === 'STOCK_UNAVAILABLE'
            || !empty($preflightResponse->json('details.unfulfilled_lines')),
            'Acknowledged preflight must enforce component stock hard gate'
        );
    }
}

<?php

namespace Modules\Pos\Tests\Feature;

use App\Services\Sequence\DocumentSequence;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosSequenceCheckoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_pos_split_checkout_allocates_monotonic_sequences_across_owners(): void
    {
        $terminalSetting = $this->createSetting('STORE 1', 'PD-S1', 'SL');
        $sourceSetting = $this->createSetting('STORE 2', 'PD-S2', 'SL');

        $cashier = $this->createUserForSetting($terminalSetting);

        $loc1 = Location::create(['name' => 'LOC 1', 'setting_id' => $terminalSetting->id]);
        $loc2 = Location::create(['name' => 'LOC 2', 'setting_id' => $sourceSetting->id]);

        $this->setupTerminal($terminalSetting, [$loc1, $loc2]);
        $methods = $this->seedPaymentMethods($terminalSetting);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = Customer::factory()->create(['setting_id' => $terminalSetting->id]);
        $terminalSetting->update(['pos_walk_in_customer_id' => $customer->id]);
        $sourceSetting->update(['pos_walk_in_customer_id' => Customer::factory()->create(['setting_id' => $sourceSetting->id])->id]);

        $tax = Tax::create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $loc1, 'PROD-A', 50000, 5, $tax);
        $compB = $this->createStockedProduct($sourceSetting, $loc2, 'PROD-B', 50000, 5, $tax);

        $bundle = ProductBundle::create([
            'setting_id' => $terminalSetting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Combo Bundle',
            'bundle_sale_price' => 100000,
            'price' => 50000,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        // Add bundle to cart
        $this->actingAs($cashier)->withSession(['setting_id' => $terminalSetting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $parent->id, 'qty' => 1, 'bundle_id' => $bundle->id])
            ->assertOk();

        $this->actingAs($cashier)->withSession(['setting_id' => $terminalSetting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])
            ->assertOk();

        $response = $this->actingAs($cashier)->withSession(['setting_id' => $terminalSetting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-POS-SEQ-' . uniqid(),
                'payment' => [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 100000,
                ],
            ]);

        $response->assertStatus(201);
        $payload = $response->json();
        $this->assertCount(2, $payload['split_groups']);

        $sale1 = Sale::findOrFail((int) $payload['split_groups'][0]['sale_id']);
        $sale2 = Sale::findOrFail((int) $payload['split_groups'][1]['sale_id']);

        $this->assertEquals($terminalSetting->id, $sale1->setting_id);
        $this->assertEquals($sourceSetting->id, $sale2->setting_id);

        $this->assertStringStartsWith('PD-S1-SL-', $sale1->reference);
        $this->assertStringStartsWith('PD-S2-SL-', $sale2->reference);

        // Assert sequences in database
        $seq1 = DocumentSequence::where('document_type', 'sale')->where('setting_id', $terminalSetting->id)->first();
        $this->assertEquals(1, $seq1->last_number);

        $seq2 = DocumentSequence::where('document_type', 'sale')->where('setting_id', $sourceSetting->id)->first();
        $this->assertEquals(1, $seq2->last_number);
    }

    private function createSetting(string $name, string $docPrefix, string $salePrefix): Setting
    {
        $suffix = $this->sequence++;
        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'store.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => $docPrefix,
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => $salePrefix,
            'pos_enabled' => true,
            'is_pkp' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting)
    {
        $role = Role::firstOrCreate(['name' => 'CASHIER-' . $setting->id]);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);
        return $user;
    }

    private function setupTerminal(Setting $setting, array $locations): void
    {
        foreach ($locations as $index => $loc) {
            SettingSaleLocation::create([
                'setting_id' => $setting->id,
                'location_id' => $loc->id,
                'is_enabled' => true,
                'position' => $index + 1
            ]);
        }
        \App\Support\SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-' . $this->sequence++,
            'name' => 'Terminal',
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
    }

    private function openSession(Setting $setting, PosTerminal $terminal, $cashier)
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

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice, int $qty, Tax $tax): Product
    {
        $unit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'UNIT', 'short_name' => 'U']);
        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => \Modules\Product\Entities\Category::create([
                'category_name' => 'CAT-' . $code,
                'category_code' => 'CODE-' . $code,
                'setting_id' => $setting->id,
                'created_by' => 1,
            ])->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => $qty,
            'product_cost' => 1000,
            'product_price' => $salePrice,
            'product_unit' => 'U',
            'stock_managed' => true,
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_tax' => $qty,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => $tax->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'sale_tax_id' => $tax->id,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA ' . $this->sequence++,
            'account_number' => 'ACC-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);
        DB::table('setting_pos_payment_methods')->insert(['setting_id' => $setting->id, 'payment_method_id' => $method->id, 'is_enabled' => true]);

        return ['cash' => $method];
    }
}

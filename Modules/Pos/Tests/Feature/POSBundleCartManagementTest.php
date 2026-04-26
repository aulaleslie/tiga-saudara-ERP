<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
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
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Tests\TestCase;

class POSBundleCartManagementTest extends TestCase
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

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_same_product_different_bundles_stay_separate(): void
    {
        $context = $this->createCheckoutContext('BUNDLE SEP');
        
        $parent = $this->createStockedProduct($context['setting'], $context['location'], 'PARENT', 100000);
        
        $bundleA = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $context['setting']->id,
            'name' => 'Bundle A',
            'price' => 10000,
        ]);

        $bundleB = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $context['setting']->id,
            'name' => 'Bundle B',
            'price' => 20000,
        ]);

        // Add Product + Bundle A
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 1, $bundleA->id);
        
        // Add Product + Bundle B
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 1, $bundleB->id);
        
        // Add Product without bundle
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 1, null);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        
        $this->assertCount(3, $snapshot['lines'], 'Should have 3 distinct rows for the same product with different bundle states.');
        
        $this->assertEquals($bundleA->id, $snapshot['lines'][0]['bundle_id']);
        $this->assertEquals($bundleB->id, $snapshot['lines'][1]['bundle_id']);
        $this->assertNull($snapshot['lines'][2]['bundle_id']);
    }

    public function test_same_product_same_bundle_merges(): void
    {
        $context = $this->createCheckoutContext('BUNDLE MERGE');
        
        $parent = $this->createStockedProduct($context['setting'], $context['location'], 'PARENT', 100000);
        
        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $context['setting']->id,
            'name' => 'Bundle A',
            'bundle_sale_price' => 110000,
            'price' => 10000, // legacy
        ]);

        // Add Product + Bundle A twice
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 1, $bundle->id);
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 2, $bundle->id);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        
        $this->assertCount(1, $snapshot['lines']);
        $this->assertEquals(3, $snapshot['lines'][0]['qty']);
        $this->assertEquals($bundle->id, $snapshot['lines'][0]['bundle_id']);
    }

    public function test_serial_appending_targets_correct_bundle_line(): void
    {
        $context = $this->createCheckoutContext('BUNDLE SERIAL');
        
        $parent = $this->createStockedProduct($context['setting'], $context['location'], 'PARENT', 100000, true);
        
        $bundleA = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $context['setting']->id,
            'name' => 'Bundle A',
            'price' => 10000,
        ]);

        $bundleB = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $context['setting']->id,
            'name' => 'Bundle B',
            'price' => 20000,
        ]);

        $snA = $this->createSerial($parent, $context['location'], 'SN-A');
        $snB = $this->createSerial($parent, $context['location'], 'SN-B');
        $snNone = $this->createSerial($parent, $context['location'], 'SN-NONE');

        // Setup cart with three lines for same product
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 1, $bundleA->id);
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 1, $bundleB->id);
        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 1, null);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineAId = $snapshot['lines'][0]['line_id'];
        $lineBId = $snapshot['lines'][1]['line_id'];
        $lineNoneId = $snapshot['lines'][2]['line_id'];

        // Append serials to specific lines
        $this->appendSerial($context['cashier'], $context['setting'], $lineAId, 'SN-A');
        $this->appendSerial($context['cashier'], $context['setting'], $lineBId, 'SN-B');
        $this->appendSerial($context['cashier'], $context['setting'], $lineNoneId, 'SN-NONE');

        $finalSnapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        
        $this->assertEquals(['SN-A'], $finalSnapshot['lines'][0]['assigned_serials']);
        $this->assertEquals(['SN-B'], $finalSnapshot['lines'][1]['assigned_serials']);
        $this->assertEquals(['SN-NONE'], $finalSnapshot['lines'][2]['assigned_serials']);
    }

    // ==== HELPER METHODS ====

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);

        // Add at least one payment method for POS session
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Cash Account ' . $name,
            'account_number' => 'CASH-' . $name,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $method = PaymentMethod::create([
            'name' => 'Cash ' . $name,
            'coa_id' => $coaId,
            'is_cash' => true,
        ]);
        
        SettingPosPaymentMethod::create([
            'setting_id' => $setting->id,
            'payment_method_id' => $method->id,
            'is_enabled' => true,
        ]);

        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'location' => $location,
            'session' => $session,
        ];
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.bundle.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => false,
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

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS BUNDLE LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-BUNDLE-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Bundle Terminal ' . $index,
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

        return $terminal;
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice, bool $serialRequired = false): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 100,
            'product_cost' => 10000,
            'product_price' => $salePrice,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
        ]);

        return $product;
    }

    private function createSerial(Product $product, Location $location, string $sn): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $sn,
            'status' => 'ACTIVE',
        ]);
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): void
    {
        $payload = [
            'product_id' => $productId,
            'qty' => $qty,
        ];

        if ($bundleId !== null) {
            $payload['bundle_id'] = $bundleId;
        }

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), $payload)
            ->assertOk();
    }

    private function appendSerial(User $cashier, Setting $setting, int $lineId, string $sn): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $sn,
            ])
            ->assertOk();
    }

    private function cartSnapshot(User $cashier, Setting $setting): array
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');
    }

    public function test_cart_snapshot_exposes_bundle_details_for_dialog(): void
    {
        $context = $this->createCheckoutContext('BUNDLE DIALOG');
        
        $parent = $this->createStockedProduct($context['setting'], $context['location'], 'PARENT', 100000);
        $item1 = $this->createStockedProduct($context['setting'], $context['location'], 'ITEM1', 0);
        $item2 = $this->createStockedProduct($context['setting'], $context['location'], 'ITEM2', 0);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $context['setting']->id,
            'name' => 'Complete Pack',
            'bundle_sale_price' => 125000,
            'price' => 25000, // legacy
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $item1->id, 'quantity' => 2]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $item2->id, 'quantity' => 1]);

        $this->addCartLine($context['cashier'], $context['setting'], $parent->id, 2, $bundle->id);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $line = $snapshot['lines'][0];

        $this->assertEquals('COMPLETE PACK', $line['bundle_name']);
        $this->assertEquals(25000, $line['bundle_price']);
        $this->assertEquals(125000, $line['unit_price']); // 100k + 25k
        $this->assertEquals(250000, $line['line_total']); // 125k * 2
        
        $this->assertCount(2, $line['bundle_items']);
        $this->assertEquals('ITEM1 NAME', $line['bundle_items'][0]['product_name']);
        $this->assertEquals(2, $line['bundle_items'][0]['quantity']);
        $this->assertEquals('ITEM2 NAME', $line['bundle_items'][1]['product_name']);
        $this->assertEquals(1, $line['bundle_items'][1]['quantity']);
    }
}

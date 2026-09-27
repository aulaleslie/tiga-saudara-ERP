<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\PosCartService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Product\Entities\ProductSerialNumber;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosCartUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    protected PosCartService $cartService;
    protected User $user;
    protected Setting $setting;
    protected PosSession $session;
    protected Location $location;
    protected Unit $baseUnit;
    protected Unit $boxUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartService = app(PosCartService::class);

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

        $role = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open']);

        $this->setting = Setting::create([
            'company_name' => 'TEST POS UNIT CONV',
            'company_email' => 'pos@example.com',
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

        $this->user = User::factory()->create([
            'email' => 'cashier.conv@example.com',
            'is_active' => true,
        ]);
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        $this->location = Location::factory()->create(['setting_id' => $this->setting->id]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => null,
            'cashier_user_id' => $this->user->id,
            'opened_by' => $this->user->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
        ]);

        session(['setting_id' => $this->setting->id]);
        $this->actingAs($this->user);

        $this->baseUnit = Unit::firstOrCreate(['name' => 'Piece', 'short_name' => 'PCS']);
        $this->boxUnit = Unit::firstOrCreate(['name' => 'Box', 'short_name' => 'BOX']);
    }

    private function createProduct(float $price = 10000): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => 'TEST-CAT'],
            [
                'category_name' => 'Test Category',
                'created_by' => 1,
                'setting_id' => $this->setting->id,
            ]
        );

        $product = Product::query()->create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'product_name' => 'Test Product Unit Conv',
            'product_code' => 'SKU-' . uniqid(),
            'barcode' => 'BAR-' . uniqid(),
            'product_quantity' => 200,
            'product_cost' => 5000,
            'product_price' => $price,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'price' => $price,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 200,
            'quantity_non_tax' => 200,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        return $product;
    }

    public function test_base_addition_without_conversion_adds_single_base_unit(): void
    {
        $product = $this->createProduct(10000);

        $snapshot = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            null
        );

        $this->assertCount(1, $snapshot['lines']);
        $this->assertEquals(1, $snapshot['lines'][0]['qty']);
        $this->assertEquals(10000.0, $snapshot['lines'][0]['unit_price']);
        $this->assertEquals(10000.0, $snapshot['totals']['grand_total']);
    }

    public function test_factor_addition_and_repeat_merge_multiplies_base_units(): void
    {
        $product = $this->createProduct(10000);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-BOX-' . uniqid(),
        ]);

        // Add 1 Box (factor 12) -> should result in 12 base units
        $snapshot1 = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            $conversion->id
        );

        $this->assertCount(1, $snapshot1['lines']);
        $this->assertEquals(12, $snapshot1['lines'][0]['qty']);
        $this->assertEquals(120000.0, $snapshot1['totals']['grand_total']);

        // Add 1 more Box -> merges into the existing line, resulting in 24 base units
        $snapshot2 = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            $conversion->id
        );

        $this->assertCount(1, $snapshot2['lines']);
        $this->assertEquals(24, $snapshot2['lines'][0]['qty']);
        $this->assertEquals(240000.0, $snapshot2['totals']['grand_total']);
    }

    public function test_stale_sales_disablement_rejects_without_mutating_cart(): void
    {
        $product = $this->createProduct(10000);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-BOX-' . uniqid(),
        ]);

        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $this->setting->id,
            'price' => 120000,
            'sales_enabled' => false, // Disabled for sales!
            'purchase_enabled' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Konversi unit BOX dinonaktifkan untuk penjualan di bisnis ini.');

        try {
            $this->cartService->addLine(
                $this->setting->id,
                $this->session->id,
                $product->id,
                1,
                $conversion->id
            );
        } finally {
            // Assert cart was not mutated
            $snapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
            $this->assertEmpty($snapshot['lines']);
        }
    }

    public function test_foreign_conversion_id_rejects_without_mutating_cart(): void
    {
        $product1 = $this->createProduct(10000);
        $product2 = $this->createProduct(20000);

        // Conversion belongs to product 2
        $foreignConversion = ProductUnitConversion::create([
            'product_id' => $product2->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-FOREIGN-' . uniqid(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unit konversi tidak ditemukan untuk produk ini.');

        try {
            // Attempt to add product 1 with foreign conversion
            $this->cartService->addLine(
                $this->setting->id,
                $this->session->id,
                $product1->id,
                1,
                $foreignConversion->id
            );
        } finally {
            $snapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
            $this->assertEmpty($snapshot['lines']);
        }
    }

    public function test_invalid_or_fractional_factor_rejects_without_mutating_cart(): void
    {
        $product = $this->createProduct(10000);

        // Fractional factor conversion (1.5)
        $fractionalConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 1.5,
            'barcode' => 'BAR-FRAC-' . uniqid(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Faktor konversi untuk unit BOX tidak valid.');

        try {
            $this->cartService->addLine(
                $this->setting->id,
                $this->session->id,
                $product->id,
                1,
                $fractionalConv->id
            );
        } finally {
            $snapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
            $this->assertEmpty($snapshot['lines']);
        }
    }

    public function test_bundle_addition_with_box_factor_12_adds_12_bundles_and_multiplies_components(): void
    {
        $parentProduct = $this->createProduct(50000);
        $childProduct = $this->createProduct(10000);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $parentProduct->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-PARENT-BOX-' . uniqid(),
        ]);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentProduct->id,
            'name' => 'Paket Promo 12',
            'bundle_sale_price' => 45000,
            'is_active' => true,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $childProduct->id,
            'quantity' => 2,
        ]);

        // Add 1 Box (factor 12) with bundle
        $snapshot = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $parentProduct->id,
            1,
            $boxConversion->id,
            bundleId: $bundle->id
        );

        $this->assertCount(1, $snapshot['lines']);
        $line = $snapshot['lines'][0];

        // Parent qty is 12 (1 * 12)
        $this->assertEquals(12, $line['qty']);
        $this->assertEquals($bundle->id, $line['bundle_id']);
        $this->assertEquals(45000.0, $line['unit_price']);
        $this->assertEquals(12 * 45000.0, $snapshot['totals']['grand_total']);

        // Component items required: child quantity 2 per bundle
        $this->assertNotEmpty($line['bundle_items']);
        $this->assertEquals($childProduct->id, $line['bundle_items'][0]['product_id']);
        $this->assertEquals(2, $line['bundle_items'][0]['quantity']); // per-bundle quantity
    }

    public function test_explicit_no_bundle_continuation_with_box_factor_12_adds_base_product_units(): void
    {
        $parentProduct = $this->createProduct(50000);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $parentProduct->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-PARENT-BOX-2-' . uniqid(),
        ]);

        // Bundle exists, but user chose "Lanjut Harga Normal" (bundleId: null)
        ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentProduct->id,
            'name' => 'Paket Promo Unused',
            'bundle_sale_price' => 45000,
            'is_active' => true,
        ]);

        $snapshot = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $parentProduct->id,
            1,
            $boxConversion->id,
            bundleId: null
        );

        $this->assertCount(1, $snapshot['lines']);
        $line = $snapshot['lines'][0];
        $this->assertEquals(12, $line['qty']);
        $this->assertNull($line['bundle_id']);
        $this->assertEquals(50000.0, $line['unit_price']);
        $this->assertEquals(12 * 50000.0, $snapshot['totals']['grand_total']);
    }

    public function test_manual_cart_quantity_updates_operate_in_base_units(): void
    {
        $product = $this->createProduct(10000);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-BOX-QTY-' . uniqid(),
        ]);

        // Add 1 Box (factor 12) -> cart has 12 base units
        $snapshot = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            $boxConversion->id
        );
        $lineId = $snapshot['lines'][0]['line_id'];
        $this->assertEquals(12, $snapshot['lines'][0]['qty']);

        // Update line quantity manually (e.g. cashier typed 13 base units)
        $updatedSnapshot = $this->cartService->updateLine(
            $this->setting->id,
            $this->session->id,
            $lineId,
            ['qty' => 13]
        );

        $this->assertEquals(13, $updatedSnapshot['lines'][0]['qty']);
        $this->assertEquals(130000.0, $updatedSnapshot['totals']['grand_total']);
    }

    public function test_serial_addition_always_contributes_one_base_unit_without_inherited_conversion(): void
    {
        $category = Category::firstOrCreate(
            ['category_code' => 'TEST-CAT-SERIAL'],
            ['category_name' => 'Test Category Serial', 'created_by' => 1, 'setting_id' => $this->setting->id]
        );

        $serialProduct = Product::query()->create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'product_name' => 'Serialized Product',
            'product_code' => 'SKU-SERIAL-' . uniqid(),
            'barcode' => 'BAR-SERIAL-' . uniqid(),
            'product_quantity' => 10,
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'serial_number_required' => true,
        ]);

        ProductPrice::query()->create([
            'product_id' => $serialProduct->id,
            'setting_id' => $this->setting->id,
            'price' => 100000,
        ]);

        ProductStock::query()->create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-TEST-001',
            'status' => \Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE,
        ]);

        // Add serial product via serial scan: add line adds 1 base unit, then append serial
        $snapshot = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $serialProduct->id,
            1,
            null,
            null
        );

        $this->assertCount(1, $snapshot['lines']);
        $lineId = $snapshot['lines'][0]['line_id'];
        $this->assertEquals(1, $snapshot['lines'][0]['qty']);

        $appendedSnapshot = $this->cartService->appendSerial(
            $this->setting->id,
            $this->session->id,
            $lineId,
            'SN-TEST-001'
        );

        $line = $appendedSnapshot['lines'][0];
        $this->assertEquals(1, $line['qty']);
        $this->assertEquals(['SN-TEST-001'], $line['assigned_serials']);
        $this->assertEquals(100000.0, $line['unit_price']);
    }

    public function test_bundle_checkout_with_conversion_factor_multiplies_component_stock_deductions_and_enforces_serials(): void
    {
        // 1. Setup parent product with conversion Box factor 12
        $parentProduct = $this->createProduct(50000);
        $boxConversion = ProductUnitConversion::create([
            'product_id' => $parentProduct->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-CKOUT-BOX-' . uniqid(),
        ]);

        // 2. Setup child product with stock 100
        $childProduct = $this->createProduct(10000);

        // 3. Setup bundle: parent requires child quantity 2 per bundle
        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentProduct->id,
            'name' => 'Paket Promo 12x2',
            'bundle_sale_price' => 45000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $childProduct->id,
            'quantity' => 2,
            'informational_item_price' => 10000,
        ]);

        // 4. Add 1 Box (factor 12) with bundle -> 12 bundles in cart
        $cartSnapshot = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $parentProduct->id,
            1,
            $boxConversion->id,
            bundleId: $bundle->id
        );

        $line = $cartSnapshot['lines'][0];
        $this->assertEquals(12, $line['qty']);
        $this->assertEquals(2, $line['bundle_items'][0]['quantity']); // 2 per bundle

        // 5. Check stock before: parent has 200, child has 200
        $parentStockBefore = ProductStock::where('product_id', $parentProduct->id)->where('location_id', $this->location->id)->first();
        $childStockBefore = ProductStock::where('product_id', $childProduct->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(200, $parentStockBefore->quantity);
        $this->assertEquals(200, $childStockBefore->quantity);

        // 6. Finalize checkout with 12 bundles (12 * 45,000 = 540,000)
        // Set up cash payment method
        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA CASH TEST',
            'account_number' => 'ACC-CASH-TEST-' . uniqid(),
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'CASH TEST',
            'coa_id' => $coaId,
            'is_cash' => true,
        ]);
        \Illuminate\Support\Facades\DB::table('setting_pos_payment_methods')->insert([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $paymentMethod->id,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Walk-in Customer',
            'customer_email' => 'walkin@example.com',
            'customer_phone' => '0812345678',
        ]);
        $this->cartService->updateCustomerSelection($this->setting->id, $this->session->id, $customer->id);

        $finalizeService = app(\Modules\Pos\Services\FinalizePosCheckoutService::class);
        $finalizeResult = $finalizeService->finalize(
            $this->setting->id,
            $this->session,
            $this->user->id,
            'IDEMP-CONV-BUNDLE-' . uniqid(),
            [
                'payment_method_id' => $paymentMethod->id,
                'amount_paid' => 12 * 45000,
            ]
        );

        $this->assertSame(201, $finalizeResult['http_status']);
        $this->assertSame('POSTED', $finalizeResult['payload']['status']);

        // 7. Verify stock deductions:
        // Parent: 200 - 12 = 188
        $parentStockAfter = ProductStock::where('product_id', $parentProduct->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(188, $parentStockAfter->quantity);

        // Child: 200 - (12 bundles * 2 = 24) = 176
        $childStockAfter = ProductStock::where('product_id', $childProduct->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(176, $childStockAfter->quantity);
    }

    public function test_bundle_checkout_with_conversion_factor_enforces_multiplied_serialized_components_and_deducts_stock(): void
    {
        // 1. Setup parent product with conversion Box factor 12
        $parentProduct = $this->createProduct(50000);
        $boxConversion = ProductUnitConversion::create([
            'product_id' => $parentProduct->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-CKOUT-SERIAL-BOX-' . uniqid(),
        ]);

        // 2. Setup child product with serial_number_required = true
        $childProduct = $this->createProduct(10000);
        $childProduct->serial_number_required = true;
        $childProduct->save();

        // Generate 24 active serial numbers for the child product
        $serialNumbers = [];
        for ($i = 1; $i <= 24; $i++) {
            $sn = 'SN-COMP-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . '-' . uniqid();
            ProductSerialNumber::create([
                'product_id' => $childProduct->id,
                'serial_number' => $sn,
                'status' => 'ACTIVE',
                'location_id' => $this->location->id,
                'created_by' => $this->user->id,
            ]);
            $serialNumbers[] = $sn;
        }

        // 3. Setup bundle: parent requires child quantity 2 per bundle (requires 12 * 2 = 24 serials total)
        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentProduct->id,
            'name' => 'Paket Serial 12x2',
            'bundle_sale_price' => 45000,
            'is_active' => true,
        ]);
        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $childProduct->id,
            'quantity' => 2,
            'informational_item_price' => 10000,
        ]);

        // 4. Add 1 Box (factor 12) with bundle -> line qty 12 in cart
        $cartSnapshot = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $parentProduct->id,
            1,
            $boxConversion->id,
            bundleId: $bundle->id
        );

        $line = $cartSnapshot['lines'][0];
        $lineId = $line['line_id'];
        $this->assertEquals(12, $line['qty']);

        // Setup cash payment method & customer
        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA CASH SERIAL TEST',
            'account_number' => 'ACC-CASH-SN-' . uniqid(),
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'CASH SERIAL TEST',
            'coa_id' => $coaId,
            'is_cash' => true,
        ]);
        \Illuminate\Support\Facades\DB::table('setting_pos_payment_methods')->insert([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $paymentMethod->id,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Serial Bundle Customer',
            'customer_email' => 'serialbundle@example.com',
            'customer_phone' => '0812345679',
        ]);
        $this->cartService->updateCustomerSelection($this->setting->id, $this->session->id, $customer->id);

        $finalizeService = app(\Modules\Pos\Services\FinalizePosCheckoutService::class);

        // 5. Attempt checkout without serials -> must throw PosCheckoutValidationException with code SERIAL_INVALID
        $thrown = false;
        try {
            $finalizeService->finalize(
                $this->setting->id,
                $this->session,
                $this->user->id,
                'IDEMP-SERIAL-EMPTY-' . uniqid(),
                [
                    'payment_method_id' => $paymentMethod->id,
                    'amount_paid' => 12 * 45000,
                ]
            );
        } catch (PosCheckoutValidationException $e) {
            $thrown = true;
            $this->assertSame('SERIAL_INVALID', $e->errorCode());
            $this->assertStringContainsString('membutuhkan 24 nomor seri', $e->getMessage());
        }
        $this->assertTrue($thrown, 'Expected PosCheckoutValidationException was not thrown for missing bundle serials.');

        // 6. Assign partial serials (23 of 24) -> still fails
        for ($i = 0; $i < 23; $i++) {
            $this->cartService->appendSerial(
                $this->setting->id,
                $this->session->id,
                $lineId,
                $serialNumbers[$i],
                $bundleItem->id
            );
        }

        $thrownPartial = false;
        try {
            $finalizeService->finalize(
                $this->setting->id,
                $this->session,
                $this->user->id,
                'IDEMP-SERIAL-PARTIAL-' . uniqid(),
                [
                    'payment_method_id' => $paymentMethod->id,
                    'amount_paid' => 12 * 45000,
                ]
            );
        } catch (PosCheckoutValidationException $e) {
            $thrownPartial = true;
            $this->assertSame('SERIAL_INVALID', $e->errorCode());
            $this->assertStringContainsString('23 yang diberikan', $e->getMessage());
        }
        $this->assertTrue($thrownPartial, 'Expected PosCheckoutValidationException for partial bundle serials.');

        // 7. Assign final 24th serial
        $this->cartService->appendSerial(
            $this->setting->id,
            $this->session->id,
            $lineId,
            $serialNumbers[23],
            $bundleItem->id
        );

        // 8. Finalize checkout -> should succeed
        $finalizeResult = $finalizeService->finalize(
            $this->setting->id,
            $this->session,
            $this->user->id,
            'IDEMP-SERIAL-FULL-' . uniqid(),
            [
                'payment_method_id' => $paymentMethod->id,
                'amount_paid' => 12 * 45000,
            ]
        );

        $this->assertSame(201, $finalizeResult['http_status']);
        $this->assertSame('POSTED', $finalizeResult['payload']['status']);

        // 9. Verify stock deductions:
        // Parent: 200 - 12 = 188
        $parentStockAfter = ProductStock::where('product_id', $parentProduct->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(188, $parentStockAfter->quantity);

        // Child: 200 - 24 = 176
        $childStockAfter = ProductStock::where('product_id', $childProduct->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(176, $childStockAfter->quantity);

        // All 24 serials should now be sold/inactive
        $activeSerials = ProductSerialNumber::whereIn('serial_number', $serialNumbers)->where('status', 'ACTIVE')->count();
        $this->assertSame(0, $activeSerials);
    }

    public function test_serial_addition_when_cart_has_conversion_line_increments_total_quantity_by_one(): void
    {
        // 1. Setup serialized product with Box conversion factor 12
        $product = $this->createProduct(50000);
        $product->serial_number_required = true;
        $product->save();

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-SERIAL-BOX-' . uniqid(),
        ]);

        $sn1 = 'SN-LINE1-' . uniqid();
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $sn1,
            'status' => 'ACTIVE',
            'location_id' => $this->location->id,
            'created_by' => $this->user->id,
        ]);

        // Case A: Cart has 1 Box with NO assigned serials (empty BOX row)
        // Add 1 Box -> cart has 1 line with qty 12
        $snapshot1 = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            $boxConversion->id
        );
        $this->assertCount(1, $snapshot1['lines']);
        $this->assertEquals(12, $snapshot1['lines'][0]['qty']);
        $boxLineId = $snapshot1['lines'][0]['line_id'];

        // Add base serialized unit -> server returns snapshot with target_line_id identifying the new base row
        $snapshot2 = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            null
        );
        $this->assertNotNull($snapshot2['target_line_id']);
        $this->assertNotEquals($boxLineId, $snapshot2['target_line_id']);

        // Frontend uses snapshot['target_line_id'] (or response.line_id) to attach serial
        $snapshot3 = $this->cartService->appendSerial(
            $this->setting->id,
            $this->session->id,
            $snapshot2['target_line_id'],
            $sn1
        );

        $boxRow = collect($snapshot3['lines'])->first(fn ($l) => $l['line_id'] === $boxLineId);
        $baseRow = collect($snapshot3['lines'])->first(fn ($l) => $l['line_id'] === $snapshot2['target_line_id']);

        // BOX row remains empty of serials with qty 12
        $this->assertCount(0, $boxRow['assigned_serials']);
        $this->assertEquals(12, $boxRow['qty']);

        // Base row has the assigned serial with qty 1
        $this->assertCount(1, $baseRow['assigned_serials']);
        $this->assertSame(strtoupper($sn1), $baseRow['assigned_serials'][0]);
        $this->assertEquals(1, $baseRow['qty']);

        $totalCartQty = collect($snapshot3['lines'])->sum('qty');
        $this->assertEquals(13, $totalCartQty);
    }

    public function test_serial_addition_when_cart_has_fully_assigned_box_row_targets_base_row_without_inflating_box_quantity(): void
    {
        // Setup serialized product with Box conversion factor 12
        $product = $this->createProduct(50000);
        $product->serial_number_required = true;
        $product->save();

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12,
            'barcode' => 'BAR-SERIAL-FULLBOX-' . uniqid(),
        ]);

        // Add 1 Box -> cart has 1 line with qty 12
        $snapshot1 = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            $boxConversion->id
        );
        $boxLineId = $snapshot1['lines'][0]['line_id'];

        // Fully assign 12 serials to the BOX row
        for ($i = 1; $i <= 12; $i++) {
            $snBox = 'SN-BOX-' . $i . '-' . uniqid();
            ProductSerialNumber::create([
                'product_id' => $product->id,
                'serial_number' => $snBox,
                'status' => 'ACTIVE',
                'location_id' => $this->location->id,
                'created_by' => $this->user->id,
            ]);
            $this->cartService->appendSerial(
                $this->setting->id,
                $this->session->id,
                $boxLineId,
                $snBox
            );
        }

        // Verify BOX row has 12 serials and qty 12
        $currentSnapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
        $boxLine = collect($currentSnapshot['lines'])->first(fn ($l) => $l['line_id'] === $boxLineId);
        $this->assertCount(12, $boxLine['assigned_serials']);
        $this->assertEquals(12, $boxLine['qty']);

        // Now, scan a new serial for base unit
        $snBase = 'SN-BASE-EXTRA-' . uniqid();
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $snBase,
            'status' => 'ACTIVE',
            'location_id' => $this->location->id,
            'created_by' => $this->user->id,
        ]);

        // Add base unit (qty 1, conversion null)
        $snapshotAfterAdd = $this->cartService->addLine(
            $this->setting->id,
            $this->session->id,
            $product->id,
            1,
            null
        );

        // Assert target_line_id points to the newly added base line, not the BOX row
        $this->assertNotNull($snapshotAfterAdd['target_line_id']);
        $this->assertNotEquals($boxLineId, $snapshotAfterAdd['target_line_id']);

        // Attach serial using target_line_id
        $snapshotAfterSerial = $this->cartService->appendSerial(
            $this->setting->id,
            $this->session->id,
            $snapshotAfterAdd['target_line_id'],
            $snBase
        );

        $boxLineFinal = collect($snapshotAfterSerial['lines'])->first(fn ($l) => $l['line_id'] === $boxLineId);
        $baseLineFinal = collect($snapshotAfterSerial['lines'])->first(fn ($l) => $l['line_id'] === $snapshotAfterAdd['target_line_id']);

        // Assert BOX row was NOT auto-incremented to 13/14
        $this->assertEquals(12, $boxLineFinal['qty']);
        $this->assertCount(12, $boxLineFinal['assigned_serials']);

        // Assert Base row has 1 serial and qty 1
        $this->assertEquals(1, $baseLineFinal['qty']);
        $this->assertCount(1, $baseLineFinal['assigned_serials']);
        $this->assertSame(strtoupper($snBase), $baseLineFinal['assigned_serials'][0]);

        // Total quantity must be 13, NOT 14
        $totalCartQty = collect($snapshotAfterSerial['lines'])->sum('qty');
        $this->assertEquals(13, $totalCartQty);
    }
}

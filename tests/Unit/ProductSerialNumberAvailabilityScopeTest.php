<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductSerialNumberAvailabilityScopeTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '0812345678',
            'company_address' => 'Test Address',
            'notification_email' => 'test@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama',
            'setting_id' => $this->setting->id,
            'is_active' => true,
        ]);

        $category = \Modules\Product\Entities\Category::firstOrCreate(
            ['category_code' => 'TEST-CAT'],
            [
                'category_name' => 'Test Category',
                'created_by' => 1,
                'setting_id' => $this->setting->id,
            ]
        );

        $unit = \Modules\Setting\Entities\Unit::firstOrCreate([
            'name' => 'TEST UNIT',
            'short_name' => 'TUNIT',
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Kamera Pro',
            'product_code' => 'CAM-01',
            'product_unit' => 'TUNIT',
            'product_price' => 1500000,
            'product_cost' => 1000000,
            'product_quantity' => 10,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);
    }

    public function test_sellable_scope_includes_only_active_unbroken_available_serials(): void
    {
        // 1. Sellable: ACTIVE, is_broken = false
        $sellableActive = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-SELLABLE-ACTIVE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        // 2. Sellable legacy: status NULL, is_broken = false
        // In sqlite with default('active'), column is NOT NULL with default.
        // We verify isSellable() and getCombinedState() on an instance with null status.
        $sellableLegacyNull = ProductSerialNumber::make([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-SELLABLE-NULL',
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);
        $sellableLegacyNull->setRawAttributes(array_merge($sellableLegacyNull->getAttributes(), ['status' => null]));

        // 3. Broken active: ACTIVE, is_broken = true
        $brokenActive = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-BROKEN-ACTIVE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => true,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        // 4. Broken legacy: status = BROKEN
        $brokenLegacy = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-BROKEN-LEGACY',
            'status' => 'BROKEN',
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        // 5. Missing: status = MISSING
        $missing = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-MISSING',
            'status' => ProductSerialNumber::STATUS_MISSING,
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        // 6. Sold: status = SOLD
        $sold = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-SOLD',
            'status' => ProductSerialNumber::STATUS_SOLD,
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        // 7. Returned: status = RETURNED
        $returned = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-RETURNED',
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        // 8. Returning: is_in_return_process = true
        $returning = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-RETURNING',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => true,
            'dispatch_detail_id' => null,
        ]);

        // 9. Dispatched: dispatch_detail_id = 999
        $dispatched = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-DISPATCHED',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => 999,
        ]);

        // Assert sellable scope
        $sellableIds = ProductSerialNumber::sellable()->pluck('id')->all();
        $this->assertContains($sellableActive->id, $sellableIds);
        $this->assertNotContains($brokenActive->id, $sellableIds);
        $this->assertNotContains($brokenLegacy->id, $sellableIds);
        $this->assertNotContains($missing->id, $sellableIds);
        $this->assertNotContains($sold->id, $sellableIds);
        $this->assertNotContains($returned->id, $sellableIds);
        $this->assertNotContains($returning->id, $sellableIds);
        $this->assertNotContains($dispatched->id, $sellableIds);

        // Assert instance sellable helper
        $this->assertTrue($sellableActive->isSellable());
        $this->assertTrue($sellableLegacyNull->isSellable());
        $this->assertFalse($brokenActive->isSellable());
        $this->assertFalse($brokenLegacy->isSellable());
        $this->assertFalse($missing->isSellable());
        $this->assertFalse($sold->isSellable());
        $this->assertFalse($returned->isSellable());
        $this->assertFalse($returning->isSellable());
        $this->assertFalse($dispatched->isSellable());

        // Assert availableBroken scope
        $brokenIds = ProductSerialNumber::availableBroken()->pluck('id')->all();
        $this->assertContains($brokenActive->id, $brokenIds);
        $this->assertContains($brokenLegacy->id, $brokenIds);
        $this->assertNotContains($sellableActive->id, $brokenIds);
        $this->assertNotContains($missing->id, $brokenIds);
        $this->assertNotContains($sold->id, $brokenIds);
        $this->assertNotContains($dispatched->id, $brokenIds);

        // Assert available scope
        $availableIds = ProductSerialNumber::available()->pluck('id')->all();
        $this->assertContains($sellableActive->id, $availableIds);
        $this->assertContains($brokenActive->id, $availableIds);
        $this->assertContains($brokenLegacy->id, $availableIds);
        $this->assertNotContains($missing->id, $availableIds);
        $this->assertNotContains($sold->id, $availableIds);
        $this->assertNotContains($returned->id, $availableIds);
        $this->assertNotContains($returning->id, $availableIds);
        $this->assertNotContains($dispatched->id, $availableIds);
    }

    public function test_combined_state_presenter_returns_expected_labels(): void
    {
        $sellable = ProductSerialNumber::make([
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);
        $this->assertSame('Tersedia — Siap Jual', $sellable->getCombinedState()['label']);

        $broken = ProductSerialNumber::make([
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => true,
        ]);
        $this->assertSame('Tersedia — Rusak', $broken->getCombinedState()['label']);

        $missing = ProductSerialNumber::make([
            'status' => ProductSerialNumber::STATUS_MISSING,
            'is_broken' => false,
        ]);
        $this->assertSame('Hilang — Tidak Tersedia', $missing->getCombinedState()['label']);

        $returning = ProductSerialNumber::make([
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_in_return_process' => true,
        ]);
        $this->assertSame('Dalam Proses Retur', $returning->getCombinedState()['label']);

        $sold = ProductSerialNumber::make([
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);
        $this->assertSame('Terjual', $sold->getCombinedState()['label']);

        $returned = ProductSerialNumber::make([
            'status' => ProductSerialNumber::STATUS_RETURNED,
        ]);
        $this->assertSame('Dikembalikan', $returned->getCombinedState()['label']);
    }
}

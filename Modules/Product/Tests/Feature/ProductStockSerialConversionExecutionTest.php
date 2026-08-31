<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductStockSerialConversionExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'products.convert_existing_stock_to_serialized', 'guard_name' => 'web']);
    }

    public function test_converts_product_stock_atomically_with_deterministic_location_allocation()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Pusat',
            'company_email' => 'pusat@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'pusat@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Pusat',
            'company_address' => 'Address Pusat',
        ]);
        $defaultTax = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $loc1 = Location::create(['name' => 'Gudang A', 'setting_id' => $setting->id]);
        $loc2 = Location::create(['name' => 'Gudang B', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'iPhone 15 Pro',
            'product_code' => 'IPH15-P',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1->id,
            'quantity' => 3,
            'quantity_non_tax' => 1,
            'quantity_tax' => 1,
            'broken_quantity' => 1,
            'broken_quantity_non_tax' => 1,
            'broken_quantity_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc2->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $poolsPayload = [
            (string) $setting->id => [
                'setting_id' => $setting->id,
                'setting_name' => $setting->company_name,
                'pools' => [
                    'normal_non_tax' => 2,
                    'normal_tax' => 1,
                    'broken_non_tax' => 1,
                    'broken_tax' => 0,
                ],
                'total' => 4,
            ]
        ];

        $scannedPayload = [
            (string) $setting->id => [
                'normal_non_tax' => ['SN-NON-TAX-1', 'SN-NON-TAX-2'],
                'normal_tax' => ['SN-TAX-1'],
                'broken_non_tax' => ['SN-BROKEN-NON-TAX-1'],
                'broken_tax' => [],
            ]
        ];

        $response = $this->actingAs($user)->postJson(route('products.convert-to-serialized.convert', $product->id), [
            'expected_pools' => $poolsPayload,
            'scanned_serials' => $scannedPayload,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertTrue((bool) $product->fresh()->serial_number_required);
        $this->assertEquals(4, ProductSerialNumber::where('product_id', $product->id)->count());

        // Check deterministic location allocation
        $sn1 = ProductSerialNumber::where('serial_number', 'SN-NON-TAX-1')->first();
        $sn2 = ProductSerialNumber::where('serial_number', 'SN-NON-TAX-2')->first();
        $this->assertNotNull($sn1);
        $this->assertNotNull($sn2);

        // Loc1 had capacity 1 for non-tax, Loc2 had capacity 1 for non-tax
        $this->assertEquals($loc1->id, $sn1->location_id);
        $this->assertEquals($loc2->id, $sn2->location_id);
        $this->assertFalse((bool) $sn1->is_broken);
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $sn1->status);

        // Check broken stock serial creation & attributes
        $brokenSerial = ProductSerialNumber::where('serial_number', 'SN-BROKEN-NON-TAX-1')->first();
        $this->assertNotNull($brokenSerial);
        $this->assertEquals($loc1->id, $brokenSerial->location_id);
        $this->assertTrue((bool) $brokenSerial->is_broken);
        $this->assertEquals(ProductSerialNumber::STATUS_BROKEN, $brokenSerial->status);
        $this->assertNull($brokenSerial->tax_id);

        // Check tax ID on PPN serial
        $taxSerial = ProductSerialNumber::where('serial_number', 'SN-TAX-1')->first();
        $this->assertNotNull($taxSerial);
        $this->assertEquals($defaultTax->id, $taxSerial->tax_id);
        $this->assertEquals($loc1->id, $taxSerial->location_id);

        // Check tax ID on Non-PPN serials (MUST BE NULL)
        $nonTaxSerial = ProductSerialNumber::where('serial_number', 'SN-NON-TAX-1')->first();
        $this->assertNotNull($nonTaxSerial);
        $this->assertNull($nonTaxSerial->tax_id);

        // Check audit history count
        $this->assertEquals(4, SerialNumberHistory::where('event_type', 'STOCK_CONVERSION')->count());
    }

    public function test_rejects_conversion_when_owner_payload_is_omitted()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting1 = Setting::create([
            'company_name' => 'Pusat 1',
            'company_email' => 'p1@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'p1@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Pusat 1',
            'company_address' => 'Address 1',
        ]);
        $setting2 = Setting::create([
            'company_name' => 'Pusat 2',
            'company_email' => 'p2@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'p2@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Pusat 2',
            'company_address' => 'Address 2',
        ]);

        $loc1 = Location::create(['name' => 'Gudang 1', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'Gudang 2', 'setting_id' => $setting2->id]);

        $product = Product::create([
            'product_name' => 'Multi Owner Product',
            'product_code' => 'MOP-001',
            'setting_id' => $setting1->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc2->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $expectedPools = [
            (string) $setting1->id => [
                'pools' => [
                    'normal_non_tax' => 1,
                    'normal_tax' => 0,
                    'broken_non_tax' => 0,
                    'broken_tax' => 0,
                ],
                'total' => 1,
            ],
            (string) $setting2->id => [
                'pools' => [
                    'normal_non_tax' => 1,
                    'normal_tax' => 0,
                    'broken_non_tax' => 0,
                    'broken_tax' => 0,
                ],
                'total' => 1,
            ],
        ];

        // Omit setting2 from scanned_serials
        $scannedPayload = [
            (string) $setting1->id => [
                'normal_non_tax' => ['SN-OWNER-1'],
                'normal_tax' => [],
                'broken_non_tax' => [],
                'broken_tax' => [],
            ]
        ];

        $response = $this->actingAs($user)->postJson(route('products.convert-to-serialized.convert', $product->id), [
            'expected_pools' => $expectedPools,
            'scanned_serials' => $scannedPayload,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => 'Daftar cabang/owner pada data pemindaian tidak sesuai dengan data stok produk saat ini.']);
        $this->assertFalse((bool) $product->fresh()->serial_number_required);
    }

    public function test_rejects_conversion_when_extra_owner_payload_is_provided()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting1 = Setting::create([
            'company_name' => 'Cabang 1',
            'company_email' => 'c1@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'c1@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Cabang 1',
            'company_address' => 'Address 1',
        ]);

        $loc1 = Location::create(['name' => 'Gudang 1', 'setting_id' => $setting1->id]);

        $product = Product::create([
            'product_name' => 'Single Owner Product',
            'product_code' => 'SOP-001',
            'setting_id' => $setting1->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $expectedPools = [
            (string) $setting1->id => [
                'pools' => [
                    'normal_non_tax' => 1,
                    'normal_tax' => 0,
                    'broken_non_tax' => 0,
                    'broken_tax' => 0,
                ],
                'total' => 1,
            ],
        ];

        // Include extra non-existent setting ID 999 in scanned_serials
        $scannedPayload = [
            (string) $setting1->id => [
                'normal_non_tax' => ['SN-OWNER-1'],
                'normal_tax' => [],
                'broken_non_tax' => [],
                'broken_tax' => [],
            ],
            '999' => [
                'normal_non_tax' => ['SN-EXTRA-999'],
                'normal_tax' => [],
                'broken_non_tax' => [],
                'broken_tax' => [],
            ]
        ];

        $response = $this->actingAs($user)->postJson(route('products.convert-to-serialized.convert', $product->id), [
            'expected_pools' => $expectedPools,
            'scanned_serials' => $scannedPayload,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => 'Daftar cabang/owner pada data pemindaian tidak sesuai dengan data stok produk saat ini.']);
        $this->assertFalse((bool) $product->fresh()->serial_number_required);
    }

    public function test_rejects_conversion_when_stock_drift_occurs()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Pusat',
            'company_email' => 'pusat@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'pusat@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Pusat',
            'company_address' => 'Address Pusat',
        ]);
        $loc = Location::create(['name' => 'Gudang Utama', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Tablet Galaxy Tab',
            'product_code' => 'TAB-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 2,
            'quantity_non_tax' => 2,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Client sent payload expecting 2 units
        $poolsPayload = [
            (string) $setting->id => [
                'pools' => [
                    'normal_non_tax' => 2,
                    'normal_tax' => 0,
                    'broken_non_tax' => 0,
                    'broken_tax' => 0,
                ],
            ]
        ];

        // Stock changes in DB before submission to 3
        $stock->update(['quantity' => 3, 'quantity_non_tax' => 3]);

        $scannedPayload = [
            (string) $setting->id => [
                'normal_non_tax' => ['SN-1', 'SN-2'],
                'normal_tax' => [],
                'broken_non_tax' => [],
                'broken_tax' => [],
            ]
        ];

        $response = $this->actingAs($user)->postJson(route('products.convert-to-serialized.convert', $product->id), [
            'expected_pools' => $poolsPayload,
            'scanned_serials' => $scannedPayload,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'stock_drift' => true,
        ]);

        $this->assertFalse((bool) $product->fresh()->serial_number_required);
        $this->assertEquals(0, ProductSerialNumber::where('product_id', $product->id)->count());
    }

    public function test_repeat_submission_is_idempotent()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Pusat 2',
            'company_email' => 'pusat2@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'pusat2@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Pusat 2',
            'company_address' => 'Address Pusat 2',
        ]);

        $product = Product::create([
            'product_name' => 'Converted Product',
            'product_code' => 'CNV-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => true, // Already converted
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson(route('products.convert-to-serialized.convert', $product->id), [
            'expected_pools' => ['dummy' => 1],
            'scanned_serials' => ['dummy' => 1],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'already_converted' => true,
        ]);
    }

    public function test_transaction_rolls_back_completely_on_execution_failure()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Rollback Setting',
            'company_email' => 'rb@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'rb@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Rollback',
            'company_address' => 'Rollback Address',
        ]);

        $loc = Location::create(['name' => 'Gudang RB', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Rollback Product',
            'product_code' => 'RB-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 2,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 1,
            'broken_quantity_non_tax' => 1,
            'broken_quantity_tax' => 0,
        ]);

        $poolsPayload = [
            (string) $setting->id => [
                'setting_id' => $setting->id,
                'setting_name' => $setting->company_name,
                'pools' => [
                    'normal_non_tax' => 1,
                    'normal_tax' => 0,
                    'broken_non_tax' => 1,
                    'broken_tax' => 0,
                ],
                'total' => 2,
            ]
        ];

        $scannedPayload = [
            (string) $setting->id => [
                'normal_non_tax' => ['SN-FIRST-OK'],
                'normal_tax' => [],
                'broken_non_tax' => ['SN-SECOND-WILL-FAIL'],
                'broken_tax' => [],
            ]
        ];

        // Listen to SerialNumberHistory created event and throw exception on the 2nd row to force mid-transaction rollback
        $createdCount = 0;
        SerialNumberHistory::created(function () use (&$createdCount) {
            $createdCount++;
            if ($createdCount === 2) {
                throw new \Exception('Simulated database error during insertion.');
            }
        });

        $response = $this->actingAs($user)->postJson(route('products.convert-to-serialized.convert', $product->id), [
            'expected_pools' => $poolsPayload,
            'scanned_serials' => $scannedPayload,
        ]);

        $response->assertStatus(422);

        // Prove SN-FIRST-OK was created in memory/transaction but rolled back out of database
        $this->assertNull(ProductSerialNumber::where('serial_number', 'SN-FIRST-OK')->first());
        $this->assertNull(ProductSerialNumber::where('serial_number', 'SN-SECOND-WILL-FAIL')->first());
        $this->assertEquals(0, SerialNumberHistory::where('event_type', 'STOCK_CONVERSION')->count());
        $this->assertFalse((bool) $product->fresh()->serial_number_required);
    }
}

<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Pos\Services\PosScanResolverService;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;

class ProductBarcodePosRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createSetting(): Setting
    {
        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        return Setting::create([
            'company_name'              => 'Alpha',
            'company_email'             => 'alpha@example.com',
            'company_phone'             => '111',
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'n@example.com',
            'footer_text'               => 'Alpha',
            'company_address'           => 'A',
        ]);
    }

    private function createUnit(Setting $setting, string $name): Unit
    {
        return Unit::create([
            'name'       => $name,
            'short_name' => substr($name, 0, 3),
            'setting_id' => $setting->id,
        ]);
    }

    public function test_pos_exact_barcode_resolution()
    {
        $setting = $this->createSetting();
        $unit = $this->createUnit($setting, 'Piece');
        
        $product = Product::create([
            'product_name' => 'Prod',
            'product_code' => 'PR01',
            'base_unit_id' => $unit->id,
            'setting_id' => $setting->id,
            'barcode' => null,
            'product_price' => 100,
            'product_cost' => 80,
            'stock_managed' => true,
        ]);
        
        $locationId = \Illuminate\Support\Facades\DB::table('locations')->insertGetId([
            'name' => 'Main',
            'setting_id' => $setting->id
        ]);
        
        \Illuminate\Support\Facades\DB::table('setting_sale_locations')->insert([
            'setting_id' => $setting->id,
            'location_id' => $locationId,
            'is_enabled' => 1,
            'position' => 1,
        ]);
        
        \Illuminate\Support\Facades\DB::table('product_prices')->insert([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 100,
        ]);
        
        \Illuminate\Support\Facades\DB::table('product_stocks')->insert([
            'product_id' => $product->id,
            'location_id' => $locationId,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $actor = \App\Models\User::factory()->create();
        $actor->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'products.barcodes.manage']));

        $service = app(\Modules\Product\Services\ProductBarcodeAssignmentService::class);
        $result = $service->assign($product->id, 'POS123', null, $actor);
        $this->assertTrue($result['success']);
        
        $resolver = app(\Modules\Pos\Services\PosScanResolverService::class);
        $resolved = $resolver->resolve($setting->id, 'POS123');
        
        $this->assertEquals('product_exact', $resolved['type']);
        $this->assertEquals($product->id, $resolved['product']['id']);
    }

    public function test_print_barcode_rendering()
    {
        $setting = $this->createSetting();
        $unit = $this->createUnit($setting, 'Piece');
        
        $product = Product::create([
            'product_name' => 'Prod',
            'product_code' => 'PR02',
            'base_unit_id' => $unit->id,
            'setting_id' => $setting->id,
            'barcode' => null,
            'product_price' => 100,
            'product_cost' => 80,
            'stock_managed' => true,
        ]);
        
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'products.barcodes.manage']));
        
        $service = app(\Modules\Product\Services\ProductBarcodeAssignmentService::class);
        $result = $service->assign($product->id, '1234567890', null, $user);
        $this->assertTrue($result['success']);
        
        $product->forceFill(['product_barcode_symbology' => 'C128'])->save();

        \Illuminate\Support\Facades\DB::table('product_prices')->insert([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 12500,
        ]);

        $result = app(\Modules\Product\Services\BarcodeBatchService::class)
            ->expand([['product_id' => $product->id, 'quantity' => 1]], $setting->id);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['labels']);

        $html = view('product::barcode.batch-print', ['labels' => $result['labels']])->render();
        $this->assertStringContainsString('<svg', $html);
    }
}
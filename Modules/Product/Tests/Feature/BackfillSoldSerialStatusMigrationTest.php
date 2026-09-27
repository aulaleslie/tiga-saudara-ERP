<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class BackfillSoldSerialStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scout.driver', null);
        Schema::disableForeignKeyConstraints();

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
        ]);

        Product::create([
            'id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TP-001',
            'product_cost' => 1000,
            'product_price' => 1500,
            'setting_id' => 1,
        ]);

        Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);
    }

    protected function runBackfillMigration(): void
    {
        $migration = require base_path('Modules/Product/Database/Migrations/2026_02_22_200000_backfill_sold_status_for_dispatched_serial_numbers.php');
        $migration->up();
    }

    /** @test */
    public function it_backfills_only_dispatched_active_status_serials_and_is_idempotent(): void
    {
        DB::table('product_serial_numbers')->insert([
            [
                'id' => 1,
                'product_id' => 1,
                'location_id' => 1,
                'dispatch_detail_id' => 10,
                'serial_number' => 'SN-ACTIVE-DISPATCHED',
                'status' => 'active',
                'tax_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'product_id' => 1,
                'location_id' => 1,
                'dispatch_detail_id' => 11,
                'serial_number' => 'SN-UPPER-ACTIVE-DISPATCHED',
                'status' => ProductSerialNumber::STATUS_ACTIVE,
                'tax_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'product_id' => 1,
                'location_id' => 1,
                'dispatch_detail_id' => 12,
                'serial_number' => 'SN-RETURNED',
                'status' => ProductSerialNumber::STATUS_RETURNED,
                'tax_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'product_id' => 1,
                'location_id' => 1,
                'dispatch_detail_id' => 13,
                'serial_number' => 'SN-BROKEN',
                'status' => ProductSerialNumber::STATUS_BROKEN,
                'tax_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'product_id' => 1,
                'location_id' => 1,
                'dispatch_detail_id' => 14,
                'serial_number' => 'SN-RETURN-IN-PROCESS',
                'status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
                'tax_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 6,
                'product_id' => 1,
                'location_id' => 1,
                'dispatch_detail_id' => 15,
                'serial_number' => 'SN-SOLD',
                'status' => ProductSerialNumber::STATUS_SOLD,
                'tax_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 7,
                'product_id' => 1,
                'location_id' => 1,
                'dispatch_detail_id' => null,
                'serial_number' => 'SN-ACTIVE-NOT-DISPATCHED',
                'status' => ProductSerialNumber::STATUS_ACTIVE,
                'tax_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->runBackfillMigration();
        $this->runBackfillMigration();

        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, DB::table('product_serial_numbers')->where('id', 1)->value('status'));
        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, DB::table('product_serial_numbers')->where('id', 2)->value('status'));

        $this->assertEquals(ProductSerialNumber::STATUS_RETURNED, DB::table('product_serial_numbers')->where('id', 3)->value('status'));
        $this->assertEquals(ProductSerialNumber::STATUS_BROKEN, DB::table('product_serial_numbers')->where('id', 4)->value('status'));
        $this->assertEquals(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, DB::table('product_serial_numbers')->where('id', 5)->value('status'));
        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, DB::table('product_serial_numbers')->where('id', 6)->value('status'));
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, DB::table('product_serial_numbers')->where('id', 7)->value('status'));
    }
}

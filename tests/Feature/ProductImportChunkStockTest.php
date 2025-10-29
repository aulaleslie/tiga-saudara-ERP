<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PosLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Jobs\ProcessProductImportChunk;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Tests\TestCase;

class ProductImportChunkStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunk_import_assigns_stock_and_pos_query_detects_product(): void
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Main Warehouse',
        ]);

        $assignment = SettingSaleLocation::firstOrCreate(
            ['location_id' => $location->id],
            ['setting_id' => $setting->id]
        );
        $assignment->update(['is_pos' => true]);
        PosLocationResolver::forget($setting->id);

        $user = User::factory()->create();

        $batch = ProductImportBatch::create([
            'user_id' => $user->id,
            'location_id' => $location->id,
            'status' => 'queued',
            'total_rows' => 1,
            'processed_rows' => 0,
            'success_rows' => 0,
            'error_rows' => 0,
        ]);

        $row = ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'product_name' => 'Imported Product',
                'product_code' => 'IMP-001',
                'base_unit_name' => 'PCS',
                'stock_managed' => true,
                'min_stock' => 0,
                'stock' => 7,
                'stock_non_tax' => 5,
                'stock_tax' => 2,
                'is_purchased' => true,
                'purchase_price' => 1000,
                'is_sold' => true,
                'sale_price' => 1500,
                'tier_1_price' => 0,
                'tier_2_price' => 0,
                'serial_required' => false,
                'conversions' => [],
            ],
            'status' => 'pending',
        ]);

        (new ProcessProductImportChunk($batch->id, [$row->id]))->handle();

        $product = Product::where('product_code', 'IMP-001')->firstOrFail();
        $stock = ProductStock::where('product_id', $product->id)->firstOrFail();
        $transaction = Transaction::where('product_id', $product->id)->firstOrFail();

        $this->assertSame(7, $stock->quantity);
        $this->assertSame(5, $stock->quantity_non_tax);
        $this->assertSame(2, $stock->quantity_tax);
        $this->assertSame(0, $stock->broken_quantity_non_tax);
        $this->assertSame(0, $stock->broken_quantity_tax);
        $this->assertSame(7, $transaction->quantity);
        $this->assertSame(5, $transaction->quantity_non_tax);
        $this->assertSame(2, $transaction->quantity_tax);
        $this->assertSame(7, $transaction->after_quantity_at_location);

        // Simulate a follow-up adjustment that moves one non-tax unit to broken stock.
        $stock->update([
            'quantity' => 6,
            'quantity_non_tax' => 4,
            'quantity_tax' => 2,
            'broken_quantity_non_tax' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 1,
        ]);
        $stock->refresh();

        $this->assertSame(6, $stock->quantity);
        $this->assertSame(4, $stock->quantity_non_tax);
        $this->assertSame(2, $stock->quantity_tax);
        $this->assertSame(1, $stock->broken_quantity_non_tax);
        $this->assertSame(0, $stock->broken_quantity_tax);

        session()->put('setting_id', $setting->id);
        $posLocationIds = PosLocationResolver::resolveLocationIds($setting->id)->all();
        $this->assertEquals([$location->id], $posLocationIds);

        $aggregatedStock = DB::table('product_stocks')
            ->selectRaw('SUM(quantity_non_tax + quantity_tax) AS stock_qty')
            ->where('product_id', $product->id)
            ->whereIn('location_id', $posLocationIds)
            ->value('stock_qty');

        $this->assertSame(6, (int) $aggregatedStock);

        $results = DB::table('products as p')
            ->leftJoinSub(
                DB::table('product_stocks')
                    ->selectRaw('product_id, SUM(quantity_non_tax + quantity_tax) AS stock_qty')
                    ->whereIn('location_id', $posLocationIds)
                    ->groupBy('product_id'),
                'st',
                'st.product_id',
                '=',
                'p.id'
            )
            ->where('p.product_code', 'IMP-001')
            ->whereRaw('COALESCE(st.stock_qty, 0) > 0')
            ->select('p.product_code', 'st.stock_qty')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame(6, (int) $results->first()->stock_qty);
    }
}

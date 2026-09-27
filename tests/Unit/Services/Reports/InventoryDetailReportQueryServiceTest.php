<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\InventoryDetailReportFilterData;
use App\Services\Reports\InventoryDetailReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Tests\TestCase;
use Modules\Setting\Entities\Setting;

class InventoryDetailReportQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private InventoryDetailReportQueryService $service;
    private \Modules\Product\Entities\Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $user = \App\Models\User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->category = \Modules\Product\Entities\Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat1', 'created_by' => $user->id]);
        \Modules\Setting\Entities\Location::create(["id" => 1, "setting_id" => $this->setting->id, "name" => "Main"]);
        $this->service = new InventoryDetailReportQueryService();
    }

    public function test_get_detail_with_no_products()
    {
        $filters = new InventoryDetailReportFilterData();

        $result = $this->service->getDetail($filters, $this->setting->id);

        $this->assertEquals(0, $result['totalItems']);
        $this->assertEmpty($result['allRows']);
        $this->assertTrue($result['paginator']->isEmpty());
    }

    public function test_get_detail_with_transactions()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        $pastDate = now()->subMonth();
        $currentDate = now();
        
        // Opening stock transaction
        $tx1 = Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1, 'type' => 'BUY',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 
            'quantity' => 10,
            'after_quantity' => 10,
            'reason' => '#BUY-1',
            'date' => $pastDate->format('Y-m-d'),
        ]);
        $tx1->created_at = $pastDate;
        $tx1->save();

        // Current period transaction
        $tx2 = Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1, 'type' => 'SELL',
            'current_quantity' => 0, 'previous_quantity' => 10, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 
            'quantity' => -2,
            'after_quantity' => 8,
            'reason' => '#SELL-1',
            'date' => $currentDate->format('Y-m-d'),
        ]);
        $tx2->created_at = $currentDate;
        $tx2->save();

        $filters = new InventoryDetailReportFilterData(
            now()->startOfMonth(),
            now()->endOfMonth()
        );

        $result = $this->service->getDetail($filters, $this->setting->id);

        $this->assertEquals(1, $result['totalItems']);
        $this->assertCount(1, $result['allRows']);
        
        $row = $result['allRows'][0];
        $this->assertEquals($product->id, $row['product_id']);
        $this->assertEquals(10, $row['opening_row']['running_stock']);
        $this->assertEquals(8, $row['subtotal']['stock']);
        $this->assertCount(1, $row['ledger_rows']);
        $this->assertEquals(-2, $row['ledger_rows'][0]['mutation']);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Sale\DispatchSaleTable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Entities\ProductStock;
use Tests\TestCase;

class DispatchSaleTableStockTest extends TestCase
{
    protected static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$databasePath = database_path('dispatch_sale_table_stock.sqlite');
        if (!file_exists(self::$databasePath)) {
            touch(self::$databasePath);
        }
    }

    protected function setUp(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => self::$databasePath]);

        parent::setUp();

        Schema::dropIfExists('product_stocks');
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('quantity_non_tax', 15, 2)->default(0);
            $table->decimal('quantity_tax', 15, 2)->default(0);
            $table->decimal('broken_quantity_non_tax', 15, 2)->default(0);
            $table->decimal('broken_quantity_tax', 15, 2)->default(0);
            $table->decimal('broken_quantity', 15, 2)->default(0);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->decimal('sale_price', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_stocks');
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$databasePath)) {
            unlink(self::$databasePath);
        }
        parent::tearDownAfterClass();
    }

    /** @test */
    public function it_returns_tax_quantity_without_double_subtracting_broken_stock(): void
    {
        ProductStock::query()->delete();

        ProductStock::create([
            'product_id' => 1,
            'location_id' => 10,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 7,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 2,
            'broken_quantity' => 0,
            'tax_id' => 1,
            'sale_price' => 0,
        ]);

        $component = new class extends DispatchSaleTable {
            public function callGetStockForProduct($compositeKey, $locationId)
            {
                return $this->getStockForProduct($compositeKey, $locationId);
            }
        };

        $this->assertSame(7, $component->callGetStockForProduct('1-1', 10));
    }

    /** @test */
    public function it_returns_non_tax_quantity_without_double_subtracting_broken_stock(): void
    {
        ProductStock::query()->delete();

        ProductStock::create([
            'product_id' => 2,
            'location_id' => 20,
            'quantity' => 0,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
            'sale_price' => 0,
        ]);

        $component = new class extends DispatchSaleTable {
            public function callGetStockForProduct($compositeKey, $locationId)
            {
                return $this->getStockForProduct($compositeKey, $locationId);
            }
        };

        $this->assertSame(5, $component->callGetStockForProduct('2-0', 20));
    }
}

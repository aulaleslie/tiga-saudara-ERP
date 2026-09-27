<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductStockSnapshotImportTest extends TestCase
{
    use RefreshDatabase;

    private Setting $tigaNusa;
    private Setting $topIt;
    private Setting $perdana;
    private Location $tigaNusaLocation;
    private Location $topItLocation;
    private Location $perdanaLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn() => true);

        $this->tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $this->topIt = Setting::factory()->create(['company_name' => 'CV TOP IT INTERNUSA']);
        $this->perdana = Setting::factory()->create(['company_name' => 'PERDANA']);

        $this->tigaNusaLocation = Location::factory()->create(['setting_id' => $this->tigaNusa->id]);
        $this->topItLocation = Location::factory()->create(['setting_id' => $this->topIt->id]);
        $this->perdanaLocation = Location::factory()->create(['setting_id' => $this->perdana->id]);
    }

    public function test_it_detects_stock_snapshot_format_and_processes_successfully()
    {
        $csvContent = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
                      "SKU-001,* Laptop Acer,0,10,Pcs\n" .
                      "SKU-002,Mouse Logitech TP,0,-5,Pcs\n" .
                      "SKU-003,Keyboard Mechanical,0,0,Pcs\n";

        $file = UploadedFile::fake()->createWithContent('stock.csv', $csvContent);
        $response = $this->post(route('products.upload'), ['file' => $file]);

        $batch = ProductImportBatch::first();
        $response->assertRedirect(route('products.imports.show', $batch));

        $this->assertEquals('stock_snapshot', $batch->import_type);

        // Process
        (new ProcessProductImportBatch($batch->id))->handle();

        $batch->refresh();

        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(3, $batch->success_rows);
        $this->assertEquals(0, $batch->error_rows);

        // Assert products created and markers removed
        $product1 = Product::where('product_name', 'LAPTOP ACER')->first();
        $this->assertNotNull($product1);
        $this->assertEquals('SKU-001', $product1->product_code);

        $product2 = Product::where('product_name', 'MOUSE LOGITECH')->first();
        $this->assertNotNull($product2);

        $product3 = Product::where('product_name', 'KEYBOARD MECHANICAL')->first();
        $this->assertNotNull($product3);

        // Assert stocks
        $stock1 = ProductStock::where('product_id', $product1->id)->where('location_id', $this->tigaNusaLocation->id)->first();
        $this->assertEquals(10, $stock1->quantity);

        $stock2 = ProductStock::where('product_id', $product2->id)->where('location_id', $this->topItLocation->id)->first();
        $this->assertEquals(-5, $stock2->quantity);

        $stock3 = ProductStock::where('product_id', $product3->id)->where('location_id', $this->perdanaLocation->id)->first();
        $this->assertEquals(0, $stock3->quantity);
    }
}

<?php

namespace Tests\Feature\Livewire\SalesReturn;

use App\Livewire\SalesReturn\SaleSerialNumberLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Tests\TestCase;

class SaleSerialNumberLoaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutExceptionHandling();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
    }

    protected function createProduct(string $name = 'Test Product', string $code = 'TP01'): Product
    {
        return Product::create([
            'setting_id' => 1,
            'product_name' => $name,
            'product_code' => $code,
            'product_unit' => 'pc',
            'product_price' => 100000,
            'product_cost' => 50000,
            'product_stock_alert' => 10,
        ]);
    }

    protected function createSale(): Sale
    {
        return Sale::create([
            'setting_id' => 1,
            'date' => now(),
            'reference' => 'SL-' . uniqid(),
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
        ]);
    }

    protected function createDispatch(int $saleId): \Modules\Sale\Entities\Dispatch
    {
        return \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $saleId,
            'dispatch_date' => now(),
        ]);
    }

    protected function createDispatchDetail(int $dispatchId, int $saleId, int $productId): DispatchDetail
    {
        return DispatchDetail::create([
            'dispatch_id' => $dispatchId,
            'sale_id' => $saleId,
            'product_id' => $productId,
            'dispatched_quantity' => 5,
        ]);
    }

    /** @test */
    public function it_can_scan_a_valid_serial_number()
    {
        $product = $this->createProduct();
        $sale = $this->createSale();
        $dispatch = $this->createDispatch($sale->id);
        $dispatchDetail = $this->createDispatchDetail($dispatch->id, $sale->id, $product->id);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'serial_number' => 'SN123',
            'location_id' => 1,
        ]);

        Livewire::test(SaleSerialNumberLoader::class, [
            'index' => 0,
            'dispatchDetailId' => $dispatchDetail->id,
            'productId' => $product->id,
        ])
            ->set('query', 'SN123')
            ->call('addSerial')
            ->assertDispatched('serialNumberSelected', 0, [
                'id' => $serial->id,
                'serial_number' => 'SN123',
            ])
            ->assertSet('query', '')
            ->assertSet('error_message', '');
    }

    /** @test */
    public function it_rejects_serial_from_wrong_dispatch()
    {
        $product = $this->createProduct();
        $sale = $this->createSale();
        $dispatch1 = $this->createDispatch($sale->id);
        $dispatchDetail1 = $this->createDispatchDetail($dispatch1->id, $sale->id, $product->id);
        
        $dispatch2 = $this->createDispatch($sale->id);
        $dispatchDetail2 = $this->createDispatchDetail($dispatch2->id, $sale->id, $product->id);
        
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'dispatch_detail_id' => $dispatchDetail2->id,
            'serial_number' => 'SN123',
            'location_id' => 1,
        ]);

        Livewire::test(SaleSerialNumberLoader::class, [
            'index' => 0,
            'dispatchDetailId' => $dispatchDetail1->id,
            'productId' => $product->id,
        ])
            ->set('query', 'SN123')
            ->call('addSerial')
            ->assertNotDispatched('serialNumberSelected')
            ->assertSet('error_message', 'Nomor seri ini tidak berasal dari pengiriman ini.');
    }

    /** @test */
    public function it_rejects_duplicate_serial_in_same_row()
    {
        $product = $this->createProduct();
        $sale = $this->createSale();
        $dispatch = $this->createDispatch($sale->id);
        $dispatchDetail = $this->createDispatchDetail($dispatch->id, $sale->id, $product->id);
        
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'serial_number' => 'SN123',
            'location_id' => 1,
        ]);

        Livewire::test(SaleSerialNumberLoader::class, [
            'index' => 0,
            'dispatchDetailId' => $dispatchDetail->id,
            'productId' => $product->id,
            'existingSerials' => [
                ['id' => $serial->id, 'serial_number' => 'SN123']
            ],
        ])
            ->set('query', 'SN123')
            ->call('addSerial')
            ->assertNotDispatched('serialNumberSelected')
            ->assertSet('error_message', 'Nomor seri ini sudah ditambahkan.');
    }

    /** @test */
    public function it_rejects_reserved_serial()
    {
        $product = $this->createProduct();
        $sale = $this->createSale();
        $dispatch = $this->createDispatch($sale->id);
        $dispatchDetail = $this->createDispatchDetail($dispatch->id, $sale->id, $product->id);
        
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'serial_number' => 'SN123',
            'location_id' => 1,
        ]);

        // Create another sale return that reserves this serial
        $otherSaleReturn = SaleReturn::create([
            'date' => now(),
            'sale_id' => $sale->id,
            'customer_id' => 1,
            'customer_name' => 'C1',
            'setting_id' => 1,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Pending Approval',
            'approval_status' => 'pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Pending',
        ]);
        
        SaleReturnDetail::create([
            'sale_return_id' => $otherSaleReturn->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
        ]);

        Livewire::test(SaleSerialNumberLoader::class, [
            'index' => 0,
            'dispatchDetailId' => $dispatchDetail->id,
            'productId' => $product->id,
        ])
            ->set('query', 'SN123')
            ->call('addSerial')
            ->assertNotDispatched('serialNumberSelected')
            ->assertSet('error_message', 'Nomor seri ini sedang dalam proses retur lain.');
    }



}

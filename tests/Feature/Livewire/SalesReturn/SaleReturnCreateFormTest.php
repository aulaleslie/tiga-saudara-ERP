<?php

namespace Tests\Feature\Livewire\SalesReturn;

use App\Livewire\SalesReturn\SaleReturnCreateForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReturnCreateFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutExceptionHandling();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        
        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => 1]);
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
            'customer_id' => 1,
        ]);
    }

    protected function createDispatch(int $saleId): Dispatch
    {
        return Dispatch::create([
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
    public function it_rejects_submission_with_all_zero_quantities()
    {
        $product = $this->createProduct();
        $sale = $this->createSale();
        $dispatch = $this->createDispatch($sale->id);
        $dispatchDetail = $this->createDispatchDetail($dispatch->id, $sale->id, $product->id);

        Livewire::test(SaleReturnCreateForm::class)
            ->call('handleSaleSelected', [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer_name' => $sale->customer_name,
                'rows' => [
                    [
                        'dispatch_detail_id' => $dispatchDetail->id,
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'quantity' => 0,
                        'unit_price' => 1000,
                        'total' => 0,
                        'location_id' => 1,
                    ]
                ]
            ])
            ->set('note', 'Test Note')
            ->call('submit')
            ->assertHasErrors(['rows'])
            ->assertDispatched('updateTableErrors');
        
        $this->assertEquals(0, SaleReturn::count());
    }

    /** @test */
    public function it_accepts_submission_with_at_least_one_positive_quantity()
    {
        $product = $this->createProduct();
        $sale = $this->createSale();
        $dispatch = $this->createDispatch($sale->id);
        $dispatchDetail = $this->createDispatchDetail($dispatch->id, $sale->id, $product->id);

        Livewire::test(SaleReturnCreateForm::class)
            ->call('handleSaleSelected', [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer_name' => $sale->customer_name,
                'rows' => [
                    [
                        'dispatch_detail_id' => $dispatchDetail->id,
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'quantity' => 1,
                        'unit_price' => 1000,
                        'total' => 1000,
                        'location_id' => 1,
                    ]
                ]
            ])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('sale-returns.index'));
        
        $this->assertEquals(1, SaleReturn::count());
    }

    /** @test */
    public function it_filters_out_zero_quantity_rows_on_submission()
    {
        $product1 = $this->createProduct('P1', 'C1');
        $product2 = $this->createProduct('P2', 'C2');
        $sale = $this->createSale();
        $dispatch = $this->createDispatch($sale->id);
        $dd1 = $this->createDispatchDetail($dispatch->id, $sale->id, $product1->id);
        $dd2 = $this->createDispatchDetail($dispatch->id, $sale->id, $product2->id);

        Livewire::test(SaleReturnCreateForm::class)
            ->call('handleSaleSelected', [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer_name' => $sale->customer_name,
                'rows' => [
                    [
                        'dispatch_detail_id' => $dd1->id,
                        'product_id' => $product1->id,
                        'product_name' => $product1->product_name,
                        'product_code' => $product1->product_code,
                        'quantity' => 0,
                        'unit_price' => 1000,
                        'total' => 0,
                        'location_id' => 1,
                    ],
                    [
                        'dispatch_detail_id' => $dd2->id,
                        'product_id' => $product2->id,
                        'product_name' => $product2->product_name,
                        'product_code' => $product2->product_code,
                        'quantity' => 2,
                        'unit_price' => 500,
                        'total' => 1000,
                        'location_id' => 1,
                    ]
                ]
            ])
            ->call('submit')
            ->assertHasNoErrors();
        
        $this->assertEquals(1, SaleReturn::count());
        $this->assertEquals(1, \Modules\SalesReturn\Entities\SaleReturnDetail::count());
        $this->assertEquals($product2->id, \Modules\SalesReturn\Entities\SaleReturnDetail::first()->product_id);
    }
}

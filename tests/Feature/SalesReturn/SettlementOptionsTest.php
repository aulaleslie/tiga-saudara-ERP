<?php

namespace Tests\Feature\SalesReturn;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

class SettlementOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

        Product::create([
            'id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'pc',
            'product_stock_alert' => 1,
            'setting_id' => 1,
        ]);

        Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);

        session(['setting_id' => 1]);
        Gate::define('saleReturns.edit', fn() => true);
    }

    protected function createSaleReturn(): SaleReturn
    {
        $saleReturn = SaleReturn::create([
            'setting_id' => 1,
            'reference' => 'SR-001',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'status' => 'Awaiting Settlement',
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => 1,
        ]);

        return $saleReturn->refresh();
    }

    /** @test */
    public function it_requires_notes_for_unprocessed_settlement()
    {
        $saleReturn = $this->createSaleReturn();

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_UNPROCESSED)
            ->call('submitLine', 0)
            ->assertHasErrors(['settlementLines.0.notes']);
    }

    /** @test */
    public function it_can_settle_with_cash_refund()
    {
        Storage::fake('public');
        $saleReturn = $this->createSaleReturn();
        $file = UploadedFile::fake()->image('proof.jpg');

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_CASH_REFUND)
            ->set('settlementLines.0.proof_file', $file)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sale_return_item_settlements', [
            'sale_return_id' => $saleReturn->id,
            'method' => SaleReturnDetail::METHOD_CASH_REFUND,
            'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);
    }

    /** @test */
    public function it_can_settle_with_repair()
    {
        $saleReturn = $this->createSaleReturn();

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_PRODUCT_REPAIR)
            ->set('settlementLines.0.location_id', 1)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sale_return_item_settlements', [
            'sale_return_id' => $saleReturn->id,
            'method' => SaleReturnDetail::METHOD_PRODUCT_REPAIR,
            'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);
    }

    /** @test */
    public function it_can_settle_with_unprocessed()
    {
        $saleReturn = $this->createSaleReturn();

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_UNPROCESSED)
            ->set('settlementLines.0.notes', 'Cannot process')
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sale_return_item_settlements', [
            'sale_return_id' => $saleReturn->id,
            'method' => SaleReturnDetail::METHOD_UNPROCESSED,
            'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);
    }
}




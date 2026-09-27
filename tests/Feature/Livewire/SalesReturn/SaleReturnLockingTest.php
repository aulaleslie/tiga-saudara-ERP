<?php

namespace Tests\Feature\Livewire\SalesReturn;

use App\Livewire\SalesReturn\SaleReturnEditForm;
use App\Livewire\SalesReturn\SaleReturnTable;
use App\Livewire\SalesReturn\SaleSerialNumberLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReturnLockingTest extends TestCase
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

        session(['setting_id' => 1]);
    }

    protected function createProduct(bool $withSerial = false): Product
    {
        return Product::create([
            'setting_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_unit' => 'pc',
            'product_price' => 1000,
            'serial_number_required' => $withSerial,
        ]);
    }

    protected function createSaleReturn(string $approvalStatus = 'pending'): SaleReturn
    {
        return SaleReturn::create([
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
            'approval_status' => $approvalStatus,
            'status' => $approvalStatus === 'approved' ? 'Awaiting Receive' : 'Pending Approval',
        ]);
    }

    /** @test */
    public function it_prevents_modifications_in_sale_return_table_when_locked()
    {
        $rows = [
            [
                'product_id' => 1,
                'product_name' => 'Test Product',
                'quantity' => 1,
                'available_quantity' => 10,
                'unit_price' => 1000,
                'total' => 1000,
                'dispatch_detail_id' => 1,
                'serial_number_required' => false,
                'serial_numbers' => [],
            ]
        ];

        Livewire::test(SaleReturnTable::class, ['rows' => $rows, 'approvalLocked' => true])
            ->call('updateQuantity', 0, 10)
            ->assertSet('rows.0.quantity', 1) // Should remain 1 because of early return
            ->call('removeRow', 0)
            ->assertCount('rows', 1);
    }

    /** @test */
    public function it_prevents_serial_modifications_in_sale_return_table_when_locked()
    {
        $rows = [
            [
                'product_id' => 1,
                'product_name' => 'Test Product',
                'serial_number_required' => true,
                'serial_numbers' => [['id' => 1, 'serial_number' => 'SN123']],
                'quantity' => 1,
                'unit_price' => 1000,
                'total' => 1000,
                'dispatch_detail_id' => 1,
            ]
        ];

        Livewire::test(SaleReturnTable::class, ['rows' => $rows, 'approvalLocked' => true])
            ->call('updateSerialNumberRow', 0, ['id' => 2, 'serial_number' => 'SN456'])
            ->assertCount('rows.0.serial_numbers', 1) 
            ->call('removeSerialNumber', 0, 0)
            ->assertCount('rows.0.serial_numbers', 1);
    }

    /** @test */
    public function it_prevents_adding_serials_in_loader_when_locked()
    {
        Livewire::test(SaleSerialNumberLoader::class, ['index' => 0, 'approvalLocked' => true])
            ->set('query', 'SN123')
            ->call('addSerial')
            ->assertNotDispatched('serialNumberSelected');
            
        Livewire::test(SaleSerialNumberLoader::class, ['index' => 0, 'approvalLocked' => true])
            ->call('selectSerial', 1)
            ->assertNotDispatched('serialNumberSelected');
    }

    /** @test */
    public function it_blocks_edit_form_ui_when_locked()
    {
        $saleReturn = $this->createSaleReturn('approved');

        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $saleReturn])
            ->assertSet('approvalLocked', true)
            ->assertSee('Retur ini telah disetujui. Data tidak dapat diubah.');
    }
}

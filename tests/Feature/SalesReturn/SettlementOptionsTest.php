<?php

namespace Tests\Feature\SalesReturn;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
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

        session(['setting_id' => 1]);
        Gate::define('saleReturns.edit', fn() => true);
    }

    protected function createSaleReturn(): SaleReturn
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
            'approval_status' => 'approved',
            'status' => 'Awaiting Settlement',
        ]);
    }

    /** @test */
    public function it_requires_return_type_and_cash_proof_for_cash_refund()
    {
        $saleReturn = $this->createSaleReturn();

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('return_type', 'cash_refund')
            ->call('submit')
            ->assertHasErrors(['cash_proof']);
    }

    /** @test */
    public function it_can_settle_with_cash_refund()
    {
        Storage::fake('public');
        $saleReturn = $this->createSaleReturn();
        $file = UploadedFile::fake()->image('proof.jpg');

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('return_type', 'cash_refund')
            ->set('cash_proof', $file)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('sale-returns.show', $saleReturn->id));

        $saleReturn->refresh();
        $this->assertEquals('CASH_REFUND', $saleReturn->return_type);
        $this->assertEquals('PAID', $saleReturn->payment_status);
        $this->assertEquals('COMPLETED', $saleReturn->status);
        $this->assertEquals(1000, $saleReturn->paid_amount);
        $this->assertEquals(0, $saleReturn->due_amount);
        $this->assertNotNull($saleReturn->cash_proof_path);
        
        $this->assertDatabaseHas('sale_return_payments', [
            'sale_return_id' => $saleReturn->id,
            'amount' => 100000,
            'payment_method' => 'CASH REFUND'
        ]);
    }

    /** @test */
    public function it_can_settle_with_repair()
    {
        $saleReturn = $this->createSaleReturn();

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('return_type', 'repair')
            ->call('submit')
            ->assertHasNoErrors();

        $saleReturn->refresh();
        $this->assertEquals('REPAIR', $saleReturn->return_type);
        $this->assertEquals('COMPLETED', $saleReturn->status);
        $this->assertEquals('REPAIR', $saleReturn->payment_method);
    }

    /** @test */
    public function it_can_settle_with_unprocessed()
    {
        $saleReturn = $this->createSaleReturn();

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('return_type', 'unprocessed')
            ->call('submit')
            ->assertHasNoErrors();

        $saleReturn->refresh();
        $this->assertEquals('UNPROCESSED', $saleReturn->return_type);
        $this->assertEquals('COMPLETED', $saleReturn->status);
        $this->assertEquals('UNPROCESSED', $saleReturn->payment_method);
    }

    /** @test */
    public function it_rejects_legacy_settlement_options()
    {
        $saleReturn = $this->createSaleReturn();

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('return_type', 'replacement')
            ->call('submit')
            ->assertHasErrors(['return_type' => 'in']);

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $saleReturn->id])
            ->set('return_type', 'credit')
            ->call('submit')
            ->assertHasErrors(['return_type' => 'in']);
    }
}

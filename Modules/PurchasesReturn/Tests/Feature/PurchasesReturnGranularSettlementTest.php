<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchasesReturnGranularSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'footer',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);
    }

    /** @test */
    public function it_can_load_settlement_lines_for_serial_and_non_serial_products()
    {
        $serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Serial Product',
            'product_code' => 'P001',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => true,
        ]);
        
        $nonSerialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Non-Serial Product',
            'product_code' => 'P002',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false,
        ]);

        // 2. Create SNs
        $sn1 = ProductSerialNumber::create(['product_id' => $serialProduct->id, 'serial_number' => 'SN001', 'location_id' => $this->location->id]);
        $sn2 = ProductSerialNumber::create(['product_id' => $serialProduct->id, 'serial_number' => 'SN002', 'location_id' => $this->location->id]);

        // 3. Create Purchase Return
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17',
            'reference' => 'PRRN-TEST-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Test Supplier',
            'setting_id' => $this->setting->id,
            'total_amount' => 3000,
            'paid_amount' => 0,
            'due_amount' => 3000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-001')->first();

        // Detail for Serial Product (2 units)
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $serialProduct->id,
            'product_name' => $serialProduct->product_name,
            'product_code' => $serialProduct->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$sn1->id, $sn2->id],
            'location_id' => $this->location->id,
        ]);

        // Detail for Non-Serial Product (1 unit)
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $nonSerialProduct->id,
            'product_name' => $nonSerialProduct->product_name,
            'product_code' => $nonSerialProduct->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('purchaseReturnId', $purchaseReturn->id)
            ->assertCount('settlementLines', 3) // 2 for serials, 1 for non-serial
            ->assertSet('settlementLines.0.serial_number', 'SN001')
            ->assertSet('settlementLines.1.serial_number', 'SN002')
            ->assertSet('settlementLines.2.product_name', 'NON-SERIAL PRODUCT');
    }

    /** @test */
    public function it_persists_granular_settlement_information()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'P999',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false
        ]);
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17',
            'reference' => 'PRRN-TEST-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Test Supplier',
            'setting_id' => $this->setting->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-002')->first();

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_PRODUCT_REPAIR)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchase-returns.show', $purchaseReturn->id));

        $this->assertDatabaseHas('purchase_return_item_settlements', [
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => PurchaseReturnDetail::METHOD_PRODUCT_REPAIR,
        ]);

        $this->assertDatabaseHas('purchase_return_settlements', [
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'MIXED',
            'status' => 'SUBMITTED',
        ]);
    }

    /** @test */
    public function it_allows_draft_save_with_pending_lines()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'P888',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false
        ]);
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17',
            'reference' => 'PRRN-TEST-003',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Test Supplier',
            'setting_id' => $this->setting->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-003')->first();

        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', '') // Empty method for draft
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchase-returns.show', $purchaseReturn->id));

        $this->assertDatabaseHas('purchase_return_item_settlements', [
            'purchase_return_id' => $purchaseReturn->id,
            'method' => null,
            'status' => 'DRAFT',
        ]);
    }

    /** @test */
    public function it_requires_method_when_submitting_line()
    {
        $product = Product::create(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => 'T1', 'product_cost' => 10, 'product_price' => 20, 'serial_number_required' => false]);
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17', 'reference' => 'PRRN-TEST-004', 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Test Supplier', 'setting_id' => $this->setting->id, 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 100, 'status' => 'Pending', 'payment_status' => 'Unpaid', 'payment_method' => 'Cash', 'approval_status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-004')->first();
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', '') // Empty
            ->call('submitLine', 0)
            ->assertHasErrors(['settlementLines.0.method' => 'required']);
    }

    /** @test */
    public function it_validates_nominal_against_max_on_line_submit()
    {
        $product = Product::create(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => 'T1', 'product_cost' => 10, 'product_price' => 20, 'serial_number_required' => false]);
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17', 'reference' => 'PRRN-TEST-005', 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Test Supplier', 'setting_id' => $this->setting->id, 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 100, 'status' => 'Pending', 'payment_status' => 'Unpaid', 'payment_method' => 'Cash', 'approval_status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-005')->first();
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_CASH)
            ->set('settlementLines.0.nominal', 150) // More than 100
            ->call('submitLine', 0)
            ->assertHasErrors(['settlementLines.0.nominal' => 'max']);
    }

    /** @test */
    public function it_updates_status_to_submitted_on_line_submit()
    {
        $product = Product::create(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => 'T1', 'product_cost' => 10, 'product_price' => 20, 'serial_number_required' => false]);
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17', 'reference' => 'PRRN-TEST-006', 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Test Supplier', 'setting_id' => $this->setting->id, 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 100, 'status' => 'Pending', 'payment_status' => 'Unpaid', 'payment_method' => 'Cash', 'approval_status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-006')->first();
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_PRODUCT_REPAIR)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_return_item_settlements', [
            'purchase_return_id' => $purchaseReturn->id,
            'status' => 'SUBMITTED',
        ]);
    }

    /** @test */
    public function it_locks_submitted_and_approved_lines()
    {
        $product = Product::create(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => 'T1', 'product_cost' => 10, 'product_price' => 20, 'serial_number_required' => false]);
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17', 'reference' => 'PRRN-TEST-007', 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Test Supplier', 'setting_id' => $this->setting->id, 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 100, 'status' => 'Pending', 'payment_status' => 'Unpaid', 'payment_method' => 'Cash', 'approval_status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-007')->first();
        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id
        ]);

        // 1. Create a submitted settlement line
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => PurchaseReturnDetail::METHOD_CASH,
            'nominal' => 100,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.status', 'SUBMITTED')
            // The view should be tested manually for read-only, 
            // but we can check if the status is correct which triggers read-only in blade.
            ->assertSeeHtml('badge-soft-info') // Submitted badge
            ->assertDontSeeHtml('button wire:click="submitLine(0)"'); // Submit button should be hidden
    }

    /** @test */
    public function it_can_reset_rejected_line()
    {
        $product = Product::create(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => 'T1', 'product_cost' => 10, 'product_price' => 20, 'serial_number_required' => false]);
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => '2026-01-17', 'reference' => 'PRRN-TEST-008', 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Test Supplier', 'setting_id' => $this->setting->id, 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 100, 'status' => 'Pending', 'payment_status' => 'Unpaid', 'payment_method' => 'Cash', 'approval_status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseReturn = PurchaseReturn::where('reference', 'PRRN-TEST-008')->first();
        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id
        ]);

        // 1. Create a rejected settlement line
        $settlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => PurchaseReturnDetail::METHOD_CASH,
            'nominal' => 100,
            'status' => PurchaseReturnItemSettlement::STATUS_REJECTED,
            'rejection_reason' => 'Wrong nominal',
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.status', 'REJECTED')
            ->assertSee('Ditolak:')
            ->assertSee('WRONG NOMINAL')
            ->call('resetLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_return_item_settlements', [
            'id' => $settlement->id,
            'status' => 'DRAFT',
            'rejection_reason' => null,
        ]);
    }
}

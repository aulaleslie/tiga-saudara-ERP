<?php

namespace Tests\Feature\Livewire\PurchaseReturn;

use App\Livewire\PurchaseReturn\PurchaseReturnCreateForm;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnCreateFormTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_handle_supplier_selection()
    {
        $setting = \Modules\Setting\Entities\Setting::factory()->create();
        $supplier = Supplier::factory()->create(['setting_id' => $setting->id]);

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        
        // Mock permission
        \Illuminate\Support\Facades\Gate::define('purchaseReturns.create', fn() => true);

        Livewire::test(PurchaseReturnCreateForm::class)
            ->call('handleSupplierSelected', supplier_id: $supplier->id)
            ->assertSet('supplier_id', $supplier->id)
            ->assertSet('supplierName', $supplier->supplier_name);
    }
}

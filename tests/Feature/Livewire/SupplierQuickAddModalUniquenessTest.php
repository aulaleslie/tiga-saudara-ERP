<?php

namespace Tests\Feature\Livewire;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use App\Livewire\Modules\People\Modals\SupplierQuickAddModal;

class SupplierQuickAddModalUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->currency = $currency;

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['suppliers.create'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    protected Currency $currency;

    public function test_supplier_quick_add_modal_rejects_duplicate_supplier_name(): void
    {
        $setting = $this->createSetting('Livewire Quick Add Test');
        $user = $this->createUserWithSupplierPermissions();

        // Create first supplier manually
        Supplier::create([
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_email' => 'contact1@example.com',
            'supplier_phone' => '081230000001',
            'address' => '',
            'city' => '',
            'country' => '',
            'setting_id' => $setting->id,
        ]);

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Attempt to create duplicate via Livewire component
        Livewire::test(SupplierQuickAddModal::class)
            ->set('supplier_name', 'PT MAJU')
            ->set('contact_name', 'Contact Two')
            ->set('supplier_phone', '081230000002')
            ->call('save')
            ->assertHasErrors(['supplier_name']);

        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());
    }

    public function test_supplier_quick_add_modal_accepts_distinct_name(): void
    {
        $setting = $this->createSetting('Livewire Distinct Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        Livewire::test(SupplierQuickAddModal::class)
            ->set('supplier_name', 'PT MAJU')
            ->set('contact_name', 'Contact One')
            ->set('supplier_phone', '081230000001')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());
    }

    private function createUserWithSupplierPermissions(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['suppliers.create']);
        return $user;
    }

    private function createSetting(string $name): Setting
    {
        return Setting::factory()->create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_address' => 'Test Address',
            'company_phone' => '081200000000',
        ]);
    }
}

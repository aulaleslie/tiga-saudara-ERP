<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\People\Entities\Supplier;
use App\Models\User;
use Spatie\Tags\Tag;

use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PurchaseReportHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();
        
        Permission::create(['name' => 'purchaseReports.access']);
        Role::create(['name' => 'Super Admin']);
        
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Super Admin');
        $this->user->givePermissionTo('purchaseReports.access');
        
        $superAdminRole = Role::where('name', 'Super Admin')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $superAdminRole->id]);
    }

    /** @test */
    public function it_can_render_the_purchase_report_page()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-report.index'));

        $response->assertStatus(200);
    }

    /** @test */
    public function it_filters_purchases_by_date_range()
    {
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier 1',
            'supplier_email' => 's1@test.com',
            'supplier_phone' => '1',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);

        Purchase::create([
            'date' => '2026-01-01',
            'due_date' => '2026-01-01',
            'setting_id' => $this->setting->id,
            'reference' => 'PR-001',
            'supplier_id' => $supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        Purchase::create([
            'date' => '2026-02-01',
            'due_date' => '2026-02-01',
            'setting_id' => $this->setting->id,
            'reference' => 'PR-002',
            'supplier_id' => $supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class, ['isGlobal' => false])
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-01-01')
            ->set('endDate', '2026-01-31')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 1;
            });
    }

    /** @test */
    public function it_enforces_setting_id_scope_in_non_global_mode()
    {
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier 1',
            'supplier_email' => 's1@test.com',
            'supplier_phone' => '1',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);

        $otherSetting = Setting::create([
            'company_name' => 'Other Company',
            'company_email' => 'other@example.com',
            'company_phone' => '987654321',
            'notification_email' => 'other-notify@example.com',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $otherSupplier = Supplier::create([
            'setting_id' => $otherSetting->id,
            'supplier_name' => 'Supplier 2',
            'supplier_email' => 's2@test.com',
            'supplier_phone' => '2',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);
        
        Purchase::create([
            'date' => '2026-01-01',
            'due_date' => '2026-01-01',
            'setting_id' => $this->setting->id,
            'reference' => 'PR-S1',
            'supplier_id' => $supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        Purchase::create([
            'date' => '2026-01-01',
            'due_date' => '2026-01-01',
            'setting_id' => $otherSetting->id,
            'reference' => 'PR-S2',
            'supplier_id' => $otherSupplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->every(fn($p) => $p->setting_id === $this->setting->id);
            });
    }

    /** @test */
    public function it_shows_empty_state_when_no_records_match()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2020-01-01')
            ->set('endDate', '2020-01-01')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 0;
            });
    }

    /** @test */
    public function it_only_triggers_supplier_lookup_after_min_chars()
    {
        Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '12345',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
        ]);

        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('supplierSearch', 'T')
            ->assertSet('supplierOptions', [])
            ->set('supplierSearch', 'Te')
            ->assertCount('supplierOptions', 1);
    }

    /** @test */
    public function it_rejects_end_date_before_start_date()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-02-01')
            ->set('endDate', '2026-01-01')
            ->call('applyFilters')
            ->assertHasErrors(['endDate']);
    }

    /** @test */
    public function it_rejects_invalid_status_value()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('status', 'INVALID_STATUS')
            ->call('applyFilters')
            ->assertHasErrors(['status']);
    }

    /** @test */
    public function it_rejects_invalid_payment_status_value()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('paymentStatus', 'INVALID_PAYMENT')
            ->call('applyFilters')
            ->assertHasErrors(['paymentStatus']);
    }

    /** @test */
    public function it_rejects_nonexistent_supplier()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('supplierIds', [99999])
            ->call('applyFilters')
            ->assertHasErrors(['supplierIds.*']);
    }

    /** @test */
    public function it_rejects_nonexistent_tag()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('tagIds', [99999])
            ->call('applyFilters')
            ->assertHasErrors(['tagIds.*']);
    }

    /** @test */
    public function it_only_triggers_tag_lookup_after_min_chars_and_respects_locale()
    {
        $tag = Tag::create(['name' => ['en' => 'Test Tag', 'id' => 'Tag Tes']]);
        
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('tagSearch', 'T')
            ->assertSet('tagOptions', [])
            ->set('tagSearch', 'Te')
            ->assertCount('tagOptions', 1)
            ->assertSet('tagOptions.0.id', $tag->id);
    }
}

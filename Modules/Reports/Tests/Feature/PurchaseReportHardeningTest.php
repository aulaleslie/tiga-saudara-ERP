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
        Role::create(['name' => 'Staff']);
        
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
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('purchaseReports.access');
        
        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
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
    public function it_shows_purchase_report_menu_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('home'));
        
        $response->assertStatus(200);
        $response->assertSee('Daftar Pembelian');
    }

    /** @test */
    public function it_hides_purchase_report_menu_for_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('home'));
        
        $response->assertStatus(200);
        $response->assertDontSee('Daftar Pembelian');
    }

    /** @test */
    public function it_renders_page_title_and_breadcrumb_as_daftar_pembelian()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-report.index'));

        $response->assertStatus(200);
        $response->assertSee('Daftar Pembelian');
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
            ->set('deliveryStatus', 'INVALID_STATUS')
            ->call('applyFilters')
            ->assertHasErrors(['deliveryStatus']);
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

    /** @test */
    public function it_defaults_start_and_end_date_to_today_for_non_global_report()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->assertSet('startDate', now()->format('Y-m-d'))
            ->assertSet('endDate', now()->format('Y-m-d'));
    }

    /** @test */
    public function it_updates_dates_when_period_preset_changes_without_auto_filtering()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('periodPreset', 'this_month')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->assertSet('filterTriggered', false);
    }

    /** @test */
    public function it_filters_by_date_basis_transaction_date_and_due_date()
    {
        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier 1',
            'supplier_email' => 's1@test.com',
            'supplier_phone' => '1',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);

        $date1 = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $supplier->id,
            'date' => now()->subDays(5)->format('Y-m-d'),
            'due_date' => now()->addDays(5)->format('Y-m-d'),
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_APPROVED,
            'reference' => 'PR-001',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Filter by transaction date (subdays(5))
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->subDays(6)->format('Y-m-d'))
            ->set('endDate', now()->subDays(4)->format('Y-m-d'))
            ->set('dateBasis', 'transaction_date')
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertViewHas('purchases', function($purchases) use ($date1) {
                return $purchases->contains('id', $date1->id);
            });

        // Filter by due date (adddays(5))
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->addDays(4)->format('Y-m-d'))
            ->set('endDate', now()->addDays(6)->format('Y-m-d'))
            ->set('dateBasis', 'due_date')
            ->call('applyFilters')
            ->assertViewHas('purchases', function($purchases) use ($date1) {
                return $purchases->contains('id', $date1->id);
            });
    }

    /** @test */
    public function it_defaults_transaction_type_to_purchase_invoice()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->assertSet('transactionType', 'purchase_invoice');
    }

    /** @test */
    public function it_filters_delivery_status_separately_from_payment_status()
    {
        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier 1',
            'supplier_email' => 's1@test.com',
            'supplier_phone' => '1',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $supplier->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED, // Delivery status
            'reference' => 'PR-002',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 500,
            'due_amount' => 500,
        ]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->set('deliveryStatus', \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED)
            ->set('paymentStatus', 'PARTIAL')
            ->call('applyFilters')
            ->assertViewHas('purchases', function($purchases) use ($purchase) {
                return $purchases->contains('id', $purchase->id);
            });
    }

    /** @test */
    public function it_filters_by_unpaid_payment_status()
    {
        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier 1',
            'supplier_email' => 's1@test.com',
            'supplier_phone' => '1',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $supplier->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
            'reference' => 'PR-003',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->set('paymentStatus', 'UNPAID')
            ->call('applyFilters')
            ->assertViewHas('purchases', function($purchases) use ($purchase) {
                return $purchases->contains('id', $purchase->id);
            });
    }

    /** @test */
    public function it_prevents_export_in_v1_scope()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportExcel')
            ->assertDispatched('alert', function ($eventName, $eventData) {
                return $eventName === 'alert' && 
                       isset($eventData[0]['message']) &&
                       str_contains($eventData[0]['message'], 'belum tersedia');
            });
    }
}

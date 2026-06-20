<?php

namespace Modules\Reports\Tests\Feature;

use App\Livewire\Reports\OperationalGeneralLedgerReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Maatwebsite\Excel\Facades\Excel;

class OperationalGeneralLedgerLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);
        
        $this->user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin']);
        $permission = Permission::firstOrCreate(['name' => 'reports.access']);
        $role->givePermissionTo($permission);
        $this->user->assignRole($role);
    }

    public function test_it_renders_buku_besar_component()
    {
        Livewire::actingAs($this->user)
            ->test(OperationalGeneralLedgerReport::class)
            ->assertStatus(200)
            ->assertViewIs('livewire.reports.operational-general-ledger-report')
            ->assertSee('Buku Besar')
            ->assertDontSee('Akun COA');
    }

    public function test_it_can_export_excel()
    {
        Excel::fake();

        Livewire::actingAs($this->user)
            ->test(OperationalGeneralLedgerReport::class)
            ->call('exportExcel');

        Excel::assertDownloaded('buku_besar_' . now()->format('d-m-Y') . '_sd_' . now()->format('d-m-Y') . '.xlsx');
    }
}

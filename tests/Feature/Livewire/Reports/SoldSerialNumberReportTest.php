<?php

namespace Tests\Feature\Livewire\Reports;

use App\Exports\SoldSerialNumberReportExport;
use App\Livewire\Reports\SoldSerialNumberReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class SoldSerialNumberReportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $settingA;
    private Setting $settingB;
    private Customer $customerA;
    private Customer $customerB;
    private Category $category;
    private Unit $unit;
    private Location $location;
    private User $authorizedUser;
    private User $unauthorizedUser;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'saleReports.access', 'guard_name' => 'web']);
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $staffRole = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);

        $this->settingA = $this->makeSetting(['company_name' => 'Business Alpha']);
        $this->settingB = $this->makeSetting(['company_name' => 'Business Beta']);
        $this->customerA = $this->makeCustomer($this->settingA);
        $this->customerB = $this->makeCustomer($this->settingB);
        $this->category = $this->makeCategory($this->settingA);
        $this->unit = $this->makeUnit($this->settingA);
        $this->location = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Main Warehouse',
        ]);

        $this->authorizedUser = User::factory()->create();
        $this->authorizedUser->givePermissionTo('saleReports.access');
        $this->authorizedUser->settings()->attach($this->settingA->id, ['role_id' => $staffRole->id]);

        $this->unauthorizedUser = User::factory()->create();
        $this->unauthorizedUser->settings()->attach($this->settingA->id, ['role_id' => $staffRole->id]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole($superAdminRole);

        session(['setting_id' => $this->settingA->id]);
    }

    public function test_route_and_livewire_enforce_sale_reports_access_and_super_admin_bypass(): void
    {
        $this->actingAs($this->unauthorizedUser)
            ->get(route('reports.sold-serial-number.index'))
            ->assertForbidden();

        $this->actingAs($this->unauthorizedUser);
        Livewire::test(SoldSerialNumberReport::class)
            ->assertForbidden();

        $this->actingAs($this->authorizedUser)
            ->get(route('reports.sold-serial-number.index'))
            ->assertOk()
            ->assertSee('Tanggal yang ditampilkan adalah tanggal penjualan asli');

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sold-serial-number.index'))
            ->assertOk();
    }

    public function test_global_tokenized_product_search_validation_and_scope_sanitization(): void
    {
        $productB = $this->makeProduct($this->settingB, $this->category, $this->unit, [
            'product_name' => 'Laptop Pro 14',
            'product_code' => 'LTP-999',
        ]);

        $saleA = $this->makeSale($this->settingA, $this->customerA, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-15']);
        $sdA = $this->makeSaleDetail($saleA, $productB);
        $dspA = Dispatch::create(['sale_id' => $saleA->id, 'status' => Dispatch::STATUS_APPROVED, 'approved_at' => now()]);
        DispatchDetail::create([
            'dispatch_id' => $dspA->id,
            'sale_id' => $saleA->id,
            'sale_detail_id' => $sdA->id,
            'product_id' => $productB->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['000123']),
        ]);

        // Sale in unassigned business B must be excluded even if tampered
        $saleB = $this->makeSale($this->settingB, $this->customerB, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-15']);
        $sdB = $this->makeSaleDetail($saleB, $productB);
        $dspB = Dispatch::create(['sale_id' => $saleB->id, 'status' => Dispatch::STATUS_APPROVED, 'approved_at' => now()]);
        DispatchDetail::create([
            'dispatch_id' => $dspB->id,
            'sale_id' => $saleB->id,
            'sale_detail_id' => $sdB->id,
            'product_id' => $productB->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['SECRET-B']),
        ]);

        $this->actingAs($this->authorizedUser);

        Livewire::test(SoldSerialNumberReport::class)
            ->set('productSearch', '999 Laptop')
            ->assertSee('Laptop Pro 14')
            ->assertSee('LTP-999')
            ->set('startDate', '2026-10-20')
            ->set('endDate', '2026-10-10')
            ->call('generateReport')
            ->assertHasErrors(['productIds', 'endDate'])
            ->set('startDate', '2026-10-01')
            ->set('endDate', '2026-10-31')
            ->set('productIds', [$productB->id])
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('generateReport')
            ->assertHasNoErrors()
            ->assertSee('000123')
            ->assertDontSee('SECRET-B');
    }

    public function test_export_blocks_unapplied_and_edited_filters_and_writes_exact_five_columns(): void
    {
        $product = $this->makeProduct($this->settingA, $this->category, $this->unit, [
            'product_name' => 'Widget Z',
        ]);

        $sale = $this->makeSale($this->settingA, $this->customerA, Sale::STATUS_DISPATCHED, null, ['date' => '2026-11-17']);
        $sd = $this->makeSaleDetail($sale, $product);
        $dsp = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED, 'approved_at' => now()]);
        DispatchDetail::create([
            'dispatch_id' => $dsp->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $sd->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['000123']),
        ]);

        $this->actingAs($this->authorizedUser);
        Excel::fake();

        $component = Livewire::test(SoldSerialNumberReport::class)
            ->set('startDate', '2026-11-01')
            ->set('endDate', '2026-11-30')
            ->set('productIds', [$product->id]);

        // Unapplied export blocked
        $component->call('exportExcel')->assertHasErrors('export');

        // Apply filters, then edit without re-applying -> blocked
        $component->call('generateReport')
            ->set('endDate', '2026-11-29')
            ->call('exportExcel')
            ->assertHasErrors('export');

        // Re-apply and export
        $component->call('generateReport')
            ->call('exportExcel')
            ->assertHasNoErrors();

        Excel::assertDownloaded('sold-serial-number-report-2026-11-01-to-2026-11-29.xlsx');
    }

    public function test_xlsx_workbook_preserves_leading_zero_sn_text_and_numeric_date_parts(): void
    {
        $product = $this->makeProduct($this->settingA, $this->category, $this->unit, [
            'product_name' => 'Barcode Scanner',
        ]);

        $sale = $this->makeSale($this->settingA, $this->customerA, Sale::STATUS_DISPATCHED, null, ['date' => '2026-11-17']);
        $sd = $this->makeSaleDetail($sale, $product);
        $dsp = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED, 'approved_at' => now()]);
        DispatchDetail::create([
            'dispatch_id' => $dsp->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $sd->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['000123']),
        ]);

        $filter = new \App\Services\Reports\SoldSerialNumberReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            productIds: [$product->id],
            scopeSettingIds: [$this->settingA->id],
        );

        $raw = Excel::raw(new SoldSerialNumberReportExport($filter), \Maatwebsite\Excel\Excel::XLSX);
        $tmpFile = tempnam(sys_get_temp_dir(), 'ssn_test_') . '.xlsx';
        file_put_contents($tmpFile, $raw);

        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Product name', $sheet->getCell('A1')->getValue());
        $this->assertSame('SN', $sheet->getCell('B1')->getValue());
        $this->assertSame('Tgl', $sheet->getCell('C1')->getValue());
        $this->assertSame('Bulan', $sheet->getCell('D1')->getValue());
        $this->assertSame('Tahun', $sheet->getCell('E1')->getValue());

        $this->assertSame('Barcode Scanner', $sheet->getCell('A2')->getValue());
        $this->assertSame('000123', $sheet->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        $this->assertSame(17, (int) $sheet->getCell('C2')->getValue());
        $this->assertSame(11, (int) $sheet->getCell('D2')->getValue());
        $this->assertSame(2026, (int) $sheet->getCell('E2')->getValue());

        @unlink($tmpFile);
    }

    public function test_pending_business_and_product_changes_preserve_applied_results_until_generated(): void
    {
        $staffRole = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $this->authorizedUser->settings()->attach($this->settingB->id, ['role_id' => $staffRole->id]);

        $product = $this->makeProduct($this->settingA, $this->category, $this->unit, [
            'product_name' => 'Dual Biz Router',
        ]);

        $saleA = $this->makeSale($this->settingA, $this->customerA, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-10']);
        $sdA = $this->makeSaleDetail($saleA, $product);
        $dspA = Dispatch::create(['sale_id' => $saleA->id, 'status' => Dispatch::STATUS_APPROVED, 'approved_at' => now()]);
        DispatchDetail::create([
            'dispatch_id' => $dspA->id,
            'sale_id' => $saleA->id,
            'sale_detail_id' => $sdA->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['SN-BIZ-A']),
        ]);

        $saleB = $this->makeSale($this->settingB, $this->customerB, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-11']);
        $sdB = $this->makeSaleDetail($saleB, $product);
        $dspB = Dispatch::create(['sale_id' => $saleB->id, 'status' => Dispatch::STATUS_APPROVED, 'approved_at' => now()]);
        DispatchDetail::create([
            'dispatch_id' => $dspB->id,
            'sale_id' => $saleB->id,
            'sale_detail_id' => $sdB->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['SN-BIZ-B']),
        ]);

        $this->actingAs($this->authorizedUser);

        // Apply with business A only
        $component = Livewire::test(SoldSerialNumberReport::class)
            ->set('startDate', '2026-10-01')
            ->set('endDate', '2026-10-31')
            ->set('productIds', [$product->id])
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('generateReport')
            ->assertSee('SN-BIZ-A')
            ->assertDontSee('SN-BIZ-B');

        // Pending change to business A + B: previous results remain, pending banner appears
        $component->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->assertSee('Filter berubah. Klik Tampilkan Laporan untuk memperbarui hasil.')
            ->assertSee('SN-BIZ-A')
            ->assertDontSee('SN-BIZ-B');

        // Apply: now both businesses appear
        $component->call('generateReport')
            ->assertDontSee('Filter berubah. Klik Tampilkan Laporan untuk memperbarui hasil.')
            ->assertSee('SN-BIZ-A')
            ->assertSee('SN-BIZ-B');
    }
}

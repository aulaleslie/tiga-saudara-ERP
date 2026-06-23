<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Setting\Entities\Setting;
use Spatie\Tags\Tag;
use Livewire\Livewire;
use App\Livewire\Reports\ExpenseDetailsReport;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ExpenseDetailsReportExport;

class ExpenseDetailsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'purchaseReports.access']);
        Setting::factory()->create([
            'id' => 1,
            'company_name' => 'Test Company',
        ]);
        session(['setting_id' => 1]);
    }

    /** @test */
    public function unauthenticated_users_are_redirected_to_login()
    {
        $this->get(route('reports.expense-details.index'))
            ->assertRedirect(route('login'));
    }

    /** @test */
    public function unauthorized_users_are_forbidden()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('reports.expense-details.index'))
            ->assertForbidden();
    }

    /** @test */
    public function authorized_users_can_access_report()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        $this->actingAs($user)->get(route('reports.expense-details.index'))
            ->assertSuccessful()
            ->assertSeeLivewire(ExpenseDetailsReport::class);
    }

    /** @test */
    public function it_filters_expenses_correctly()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        $cat1 = ExpenseCategory::create(['category_name' => 'Cat 1']);
        $cat2 = ExpenseCategory::create(['category_name' => 'Cat 2']);

        $expense1 = Expense::create([
            'setting_id' => 1,
            'category_id' => $cat1->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 100000,
            'status' => Expense::STATUS_APPROVED,
        ]);

        $expense2 = Expense::create([
            'setting_id' => 1,
            'category_id' => $cat2->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 50000,
            'status' => Expense::STATUS_APPROVED,
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->set('startDate', Carbon::now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', Carbon::now()->endOfMonth()->format('Y-m-d'))
            ->set('categoryIds', [$cat1->id])
            ->call('applyFilters')
            ->assertSee('CAT 1')
            ->assertDontSee('CAT 2')
            ->assertSee('100,000.00')
            ->assertSee('Menampilkan total dari 1 baris transaksi');
    }

    /** @test */
    public function it_groups_by_category_and_calculates_subtotals()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        $cat1 = ExpenseCategory::create(['category_name' => 'Category Group A']);

        $expense1 = Expense::create([
            'setting_id' => 1,
            'category_id' => $cat1->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 100000,
            'status' => Expense::STATUS_APPROVED,
            'reference' => 'EXP-001',
        ]);
        $expense1->details = 'Ket EXP-001';
        $expense1->save();

        $expense2 = Expense::create([
            'setting_id' => 1,
            'category_id' => $cat1->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 150000,
            'status' => Expense::STATUS_APPROVED,
            'reference' => 'EXP-002',
        ]);
        $expense2->details = 'Ket EXP-002';
        $expense2->save();

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->set('startDate', Carbon::now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', Carbon::now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('CATEGORY GROUP A')
            ->assertSee('EXP-001')
            ->assertSee('100,000.00')
            ->assertSee('EXP-002')
            ->assertSee('150,000.00')
            ->assertSee('Total CATEGORY GROUP A')
            ->assertSee('250,000.00') // Subtotal
            ->assertSee('Grand Total')
            ->assertSee('250,000.00') // Grand Total
            ->assertSee('Menampilkan total dari 2 baris transaksi');
    }

    /** @test */
    public function category_subtotal_reflects_all_matching_rows_not_just_the_page()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        $cat = ExpenseCategory::create(['category_name' => 'Category Group B']);

        // Create 16 expenses (over the 15-per-page threshold) in a single category.
        for ($i = 1; $i <= 16; $i++) {
            Expense::create([
                'setting_id' => 1,
                'category_id' => $cat->id,
                'date' => Carbon::now()->format('Y-m-d'),
                'amount' => 10000,
                'status' => Expense::STATUS_APPROVED,
                'reference' => 'EXP-B-' . $i,
            ]);
        }

        // Subtotal MUST be the full category sum (16 * 10,000 = 160,000.00),
        // not a page subtotal, even though pagination is active.
        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->set('startDate', Carbon::now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', Carbon::now()->endOfMonth()->format('Y-m-d'))
            ->set('categoryIds', [$cat->id])
            ->call('applyFilters')
            ->assertSee('Total CATEGORY GROUP B')
            ->assertSee('160,000.00') // Full category subtotal == grand total
            ->assertSee('Menampilkan total dari 16 baris transaksi');
    }

    /** @test */
    public function empty_description_renders_blank_keterangan()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        $cat = ExpenseCategory::create(['category_name' => 'Cat Blank']);

        Expense::create([
            'setting_id' => 1,
            'category_id' => $cat->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 100000,
            'status' => Expense::STATUS_APPROVED,
            'reference' => 'EXP-NO-DETAIL',
            // details intentionally left null
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->set('startDate', Carbon::now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', Carbon::now()->endOfMonth()->format('Y-m-d'))
            ->set('categoryIds', [$cat->id])
            ->call('applyFilters')
            ->assertSee('EXP-NO-DETAIL')
            ->assertSeeHtml('<td class="text-wrap" style="max-width: 300px; word-break: break-word;"></td>');
    }

    /** @test */
    public function report_title_and_currency_note_show_on_initial_page_state()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        // Before any filter is applied, the visible heading and IDR note must render.
        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->assertSee('Rincian Biaya')
            ->assertSee('(dalam IDR)');
    }

    /** @test */
    public function it_blocks_export_when_filters_are_stale()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_exports_to_excel_after_applying_filters()
    {
        Excel::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->call('applyFilters')
            ->call('exportExcel');

        $startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = Carbon::now()->endOfMonth()->format('Y-m-d');
        Excel::assertDownloaded("expense_details_{$startDate}_{$endDate}.xlsx");
    }

    /** @test */
    public function it_exports_to_csv_after_applying_filters()
    {
        Excel::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->call('applyFilters')
            ->call('exportCsv');

        $startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = Carbon::now()->endOfMonth()->format('Y-m-d');
        Excel::assertDownloaded("expense_details_{$startDate}_{$endDate}.csv");
    }

    /** @test */
    public function it_exports_to_pdf_after_applying_filters()
    {
        Excel::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->call('applyFilters')
            ->call('exportPdf');

        $startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = Carbon::now()->endOfMonth()->format('Y-m-d');
        Excel::assertDownloaded("expense_details_{$startDate}_{$endDate}.pdf");
    }

    /** @test */
    public function categories_sharing_a_name_are_not_merged_into_one_group()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        // Two distinct categories with the same display name.
        $catA = ExpenseCategory::create(['category_name' => 'Operasional']);
        $catB = ExpenseCategory::create(['category_name' => 'Operasional']);

        Expense::create([
            'setting_id' => 1,
            'category_id' => $catA->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 100000,
            'status' => Expense::STATUS_APPROVED,
            'reference' => 'EXP-DUP-A',
        ]);
        Expense::create([
            'setting_id' => 1,
            'category_id' => $catB->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 50000,
            'status' => Expense::STATUS_APPROVED,
            'reference' => 'EXP-DUP-B',
        ]);

        // Build the query and group it. Two distinct category_ids must produce two
        // separate groups (subtotals 100,000 and 50,000), not one merged 150,000.
        $filter = \App\Services\Reports\ExpenseDetailsReportFilterData::fromArray([
            'startDate' => Carbon::now()->startOfMonth()->format('Y-m-d'),
            'endDate' => Carbon::now()->endOfMonth()->format('Y-m-d'),
            'categoryIds' => [],
            'tagIds' => [],
            'tagLogic' => 'Salah satu',
            'sortDirection' => 'asc',
        ]);
        $filter->scopeSettingId = 1;

        $service = app(\App\Services\Reports\ExpenseDetailsReportQueryService::class);
        $expenses = $service->buildSorted($filter)->get();
        $mapped = \App\Services\Reports\ExpenseDetailsReportQueryService::groupAndSummarize($expenses);

        $this->assertCount(2, $mapped['groups']);
        $subtotals = collect($mapped['groups'])->pluck('subtotal')->sort()->values()->all();
        $this->assertEquals([50000.0, 100000.0], $subtotals);
        $this->assertEquals(150000.0, $mapped['grandTotal']);
        $this->assertEquals(2, $mapped['transactionCount']);
    }

    /** @test */
    public function it_filters_by_tag()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        $cat1 = ExpenseCategory::create(['category_name' => 'Cat 1']);

        $tag1 = Tag::create(['name' => 'Tag 1']);

        $expense1 = Expense::create([
            'setting_id' => 1,
            'category_id' => $cat1->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 100000,
            'status' => Expense::STATUS_APPROVED,
            'reference' => 'EXP-TAG-1'
        ]);
        $expense1->attachTag('Tag 1');

        $expense2 = Expense::create([
            'setting_id' => 1,
            'category_id' => $cat1->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => 50000,
            'status' => Expense::STATUS_APPROVED,
            'reference' => 'EXP-TAG-2'
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseDetailsReport::class)
            ->set('startDate', Carbon::now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', Carbon::now()->endOfMonth()->format('Y-m-d'))
            ->set('tagIds', [$tag1->id])
            ->call('applyFilters')
            ->assertSee('EXP-TAG-1')
            ->assertDontSee('EXP-TAG-2');
    }
}

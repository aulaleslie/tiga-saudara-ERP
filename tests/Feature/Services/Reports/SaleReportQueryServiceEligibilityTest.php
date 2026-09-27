<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\SaleReportFilterData;
use App\Services\Reports\SaleReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class SaleReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Customer $customer;
    private Category $category;
    private Unit $unit;
    private SaleReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->customer = $this->makeCustomer($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->service = new SaleReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): SaleReportFilterData
    {
        return SaleReportFilterData::fromArray(array_merge([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'reportMode' => 'header',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_default_eligibility_only_includes_dispatched_and_partially_returned()
    {
        $dispatched = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED);
        $partiallyReturned = $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED_PARTIALLY);
        $partiallyDispatched = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY);
        $drafted = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DRAFTED);

        $results = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($dispatched->id, $results);
        $this->assertContains($partiallyReturned->id, $results);
        $this->assertNotContains($partiallyDispatched->id, $results);
        $this->assertNotContains($drafted->id, $results);
    }

    public function test_unfinished_full_return_is_included_and_archived_is_excluded()
    {
        $unfinishedReturn = $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED);
        $completedReturn = $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED, Carbon::parse('2026-01-20'));

        $results = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($unfinishedReturn->id, $results);
        $this->assertNotContains($completedReturn->id, $results);
    }

    public function test_document_status_filter_narrows_within_eligible_set()
    {
        $dispatched = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED);
        $partiallyReturned = $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED_PARTIALLY);

        $results = $this->service->build($this->baseFilter([
            'documentStatuses' => [Sale::STATUS_DISPATCHED],
        ]))->pluck('id')->all();

        $this->assertContains($dispatched->id, $results);
        $this->assertNotContains($partiallyReturned->id, $results);
    }

    public function test_totals_match_eligible_population_only()
    {
        $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, ['total_amount' => 500, 'due_amount' => 500]);
        $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED_PARTIALLY, null, ['total_amount' => 300, 'due_amount' => 300]);
        $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY, null, ['total_amount' => 9999, 'due_amount' => 9999]);

        $rows = $this->service->build($this->baseFilter())->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([500.0, 300.0], $rows->pluck('total_amount')->map(fn($v) => (float) $v)->all());
    }

    public function test_detail_mode_ties_eligibility_to_sale_details_row()
    {
        $dispatched = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED);
        $partiallyDispatched = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY);

        $product = $this->makeProduct($this->setting, $this->category, $this->unit);

        $eligibleDetail = $this->makeSaleDetail($dispatched, $product);
        $ineligibleDetail = $this->makeSaleDetail($partiallyDispatched, $product);

        $results = $this->service->build($this->baseFilter(['reportMode' => 'detail']))->pluck('id')->all();

        $this->assertContains($eligibleDetail->id, $results);
        $this->assertNotContains($ineligibleDetail->id, $results);
    }

    public function test_validator_rejects_ineligible_document_statuses()
    {
        $validator = new \App\Services\Reports\SaleReportValidator();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $validator->validate([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'documentStatuses' => [Sale::STATUS_DISPATCHED_PARTIALLY],
        ]);
    }

    public function test_validator_accepts_eligible_document_statuses()
    {
        $validator = new \App\Services\Reports\SaleReportValidator();

        $validated = $validator->validate([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'documentStatuses' => [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY],
        ]);

        $this->assertEquals(
            [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY],
            $validated['documentStatuses']
        );
    }
}

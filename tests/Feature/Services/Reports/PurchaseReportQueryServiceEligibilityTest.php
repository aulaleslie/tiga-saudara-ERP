<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\PurchaseReportFilterData;
use App\Services\Reports\PurchaseReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class PurchaseReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Supplier $supplier;
    private Category $category;
    private Unit $unit;
    private PurchaseReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->supplier = $this->makeSupplier($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->service = new PurchaseReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): PurchaseReportFilterData
    {
        return PurchaseReportFilterData::fromArray(array_merge([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'reportMode' => 'header',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_default_eligibility_only_includes_received_and_partially_returned()
    {
        $received = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED);
        $partiallyReturned = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED_PARTIALLY);
        $partiallyReceived = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY);
        $drafted = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_DRAFTED);

        $results = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($received->id, $results);
        $this->assertContains($partiallyReturned->id, $results);
        $this->assertNotContains($partiallyReceived->id, $results);
        $this->assertNotContains($drafted->id, $results);
    }

    public function test_unfinished_full_return_is_included_and_archived_is_excluded()
    {
        $unfinishedReturn = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED);
        $completedReturn = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED, Carbon::parse('2026-01-20'));

        $results = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($unfinishedReturn->id, $results);
        $this->assertNotContains($completedReturn->id, $results);
    }

    public function test_document_status_filter_narrows_within_eligible_set()
    {
        $received = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED);
        $partiallyReturned = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED_PARTIALLY);

        $results = $this->service->build($this->baseFilter([
            'documentStatuses' => [Purchase::STATUS_RECEIVED],
        ]))->pluck('id')->all();

        $this->assertContains($received->id, $results);
        $this->assertNotContains($partiallyReturned->id, $results);
    }

    public function test_totals_match_eligible_population_only()
    {
        $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED, null, ['total_amount' => 500, 'due_amount' => 500]);
        $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED_PARTIALLY, null, ['total_amount' => 300, 'due_amount' => 300]);
        $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY, null, ['total_amount' => 9999, 'due_amount' => 9999]);

        $rows = $this->service->build($this->baseFilter())->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([500.0, 300.0], $rows->pluck('total_amount')->map(fn($v) => (float) $v)->all());
    }

    public function test_detail_mode_ties_eligibility_to_purchase_detail_row()
    {
        $received = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED);
        $partiallyReceived = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY);

        $product = $this->makeProduct($this->setting, $this->category, $this->unit);

        $eligibleDetail = $this->makePurchaseDetail($received, $product);
        $ineligibleDetail = $this->makePurchaseDetail($partiallyReceived, $product);

        $results = $this->service->build($this->baseFilter(['reportMode' => 'detail']))->pluck('id')->all();

        $this->assertContains($eligibleDetail->id, $results);
        $this->assertNotContains($ineligibleDetail->id, $results);
    }

    public function test_validator_rejects_ineligible_document_statuses()
    {
        $validator = new \App\Services\Reports\PurchaseReportValidator();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $validator->validate([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'documentStatuses' => [Purchase::STATUS_RECEIVED_PARTIALLY],
        ]);
    }

    public function test_validator_accepts_eligible_document_statuses()
    {
        $validator = new \App\Services\Reports\PurchaseReportValidator();

        $validated = $validator->validate([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'documentStatuses' => [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY],
        ]);

        $this->assertEquals(
            [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY],
            $validated['documentStatuses']
        );
    }
}

<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\SoldSerialNumberReportFilterData;
use App\Services\Reports\SoldSerialNumberReportValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SoldSerialNumberReportFilterAndValidatorTest extends TestCase
{
    public function test_validates_required_products_and_inclusive_date_range(): void
    {
        $validator = new SoldSerialNumberReportValidator();

        $validated = $validator->validate([
            'startDate' => '2026-10-15',
            'endDate' => '2026-10-15',
            'productIds' => [10, 20],
            'scopeSettingIds' => [1, 2],
        ]);

        $this->assertSame('2026-10-15', $validated['startDate']);
        $this->assertSame('2026-10-15', $validated['endDate']);
        $this->assertSame([10, 20], $validated['productIds']);
        $this->assertSame([1, 2], $validated['scopeSettingIds']);
    }

    public function test_rejects_missing_products_missing_dates_and_reversed_range(): void
    {
        $validator = new SoldSerialNumberReportValidator();

        try {
            $validator->validate([
                'startDate' => '',
                'endDate' => '',
                'productIds' => [],
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('startDate', $errors);
            $this->assertArrayHasKey('endDate', $errors);
            $this->assertArrayHasKey('productIds', $errors);
        }

        try {
            $validator->validate([
                'startDate' => '2026-10-20',
                'endDate' => '2026-10-10',
                'productIds' => [5],
            ]);
            $this->fail('Expected ValidationException for reversed range.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('endDate', $e->errors());
        }
    }

    public function test_sanitizes_tampered_business_ids_and_applies_empty_scope_fallback(): void
    {
        $validator = new SoldSerialNumberReportValidator();

        $this->assertSame(
            [2],
            $validator->sanitizeSettingIds([2, 999], [1, 2], [1])
        );

        $this->assertSame(
            [1],
            $validator->sanitizeSettingIds([999], [1, 2], [1])
        );

        $this->assertSame(
            [1],
            $validator->sanitizeSettingIds([], [1, 2], [1])
        );

        $this->assertSame(
            [],
            $validator->sanitizeSettingIds([1, 2], [], [1])
        );
    }

    public function test_filter_data_normalizes_arrays_and_hash(): void
    {
        $filterA = SoldSerialNumberReportFilterData::fromArray([
            'startDate' => '2026-10-01',
            'endDate' => '2026-10-31',
            'productIds' => ['20', 10, 10, 0],
            'scopeSettingIds' => [3, '1', 3],
        ]);

        $filterB = new SoldSerialNumberReportFilterData(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            productIds: [10, 20],
            scopeSettingIds: [1, 3],
        );

        $this->assertSame([10, 20], $filterA->productIds);
        $this->assertSame([1, 3], $filterA->scopeSettingIds);
        $this->assertSame($filterB->hash(), $filterA->hash());
    }
}

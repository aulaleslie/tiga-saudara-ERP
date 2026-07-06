<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ExpenseReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_expense_reference_uses_expense_date_not_created_at()
    {
        $setting = Setting::factory()->create(['document_prefix' => 'TS']);
        $category = ExpenseCategory::create(['category_name' => 'Test Category']);

        // Create an expense with a date in the past
        $pastDate = now()->subMonths(2);

        // Ensure created_at is the current time
        $expense = Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => $pastDate->format('Y-m-d'),
            'amount' => 1000,
            'details' => 'Test Expense',
            'status' => Expense::STATUS_APPROVED,
        ]);

        $year = $pastDate->year;
        $month = str_pad($pastDate->month, 2, '0', STR_PAD_LEFT);

        $this->assertEquals("TS-EXP-{$year}-{$month}-00001", $expense->reference);
    }

    public function test_expense_reference_sequence_lookup_uses_expense_date()
    {
        $setting = Setting::factory()->create(['document_prefix' => 'TS']);
        $category = ExpenseCategory::create(['category_name' => 'Test Category']);

        $pastDate = now()->subMonths(2);
        $year = $pastDate->year;
        $month = str_pad($pastDate->month, 2, '0', STR_PAD_LEFT);

        // Create first expense in the past month
        Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => $pastDate->format('Y-m-d'),
            'amount' => 1000,
            'details' => 'First Expense',
            'status' => Expense::STATUS_APPROVED,
        ]);

        // Create second expense in the same past month
        $expense2 = Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => $pastDate->copy()->addDays(1)->format('Y-m-d'),
            'amount' => 2000,
            'details' => 'Second Expense',
            'status' => Expense::STATUS_APPROVED,
        ]);

        $this->assertEquals("TS-EXP-{$year}-{$month}-00002", $expense2->reference);
    }
}

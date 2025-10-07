<?php

namespace Tests\Feature;

use App\Livewire\Expense\ExpenseForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Expense\Entities\ExpenseDetail;
use Tests\TestCase;

class ExpenseFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_detail_without_tax_is_saved_with_null_tax_id(): void
    {
        $category = ExpenseCategory::create([
            'category_name' => 'Travel',
        ]);

        Livewire::test(ExpenseForm::class)
            ->set('category_id', $category->id)
            ->set('details', [
                [
                    'name' => 'Plane Ticket',
                    'tax_id' => '',
                    'amount' => '150000',
                ],
            ])
            ->call('save');

        $detail = ExpenseDetail::first();

        $this->assertNotNull($detail, 'Expense detail should be persisted.');
        $this->assertNull($detail->tax_id, 'Tax identifier should be stored as NULL when not provided.');
    }
}


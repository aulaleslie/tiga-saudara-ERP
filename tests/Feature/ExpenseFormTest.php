<?php

namespace Tests\Feature;

use App\Livewire\Expense\ExpenseForm;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Expense\Entities\ExpenseDetail;
use Modules\Setting\Entities\Tax;
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

    public function test_editing_expense_updates_rows_taxes_and_total(): void
    {
        Storage::fake('public');

        $category = ExpenseCategory::create([
            'category_name' => 'Travel',
        ]);

        $tax = Tax::create([
            'name' => 'VAT 10%',
            'value' => 10,
        ]);

        $expense = Expense::create([
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 110000,
        ]);

        $detailWithTax = $expense->details()->create([
            'name' => 'Initial Taxi',
            'tax_id' => $tax->id,
            'amount' => 50000,
        ]);

        $detailWithoutTax = $expense->details()->create([
            'name' => 'Initial Meal',
            'tax_id' => null,
            'amount' => 50000,
        ]);

        $existingAttachment = UploadedFile::fake()->create('old-receipt.pdf', 10, 'application/pdf');
        $expense->addMedia($existingAttachment)->toMediaCollection('attachments');
        $existingMediaId = $expense->getMedia('attachments')->first()->id;

        $newAttachment = UploadedFile::fake()->create('new-receipt.pdf', 12, 'application/pdf');

        Livewire::test(ExpenseForm::class, ['expense' => $expense->fresh('details', 'media')])
            ->set('details', [
                [
                    'id' => $detailWithTax->id,
                    'name' => 'Taxi Ride',
                    'tax_id' => $tax->id,
                    'amount' => '75000',
                ],
                [
                    'name' => 'Hotel Stay',
                    'tax_id' => null,
                    'amount' => '125000',
                ],
            ])
            ->set('files', [$newAttachment])
            ->call('removeExistingAttachment', $existingMediaId)
            ->call('save')
            ->assertRedirect(route('expenses.index'));

        $expense->refresh();

        $this->assertSame(2, $expense->details()->count());

        $this->assertDatabaseHas('expense_details', [
            'id' => $detailWithTax->id,
            'name' => 'Taxi Ride',
            'tax_id' => $tax->id,
            'amount' => 75000.00,
        ]);

        $this->assertDatabaseMissing('expense_details', [
            'id' => $detailWithoutTax->id,
        ]);

        $this->assertDatabaseHas('expense_details', [
            'expense_id' => $expense->id,
            'name' => 'Hotel Stay',
            'tax_id' => null,
            'amount' => 125000.00,
        ]);

        $this->assertEquals(207500.0, $expense->amount);

        $this->assertCount(1, $expense->getMedia('attachments'));
        $this->assertEquals('new-receipt.pdf', $expense->getMedia('attachments')->first()->file_name);
    }
}


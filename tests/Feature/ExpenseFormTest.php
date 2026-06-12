<?php

namespace Tests\Feature;

use App\Livewire\Expense\ExpenseForm;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
            ->set('date', now()->format('Y-m-d'))
            ->set('category_id', $category->id)
            ->set('details', [
                [
                    'name' => 'Plane Ticket',
                    'amount' => '150000',
                ],
            ])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $detail = ExpenseDetail::first();

        $this->assertNotNull($detail, 'Expense detail should be persisted.');
        $this->assertNull($detail->tax_id, 'Tax identifier should be stored as NULL when not provided.');
    }

    public function test_editing_expense_updates_rows_taxes_and_total(): void
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'test@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $setting->id]);

        Storage::fake('public');

        $category = ExpenseCategory::create([
            'category_name' => 'Travel',
        ]);

        $tax = Tax::create([
            'name' => 'VAT 10%',
            'value' => 10,
        ]);

        $expense = Expense::create([
            'setting_id' => $setting->id,
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

        $expenseForComponent = $expense->fresh();
        unset($expenseForComponent->details);
        $expenseForComponent->setRelation('details', $expenseForComponent->details()->get());
        $expenseForComponent->setRelation('media', $expenseForComponent->media()->get());

        Livewire::test(ExpenseForm::class, ['expense' => $expenseForComponent])
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
            ->call('saveDraft')
            ->assertRedirect(route('expenses.index'));

        $expense->refresh();

        $this->assertSame(2, $expense->details()->count());

        $this->assertDatabaseHas('expense_details', [
            'id' => $detailWithTax->id,
            'name' => 'TAXI RIDE',
            'tax_id' => $tax->id,
            'amount' => 75000.00,
        ]);

        $this->assertDatabaseMissing('expense_details', [
            'id' => $detailWithoutTax->id,
        ]);

        // Note: 'Hotel Stay' is new so it uses create() which DOES uppercase via BaseModel
        $this->assertDatabaseHas('expense_details', [
            'expense_id' => $expense->id,
            'name' => 'HOTEL STAY',
            'tax_id' => null,
            'amount' => 125000.00,
        ]);

        $this->assertEquals(207500.0, $expense->amount);

        $this->assertCount(1, $expense->getMedia('attachments'));
        $this->assertEquals('new-receipt.pdf', $expense->getMedia('attachments')->first()->file_name);
    }

    public function test_pkp_expense_with_default_tax_and_tax_included_calculates_correctly(): void
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'VAT 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'test@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $setting->id]);

        $category = ExpenseCategory::create([
            'category_name' => 'Office Supplies',
        ]);

        Livewire::test(ExpenseForm::class)
            ->set('date', now()->format('Y-m-d'))
            ->set('category_id', $category->id)
            ->set('is_tax_included', true)
            ->set('details', [
                [
                    'name' => 'Printer Ink',
                    'tax_id' => $tax->id,
                    'amount' => '100000',
                ],
            ])
            ->assertSet('totalBeforeTaxFormatted', 'Rp 90.090')
            ->assertSet('totalTaxFormatted', 'Rp 9.910')
            ->assertSet('totalFormatted', 'Rp 100.000')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertRedirect(route('expenses.index'));

        $expense = Expense::first();
        $this->assertNotNull($expense);

        // When tax is included, gross amount is just sum of details
        $this->assertEquals(100000.0, $expense->amount);
        $this->assertTrue($expense->is_tax_included);

        $detail = $expense->details()->first();
        $this->assertEquals('PRINTER INK', $detail->name);
        $this->assertEquals(100000.0, $detail->amount);
        $this->assertEquals($tax->id, $detail->tax_id);
    }

    public function test_non_pkp_expense_ignores_tax_included_payload(): void
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Non PKP Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'test@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);
        session(['setting_id' => $setting->id]);

        $category = ExpenseCategory::create([
            'category_name' => 'Office Supplies',
        ]);

        Livewire::test(ExpenseForm::class)
            ->set('date', now()->format('Y-m-d'))
            ->set('category_id', $category->id)
            ->set('is_tax_included', true) // Attempt to set tax included
            ->set('details', [
                [
                    'name' => 'Printer Ink',
                    'amount' => '100000',
                ],
            ])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $expense = Expense::first();
        $this->assertNotNull($expense);

        // Assert the normalization forces it to false since setting is not PKP
        $this->assertFalse((bool) $expense->is_tax_included);
    }
}

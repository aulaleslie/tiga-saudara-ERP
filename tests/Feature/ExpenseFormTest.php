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

        $detailWithTax = $expense->detailRows()->create([
            'name' => 'Initial Taxi',
            'tax_id' => $tax->id,
            'amount' => 50000,
        ]);

        $detailWithoutTax = $expense->detailRows()->create([
            'name' => 'Initial Meal',
            'tax_id' => null,
            'amount' => 50000,
        ]);

        $existingAttachment = UploadedFile::fake()->create('old-receipt.pdf', 10, 'application/pdf');
        $expense->addMedia($existingAttachment)->toMediaCollection('attachments');
        $existingMediaId = $expense->getMedia('attachments')->first()->id;

        $newAttachment = UploadedFile::fake()->create('new-receipt.pdf', 12, 'application/pdf');

        $expenseForComponent = $expense->fresh();
        unset($expenseForComponent->detailRows);
        $expenseForComponent->setRelation('detailRows', $expenseForComponent->detailRows()->get());
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

        $this->assertSame(2, $expense->detailRows()->count());

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

        $detail = $expense->detailRows()->first();
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

    public function test_expense_category_created_event_updates_category_id(): void
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
            'category_name' => 'New Event Category',
        ]);

        Livewire::test(ExpenseForm::class)
            ->assertSet('category_id', null)
            ->dispatch('expenseCategoryCreated', id: $category->id, name: 'New Event Category', requester: 'expense-form')
            ->assertSet('category_id', $category->id);
    }

    public function test_expense_category_modal_dispatches_targeted_event(): void
    {
        Livewire::test(\App\Livewire\Expense\ExpenseCategoryQuickAddModal::class)
            ->call('openModal', requester: 'expense-form')
            ->set('category_name', 'Transport')
            ->call('save')
            ->assertDispatched('expenseCategoryCreated');
    }

    public function test_expense_create_renders_without_exception_and_excludes_inactive_unselected_taxes(): void
    {
        $activeTax = Tax::create([
            'name' => 'Active Tax 10%',
            'value' => 10,
            'is_active' => true,
        ]);

        $inactiveTax = Tax::create([
            'name' => 'Legacy Tax 5%',
            'value' => 5,
            'is_active' => false,
        ]);

        $component = Livewire::test(ExpenseForm::class)
            ->assertOk();

        /** @var \Illuminate\Database\Eloquent\Collection $taxes */
        $taxes = $component->viewData('taxes');
        $taxIds = $taxes->pluck('id')->all();

        $this->assertContains($activeTax->id, $taxIds);
        $this->assertNotContains($inactiveTax->id, $taxIds);
    }

    public function test_expense_edit_retains_selected_inactive_tax_and_excludes_other_inactive_taxes(): void
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

        $category = ExpenseCategory::create([
            'category_name' => 'Utilities',
        ]);

        $activeTax = Tax::create([
            'name' => 'Active Tax 11%',
            'value' => 11,
            'is_active' => true,
        ]);

        $selectedInactiveTax = Tax::create([
            'name' => 'Selected Inactive Tax 5%',
            'value' => 5,
            'is_active' => false,
        ]);

        $unselectedInactiveTax = Tax::create([
            'name' => 'Unselected Inactive Tax 7%',
            'value' => 7,
            'is_active' => false,
        ]);

        $expense = Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 50000,
        ]);

        $expense->detailRows()->create([
            'name' => 'Internet Service',
            'tax_id' => $selectedInactiveTax->id,
            'amount' => 50000,
        ]);

        $expenseForComponent = $expense->fresh();
        $expenseForComponent->setRelation('detailRows', $expenseForComponent->detailRows()->get());
        $expenseForComponent->setRelation('media', $expenseForComponent->media()->get());

        $component = Livewire::test(ExpenseForm::class, ['expense' => $expenseForComponent])
            ->assertOk()
            ->assertSet('details.0.tax_id', $selectedInactiveTax->id)
            ->assertSeeHtml('<option value="' . $selectedInactiveTax->id . '">' . $selectedInactiveTax->name . '</option>')
            ->assertSeeHtml('<option value="' . $activeTax->id . '">' . $activeTax->name . '</option>')
            ->assertDontSeeHtml('<option value="' . $unselectedInactiveTax->id . '">' . $unselectedInactiveTax->name . '</option>');

        /** @var \Illuminate\Database\Eloquent\Collection $taxes */
        $taxes = $component->viewData('taxes');
        $taxIds = $taxes->pluck('id')->all();

        $this->assertContains($activeTax->id, $taxIds);
        $this->assertContains($selectedInactiveTax->id, $taxIds);
        $this->assertNotContains($unselectedInactiveTax->id, $taxIds);
    }

    public function test_new_expense_rejects_crafted_inactive_tax_id(): void
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
            'company_name' => 'PKP Company',
            'company_email' => 'pkp@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'pkp@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Office Supplies']);

        $inactiveTax = Tax::create([
            'name' => 'Inactive Tax 5%',
            'value' => 5,
            'is_active' => false,
        ]);

        Livewire::test(ExpenseForm::class)
            ->set('date', now()->format('Y-m-d'))
            ->set('category_id', $category->id)
            ->set('details', [
                [
                    'name' => 'Printer Ink',
                    'tax_id' => $inactiveTax->id,
                    'amount' => '100000',
                ],
            ])
            ->call('saveDraft')
            ->assertHasErrors(['details.0.tax_id']);

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_edit_expense_rejects_assigning_unrelated_inactive_tax(): void
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
            'company_name' => 'PKP Company',
            'company_email' => 'pkp@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'pkp@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Office Supplies']);

        $inactiveTax = Tax::create([
            'name' => 'Unrelated Inactive Tax 5%',
            'value' => 5,
            'is_active' => false,
        ]);

        $expense = Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 50000,
        ]);

        $detailRow = $expense->detailRows()->create([
            'name' => 'Paper',
            'tax_id' => null,
            'amount' => 50000,
        ]);

        $expenseForComponent = $expense->fresh();
        $expenseForComponent->setRelation('detailRows', $expenseForComponent->detailRows()->get());
        $expenseForComponent->setRelation('media', $expenseForComponent->media()->get());

        Livewire::test(ExpenseForm::class, ['expense' => $expenseForComponent])
            ->set('details', [
                [
                    'id' => $detailRow->id,
                    'name' => 'Paper',
                    'tax_id' => $inactiveTax->id,
                    'amount' => '50000',
                ],
            ])
            ->call('saveDraft')
            ->assertHasErrors(['details.0.tax_id']);
    }

    public function test_edit_expense_permits_unchanged_persisted_inactive_tax(): void
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
            'company_name' => 'PKP Company',
            'company_email' => 'pkp@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'pkp@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Office Supplies']);

        $inactiveTax = Tax::create([
            'name' => 'Historical Inactive Tax 5%',
            'value' => 5,
            'is_active' => false,
        ]);

        $expense = Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 52500,
        ]);

        $detailRow = $expense->detailRows()->create([
            'name' => 'Historical Ink',
            'tax_id' => $inactiveTax->id,
            'amount' => 50000,
        ]);

        $expenseForComponent = $expense->fresh();
        $expenseForComponent->setRelation('detailRows', $expenseForComponent->detailRows()->get());
        $expenseForComponent->setRelation('media', $expenseForComponent->media()->get());

        Livewire::test(ExpenseForm::class, ['expense' => $expenseForComponent])
            ->set('details', [
                [
                    'id' => $detailRow->id,
                    'name' => 'Historical Ink Refill',
                    'tax_id' => $inactiveTax->id,
                    'amount' => '50000',
                ],
            ])
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expense_details', [
            'id' => $detailRow->id,
            'tax_id' => $inactiveTax->id,
        ]);
    }

    public function test_tax_deactivated_before_submission_is_rejected(): void
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
            'company_name' => 'PKP Company',
            'company_email' => 'pkp@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'pkp@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Office Supplies']);

        $tax = Tax::create([
            'name' => 'Temporary Active Tax 10%',
            'value' => 10,
            'is_active' => true,
        ]);

        $testComponent = Livewire::test(ExpenseForm::class)
            ->set('date', now()->format('Y-m-d'))
            ->set('category_id', $category->id)
            ->set('details', [
                [
                    'name' => 'New Paper',
                    'tax_id' => $tax->id,
                    'amount' => '50000',
                ],
            ]);

        // Tax is deactivated right before submission
        $tax->update(['is_active' => false]);

        $testComponent->call('saveDraft')
            ->assertHasErrors(['details.0.tax_id']);

        $this->assertDatabaseCount('expenses', 0);
    }
}

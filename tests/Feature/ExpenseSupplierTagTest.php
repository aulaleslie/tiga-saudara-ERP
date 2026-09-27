<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Tags\Tag;
use Tests\TestCase;
use App\Livewire\Expense\ExpenseForm;
use App\Models\User;
use Spatie\Permission\Models\Role;

class ExpenseSupplierTagTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Staff']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Staff');
        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    public function test_expense_form_can_save_supplier_and_tags()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Test Category']);
        
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Acme Corp',
            'supplier_email' => 'acme@test.com',
            'supplier_phone' => '123',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country'
        ]);

        $tag = Tag::findOrCreate('ProjectA', 'en');

        Livewire::test(ExpenseForm::class)
            ->set('date', now()->format('Y-m-d'))
            ->set('category_id', $category->id)
            ->set('details', [
                ['name' => 'Test Item', 'amount' => '1000', 'tax_id' => null]
            ])
            ->set('supplier_id', $supplier->id)
            ->set('tagIds', [$tag->id])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $expense = Expense::first();
        $this->assertNotNull($expense);
        $this->assertEquals($supplier->id, $expense->supplier_id);
        $this->assertTrue($expense->tags->contains('id', $tag->id));
    }

    public function test_expense_form_hydrates_supplier_and_tags()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Test Category']);
        
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Acme Corp',
            'supplier_email' => 'acme@test.com',
            'supplier_phone' => '123',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country'
        ]);

        $expense = Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 1000,
            'status' => 'DRAFT',
            'is_tax_included' => false,
        ]);
        
        $tag = Tag::findOrCreate('ProjectA', 'en');
        $expense->attachTag($tag);

        Livewire::test(ExpenseForm::class, ['expense' => $expense])
            ->assertSet('supplier_id', $supplier->id)
            ->assertSet('supplierLabel', 'ACME CORP')
            ->assertSet('tagIds', [$tag->id]);
    }

    public function test_expense_form_rejects_invalid_tags()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Test Category']);

        Livewire::test(ExpenseForm::class)
            ->set('date', now()->format('Y-m-d'))
            ->set('category_id', $category->id)
            ->set('details', [
                ['name' => 'Test Item', 'amount' => '1000', 'tax_id' => null]
            ])
            ->set('tagIds', [9999]) // Invalid tag ID
            ->call('saveDraft')
            ->assertHasErrors(['tagIds.0']);
    }

    public function test_expense_form_renders_supplier_and_tag_inputs()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Livewire::test(ExpenseForm::class)
            ->assertSeeHtml('wire:model.live.debounce.300ms="supplierSearch"')
            ->assertSeeHtml('wire:model.live.debounce.300ms="tagSearch"');
    }
}

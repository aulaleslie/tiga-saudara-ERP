<?php

namespace Tests\Feature;

use App\Livewire\Expense\ExpenseForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExpenseCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_renders_without_a_duplicate_expense(): void
    {
        $this->signInForExpenses();

        $this->get(route('expenses.create'))
            ->assertOk()
            ->assertSeeLivewire(ExpenseForm::class);
    }

    public function test_duplicate_and_edit_pages_render_with_an_existing_expense(): void
    {
        $setting = $this->signInForExpenses();
        $category = ExpenseCategory::create(['category_name' => 'Travel']);
        $expense = Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => '2026-11-01',
            'amount' => 100000,
            'status' => Expense::STATUS_DRAFT,
        ]);
        $expense->detailRows()->create(['name' => 'Plane ticket', 'amount' => 100000]);

        foreach (['expenses.duplicate', 'expenses.edit'] as $route) {
            $this->get(route($route, $expense))
                ->assertOk()
                ->assertSeeLivewire(ExpenseForm::class)
                ->assertSee('PLANE TICKET');
        }
    }

    private function signInForExpenses(): Setting
    {
        $user = User::factory()->create();
        foreach (['expenses.create', 'expenses.edit'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);
        $setting = Setting::create([
            'company_name' => 'Test Company', 'company_email' => 'test@test.com',
            'company_phone' => '1234', 'company_address' => 'Test',
            'notification_email' => 'test@test.com', 'footer_text' => 'Footer',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
        ]);
        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        return $setting;
    }
}

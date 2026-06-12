<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Tests\TestCase;

class ExpenseShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_show_expense_with_legacy_details_string_and_details_relation(): void
    {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::create(['name' => 'expenses.access']);
        $user->givePermissionTo('expenses.access');

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

        $category = ExpenseCategory::create([
            'category_name' => 'Travel',
        ]);

        // Simulate the legacy scenario:
        // 'details' column in expenses table has a string,
        // AND there is an expense_detail row.
        $expense = Expense::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 100000,
            'status' => Expense::STATUS_DRAFT,
            'details' => 'Legacy String Details', // legacy string
        ]);

        $expense->detailRows()->create([
            'name' => 'Legacy String Details',
            'amount' => 100000,
            'tax_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('expenses.show', $expense->id));

        $response->assertStatus(200);
        $response->assertSee('LEGACY STRING DETAILS');
    }
}

<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Currency;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Livewire\Livewire;
use Carbon\Carbon;
use Modules\Purchase\Entities\PaymentTerm;

class SaleDueDateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_sale_payment_table_shows_due_date(): void
    {
        $setting = $this->createSetting('BIZ GLOBAL');
        $user = $this->createUserForSetting($setting, 'GLOBAL ADMIN', ['salePayments.global.access']);

        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        
        $dueDate = Carbon::now()->addDays(7)->format('Y-m-d');
        
        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'date' => now()->format('Y-m-d'),
            'reference' => 'SALE-DUE-01',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'payment_method' => 'Cash',
            'due_date' => $dueDate,
        ]);

        Livewire::actingAs($user)
            ->test('sale.sale-table', ['globalMode' => true])
            ->assertSee(Carbon::parse($dueDate)->format('d M Y'));
    }

    public function test_pos_sale_detail_modal_shows_due_date(): void
    {
        $setting = $this->createSetting('BIZ POS');
        $user = $this->createUserForSetting($setting, 'POS ADMIN', ['pos.access']);

        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        
        $dueDate = Carbon::now()->addDays(14)->format('Y-m-d');
        
        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'date' => now()->format('Y-m-d'),
            'reference' => 'SALE-POS-01',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'payment_method' => 'Cash',
            'due_date' => $dueDate,
        ]);

        $view = view('pos::checkouts.sale-readonly', compact('sale'))->render();

        $this->assertStringContainsString(Carbon::parse($dueDate)->format('d M, Y'), $view);
        $this->assertStringContainsString('Jatuh Tempo:', $view);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => \Modules\Currency\Entities\Currency::query()->value('id') ?? \Modules\Currency\Entities\Currency::factory()->create()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Purchase\PurchaseTable;
use App\Livewire\Sale\SaleTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GlobalPaymentEffectiveDateSortTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
    }

    public function test_purchase_default_date_sort_uses_reporting_date_and_id_tie_break(): void
    {
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address',
        ]);

        $createPurchase = function (string $date, ?string $reportingDate) use ($supplier): Purchase {
            return Purchase::create([
                'date' => $date,
                'reporting_date' => $reportingDate,
                'due_date' => '2024-02-01',
                'supplier_id' => $supplier->id,
                'status' => Purchase::STATUS_RECEIVED,
                'payment_status' => 'UNPAID',
                'payment_method' => 'Cash',
                'total_amount' => 1000,
                'paid_amount' => 0,
                'due_amount' => 1000,
                'setting_id' => $this->setting->id,
            ]);
        };

        $first = $createPurchase('2024-01-10', null);
        $overridden = $createPurchase('2024-01-15', '2024-01-05');
        $tied = $createPurchase('2024-01-09', '2024-01-10');

        Livewire::test(PurchaseTable::class, ['globalMode' => true])
            ->assertSet('sortField', 'date')
            ->assertViewHas('purchases', fn ($purchases) => $purchases->pluck('id')->all() === [$tied->id, $first->id, $overridden->id])
            ->call('sortBy', 'date')
            ->assertViewHas('purchases', fn ($purchases) => $purchases->pluck('id')->all() === [$overridden->id, $first->id, $tied->id]);
    }

    public function test_sale_default_date_sort_uses_reporting_date_and_id_tie_break(): void
    {
        $customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address',
        ]);

        $createSale = function (string $date, ?string $reportingDate) use ($customer): Sale {
            return Sale::create([
                'date' => $date,
                'reporting_date' => $reportingDate,
                'due_date' => '2024-02-01',
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => 'UNPAID',
                'payment_method' => 'Cash',
                'total_amount' => 1000,
                'paid_amount' => 0,
                'due_amount' => 1000,
                'setting_id' => $this->setting->id,
            ]);
        };

        $first = $createSale('2024-01-10', null);
        $overridden = $createSale('2024-01-15', '2024-01-05');
        $tied = $createSale('2024-01-09', '2024-01-10');

        Livewire::test(SaleTable::class, ['globalMode' => true])
            ->assertSet('sortField', 'date')
            ->assertViewHas('sales', fn ($sales) => $sales->pluck('id')->all() === [$tied->id, $first->id, $overridden->id])
            ->call('sortBy', 'date')
            ->assertViewHas('sales', fn ($sales) => $sales->pluck('id')->all() === [$overridden->id, $first->id, $tied->id]);
    }
}

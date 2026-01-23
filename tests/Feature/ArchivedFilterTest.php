<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use App\Livewire\Purchase\PurchaseTable;
use App\Livewire\Sale\SaleTable;
use Tests\TestCase;

class ArchivedFilterTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        
        // Ensure a currency exists
        \Modules\Currency\Entities\Currency::create([
            'id' => 1,
            'currency_name' => 'US Dollar',
            'code' => 'USD',
            'symbol' => '$',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'exchange_rate' => 1,
        ]);

        // Ensure a setting exists
        \Modules\Setting\Entities\Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '123456789',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        // Ensure a session setting_id exists if needed by the component
        session(['setting_id' => 1]);
    }

    public function test_purchase_table_filters_archived_records()
    {
        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@test.com',
            'supplier_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        // Create an active purchase
        $activePurchase = Purchase::create([
            'date' => now(),
            'reference' => 'PR-ACTIVE',
            'supplier_id' => $supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(30),
            'setting_id' => 1,
        ]);

        // Create an archived purchase
        $archivedPurchase = Purchase::create([
            'date' => now(),
            'reference' => 'PR-ARCHIVED',
            'supplier_id' => $supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(30),
            'setting_id' => 1,
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);

        Livewire::test(PurchaseTable::class)
            ->assertSee($activePurchase->fresh()->reference)
            ->assertDontSee($archivedPurchase->fresh()->reference)
            ->set('showArchived', true)
            ->assertSee($archivedPurchase->fresh()->reference)
            ->assertDontSee($activePurchase->fresh()->reference)
            ->set('search', $archivedPurchase->fresh()->reference)
            ->assertSee($archivedPurchase->fresh()->reference)
            ->set('search', $activePurchase->fresh()->reference)
            ->assertDontSee($activePurchase->fresh()->reference);
    }

    public function test_sale_table_filters_archived_records()
    {
        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@test.com',
            'customer_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        // Create an active sale
        $activeSale = Sale::create([
            'date' => now(),
            'reference' => 'SL-ACTIVE',
            'customer_id' => $customer->id,
            'customer_name' => 'Test Customer',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => 1,
        ]);

        // Create an archived sale
        $archivedSale = Sale::create([
            'date' => now(),
            'reference' => 'SL-ARCHIVED',
            'customer_id' => $customer->id,
            'customer_name' => 'Test Customer',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'setting_id' => 1,
            'archived_at' => now(),
        ]);

        Livewire::test(SaleTable::class)
            ->assertSee($activeSale->fresh()->reference)
            ->assertDontSee($archivedSale->fresh()->reference)
            ->set('showArchived', true)
            ->assertSee($archivedSale->fresh()->reference)
            ->assertDontSee($activeSale->fresh()->reference)
            ->set('search', $archivedSale->fresh()->reference)
            ->assertSee($archivedSale->fresh()->reference)
            ->set('search', $activeSale->fresh()->reference)
            ->assertDontSee($activeSale->fresh()->reference);
    }
}

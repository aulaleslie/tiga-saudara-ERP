<?php

namespace Tests\Feature;

use App\Livewire\Purchase\PurchaseTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReceivingFilteredTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => 1]);
    }

    public function test_filtered_receiving_table_hides_purchase_status_column(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        Purchase::create([
            'date' => now(),
            'reference' => 'PO-REC-001',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        Livewire::test(PurchaseTable::class, [
            'settingId' => 1,
            'statusFilter' => [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY],
        ])
            ->assertDontSeeHtml('<th>Status</th>')
            ->assertSeeHtml('<th>Action</th>');
    }
}

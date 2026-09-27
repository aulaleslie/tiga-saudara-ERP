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

    public function test_receiving_table_pagination_navigates_between_pages(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier Receiving',
            'supplier_email' => 'supplier_rec@example.com',
            'supplier_phone' => '081234567',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        // Default perPage is 10, create 15 eligible purchases
        for ($i = 1; $i <= 15; $i++) {
            $purchase = new Purchase([
                'date' => now()->subDays(15 - $i),
                'reference' => sprintf('PO-REC-%03d', $i),
                'supplier_id' => $supplier->id,
                'status' => Purchase::STATUS_APPROVED,
                'payment_status' => 'UNPAID',
                'payment_method' => 'CASH',
                'total_amount' => 1000 * $i,
                'paid_amount' => 0,
                'due_amount' => 1000 * $i,
                'due_date' => now()->addDays(7),
                'setting_id' => 1,
            ]);
            $purchase->created_at = now()->subMinutes(20 - $i);
            $purchase->save();
        }

        // Ordered by created_at desc by default:
        // Page 1 will contain PO-REC-015 down to PO-REC-006 (10 items)
        // Page 2 will contain PO-REC-005 down to PO-REC-001 (5 items)
        $component = Livewire::test(PurchaseTable::class, [
            'settingId' => 1,
            'statusFilter' => [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY],
        ]);

        $component->assertSee('PO-REC-015')
            ->assertDontSee('PO-REC-001')
            ->call('nextPage', 'page')
            ->assertSet('paginators.page', 2)
            ->assertSee('PO-REC-001')
            ->assertDontSee('PO-REC-015')
            ->call('previousPage', 'page')
            ->assertSet('paginators.page', 1)
            ->assertSee('PO-REC-015')
            ->assertDontSee('PO-REC-001');
    }
}


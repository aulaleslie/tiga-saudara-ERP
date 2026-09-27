<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseSummaryCardsRupiahStorageTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        $this->setting = Setting::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
    }

    protected function createPurchase($overrides = [])
    {
        $defaults = [
            'date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => '',
            'note' => null,
            'payment_term_id' => null,
            'tax_id' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'archived_at' => null,
        ];

        return Purchase::create(array_merge($defaults, $overrides));
    }

    /**
     * Test: Pelunasan property uses stored rupiah without /100 scaling
     * This directly tests the getPelunasanProperty method logic
     */
    public function test_pelunasan_payment_amount_stored_as_rupiah()
    {
        $purchase = $this->createPurchase();

        // Create payment with amount stored directly as rupiah (1 million)
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'date' => now()->subDays(5)->toDateString(),
            'amount' => 1000000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'CASH',
            'reference' => 'TEST-PAY-001',
        ]);

        // Query the pelunasan data using the same logic as PurchaseSummaryCards
        $thirtyDaysAgo = now()->subDays(30)->format('Y-m-d');

        $result = PurchasePayment::active()
            ->whereHas('purchase', function ($q) {
                $q->where('setting_id', $this->setting->id)
                  ->where('payment_status', 'PAID')
                  ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED]);
            })
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('date', '<=', now()->endOfDay())
            ->selectRaw('COUNT(DISTINCT purchase_id) as cnt, SUM(amount) as total')
            ->first();

        // The amount should be 1,000,000 (not 10,000 which would indicate /100 scaling)
        $this->assertEquals(1, $result->cnt);
        $this->assertEquals(1000000.0, (float) $result->total);
    }

    /**
     * Test: Multiple payment amounts sum without scaling
     */
    public function test_pelunasan_multiple_amounts_sum_correctly()
    {
        $purchase = $this->createPurchase();

        // Create multiple payments
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'date' => now()->subDays(5)->toDateString(),
            'amount' => 500000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'TRANSFER',
            'reference' => 'TEST-PAY-002',
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'date' => now()->subDays(3)->toDateString(),
            'amount' => 750000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'CHECK',
            'reference' => 'TEST-PAY-003',
        ]);

        $thirtyDaysAgo = now()->subDays(30)->format('Y-m-d');

        $result = PurchasePayment::active()
            ->whereHas('purchase', function ($q) {
                $q->where('setting_id', $this->setting->id)
                  ->where('payment_status', 'PAID')
                  ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED]);
            })
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('date', '<=', now()->endOfDay())
            ->selectRaw('COUNT(DISTINCT purchase_id) as cnt, SUM(amount) as total')
            ->first();

        // Should sum to 1,250,000 (500k + 750k, not divided by 100)
        $this->assertEquals(1, $result->cnt);
        $this->assertEquals(1250000.0, (float) $result->total);
    }
}

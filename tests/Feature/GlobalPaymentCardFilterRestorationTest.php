<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GlobalPaymentCardFilterRestorationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        $this->seedPermissions([
            'purchasePayments.global.access',
            'salePayments.global.access',
        ]);

        $this->setting1 = Setting::factory()->create(['company_name' => 'Business 1']);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Business 2']);
        session(['setting_id' => $this->setting1->id]);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'purchasePayments.global.access',
            'salePayments.global.access',
        ]);
    }

    protected function seedPermissions(array $permissionNames): void
    {
        foreach ($permissionNames as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }

    private function createPurchase($overrides = [])
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => uniqid() . rand(1000, 9999),
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $overrides['setting_id'] ?? $this->setting1->id,
        ]);

        return Purchase::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }

    private function createSale($overrides = [])
    {
        $customer = Customer::create([
            'customer_name' => 'Test Customer ' . uniqid(),
            'customer_email' => uniqid() . '@example.com',
            'customer_phone' => uniqid() . rand(1000, 9999),
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $overrides['setting_id'] ?? $this->setting1->id,
        ]);

        return Sale::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'INV-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }


    /**
     * Test: URL-encoded card filters with business/date filters apply correctly
     */
    public function test_purchase_filters_combined_url_parameters()
    {
        // Create purchases in different settings and dates
        $purchase1 = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now(),
            'status' => Purchase::STATUS_RECEIVED,
            'due_amount' => 100000,
        ]);

        $purchase2 = $this->createPurchase([
            'setting_id' => $this->setting2->id,
            'date' => now(),
            'status' => Purchase::STATUS_RECEIVED,
            'due_amount' => 100000,
        ]);

        $purchase3 = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(10),
            'status' => Purchase::STATUS_RECEIVED,
            'due_amount' => 100000,
        ]);

        $this->actingAs($this->user);

        // Test: Mount with business filter + card filter
        Livewire::test('purchase.purchase-table', [
            'globalMode' => true,
            'globalBusinessFilter' => $this->setting1->id,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertViewHas('purchases', function ($purchases) use ($purchase1, $purchase2, $purchase3) {
                // Only purchase1 should appear (setting1 + unpaid)
                // purchase2 is in setting2, purchase3 is same setting but both are unpaid
                $ids = $purchases->pluck('id')->toArray();
                return in_array($purchase1->id, $ids) &&
                       !in_array($purchase2->id, $ids); // Different business
            });
    }


    /**
     * Test: PurchaseTable responds to purchaseReceivingCompleted event and re-renders
     */
    public function test_purchase_table_refreshes_on_receiving_completion()
    {
        $this->seedPermissions(['purchases.receive.complete_shortfall']);
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');

        $purchase = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'status' => Purchase::STATUS_RECEIVED,
            'due_amount' => 100000,
        ]);

        $this->actingAs($this->user);

        // Start with purchase visible
        Livewire::test('purchase.purchase-table', [
            'globalMode' => true,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->contains($purchase->id);
            })
            // Simulate the purchaseReceivingCompleted event dispatch
            ->dispatch('purchaseReceivingCompleted', ['purchase_id' => $purchase->id])
            // After re-render, table should still work
            ->assertStatus(200);
    }

    /**
     * Test: SaleTable mounted with globalMode: true, selectedCardFilter: 'unpaid' and business filter applies derived flags
     */
    public function test_sale_table_card_filter_with_business_filter()
    {
        $matchingSale = $this->createSale([
            'setting_id' => $this->setting1->id,
            'payment_status' => 'UNPAID',
            'due_amount' => 50000,
            'status' => Sale::STATUS_DISPATCHED,
        ]);

        $fullyPaidSale = $this->createSale([
            'setting_id' => $this->setting1->id,
            'payment_status' => 'PAID',
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
        ]);

        $otherBusinessSale = $this->createSale([
            'setting_id' => $this->setting2->id,
            'payment_status' => 'UNPAID',
            'due_amount' => 50000,
            'status' => Sale::STATUS_DISPATCHED,
        ]);

        $this->actingAs($this->user);

        Livewire::test('sale.sale-table', [
            'globalMode' => true,
            'globalBusinessFilter' => $this->setting1->id,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertViewHas('sales', function ($sales) use ($matchingSale, $fullyPaidSale, $otherBusinessSale) {
                $ids = $sales->pluck('id')->toArray();
                return in_array($matchingSale->id, $ids) &&
                       !in_array($fullyPaidSale->id, $ids) &&
                       !in_array($otherBusinessSale->id, $ids);
            });
    }

    /**
     * Test: PurchaseSummaryCards in globalMode with business filter shows correct totals
     */
    public function test_purchase_summary_cards_global_mode_with_business_filter()
    {
        $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'payment_status' => 'UNPAID',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
        ]);

        $this->createPurchase([
            'setting_id' => $this->setting2->id,
            'payment_status' => 'UNPAID',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Purchase::STATUS_RECEIVED,
        ]);

        $this->actingAs($this->user);

        Livewire::test('purchase.purchase-summary-cards', [
            'globalMode' => true,
            'globalBusinessFilter' => $this->setting1->id,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertSet('selectedCardFilter', 'unpaid')
            ->assertSeeHtml('bg-light')
            ->tap(function ($component) {
                // Verify totals are computed correctly with business filter applied
                // Only setting1 purchase should be included in the total
                $belumDibayar = $component->instance()->belumDibayar;
                $this->assertEquals(1, $belumDibayar['count']);
                $this->assertEquals(100000.0, $belumDibayar['total']);
            });
    }

    /**
     * Test: SaleSummaryCards in globalMode with business filter shows correct totals
     */
    public function test_sale_summary_cards_global_mode_with_business_filter()
    {
        $this->createSale([
            'setting_id' => $this->setting1->id,
            'payment_status' => 'UNPAID',
            'total_amount' => 75000,
            'paid_amount' => 0,
            'due_amount' => 75000,
            'status' => Sale::STATUS_DISPATCHED,
        ]);

        $this->createSale([
            'setting_id' => $this->setting2->id,
            'payment_status' => 'UNPAID',
            'total_amount' => 75000,
            'paid_amount' => 0,
            'due_amount' => 75000,
            'status' => Sale::STATUS_DISPATCHED,
        ]);

        $this->actingAs($this->user);

        Livewire::test('sale.sale-summary-cards', [
            'globalMode' => true,
            'globalBusinessFilter' => $this->setting1->id,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertSet('selectedCardFilter', 'unpaid')
            ->assertSeeHtml('bg-light')
            ->tap(function ($component) {
                // Verify totals are computed correctly with business filter applied
                // Only setting1 sale should be included in the total
                $piutangBelumTertagih = $component->instance()->piutangBelumTertagih;
                $this->assertEquals(1, $piutangBelumTertagih['count']);
                $this->assertEquals(75000.0, $piutangBelumTertagih['total']);
            });
    }

    /**
     * Test: PurchaseTable with date range filter excludes older purchases
     */
    public function test_purchase_table_date_range_filter()
    {
        $oldPurchase = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(40),
            'payment_status' => 'UNPAID',
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
        ]);

        $newPurchase = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(5),
            'payment_status' => 'UNPAID',
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
        ]);

        $this->actingAs($this->user);

        Livewire::test('purchase.purchase-table', [
            'globalMode' => true,
            'selectedCardFilter' => 'unpaid',
            'documentDateFrom' => now()->subDays(30)->format('Y-m-d'),
            'documentDateTo' => now()->format('Y-m-d'),
        ])
            ->assertViewHas('purchases', function ($purchases) use ($oldPurchase, $newPurchase) {
                $ids = $purchases->pluck('id')->toArray();
                return !in_array($oldPurchase->id, $ids) &&
                       in_array($newPurchase->id, $ids);
            });
    }
}

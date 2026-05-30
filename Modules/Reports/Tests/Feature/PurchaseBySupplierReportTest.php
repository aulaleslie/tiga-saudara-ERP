<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\People\Entities\Supplier;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PurchaseBySupplierReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'purchaseReports.access']);
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
        $this->user->givePermissionTo('purchaseReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    protected function makeSupplier(string $name = 'Supplier 1', ?int $settingId = null): Supplier
    {
        static $counter = 0;
        $counter++;
        return Supplier::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'supplier_name' => $name,
            'supplier_email' => "s{$counter}@test.com",
            'supplier_phone' => (string) $counter,
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);
    }

    protected function makePurchase(Supplier $supplier, array $overrides = []): Purchase
    {
        static $ref = 0;
        $ref++;
        return Purchase::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'PR-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ], $overrides));
    }

    protected function makePurchaseDetail(Purchase $purchase, array $overrides = []): PurchaseDetail
    {
        return PurchaseDetail::create(array_merge([
            'purchase_id' => $purchase->id,
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => 'Product A',
            'product_code' => 'PA-001',
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $overrides));
    }

    /** @test */
    public function it_can_render_the_purchase_by_supplier_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-supplier.index'));
        $response->assertStatus(200);
        $response->assertSee('Pembelian Per Supplier');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-supplier.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_shows_purchase_by_supplier_menu_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertSee('Pembelian Per Supplier');
    }

    /** @test */
    public function it_defaults_start_and_end_date_to_current_month()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }

    /** @test */
    public function it_returns_one_row_per_purchase_detail_for_a_single_purchase()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product A']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product B']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product C']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 3;
            });
    }

    /** @test */
    public function it_hides_suppliers_without_matching_purchase_details()
    {
        $supplierWithPurchases = $this->makeSupplier('Supplier A');
        $supplierWithoutPurchases = $this->makeSupplier('Supplier B');

        $purchase = $this->makePurchase($supplierWithPurchases, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makePurchaseDetail($purchase);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $supplierNames = $purchases->pluck('supplier_name')->unique()->map(fn($name) => strtoupper($name));
                \PHPUnit\Framework\Assert::assertTrue($supplierNames->contains(strtoupper('Supplier A')), 'Supplier A not found. Found: ' . $supplierNames->implode(', '));
                \PHPUnit\Framework\Assert::assertFalse($supplierNames->contains(strtoupper('Supplier B')), 'Supplier B was found but should be hidden.');
                return true;
            });
    }

    /** @test */
    public function it_computes_running_totals_per_supplier_using_sub_total()
    {
        $supplier = $this->makeSupplier('Supplier A');
        $purchase1 = $this->makePurchase($supplier, ['date' => '2026-05-01']);
        $purchase2 = $this->makePurchase($supplier, ['date' => '2026-05-02']);

        // First purchase detail
        $this->makePurchaseDetail($purchase1, ['sub_total' => 1000]);
        // Second purchase detail
        $this->makePurchaseDetail($purchase2, ['sub_total' => 2000]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                // The query sorts by date desc.
                // Row 0 is purchase2 (2026-05-02, sub_total 2000)
                // Row 1 is purchase1 (2026-05-01, sub_total 1000)
                $rows = $purchases->values(); // Use the original order
                \PHPUnit\Framework\Assert::assertCount(2, $rows, 'Expected 2 rows, found ' . $rows->count());
                
                // Assert Nominal tagihan (sub_total) and Total nominal tagihan (running total)
                \PHPUnit\Framework\Assert::assertEquals(2000, $rows[0]->sub_total);
                \PHPUnit\Framework\Assert::assertEquals(2000, $rows[0]->running_total);
                \PHPUnit\Framework\Assert::assertEquals(1000, $rows[1]->sub_total);
                \PHPUnit\Framework\Assert::assertEquals(3000, $rows[1]->running_total);
                
                return true;
            });
    }
}

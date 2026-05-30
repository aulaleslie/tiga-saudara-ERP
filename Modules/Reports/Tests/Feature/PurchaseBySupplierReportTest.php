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
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Spatie\Tags\Tag;

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

    /** @test */
    public function it_filters_by_categories_correctly()
    {
        $category1 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat 1', 'created_by' => $this->user->id]);
        $category2 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C2', 'category_name' => 'Cat 2', 'created_by' => $this->user->id]);
        
        $product1 = Product::create(['product_name' => 'P1', 'product_code' => 'P1', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category1->id]);
        $product2 = Product::create(['product_name' => 'P2', 'product_code' => 'P2', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category2->id]);

        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier);
        
        $this->makePurchaseDetail($purchase, ['product_id' => $product1->id, 'product_name' => 'P1']);
        $this->makePurchaseDetail($purchase, ['product_id' => $product2->id, 'product_name' => 'P2']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('categoryIds', [$category1->id])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $names = $purchases->pluck('product_name');
                \PHPUnit\Framework\Assert::assertTrue($names->contains('P1'));
                \PHPUnit\Framework\Assert::assertFalse($names->contains('P2'));
                return true;
            });
    }

    /** @test */
    public function it_filters_by_tags_using_mencakup_semua_and_salah_satu_logic()
    {
        $tag1 = Tag::findOrCreate('tag1');
        $tag2 = Tag::findOrCreate('tag2');

        $supplier = $this->makeSupplier();
        
        $purchase1 = $this->makePurchase($supplier, ['date' => '2026-05-01']);
        $purchase1->attachTag($tag1);
        $this->makePurchaseDetail($purchase1, ['product_name' => 'Only Tag1']);
        
        $purchase2 = $this->makePurchase($supplier, ['date' => '2026-05-02']);
        $purchase2->attachTags([$tag1, $tag2]);
        $this->makePurchaseDetail($purchase2, ['product_name' => 'Both Tags']);

        // Salah satu logic
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('tagIds', [$tag1->id, $tag2->id])
            ->set('tagLogic', 'Salah satu')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(2, $purchases->count());
                return true;
            });
            
        // Mencakup semua logic
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('tagIds', [$tag1->id, $tag2->id])
            ->set('tagLogic', 'Mencakup semua')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(1, $purchases->count());
                \PHPUnit\Framework\Assert::assertEquals('BOTH TAGS', strtoupper($purchases->first()->product_name));
                return true;
            });
    }

    /** @test */
    public function it_sorts_suppliers_by_total_purchase_amount()
    {
        $supplier1 = $this->makeSupplier('Supplier Low');
        $supplier2 = $this->makeSupplier('Supplier High');

        $purchase1 = $this->makePurchase($supplier1, ['date' => '2026-05-01']);
        $this->makePurchaseDetail($purchase1, ['sub_total' => 1000]);

        $purchase2 = $this->makePurchase($supplier2, ['date' => '2026-05-01']);
        $this->makePurchaseDetail($purchase2, ['sub_total' => 5000]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('sortField', 'supplier_total')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $rows = $purchases->values();
                // Row 0 should be Supplier High because total is 5000 > 1000
                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER HIGH', strtoupper($rows[0]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER LOW', strtoupper($rows[1]->supplier_name));
                return true;
            });
    }

    /** @test */
    public function it_does_not_have_export_buttons()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-supplier.index'));
        $response->assertDontSee('Excel');
        $response->assertDontSee('PDF');
        $response->assertDontSee('CSV');
    }

    /** @test */
    public function it_hides_the_menu_item_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertDontSee('Pembelian Per Supplier');
    }

    /** @test */
    public function it_handles_period_presets_without_refreshing_rows_until_filtered()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('periodPreset', 'today')
            ->assertSet('startDate', now()->format('Y-m-d'))
            // Before applying filters, appliedFilters startDate is still the old one
            ->assertSet('appliedFilters.startDate', now()->startOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            // After applying filters, appliedFilters matches the new startDate
            ->assertSet('appliedFilters.startDate', now()->format('Y-m-d'));
    }

    /** @test */
    public function it_scopes_purchases_to_the_current_setting()
    {
        $supplier = $this->makeSupplier();
        $purchaseInSetting = $this->makePurchase($supplier, ['setting_id' => $this->setting->id]);
        $this->makePurchaseDetail($purchaseInSetting, ['product_name' => 'In Setting']);

        $otherSetting = Setting::create([
            'company_name' => 'Other',
            'company_email' => 'other@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);
        $purchaseOtherSetting = $this->makePurchase($supplier, ['setting_id' => $otherSetting->id]);
        $this->makePurchaseDetail($purchaseOtherSetting, ['product_name' => 'Other Setting']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(1, $purchases->count());
                \PHPUnit\Framework\Assert::assertEquals('IN SETTING', strtoupper($purchases->first()->product_name));
                return true;
            });
    }
    /** @test */
    public function it_groups_supplier_rows_together_without_interleaving_when_dates_alternate()
    {
        $supplierA = $this->makeSupplier('Supplier A');
        $supplierB = $this->makeSupplier('Supplier B');

        // Alternating dates: B, A, B
        $purchaseB1 = $this->makePurchase($supplierB, ['date' => '2026-05-01']);
        $this->makePurchaseDetail($purchaseB1, ['product_name' => 'B 01']);

        $purchaseA1 = $this->makePurchase($supplierA, ['date' => '2026-05-02']);
        $this->makePurchaseDetail($purchaseA1, ['product_name' => 'A 02']);

        $purchaseB2 = $this->makePurchase($supplierB, ['date' => '2026-05-03']);
        $this->makePurchaseDetail($purchaseB2, ['product_name' => 'B 03']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('sortField', 'date')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $rows = $purchases->values();
                \PHPUnit\Framework\Assert::assertCount(3, $rows);

                // Both B rows must be adjacent, and A must be by itself.
                // With max date DESC:
                // B's max date is 2026-05-03.
                // A's max date is 2026-05-02.
                // So Supplier B group comes first, then Supplier A group.
                // Within B, dates are DESC: 03 then 01.

                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER B', strtoupper($rows[0]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('B 03', $rows[0]->product_name);

                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER B', strtoupper($rows[1]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('B 01', $rows[1]->product_name);

                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER A', strtoupper($rows[2]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('A 02', $rows[2]->product_name);

                return true;
            });
    }

    /** @test */
    public function it_computes_running_totals_correctly_on_page_two()
    {
        $supplier = $this->makeSupplier('Supplier A');
        
        // Create 20 purchases so page 2 has 5 items (default perPage is 15)
        for ($i = 1; $i <= 20; $i++) {
            $purchase = $this->makePurchase($supplier, ['date' => '2026-05-01']);
            // The very first inserted row gets a massive sub_total of 1000.
            // Since the report sorts by date DESC, id DESC, this row will be the LAST row on page 2.
            // Page 1 contains rows 20 down to 6. They all have sub_total = 100.
            // So the correct running total at the start of page 2 (after page 1) MUST be 15 * 100 = 1500.
            $subTotal = ($i === 1) ? 1000 : 100;
            $this->makePurchaseDetail($purchase, ['sub_total' => $subTotal]);
        }

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('setPage', 2)
            ->assertViewHas('purchases', function ($purchases) {
                $rows = $purchases->values();
                \PHPUnit\Framework\Assert::assertCount(5, $rows);
                
                // The first 15 items (rows 20 down to 6) had sub_total 100, so running total before page 2 is 1500.
                // The first item on page 2 (row 5) should have a running total of 1600.
                \PHPUnit\Framework\Assert::assertEquals(1600, $rows[0]->running_total);
                
                // The last item on page 2 (row 1) has sub_total 1000, so its running total should be 2500.
                \PHPUnit\Framework\Assert::assertEquals(2900, $rows[4]->running_total);
                
                return true;
            });
    }

    /** @test */
    public function it_restores_date_filters_when_cancel_filters_is_called()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('periodPreset', 'this_month')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('periodPreset', 'today')
            ->set('startDate', '2026-06-01') // Unapplied change
            ->call('cancelFilters')
            ->assertSet('periodPreset', 'this_month') // Restored from appliedFilters
            ->assertSet('startDate', '2026-05-01'); // Restored from appliedFilters
    }

    /** @test */
    public function it_resets_date_filters_to_current_month_when_reset_filters_is_called()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('periodPreset', 'this_month')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('resetFilters')
            ->assertSet('periodPreset', '')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }
}

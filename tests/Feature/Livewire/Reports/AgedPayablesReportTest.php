<?php

namespace Tests\Feature\Livewire\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Reports\AgedPayablesReport;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\People\Entities\Supplier;

class AgedPayablesReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $supplier2;

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
        
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'a@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id
        ]);

        $this->supplier2 = Supplier::create([
            'supplier_name' => 'Supplier B',
            'supplier_email' => 'b@test.com',
            'supplier_phone' => '654321',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id
        ]);
    }

    private function createPurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'date' => '2023-01-01',
            'due_date' => '2023-02-01',
            'supplier_id' => $this->supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'setting_id' => $this->setting->id
        ], $overrides));
    }

    public function test_aged_payables_bucket_boundaries_transaction_date()
    {
        // Bucket 1 (1 - 30 Hari)
        $this->createPurchase(['date' => '2023-01-15', 'reference' => 'INV-0', 'total_amount' => 10000, 'due_amount' => 10000]);  // 0 days
        $this->createPurchase(['date' => '2022-12-16', 'reference' => 'INV-30', 'total_amount' => 20000, 'due_amount' => 20000]); // 30 days

        // Bucket 2 (31 - 60 Hari)
        $this->createPurchase(['date' => '2022-12-15', 'reference' => 'INV-31', 'total_amount' => 30000, 'due_amount' => 30000]); // 31 days
        $this->createPurchase(['date' => '2022-11-16', 'reference' => 'INV-60', 'total_amount' => 40000, 'due_amount' => 40000]); // 60 days

        // Bucket 3 (61 - 90 Hari)
        $this->createPurchase(['date' => '2022-11-15', 'reference' => 'INV-61', 'total_amount' => 50000, 'due_amount' => 50000]); // 61 days
        $this->createPurchase(['date' => '2022-10-17', 'reference' => 'INV-90', 'total_amount' => 60000, 'due_amount' => 60000]); // 90 days

        // Bucket 4 (> 90 Hari)
        $this->createPurchase(['date' => '2022-10-16', 'reference' => 'INV-91', 'total_amount' => 70000, 'due_amount' => 70000]); // 91 days

        // Report as of 2023-01-15
        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->set('agingBasis', 'Tanggal Transaksi')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        
        $grandTotals = $component->viewData('grandTotals');
        $this->assertEquals(30000, $grandTotals['1 - 30 Hari']);
        $this->assertEquals(70000, $grandTotals['31 - 60 Hari']);
        $this->assertEquals(110000, $grandTotals['61 - 90 Hari']);
        $this->assertEquals(70000, $grandTotals['> 90 Hari']);
        $this->assertEquals(280000, $grandTotals['Total']);
    }

    public function test_aged_payables_bucket_boundaries_due_date()
    {
        // For due date, the aging basis is COALESCE(due_date, date)
        $this->createPurchase(['date' => '2022-01-01', 'due_date' => '2023-01-15', 'reference' => 'INV-0', 'total_amount' => 10000, 'due_amount' => 10000]);  // 0 days
        $this->createPurchase(['date' => '2022-01-01', 'due_date' => '2022-12-16', 'reference' => 'INV-30', 'total_amount' => 20000, 'due_amount' => 20000]); // 30 days
        $this->createPurchase(['date' => '2022-12-15', 'due_date' => '2022-12-15', 'reference' => 'INV-31', 'total_amount' => 30000, 'due_amount' => 30000]); // 31 days

        // Report as of 2023-01-15
        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->set('agingBasis', 'Tanggal Jatuh Tempo')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        
        $grandTotals = $component->viewData('grandTotals');
        $this->assertEquals(30000, $grandTotals['1 - 30 Hari']);
        $this->assertEquals(30000, $grandTotals['31 - 60 Hari']);
        $this->assertEquals(0, $grandTotals['61 - 90 Hari']);
        $this->assertEquals(0, $grandTotals['> 90 Hari']);
        $this->assertEquals(60000, $grandTotals['Total']);
    }

    public function test_as_of_balance_replay_excludes_later_payments()
    {
        $purchase = $this->createPurchase([
            'total_amount' => 100000,
            'due_amount' => 100000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 40000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 60000,
            'date' => '2023-01-20',
            'reference' => 'PAY-002',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $grandTotals = $component->viewData('grandTotals');
        $this->assertEquals(60000, $grandTotals['Total']);

        $component2 = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-25')
            ->call('applyFilters');

        $this->assertCount(0, $component2->viewData('purchases'));
    }

    public function test_access_control()
    {
        $this->user->revokePermissionTo('purchaseReports.access');

        $response = $this->actingAs($this->user)->get(route('reports.aged-payables.index'));
        $response->assertStatus(403);
    }

    public function test_tenant_scoping()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Company',
            'company_email' => 'other@example.com',
            'company_phone' => '987654321',
            'notification_email' => 'other_notify@example.com',
            'company_address' => 'Other Address',
            'footer_text' => 'Other Footer',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
        ]);

        $otherSupplier = Supplier::create([
            'supplier_name' => 'Other Supplier',
            'supplier_email' => 'other@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $otherSetting->id
        ]);

        $this->createPurchase([
            'reference' => 'INV-OTHER',
            'supplier_id' => $otherSupplier->id,
            'setting_id' => $otherSetting->id
        ]);

        $this->createPurchase([
            'reference' => 'INV-MINE',
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->supplier->id, $purchases->first()->supplier_id);
    }

    public function test_filters_and_sort()
    {
        $tag = \Spatie\Tags\Tag::findOrCreate('VVIP', 'en');
        
        $purchase1 = $this->createPurchase([
            'date' => '2023-01-01',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 50000,
        ]);
        $purchase1->attachTag($tag);

        $purchase2 = $this->createPurchase([
            'date' => '2023-01-05',
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 100000,
        ]);

        // Filter by tag
        $component2 = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-20')
            ->set('tagIds', [$tag->id])
            ->call('applyFilters');
            
        $this->assertCount(1, $component2->viewData('purchases'));
        $this->assertEquals($this->supplier->id, $component2->viewData('purchases')->first()->supplier_id);

        // Sort by total balance desc
        $component3 = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-20')
            ->set('sortField', 'total_balance')
            ->set('sortDirection', 'desc')
            ->call('applyFilters');
            
        $purchasesDesc = $component3->viewData('purchases');
        $this->assertEquals($this->supplier2->id, $purchasesDesc->first()->supplier_id);
    }

    public function test_export_parity()
    {
        $this->createPurchase([
            'date' => '2023-01-01',
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $component->call('exportExcel');
        $component->assertFileDownloaded('usia_utang_2023-01-15.xlsx');

        $component->call('exportCsv');
        $component->assertFileDownloaded('usia_utang_2023-01-15.csv');

        $component->call('exportPdf');
        $component->assertFileDownloaded('usia_utang_2023-01-15.pdf');
    }

    public function test_stale_export_is_blocked()
    {
        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15');
        
        $component->call('exportExcel');
        $component->assertDispatched('alert');
        $component->assertHasNoErrors();
    }

    public function test_invalidated_payment_is_excluded()
    {
        $purchase = $this->createPurchase([
            'date' => '2023-01-01',
            'total_amount' => 100000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 40000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'DELETED'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $grandTotals = $component->viewData('grandTotals');
        // Payment is DELETED, so it shouldn't be counted. Full balance remains.
        $this->assertEquals(100000, $grandTotals['Total']);
    }

    public function test_aged_payables_bucket_boundaries_future_due_date()
    {
        // Future due date (negative diff) - e.g. due 1 month after as-of date
        $this->createPurchase(['date' => '2022-01-01', 'due_date' => '2023-02-15', 'reference' => 'INV-FUTURE', 'total_amount' => 10000, 'due_amount' => 10000]);

        // Report as of 2023-01-15
        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->set('agingBasis', 'Tanggal Jatuh Tempo')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        
        $grandTotals = $component->viewData('grandTotals');
        // It should be lumped into Bucket 1
        $this->assertEquals(10000, $grandTotals['1 - 30 Hari']);
        $this->assertEquals(0, $grandTotals['31 - 60 Hari']);
        $this->assertEquals(0, $grandTotals['61 - 90 Hari']);
        $this->assertEquals(0, $grandTotals['> 90 Hari']);
        $this->assertEquals(10000, $grandTotals['Total']);
    }

    public function test_supplier_search()
    {
        $component = Livewire::actingAs($this->user)
            ->test(AgedPayablesReport::class)
            ->set('supplierSearch', 'supp');
            
        $options = $component->get('supplierOptions');
        $this->assertIsArray($options);
        $this->assertCount(2, $options);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Purchase\PurchaseTable;
use App\Livewire\Sale\SaleTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Modules\People\Entities\Customer;
use App\Models\User;
use Tests\TestCase;

class PurchaseSaleActionMenuAfterSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
    }

    private function getUser(): User
    {
        return User::factory()->create();
    }

    private function createSetting(): Setting
    {
        $currency = Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
            'exchange_rate'      => 1,
        ]);

        return Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '123456789',
            'notification_email'        => 'notify@example.com',
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text'               => 'Footer',
            'company_address'           => 'Address',
        ]);
    }

    /**
     * Test that Purchase table action menu is accessible after search
     * Asserts the rendered HTML contains:
     * - The purchase table root marker (data-purchase-table-root)
     * - The expected purchase-action-{id}-{refreshId} key
     */
    public function test_purchase_action_menu_after_search()
    {
        $setting = $this->createSetting();

        $supplier = Supplier::create([
            'supplier_name'   => 'Test Supplier',
            'supplier_email'  => 'test@example.com',
            'supplier_phone'  => '123456789',
            'address'         => 'Test Address',
            'city'            => 'Test City',
            'country'         => 'Test Country',
            'setting_id'      => $setting->id,
        ]);

        $purchase = Purchase::create([
            'date'                    => now(),
            'due_date'                => now()->addDays(30),
            'reference'               => 'PO-001',
            'supplier_purchase_number' => 'SUP-REF-001',
            'supplier_id'             => $supplier->id,
            'status'                  => Purchase::STATUS_RECEIVED,
            'total_amount'            => 100000,
            'paid_amount'             => 0,
            'due_amount'              => 100000,
            'payment_status'          => 'UNPAID',
            'payment_method'          => 'Cash',
            'tax_percentage'          => 0,
            'discount_percentage'     => 0,
            'shipping_amount'         => 0,
            'setting_id'              => $setting->id,
        ]);

        $user = $this->getUser();

        // Test initial render
        Livewire::actingAs($user)
            ->test(PurchaseTable::class, ['settingId' => $setting->id])
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->contains('id', $purchase->id);
            })
            // Verify the table root is marked for scoping
            ->assertSee('data-purchase-table-root');

        // Test search and verify action menu is present with correct wire:key
        Livewire::actingAs($user)
            ->test(PurchaseTable::class, ['settingId' => $setting->id])
            ->set('searchText', 'PO-001')
            ->call('searchSubmit')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->count() === 1 && $purchases->first()->id === $purchase->id;
            })
            // Verify the rendered contract: table root marked and action menu with expected wire:key pattern
            ->assertSee('data-purchase-table-root')
            ->assertSee("purchase-action-{$purchase->id}-");
    }

    /**
     * Test that Sales table action menu is accessible after search
     * Asserts the rendered HTML contains:
     * - The sale table root marker (data-sale-table-root)
     * - The expected sale-action-{id}-{refreshId} key
     */
    public function test_sale_action_menu_after_search()
    {
        $setting = $this->createSetting();

        $customer = Customer::create([
            'setting_id'     => $setting->id,
            'customer_name'  => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '123456789',
            'city'           => 'Test City',
        ]);

        $sale = Sale::create([
            'date'           => now(),
            'reference'      => 'SO-001',
            'customer_id'    => $customer->id,
            'customer_name'  => $customer->customer_name,
            'status'         => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount'   => 100000,
            'paid_amount'    => 0,
            'due_amount'     => 100000,
            'due_date'       => now()->addDays(30),
            'setting_id'     => $setting->id,
        ]);

        $user = $this->getUser();

        // Test initial render
        Livewire::actingAs($user)
            ->test(SaleTable::class, ['settingId' => $setting->id])
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->contains('id', $sale->id);
            })
            // Verify the table root is marked for scoping
            ->assertSee('data-sale-table-root');

        // Test search and verify action menu is present with correct wire:key
        Livewire::actingAs($user)
            ->test(SaleTable::class, ['settingId' => $setting->id])
            ->set('searchText', 'SO-001')
            ->call('searchSubmit')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->count() === 1 && $sales->first()->id === $sale->id;
            })
            // Verify the rendered contract: table root marked and action menu with expected wire:key pattern
            ->assertSee('data-sale-table-root')
            ->assertSee("sale-action-{$sale->id}-");
    }

    /**
     * Test that Purchase table action menu remains accessible after clearing search
     */
    public function test_purchase_action_menu_persists_after_clear_search()
    {
        $setting = $this->createSetting();

        $supplier = Supplier::create([
            'supplier_name'   => 'Test Supplier',
            'supplier_email'  => 'test@example.com',
            'supplier_phone'  => '123456789',
            'address'         => 'Test Address',
            'city'            => 'Test City',
            'country'         => 'Test Country',
            'setting_id'      => $setting->id,
        ]);

        $purchase = Purchase::create([
            'date'                    => now(),
            'due_date'                => now()->addDays(30),
            'reference'               => 'PO-002',
            'supplier_purchase_number' => 'SUP-REF-002',
            'supplier_id'             => $supplier->id,
            'status'                  => Purchase::STATUS_RECEIVED,
            'total_amount'            => 100000,
            'paid_amount'             => 0,
            'due_amount'              => 100000,
            'payment_status'          => 'UNPAID',
            'payment_method'          => 'Cash',
            'tax_percentage'          => 0,
            'discount_percentage'     => 0,
            'shipping_amount'         => 0,
            'setting_id'              => $setting->id,
        ]);

        $user = $this->getUser();

        // Test search then clear
        Livewire::actingAs($user)
            ->test(PurchaseTable::class, ['settingId' => $setting->id])
            ->set('searchText', 'PO-002')
            ->call('searchSubmit')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->count() === 1 && $purchases->first()->id === $purchase->id;
            })
            ->call('clearSearch')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() >= 1;
            })
            // Verify action menu is still accessible after clearing search
            ->assertSee('three-dots');
    }

    /**
     * Test that Sales table action menu remains accessible after clearing search
     */
    public function test_sale_action_menu_persists_after_clear_search()
    {
        $setting = $this->createSetting();

        $customer = Customer::create([
            'setting_id'     => $setting->id,
            'customer_name'  => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '123456789',
            'city'           => 'Test City',
        ]);

        $sale = Sale::create([
            'date'           => now(),
            'reference'      => 'SO-002',
            'customer_id'    => $customer->id,
            'customer_name'  => $customer->customer_name,
            'status'         => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount'   => 100000,
            'paid_amount'    => 0,
            'due_amount'     => 100000,
            'due_date'       => now()->addDays(30),
            'setting_id'     => $setting->id,
        ]);

        $user = $this->getUser();

        // Test search then clear
        Livewire::actingAs($user)
            ->test(SaleTable::class, ['settingId' => $setting->id])
            ->set('searchText', 'SO-002')
            ->call('searchSubmit')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->count() === 1 && $sales->first()->id === $sale->id;
            })
            ->call('clearSearch')
            ->assertViewHas('sales', function ($sales) {
                return $sales->count() >= 1;
            })
            // Verify action menu is still accessible after clearing search
            ->assertSee('three-dots');
    }

    /**
     * Test that refresh-sensitive keys generate new Alpine instances on follow-up search
     * Asserts the second result's action key is present, first result's is absent, and refresh ID increased
     */
    public function test_purchase_action_menu_refresh_id_changes_on_search()
    {
        $setting = $this->createSetting();

        $supplier = Supplier::create([
            'supplier_name'   => 'Test Supplier',
            'supplier_email'  => 'test@example.com',
            'supplier_phone'  => '123456789',
            'address'         => 'Test Address',
            'city'            => 'Test City',
            'country'         => 'Test Country',
            'setting_id'      => $setting->id,
        ]);

        $purchase1 = Purchase::create([
            'date'                    => now(),
            'due_date'                => now()->addDays(30),
            'reference'               => 'PO-Alpha',
            'supplier_purchase_number' => 'SUP-REF-001',
            'supplier_id'             => $supplier->id,
            'status'                  => Purchase::STATUS_RECEIVED,
            'total_amount'            => 100000,
            'paid_amount'             => 0,
            'due_amount'              => 100000,
            'payment_status'          => 'UNPAID',
            'payment_method'          => 'Cash',
            'tax_percentage'          => 0,
            'discount_percentage'     => 0,
            'shipping_amount'         => 0,
            'setting_id'              => $setting->id,
        ]);

        $purchase2 = Purchase::create([
            'date'                    => now(),
            'due_date'                => now()->addDays(30),
            'reference'               => 'PO-Beta',
            'supplier_purchase_number' => 'SUP-REF-002',
            'supplier_id'             => $supplier->id,
            'status'                  => Purchase::STATUS_RECEIVED,
            'total_amount'            => 200000,
            'paid_amount'             => 0,
            'due_amount'              => 200000,
            'payment_status'          => 'UNPAID',
            'payment_method'          => 'Cash',
            'tax_percentage'          => 0,
            'discount_percentage'     => 0,
            'shipping_amount'         => 0,
            'setting_id'              => $setting->id,
        ]);

        $user = $this->getUser();

        // First search for Alpha and get initial refresh ID
        $component = Livewire::actingAs($user)
            ->test(PurchaseTable::class, ['settingId' => $setting->id])
            ->set('searchText', 'PO-Alpha')
            ->call('searchSubmit')
            ->assertViewHas('purchases', function ($purchases) use ($purchase1) {
                return $purchases->pluck('id')->contains($purchase1->id);
            });

        $firstSearchRefreshId = $component->get('tableRefreshId');

        // Then search for Beta
        $component->set('searchText', 'PO-Beta')
            ->call('searchSubmit')
            ->assertViewHas('purchases', function ($purchases) use ($purchase2) {
                return $purchases->pluck('id')->contains($purchase2->id);
            });

        $secondSearchRefreshId = $component->get('tableRefreshId');

        // Verify refresh ID incremented on second search
        $this->assertGreaterThan($firstSearchRefreshId, $secondSearchRefreshId,
            'tableRefreshId should increment after second search');

        // Get the rendered HTML from the last response
        $html = $component->html();

        // Verify second result action key is present
        $this->assertStringContainsString("purchase-action-{$purchase2->id}-",
            $html,
            'Second result action key should be present in rendered HTML');

        // Verify first result action key is absent (search filtered to only Beta)
        $this->assertStringNotContainsString("purchase-action-{$purchase1->id}-",
            $html,
            'First result action key should be absent after new search');
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use App\Livewire\PurchaseReturn\PurchaseReturnTable;
use App\Livewire\PurchaseReturn\PurchaseReturnCreateForm;
use App\Livewire\PurchaseReturn\PurchaseOrderSerialNumberLoader;

class PurchaseReturnSerialLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            VerifyCsrfToken::class,
        ]);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Tenant A',
            'company_email'             => 'tenant_a@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'tenant_a@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        
        $this->location = Location::create([
            'name' => 'Location 1',
            'setting_id' => $this->setting->id
        ]);

        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serial Product',
            'product_code' => 'SP01',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'serial_number_required' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN123',
            'status' => 'active',
        ]);

        session(['setting_id' => $this->setting->id]);
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        Gate::shouldReceive('allows')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('check')->andReturnTrue()->zeroOrMoreTimes();
    }

    /**
     * Scenario: Serial lookup auto-fills and locks location
     */
    public function test_serial_lookup_auto_fills_and_locks_location(): void
    {
        $serialObj = ProductSerialNumber::where('serial_number', 'SN123')->first();
        
        Livewire::test(PurchaseReturnTable::class, ['supplierId' => $this->supplier->id])
            ->set('supplierId', $this->supplier->id)
            ->call('addProductRow')
            // Emit product selection manually since it's a listener
            ->dispatch('productSelected', 0, [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'last_purchase_price' => 5000,
                'serial_number_required' => true,
            ])
            // Emit serial selection manually as if from loader
            ->dispatch('serialNumberSelected', 0, [
                'id' => $serialObj->id,
                'serial_number' => 'SN123',
                'location_id' => $this->location->id,
                'location_name' => 'LOCATION 1',
                'location_label' => 'TENANT A - LOCATION 1',
                'purchase_order_id' => 123,
                'purchase_order_reference' => 'PO-123',
                'purchase_order_date' => '2025-01-15',
            ])
            ->assertSet('rows.0.location_id', $this->location->id)
            ->assertSet('rows.0.location_name', 'TENANT A - LOCATION 1')
            ->assertSet('rows.0.location_locked', true)
            ->assertSet('rows.0.purchase_order_id', 123)
            ->assertSet('rows.0.purchase_order_locked', true);
    }

    /**
     * Scenario: Location unlocks when all serials removed
     */
    public function test_location_remains_locked_for_serial_products(): void
    {
        Livewire::test(PurchaseReturnTable::class, ['supplierId' => $this->supplier->id])
            ->set('supplierId', $this->supplier->id)
            ->call('addProductRow')
            ->dispatch('productSelected', 0, [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'last_purchase_price' => 5000,
                'serial_number_required' => true,
            ])
            ->assertSet('rows.0.location_locked', true)
            ->assertSet('rows.0.purchase_order_locked', true);
    }

    /**
     * Scenario: Serial loader finds serial and emits location and PO
     */
    public function test_serial_loader_finds_serial_and_emits_location_and_po(): void
    {
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'RECEIVED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'supplier_name' =>'Supplier Test',
            'due_date' => now(),
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
        ]);

        $rn = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => 'APPROVED',
        ]);

        $pod = \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $rnd = \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $pod->id,
            'quantity_received' => 1,
        ]);

        $serialObj = ProductSerialNumber::where('serial_number', 'SN123')->first();
        $serialObj->update(['received_note_detail_id' => $rnd->id]);

        Livewire::test(PurchaseOrderSerialNumberLoader::class, [
                'index' => 0,
                'product_id' => $this->product->id
            ])
            ->set('query', 'SN123')
            ->call('addSerial')
            ->assertDispatched('serialNumberSelected', 0, [
                'id' => $serialObj->id,
                'serial_number' => 'SN123',
                'location_id' => $this->location->id,
                'location_name' => 'LOCATION 1',
                'location_label' => 'TENANT A - LOCATION 1',
                'purchase_order_id' => $purchase->id,
                'purchase_order_reference' => $purchase->reference,
                'purchase_order_date' => \Carbon\Carbon::parse($purchase->date)->format('Y-m-d'),
            ]);
    }

    /**
     * Scenario: Serial from different location triggers validation error
     */
    public function test_serial_from_different_location_shows_error(): void
    {
        // Create two locations
        $location2 = Location::create([
            'name' => 'Location 2',
            'setting_id' => $this->setting->id
        ]);

        // Create serial at location2
        $serial2 = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $location2->id,
            'serial_number' => 'SN456',
            'status' => 'active',
        ]);

        // Existing serials in the row (from first location)
        $existingSerials = [
            [
                'id' => 1,
                'serial_number' => 'SN123',
                'location_id' => $this->location->id,
                'purchase_order_id' => 1,
            ]
        ];

        Livewire::test(PurchaseOrderSerialNumberLoader::class, [
                'index' => 0,
                'product_id' => $this->product->id,
                'existingSerials' => $existingSerials,
            ])
            ->set('query', 'SN456')
            ->call('addSerial')
            ->assertSet('error_message', 'Nomor seri berasal dari lokasi yang berbeda, tambahkan baris baru dan scan ulang nomor seri.')
            ->assertNotDispatched('serialNumberSelected');
    }
}

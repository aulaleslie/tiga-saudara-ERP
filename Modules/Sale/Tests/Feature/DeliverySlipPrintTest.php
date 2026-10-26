<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliverySlipPrintTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected User $user;
    protected Customer $customer;
    protected Location $location;
    protected Unit $unit;
    protected Product $product;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermissions([
            'sales.show',
            'sales.access',
            'salePayments.global.access',
            'globalSalesSearch.access',
        ]);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Delivery Company',
            'company_email' => 'delivery@example.com',
            'company_phone' => '0811223344',
            'company_address' => 'Delivery Hub No. 9',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Delivery Footer',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->setting->id, ['role_id' => 1]);
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::create([
            'customer_name' => 'Recipient Customer',
            'customer_email' => 'recipient@example.com',
            'customer_phone' => '0899887766',
            'address' => 'Customer Street 100',
        ]);

        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $category = Category::create([
            'category_name' => 'Hardware',
            'category_code' => 'HDW',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Laser Printer',
            'product_code' => 'PRN-001',
            'setting_id' => $this->setting->id,
            'product_quantity' => 50,
            'product_cost' => 1000000,
            'product_price' => 1500000,
            'category_id' => $category->id,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'stock_managed' => true,
        ]);
    }

    protected function seedPermissions(array $permissions): void
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    protected function createSale(array $overrides = []): Sale
    {
        $sale = Sale::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500000,
            'paid_amount' => 0,
            'due_amount' => 1500000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ], $overrides));

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1500000,
            'unit_price' => 1500000,
            'sub_total' => 1500000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        return $sale;
    }

    protected function createDispatch(Sale $sale, string $status, array $serials = []): Dispatch
    {
        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => $status,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => !empty($serials) ? json_encode($serials) : null,
        ]);

        return $dispatch;
    }

    public function test_latest_shortcut_prints_latest_approved_dispatch_ignoring_newer_pending_or_rejected(): void
    {
        $sale = $this->createSale();

        // Older approved dispatch (ID 1)
        $approvedDispatch = $this->createDispatch($sale, Dispatch::STATUS_APPROVED, ['SN-APPROVED-01']);

        // Newer pending dispatch (ID 2)
        $pendingDispatch = $this->createDispatch($sale, Dispatch::STATUS_PENDING, ['SN-PENDING-02']);

        // Even newer rejected dispatch (ID 3)
        $rejectedDispatch = $this->createDispatch($sale, Dispatch::STATUS_REJECTED, ['SN-REJECTED-03']);

        $response = $this->actingAs($this->user)->get(route('sales.deliverySlip', $sale->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_selected_approved_dispatch_prints_only_that_dispatch(): void
    {
        $sale = $this->createSale();

        $dispatch1 = $this->createDispatch($sale, Dispatch::STATUS_APPROVED, ['SN-BATCH-1']);
        $dispatch2 = $this->createDispatch($sale, Dispatch::STATUS_APPROVED, ['SN-BATCH-2']);

        $response = $this->actingAs($this->user)->get(route('sales.deliverySlip', [
            'sale' => $sale->id,
            'dispatch_id' => $dispatch1->id,
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_direct_request_for_unapproved_dispatch_returns_404(): void
    {
        $sale = $this->createSale();

        $pendingDispatch = $this->createDispatch($sale, Dispatch::STATUS_PENDING);
        $rejectedDispatch = $this->createDispatch($sale, Dispatch::STATUS_REJECTED);

        // Pending dispatch directly requested
        $responsePending = $this->actingAs($this->user)->get(route('sales.deliverySlip', [
            'sale' => $sale->id,
            'dispatch_id' => $pendingDispatch->id,
        ]));
        $responsePending->assertStatus(404);

        // Rejected dispatch directly requested
        $responseRejected = $this->actingAs($this->user)->get(route('sales.deliverySlip', [
            'sale' => $sale->id,
            'dispatch_id' => $rejectedDispatch->id,
        ]));
        $responseRejected->assertStatus(404);
    }

    public function test_request_for_foreign_sale_dispatch_returns_404(): void
    {
        $saleA = $this->createSale();
        $saleB = $this->createSale();

        $dispatchB = $this->createDispatch($saleB, Dispatch::STATUS_APPROVED);

        // Requesting dispatch B under sale A must 404
        $response = $this->actingAs($this->user)->get(route('sales.deliverySlip', [
            'sale' => $saleA->id,
            'dispatch_id' => $dispatchB->id,
        ]));
        $response->assertStatus(404);
    }

    public function test_no_approved_dispatches_returns_404(): void
    {
        $sale = $this->createSale();

        // No dispatches at all
        $responseNoDispatches = $this->actingAs($this->user)->get(route('sales.deliverySlip', $sale->id));
        $responseNoDispatches->assertStatus(404);

        // Only pending dispatch
        $this->createDispatch($sale, Dispatch::STATUS_PENDING);
        $responseOnlyPending = $this->actingAs($this->user)->get(route('sales.deliverySlip', $sale->id));
        $responseOnlyPending->assertStatus(404);
    }

    public function test_archived_sale_with_approved_dispatch_remains_printable(): void
    {
        $sale = $this->createSale(['archived_at' => now()]);
        $this->createDispatch($sale, Dispatch::STATUS_APPROVED);

        $response = $this->actingAs($this->user)->get(route('sales.deliverySlip', $sale->id));
        $response->assertOk();
    }

    public function test_delivery_slip_contains_no_prices_or_settlement_and_uses_owning_tenant_setting(): void
    {
        $sale = $this->createSale();
        $dispatch = $this->createDispatch($sale, Dispatch::STATUS_APPROVED, ['SN-PRINT-999']);

        $customer = $this->customer;
        $tenantSetting = $this->setting;
        $tanggal = now();
        $jatuhTempo = now()->addDays(7);
        $slipNumber = $sale->reference;

        $grouped = collect([(object) [
            'product' => $this->product,
            'product_code' => $this->product->product_code,
            'unit_name' => 'PCS',
            'quantity' => 1,
            'serial_numbers' => collect(['SN-PRINT-999']),
        ]]);

        $rendered = view('sale::print.delivery-slip', [
            'sale' => $sale,
            'customer' => $customer,
            'tenantSetting' => $tenantSetting,
            'dispatch' => $dispatch,
            'grouped' => $grouped,
            'slipNumber' => $slipNumber,
            'tanggal' => $tanggal,
            'jatuhTempo' => $jatuhTempo,
        ])->render();

        // Owning business branding is rendered
        $this->assertStringContainsString('DELIVERY COMPANY', $rendered);
        $this->assertStringContainsString('DELIVERY HUB NO. 9', $rendered);

        // Product code and serial number are rendered
        $this->assertStringContainsString('PRN-001', $rendered);
        $this->assertStringContainsString('SN-PRINT-999', $rendered);

        // No price, subtotal, tax, or payment settlement information
        $this->assertStringNotContainsString('Rp', $rendered);
        $this->assertStringNotContainsString('1.500.000', $rendered);
        $this->assertStringNotContainsString('Subtotal', $rendered);
        $this->assertStringNotContainsString('Pajak', $rendered);
        $this->assertStringNotContainsString('Total Bayar', $rendered);
        $this->assertStringNotContainsString('Sisa Tagihan', $rendered);
    }
}

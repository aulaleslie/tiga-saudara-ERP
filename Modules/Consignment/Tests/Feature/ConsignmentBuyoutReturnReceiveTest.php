<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\People\Entities\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsignmentBuyoutReturnReceiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scout.driver', null);

        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

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
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        session(['setting_id' => 1]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Gate::define('saleReturns.receive', fn () => true);
        Gate::define('saleReturnSettlements.approve', fn () => true);
    }

    /**
     * @return array{sale: Sale, saleReturn: SaleReturn, saleReturnDetail: SaleReturnDetail, product: Product, stock: ProductStock, serial: ProductSerialNumber, location: Location}
     */
    /** Records the financial review the settlement form performs on submit (derived from the source sale). */
    protected function recordFinancialReview(SaleReturnItemSettlement $item): void
    {
        $derived = app(\App\Support\SalesReturn\StandardFinancialService::class)->resolve($item->fresh());
        $item->forceFill(['nominal' => $derived['value'], 'reviewed_value' => $derived['value'], 'reviewed_fingerprint' => $derived['fingerprint']])->save();
    }

    protected function createSingleSerialSaleReturnFixture(): array
    {
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create([
            'setting_id' => 1,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $location = Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP-001',
            'product_quantity' => 0,
            'product_cost' => 1000,
            'product_price' => 1500,
            'setting_id' => 1,
            'serial_number_required' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => 1,
            'is_tax_included' => false,
            'reference' => 'SO-RET-001',
        ]);

        $saleDetailRow = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode(['SN-RET-001']),
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'serial_number' => 'SN-RET-001',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $sale->id,
            'product_serial_number_id' => $serial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
            'return_date' => null,
        ]);

        $saleReturn = SaleReturn::create([
            'sale_id' => $sale->id,
            'setting_id' => 1,
            'reference' => 'SR-RET-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'location_id' => $location->id,
            'date' => now()->toDateString(),
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'status' => 'Awaiting Receiving',
        ]);

        $saleReturnDetail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_detail_id' => $saleDetailRow->id,
            'location_id' => $location->id,
            'tax_id' => null,
            'serial_number_ids' => [$serial->id],
        ]);

        return [
            'sale' => $sale,
            'saleReturn' => $saleReturn,
            'saleReturnDetail' => $saleReturnDetail,
            'product' => $product,
            'stock' => $stock,
            'serial' => $serial,
            'location' => $location,
        ];
    }

    /** @return array{0: ConsignmentBuyoutLifecycleService, 1: \Modules\Consignment\Entities\ConsignmentBuyout, 2: ProductSerialNumber, 3: User} */
    private function approvedSerialBuyout(array $fixture): array
    {
        $supplier = Supplier::create(['setting_id' => 1, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $receival = ConsignmentReceival::create(['setting_id' => 1, 'supplier_id' => $supplier->id, 'reference' => 'CR-R',
            'receival_number' => 'CR-R', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => 1, 'location_id' => $fixture['location']->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => 'CR-R', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $fixture['product']->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => 1, 'unit_cost' => 1, 'unit_dpp' => 1,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $detail = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $fixture['product']->id, 'quantity_received' => 1, 'received_base_quantity' => 1, 'unit_cost' => 1, 'unit_dpp' => 1]);
        $free = ProductSerialNumber::create(['product_id' => $fixture['product']->id, 'location_id' => $fixture['location']->id,
            'serial_number' => 'SN-FREE-1', 'status' => 'ACTIVE', 'consignment_receiving_detail_id' => $detail->id]);
        $dest = Location::create(['name' => 'Std Dest', 'setting_id' => 1, 'is_consignment' => false]);

        $buyoutUser = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::findOrCreate($p, 'web');
            $buyoutUser->givePermissionTo($p);
        }
        $buyoutUser->settings()->attach(1, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft(1, $supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $detail->id, 'destination_location_id' => $dest->id,
            'base_quantity' => '1', 'serial_ids' => [$free->id],
        ]], $buyoutUser);

        return [$svc, $svc->approve($svc->submit($b, $buyoutUser), $buyoutUser), $free, $buyoutUser];
    }

    /** @test */
    public function receiving_a_return_preserves_buyout_reservations_and_coordinates_the_product(): void
    {
        $fixture = $this->createSingleSerialSaleReturnFixture();
        [, $buyout, $free] = $this->approvedSerialBuyout($fixture);
        $before = $buyout->reservations()->where('status', 'ACTIVE')->count();

        $this->post(route('sale-returns.receive', $fixture['saleReturn']))->assertRedirect();

        $this->assertSame('AWAITING SETTLEMENT', strtoupper((string) $fixture['saleReturn']->fresh()->status));
        $this->assertTrue((bool) $fixture['serial']->fresh()->is_in_return_process);
        // The buyout's claim and reservations are untouched, and its serial stayed ACTIVE and unclaimed by the return.
        $this->assertSame($before, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->count());
        $this->assertSame('ACTIVE', strtoupper($free->fresh()->status));
        $this->assertDatabaseHas('consignment_inventory_coordinations', ['setting_id' => 1, 'product_id' => $fixture['product']->id]);
    }

    /** @test */
    public function a_failed_return_receive_rolls_back_completely_and_keeps_the_reservations(): void
    {
        $fixture = $this->createSingleSerialSaleReturnFixture();
        [, $buyout] = $this->approvedSerialBuyout($fixture);

        // Inject a failure after the serial/custody writes have happened.
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: ' . SerialNumberHistory::class,
            function () { throw new \RuntimeException('injected failure after writes'); });

        $this->post(route('sale-returns.receive', $fixture['saleReturn']))->assertRedirect();
        \Illuminate\Support\Facades\Event::forget('eloquent.creating: ' . SerialNumberHistory::class);

        $this->assertSame('awaiting receiving', strtolower((string) $fixture['saleReturn']->fresh()->status));
        $this->assertFalse((bool) $fixture['serial']->fresh()->is_in_return_process);
        $this->assertSame('SOLD', strtoupper($fixture['serial']->fresh()->status));
        $this->assertSame(0, \Modules\SalesReturn\Entities\SaleReturnCustody::count());
        $this->assertSame(0, \DB::table('sale_return_custody_movements')->count());
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->count());
    }
}

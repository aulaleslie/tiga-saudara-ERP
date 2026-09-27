<?php

namespace Modules\SalesReturn\Tests\Feature;

use App\Support\SalesReturn\SaleReturnLifecycleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReturnLifecycleCoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;

    protected Customer $customer;

    protected Location $location;

    protected Product $productA;

    protected Product $productB;

    protected SaleReturnLifecycleSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scout.driver', null);

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
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

        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $this->location = Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $this->productA = Product::create([
            'product_name' => 'Coverage Product A',
            'product_code' => 'COV-A',
            'product_quantity' => 0,
            'product_cost' => 1000,
            'product_price' => 1500,
            'setting_id' => $this->setting->id,
        ]);

        $this->productB = Product::create([
            'product_name' => 'Coverage Product B',
            'product_code' => 'COV-B',
            'product_quantity' => 0,
            'product_cost' => 1000,
            'product_price' => 1500,
            'setting_id' => $this->setting->id,
        ]);

        $this->service = app(SaleReturnLifecycleSyncService::class);
    }

    protected function createSale(array $lines): array
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
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
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-COV-' . uniqid(),
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetails = [];
        foreach ($lines as $line) {
            SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $line['product']->id,
                'product_name' => $line['product']->product_name,
                'product_code' => $line['product']->product_code,
                'quantity' => $line['quantity'],
                'price' => 1500,
                'unit_price' => 1500,
                'sub_total' => 1500 * $line['quantity'],
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'tax_id' => null,
            ]);

            $dispatchDetails[] = DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'product_id' => $line['product']->id,
                'dispatched_quantity' => $line['quantity'],
                'location_id' => $this->location->id,
                'tax_id' => null,
                'serial_numbers' => json_encode([]),
            ]);
        }

        return [$sale, $dispatchDetails];
    }

    protected function createReceivedSaleReturn(
        Sale $sale,
        array $items,
        string $status = 'AWAITING SETTLEMENT'
    ): SaleReturn {
        $saleReturn = SaleReturn::create([
            'sale_id' => $sale->id,
            'setting_id' => $this->setting->id,
            'reference' => 'SR-COV-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'location_id' => $this->location->id,
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
            'status' => $status,
        ]);

        foreach ($items as $item) {
            SaleReturnDetail::create([
                'sale_return_id' => $saleReturn->id,
                'product_id' => $item['product']->id,
                'product_name' => $item['product']->product_name,
                'product_code' => $item['product']->product_code,
                'quantity' => $item['quantity'],
                'price' => 1500,
                'unit_price' => 1500,
                'sub_total' => 1500 * $item['quantity'],
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'dispatch_detail_id' => $item['dispatch_detail_id'] ?? null,
                'location_id' => $this->location->id,
                'tax_id' => null,
                'serial_number_ids' => [],
            ]);
        }

        return $saleReturn;
    }

    /** @test */
    public function one_line_full_coverage_is_reported_as_fully_covered(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([
            ['product' => $this->productA, 'quantity' => 2],
        ]);

        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id],
        ]);

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertTrue($coverage['fully_covered']);
        $this->assertSame(0.0, $coverage['ambiguous_quantity']);
    }

    /** @test */
    public function partial_coverage_is_not_fully_covered(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([
            ['product' => $this->productA, 'quantity' => 2],
        ]);

        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 1, 'dispatch_detail_id' => $dispatchDetails[0]->id],
        ]);

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertFalse($coverage['fully_covered']);
    }

    /** @test */
    public function multi_line_uneven_coverage_is_not_fully_covered_when_one_line_is_short(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([
            ['product' => $this->productA, 'quantity' => 2],
            ['product' => $this->productB, 'quantity' => 3],
        ]);

        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id],
            ['product' => $this->productB, 'quantity' => 1, 'dispatch_detail_id' => $dispatchDetails[1]->id],
        ]);

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertFalse($coverage['fully_covered'], 'Over-return on one line must not hide under-return on another.');
        $this->assertTrue($coverage['dispatch_lines'][$dispatchDetails[0]->id]['covered']);
        $this->assertFalse($coverage['dispatch_lines'][$dispatchDetails[1]->id]['covered']);
    }

    /** @test */
    public function cumulative_multiple_returns_can_fully_cover_a_single_line(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([
            ['product' => $this->productA, 'quantity' => 3],
        ]);

        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 1, 'dispatch_detail_id' => $dispatchDetails[0]->id],
        ]);
        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id],
        ], 'COMPLETED');

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertTrue($coverage['fully_covered']);
    }

    /** @test */
    public function rejected_and_unreceived_returns_are_excluded_from_coverage(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([
            ['product' => $this->productA, 'quantity' => 2],
        ]);

        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id],
        ], 'Awaiting Receiving');

        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id],
        ], 'Rejected');

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertFalse($coverage['fully_covered'], 'Unreceived and rejected return quantities must not prove coverage.');
    }

    /** @test */
    public function ambiguous_legacy_lineage_without_dispatch_detail_cannot_prove_coverage(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([
            ['product' => $this->productA, 'quantity' => 2],
        ]);

        $this->createReceivedSaleReturn($sale, [
            ['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => null],
        ]);

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertFalse($coverage['fully_covered'], 'Ambiguous legacy return lineage must not archive a Sale.');
        $this->assertSame(2.0, $coverage['ambiguous_quantity']);
    }
}

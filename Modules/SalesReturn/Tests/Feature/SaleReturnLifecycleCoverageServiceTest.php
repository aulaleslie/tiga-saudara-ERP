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
        string $status = 'AWAITING SETTLEMENT',
        string $settlementStatus = 'APPROVED'
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
            // authoritative physical-receiving evidence exists only for received returns
            'received_at' => in_array(strtoupper($status), ['AWAITING SETTLEMENT', 'COMPLETED'], true) ? now() : null,
        ]);

        foreach ($items as $item) {
            $detail = SaleReturnDetail::create([
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

            // Only RECEIVED returns carry settlements; a final (APPROVED) cash settlement is what proves coverage.
            if (in_array(strtoupper($status), ['AWAITING SETTLEMENT', 'COMPLETED'], true)) {
                \Modules\SalesReturn\Entities\SaleReturnItemSettlement::create([
                    'sale_return_id' => $saleReturn->id,
                    'sale_return_detail_id' => $detail->id,
                    'method' => 'CASH_REFUND',
                    'status' => $settlementStatus,
                    'nominal' => 1500 * $item['quantity'],
                ]);
            }
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

    /** @test */
    public function a_pending_cash_contributor_blocks_full_coverage(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([['product' => $this->productA, 'quantity' => 2]]);

        // Final cash settlements already cover the whole line; one more contributing settlement is unresolved.
        $this->createReceivedSaleReturn($sale, [['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id]]);
        $this->createReceivedSaleReturn($sale, [['product' => $this->productA, 'quantity' => 1, 'dispatch_detail_id' => $dispatchDetails[0]->id]], 'AWAITING SETTLEMENT', 'SUBMITTED');

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertTrue($coverage['dispatch_lines'][$dispatchDetails[0]->id]['covered']);
        $this->assertSame(1, $coverage['pending_contributors']);
        $this->assertFalse($coverage['fully_covered'], 'a pending contributor blocks archival even when the line is covered');
    }

    /** @test */
    public function service_quantities_never_prove_cash_coverage(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([['product' => $this->productA, 'quantity' => 2]]);
        $return = $this->createReceivedSaleReturn($sale, [['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id]], 'AWAITING SETTLEMENT', 'DISPATCHED');
        \Modules\SalesReturn\Entities\SaleReturnItemSettlement::query()->where('sale_return_id', $return->id)->update(['method' => 'REPAIR']);

        $this->assertFalse($this->service->calculateEffectiveStandardReturnCoverage($sale->id)['fully_covered']);
    }

    // ------------------------------------------------------------------ review follow-up

    /** @test */
    public function rejected_or_unreceived_parents_contribute_neither_finals_nor_pending(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([['product' => $this->productA, 'quantity' => 2]]);
        $line = ['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id];

        $rejected = $this->createReceivedSaleReturn($sale, [$line]);
        $rejected->forceFill(['approval_status' => 'rejected'])->save();

        $unreceived = $this->createReceivedSaleReturn($sale, [$line]);
        $unreceived->forceFill(['received_at' => null])->save();

        // an unresolved settlement on an invalid parent is not a pending contributor either
        $invalidPending = $this->createReceivedSaleReturn($sale, [$line], 'AWAITING SETTLEMENT', 'SUBMITTED');
        $invalidPending->forceFill(['approval_status' => 'rejected'])->save();

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertFalse($coverage['fully_covered'], 'a rejected or unreceived return cannot prove coverage');
        $this->assertSame(0.0, $coverage['dispatch_lines'][$dispatchDetails[0]->id]['returned']);
        $this->assertSame(0, $coverage['pending_contributors']);
        $this->assertFalse(\App\Support\SalesReturn\StandardReturnCoverage::returnCompletion($rejected->fresh())['complete']);
        $this->assertFalse(\App\Support\SalesReturn\StandardReturnCoverage::returnCompletion($unreceived->fresh())['complete']);
    }

    /** @test */
    public function duplicate_final_settlements_never_count_a_unit_twice_and_block_completion(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([['product' => $this->productA, 'quantity' => 2]]);
        $return = $this->createReceivedSaleReturn($sale, [['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id]]);
        $detailId = SaleReturnDetail::query()->where('sale_return_id', $return->id)->value('id');
        // a second final cash settlement for the very same (detail, no serial) occurrence
        \Modules\SalesReturn\Entities\SaleReturnItemSettlement::create(['sale_return_id' => $return->id, 'sale_return_detail_id' => $detailId, 'method' => 'CASH_REFUND', 'status' => 'APPROVED', 'nominal' => 1]);

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertFalse($coverage['fully_covered']);
        $this->assertSame(0.0, $coverage['dispatch_lines'][$dispatchDetails[0]->id]['returned'], 'neither duplicate counts');
        $this->assertSame(2.0, $coverage['ambiguous_quantity']);
        $this->assertFalse(\App\Support\SalesReturn\StandardReturnCoverage::returnCompletion($return->fresh())['complete']);
    }

    /** @test */
    public function serial_cardinality_must_match_the_received_quantity(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([['product' => $this->productA, 'quantity' => 3]]);
        $return = $this->createReceivedSaleReturn($sale, [['product' => $this->productA, 'quantity' => 3, 'dispatch_detail_id' => $dispatchDetails[0]->id]]);
        $detail = SaleReturnDetail::query()->where('sale_return_id', $return->id)->first();
        \Modules\SalesReturn\Entities\SaleReturnItemSettlement::query()->where('sale_return_id', $return->id)->delete();

        // quantity 3 but only two serial ids recorded; both serial-level cash settlements are final
        $serialIds = [];
        foreach ([1, 2] as $n) {
            $serialIds[] = \Modules\Product\Entities\ProductSerialNumber::create([
                'product_id' => $this->productA->id, 'dispatch_detail_id' => $dispatchDetails[0]->id,
                'serial_number' => 'COV-SN-' . $n, 'location_id' => $this->location->id, 'status' => 'SOLD',
            ])->id;
        }
        $detail->forceFill(['serial_number_ids' => $serialIds])->save();
        foreach ($serialIds as $serialId) {
            \Modules\SalesReturn\Entities\SaleReturnItemSettlement::create([
                'sale_return_id' => $return->id, 'sale_return_detail_id' => $detail->id, 'product_serial_number_id' => $serialId,
                'method' => 'CASH_REFUND', 'status' => 'APPROVED', 'nominal' => 1,
            ]);
        }

        $completion = \App\Support\SalesReturn\StandardReturnCoverage::returnCompletion($return->fresh());
        $this->assertFalse($completion['complete'], 'two serials cannot settle a quantity of three');
        $this->assertNotEmpty($completion['missing']);
        $this->assertFalse($this->service->calculateEffectiveStandardReturnCoverage($sale->id)['fully_covered']);
    }

    /** @test */
    public function finality_depends_on_the_settlement_method(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([['product' => $this->productA, 'quantity' => 1]]);
        $line = ['product' => $this->productA, 'quantity' => 1, 'dispatch_detail_id' => $dispatchDetails[0]->id];

        foreach ([['REPAIR', 'APPROVED', false], ['REPAIR', 'APPROVED_AWAITING_DISPATCH', false], ['REPAIR', 'DISPATCHED', true],
                  ['UNPROCESSED', 'APPROVED', false], ['UNPROCESSED', 'DISPATCHED', true],
                  ['CASH_REFUND', 'DISPATCHED', false], ['CASH_REFUND', 'APPROVED', true]] as [$method, $status, $expected]) {
            $return = $this->createReceivedSaleReturn($sale, [$line], 'AWAITING SETTLEMENT', $status);
            \Modules\SalesReturn\Entities\SaleReturnItemSettlement::query()->where('sale_return_id', $return->id)->update(['method' => $method]);

            $this->assertSame($expected, \App\Support\SalesReturn\StandardReturnCoverage::returnCompletion($return->fresh())['complete'], "{$method} + {$status}");
        }
    }

    /** @test */
    public function a_pending_dispatch_is_not_part_of_the_archival_denominator(): void
    {
        [$sale, $dispatchDetails] = $this->createSale([['product' => $this->productA, 'quantity' => 2]]);
        $this->createReceivedSaleReturn($sale, [['product' => $this->productA, 'quantity' => 2, 'dispatch_detail_id' => $dispatchDetails[0]->id]]);

        // a later dispatch that is still pending approval (and a zero-quantity one) is not a delivered line
        $pending = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now()->toDateString(), 'status' => Dispatch::STATUS_PENDING ?? 'PENDING']);
        DispatchDetail::create(['dispatch_id' => $pending->id, 'sale_id' => $sale->id, 'product_id' => $this->productB->id, 'dispatched_quantity' => 5, 'location_id' => $this->location->id, 'tax_id' => null, 'serial_numbers' => json_encode([])]);
        $zero = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now()->toDateString(), 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create(['dispatch_id' => $zero->id, 'sale_id' => $sale->id, 'product_id' => $this->productB->id, 'dispatched_quantity' => 0, 'location_id' => $this->location->id, 'tax_id' => null, 'serial_numbers' => json_encode([])]);

        $coverage = $this->service->calculateEffectiveStandardReturnCoverage($sale->id);

        $this->assertTrue($coverage['fully_covered'], 'only the approved, positive dispatched line is required');
        $this->assertCount(1, $coverage['dispatch_lines']);
    }
}

<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReceiptService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;

/**
 * Regression: LINE_UNIT_PRICE_OVERRIDE rows that retain a pack conversion
 * (transaction 11364) must print "20 PCS(S) @ Rp1.750", not "PACK(S) @ Rp175".
 */
class POSReceiptOverrideUnitPriceTest extends PosLineageSchemaAndCheckoutTest
{
    use RefreshDatabase;

    private Setting $setting;
    private User $cashier;
    private Location $loc;
    private array $methods;
    private Product $product;
    private ProductUnitConversion $conversion;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('pos.receipts.reprint', 'web');
    }

    private function bootFixture(): void
    {
        $this->setting = $this->createSetting('OVERRIDE STORE');
        $this->cashier = $this->createUserForSetting($this->setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $this->loc = Location::create(['name' => 'LOC OVR', 'setting_id' => $this->setting->id]);
        $this->createTerminalAndSaleLocations($this->setting, [$this->loc]);
        $this->methods = $this->seedPaymentMethods($this->setting, true);
        $this->openSession($this->setting, PosTerminal::where('setting_id', $this->setting->id)->first(), $this->cashier);

        $this->product = $this->createStockedProduct($this->setting, $this->loc, 'OVR-PROD', 1750, 100);
        $pack = Unit::query()->create(['name' => 'PACK', 'short_name' => 'PACK']);
        $this->conversion = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'base_unit_id' => $this->product->unit_id,
            'unit_id' => $pack->id,
            'conversion_factor' => 10,
            'selling_price' => 15000,
        ]);
    }

    private function overrideMeta(bool $withLabel = true): array
    {
        return [
            'price_source' => 'LINE_UNIT_PRICE_OVERRIDE',
            'breakdown' => array_filter([
                'box_count' => 0,
                'loose_count' => 20,
                'box_price_applied' => 1500000,
                'loose_price_applied' => 160000,
                'conversion_unit_label' => $withLabel ? 'PACK' : null,
                'base_unit_label' => $withLabel ? 'PCS' : null,
            ], fn ($v) => $v !== null),
        ];
    }

    private function applyOverride(PosTransactionLine $line, bool $withLabel = true): void
    {
        $line->update([
            'conversion_id' => $this->conversion->id,
            'unit_price' => 1750,
            'line_meta' => $this->overrideMeta($withLabel),
        ]);
    }

    private function draftTransaction(): PosTransaction
    {
        // Synthetic DRAFT fixture: reuse a real line built by checkout, then
        // mark the transaction as draft so the draft/loaded mapping is exercised.
        $txn = $this->completedCheckout()->transaction;
        $txn->update(['status' => PosTransaction::STATUS_DRAFT]);

        return $txn->fresh();
    }

    private function completedCheckout(): PosCheckout
    {
        $this->bootFixture();
        $this->addCartLine($this->cashier, $this->setting, $this->product->id, 20);
        $this->selectCustomerInCart($this->cashier, $this->setting, $this->assignDefaultWalkInCustomer($this->setting));
        $res = $this->finalize($this->cashier, $this->setting, [
            'idempotency_key' => 'K-OVR-' . uniqid(),
            'payment' => ['payment_method_id' => $this->methods['cash']->id, 'amount_paid' => 35000],
        ]);
        $res->assertStatus(201);

        return PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
    }

    private function assertOverrideBreakdown(array $line, string $price = 'Rp1.750'): void
    {
        $breakdown = (string) $line['unit_breakdown'];
        $this->assertStringContainsString('20 PCS(S) @', $breakdown);
        $this->assertStringContainsString(str_replace('Rp', '', $price), $breakdown);
        $this->assertStringNotContainsString('PACK', $breakdown);
        $this->assertStringNotContainsString('175,', $breakdown);
        $this->assertDoesNotMatchRegularExpression('/@\s*(Rp\s*)?175(\D|$)/', $breakdown);
    }

    public function test_draft_receipt_shows_override_in_base_unit(): void
    {
        $txn = $this->draftTransaction();
        $line = $txn->lines()->firstOrFail();
        $this->applyOverride($line);
        $line->refresh();
        $subtotalBefore = $line->only(['qty', 'unit_price', 'line_total', 'line_total_minor']);

        $data = app(PosReceiptService::class)->getTransactionReceiptData($txn->fresh());
        $this->assertOverrideBreakdown($data['lines'][0]);
        $this->assertEquals(1750.0, $data['lines'][0]['price']);
        $this->assertEquals(35000.0, (float) $data['lines'][0]['sub_total']);

        $html = view('pos::receipt', ['receiptData' => $data])->render();
        $this->assertStringContainsString('20 PCS(S) @', $html);
        $this->assertStringNotContainsString('PACK(S)', $html);

        $this->assertSame($subtotalBefore, $line->fresh()->only(array_keys($subtotalBefore)));
    }

    public function test_blank_breakdown_label_uses_pricing_basis_label(): void
    {
        $txn = $this->draftTransaction();
        $line = $txn->lines()->firstOrFail();
        $meta = $this->overrideMeta();
        $meta['breakdown']['base_unit_label'] = '  ';
        $meta['pricing_basis'] = ['base_unit_label' => 'PCS'];
        $line->update([
            'conversion_id' => $this->conversion->id,
            'unit_price' => 1750,
            'line_meta' => $meta,
        ]);

        $data = app(PosReceiptService::class)->getTransactionReceiptData($txn->fresh());
        $this->assertOverrideBreakdown($data['lines'][0]);
        $this->assertStringNotContainsString('PUNIT', (string) $data['lines'][0]['unit_breakdown']);
    }

    public function test_draft_receipt_falls_back_to_model_base_unit_without_label(): void
    {
        $txn = $this->draftTransaction();
        $line = $txn->lines()->firstOrFail();
        $this->applyOverride($line, false);

        $data = app(PosReceiptService::class)->getTransactionReceiptData($txn->fresh());
        $breakdown = (string) $data['lines'][0]['unit_breakdown'];
        $this->assertStringContainsString('20 PUNIT(S) @', $breakdown);
        $this->assertStringContainsString('1.750', $breakdown);
        $this->assertStringNotContainsString('PACK', $breakdown);
    }

    public function test_checkout_snapshot_and_historical_receipt_show_override_in_base_unit(): void
    {
        $checkout = $this->completedCheckout();
        $this->applyOverride($checkout->transaction->lines->first());

        $data = app(PosReceiptService::class)->getReceiptData($checkout->fresh());
        $this->assertOverrideBreakdown($data['lines'][0]);
        $this->assertEquals(35000.0, (float) $data['lines'][0]['sub_total']);

        $html = view('pos::receipt', ['receiptData' => $data])->render();
        $this->assertStringContainsString('20 PCS(S) @', $html);
        $this->assertStringNotContainsString('PACK(S)', $html);
    }

    public function test_completed_projection_uses_current_sale_price_with_base_unit_label(): void
    {
        $checkout = $this->completedCheckout();
        $this->applyOverride($checkout->transaction->lines->first());

        $projection = app(PosReceiptReprintProjectionService::class);
        $data = $projection->getCompletedReceiptData($checkout->fresh());
        $this->assertOverrideBreakdown($data['lines'][0]);

        // Later legitimate Sale price edit: current receipt follows it, historical keeps snapshot.
        $detail = $checkout->sale->saleDetails->first();
        $detail->update(['unit_price' => 1600, 'price' => 1600, 'sub_total' => 32000]);
        $checkout->sale->update(['total_amount' => 32000]);

        $current = $projection->getCompletedReceiptData($checkout->fresh());
        $currentBreakdown = (string) $current['lines'][0]['unit_breakdown'];
        $this->assertStringContainsString('20 PCS(S) @', $currentBreakdown);
        $this->assertStringContainsString('1.600', $currentBreakdown);
        $this->assertStringNotContainsString('PACK', $currentBreakdown);

        $historical = app(PosReceiptService::class)->getReceiptData($checkout->fresh());
        $this->assertOverrideBreakdown($historical['lines'][0]);

        $this->assertEquals(1750.0, (float) $checkout->transaction->lines->first()->fresh()->unit_price);
    }

    public function test_packed_rows_keep_existing_breakdown(): void
    {
        $txn = $this->draftTransaction();
        $line = $txn->lines()->firstOrFail();
        $line->update([
            'conversion_id' => $this->conversion->id,
            'line_meta' => [
                'price_source' => 'PACKED',
                'breakdown' => [
                    'box_count' => 2,
                    'loose_count' => 0,
                    'box_price_applied' => 1750000,
                    'loose_price_applied' => 175000,
                    'conversion_unit_label' => 'PACK',
                    'base_unit_label' => 'PCS',
                ],
            ],
        ]);

        $data = app(PosReceiptService::class)->getTransactionReceiptData($txn->fresh());
        $breakdown = $data['lines'][0]['unit_breakdown'];
        $this->assertIsArray($breakdown);
        $this->assertCount(1, $breakdown);
        $this->assertStringContainsString('2 PACK @', $breakdown[0]);
        $this->assertStringContainsString('17.500', $breakdown[0]);
    }
}

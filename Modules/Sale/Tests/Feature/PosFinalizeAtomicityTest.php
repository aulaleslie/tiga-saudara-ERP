<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\PosReceipt;
use Modules\Sale\Entities\Sale;

class PosFinalizeAtomicityTest extends PosDraftFeatureTestCase
{
    public function test_submit_payment_is_idempotent_for_same_key(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product, qty: 1, price: 100000));
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();

        $firstResponse = $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => 'idem-finalize-atomicity-1',
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 100000],
            ],
        ]);
        $firstResponse->assertOk();

        $receiptId = (int) $firstResponse->json('receipt_id');
        $salesCount = Sale::query()->count();
        $receiptCount = PosReceipt::query()->count();

        $secondResponse = $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => 'idem-finalize-atomicity-1',
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 100000],
            ],
        ]);

        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('receipt_id', $receiptId);
        $this->assertSame($salesCount, Sale::query()->count());
        $this->assertSame($receiptCount, PosReceipt::query()->count());
    }

    public function test_stock_failure_rolls_back_finalize_side_effects(): void
    {
        $product = $this->createProduct([
            'product_quantity' => 1,
        ]);
        $this->createStock($product, nonTaxQty: 0, taxQty: 0);

        $payload = $this->makeDraftPayload($product, qty: 1, price: 100000, optionsOverrides: [
            'pos_location_allocations' => [[
                'location_id' => $this->location->id,
                'allocated_non_tax' => 1,
                'allocated_tax' => 0,
            ]],
        ]);

        $createResponse = $this->postJson(route('app.pos.drafts.store'), $payload);
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();

        $submitResponse = $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => 'idem-finalize-stock-fail',
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 100000],
            ],
        ]);

        $submitResponse->assertStatus(422);
        $submitResponse->assertJsonPath('code', 'POS_DRAFT_STATE_INVALID');

        $this->assertDatabaseCount('pos_receipts', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
    }
}

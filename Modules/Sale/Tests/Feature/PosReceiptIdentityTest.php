<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Modules\Sale\Entities\PosDraft;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;

class PosReceiptIdentityTest extends PosDraftFeatureTestCase
{
    public function test_final_receipt_number_reuses_draft_code(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product, qty: 1, price: 150000));
        $createResponse->assertCreated();
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();

        $submitResponse = $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => 'idem-pos-receipt-1',
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 150000],
            ],
        ]);

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('receipt_number', $code);

        $this->assertDatabaseHas('pos_receipts', [
            'receipt_number' => $code,
        ]);

        $draft = PosDraft::query()->where('document_number', $code)->firstOrFail();
        $this->assertDatabaseHas('pos_drafts', [
            'id' => $draft->id,
            'status' => PosDraft::STATUS_TERBAYAR,
        ]);
    }

    public function test_print_route_rejects_cross_tenant_access(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();
        $submitResponse = $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => 'idem-print-cross-tenant',
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 100],
            ],
        ])->assertOk();

        $receiptId = (int) $submitResponse->json('receipt_id');

        $otherSetting = Setting::factory()->create([
            'pos_draft_flow_enabled' => true,
        ]);
        $otherLocation = Location::factory()->create([
            'setting_id' => $otherSetting->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['location_id' => $otherLocation->id],
            ['setting_id' => $otherSetting->id, 'position' => 1]
        );

        $otherUser = User::factory()->create();
        $otherUser->settings()->attach($otherSetting->id, ['role_id' => 1]);
        $this->syncPermissions($otherUser, ['pos.transactions.access', 'pos.access']);

        $this->actingAs($otherUser);
        session(['setting_id' => $otherSetting->id]);

        $this->get(route('pos.receipt.print', $receiptId))->assertForbidden();
    }

    public function test_reprint_last_uses_latest_receipt_for_user_and_setting(): void
    {
        $product = $this->createProduct();

        $firstDraft = (string) $this->postJson(route('app.pos.drafts.store', []), $this->makeDraftPayload($product, qty: 1, price: 100))
            ->json('code');
        $this->postJson(route('app.pos.drafts.lock', $firstDraft))->assertOk();
        $firstSubmit = $this->postJson(route('app.pos.drafts.submit-payment', $firstDraft), [
            'idempotency_key' => 'idem-reprint-1',
            'payments' => [['method_id' => $this->cashMethod->id, 'amount' => 100]],
        ])->assertOk();

        $secondDraft = (string) $this->postJson(route('app.pos.drafts.store', []), $this->makeDraftPayload($product, qty: 1, price: 200))
            ->json('code');
        $this->postJson(route('app.pos.drafts.lock', $secondDraft))->assertOk();
        $secondSubmit = $this->postJson(route('app.pos.drafts.submit-payment', $secondDraft), [
            'idempotency_key' => 'idem-reprint-2',
            'payments' => [['method_id' => $this->cashMethod->id, 'amount' => 200]],
        ])->assertOk();

        $latestReceiptId = (int) $secondSubmit->json('receipt_id');
        $this->assertNotEquals((int) $firstSubmit->json('receipt_id'), $latestReceiptId);

        $response = $this->from(route('app.pos.index'))->post(route('app.pos.reprint-last'));
        $response->assertRedirect(route('app.pos.index'));
        $response->assertSessionHas('pos_receipt_id', $latestReceiptId);
    }
}

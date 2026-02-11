<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\PosSession;
use App\Models\User;

class PosErrorContractTest extends PosDraftFeatureTestCase
{
    public function test_business_error_uses_standard_pos_error_payload(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();

        $otherUser = User::factory()->create();
        $otherUser->settings()->attach($this->setting->id, ['role_id' => 1]);
        $this->syncPermissions($otherUser, ['pos.access', 'pos.drafts.submit', 'pos.drafts.view']);
        PosSession::create([
            'user_id' => $otherUser->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'device_name' => 'OTHER DEVICE',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->actingAs($otherUser);
        session(['setting_id' => $this->setting->id]);

        $lockConflict = $this->postJson(route('app.pos.drafts.lock', $code));
        $lockConflict->assertStatus(409);
        $lockConflict->assertJsonStructure(['code', 'message', 'details', 'trace_id']);
        $lockConflict->assertJsonPath('code', 'POS_LOCK_CONFLICT');
    }

    public function test_validation_failure_is_mapped_to_pos_contract(): void
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product, qty: 1, price: 100000));
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();

        $validationResponse = $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => 'idem-validation-error',
            'payments' => [
                ['method_id' => $this->cardMethod->id, 'amount' => 200000], // non-cash overpay
            ],
        ]);

        $validationResponse->assertStatus(422);
        $validationResponse->assertJsonPath('code', 'POS_DRAFT_STATE_INVALID');
        $validationResponse->assertJsonStructure(['code', 'message', 'details', 'trace_id']);
    }

    public function test_unexpected_exception_returns_trace_id_without_sensitive_stack(): void
    {
        $badPayload = [
            'payload' => [
                'cart' => [[
                    'id' => 'invalid-product',
                    'name' => 'Invalid Product',
                    'qty' => 1,
                    'price' => 1000,
                    'options' => [
                        'product_id' => 999999, // forces FK error in pos_draft_items
                        'sub_total' => 1000,
                    ],
                ]],
            ],
        ];

        $response = $this->postJson(route('app.pos.drafts.store'), $badPayload);

        $response->assertStatus(500);
        $response->assertJsonPath('code', 'POS_REFERENCE_GENERATION_FAILED');
        $response->assertJsonStructure(['code', 'message', 'details', 'trace_id']);
        $this->assertStringNotContainsStringIgnoringCase('stack', (string) $response->json('message'));
    }
}

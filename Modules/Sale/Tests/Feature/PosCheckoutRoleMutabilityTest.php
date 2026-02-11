<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\PosSession;
use App\Models\User;
use Modules\Sale\Entities\PosDraft;
use Modules\Sale\Entities\PosSubmitIdempotency;

class PosCheckoutRoleMutabilityTest extends PosDraftFeatureTestCase
{
    public function test_pay_only_user_cannot_update_draft_payload(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $createResponse->assertCreated();
        $code = (string) $createResponse->json('code');

        $payOnlyUser = User::factory()->create();
        $payOnlyUser->settings()->attach($this->setting->id, ['role_id' => 1]);
        $this->syncPermissions($payOnlyUser, [
            'pos.access',
            'pos.drafts.submit',
            'pos.drafts.view',
        ]);

        PosSession::create([
            'user_id' => $payOnlyUser->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'device_name' => 'PAY-ONLY-CHECKOUT',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->actingAs($payOnlyUser);
        session(['setting_id' => $this->setting->id]);

        $updateResponse = $this->patchJson(route('app.pos.drafts.update', $code), [
            'note' => 'Should not be allowed',
            'discount_percentage' => 15,
        ]);

        $updateResponse->assertForbidden();
        $updateResponse->assertJsonPath('code', 'POS_PERMISSION_DENIED');
    }

    public function test_manager_can_update_draft_before_submit_started(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $code = (string) $createResponse->json('code');

        $updateResponse = $this->patchJson(route('app.pos.drafts.update', $code), [
            'note' => 'Manager edited note',
            'shipping_amount' => 5000,
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('payload.note', 'Manager edited note');
        $updateResponse->assertJsonPath('payload.shipping_amount', 5000);
    }

    public function test_draft_becomes_immutable_after_submit_attempt_started(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $code = (string) $createResponse->json('code');

        $draft = PosDraft::query()->where('document_number', $code)->firstOrFail();
        PosSubmitIdempotency::query()->create([
            'setting_id' => $this->setting->id,
            'pos_draft_id' => $draft->id,
            'idempotency_key' => 'submit-started-key',
            'created_by' => $this->user->id,
        ]);

        $updateResponse = $this->patchJson(route('app.pos.drafts.update', $code), [
            'note' => 'late edit',
        ]);

        $updateResponse->assertStatus(409);
        $updateResponse->assertJsonPath('code', 'POS_DRAFT_STATE_INVALID');
    }
}

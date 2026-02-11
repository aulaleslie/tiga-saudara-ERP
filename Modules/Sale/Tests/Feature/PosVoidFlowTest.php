<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\PosSession;
use App\Models\User;
use Modules\Sale\Entities\PosDraft;
use Modules\Sale\Entities\PosSubmitIdempotency;

class PosVoidFlowTest extends PosDraftFeatureTestCase
{
    public function test_manager_can_void_unpaid_draft_before_submit(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $code = (string) $createResponse->json('code');

        $voidResponse = $this->postJson(route('app.pos.drafts.void', $code), [
            'reason' => 'Customer changed mind',
        ]);

        $voidResponse->assertOk();
        $voidResponse->assertJsonPath('status', PosDraft::STATUS_DIBATALKAN);

        $this->assertDatabaseHas('pos_drafts', [
            'document_number' => $code,
            'status' => PosDraft::STATUS_DIBATALKAN,
        ]);
    }

    public function test_pay_only_user_cannot_void_draft(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
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
            'device_name' => 'PAY-ONLY-VOID',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->actingAs($payOnlyUser);
        session(['setting_id' => $this->setting->id]);

        $voidResponse = $this->postJson(route('app.pos.drafts.void', $code), [
            'reason' => 'unauthorized',
        ]);

        $voidResponse->assertForbidden();
        $voidResponse->assertJsonPath('code', 'POS_PERMISSION_DENIED');
    }

    public function test_void_is_rejected_after_submit_started(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $code = (string) $createResponse->json('code');

        $draft = PosDraft::query()->where('document_number', $code)->firstOrFail();
        PosSubmitIdempotency::query()->create([
            'setting_id' => $this->setting->id,
            'pos_draft_id' => $draft->id,
            'idempotency_key' => 'void-blocked-after-submit-start',
            'created_by' => $this->user->id,
        ]);

        $voidResponse = $this->postJson(route('app.pos.drafts.void', $code), [
            'reason' => 'Too late',
        ]);

        $voidResponse->assertStatus(409);
        $voidResponse->assertJsonPath('code', 'POS_DRAFT_STATE_INVALID');
    }
}

<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\PosSession;
use App\Models\User;
use Modules\Sale\Entities\PosDraft;

class PosDraftApiAuthorizationTest extends PosDraftFeatureTestCase
{
    public function test_floor_user_can_create_and_retrieve_draft_payload(): void
    {
        $product = $this->createProduct();
        $payload = $this->makeDraftPayload($product, qty: 2, price: 125000);

        $createResponse = $this->postJson(route('app.pos.drafts.store'), $payload);
        $createResponse->assertCreated();

        $code = (string) $createResponse->json('code');
        $this->assertNotEmpty($code);

        $showResponse = $this->getJson(route('app.pos.drafts.show', $code));
        $showResponse->assertOk();
        $showResponse->assertJsonPath('draft.code', $code);
        $showResponse->assertJsonPath('draft.payload.customer_id', $this->customer->id);
    }

    public function test_pay_only_cashier_cannot_update_draft(): void
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
            'device_name' => 'PAY ONLY DEVICE',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->actingAs($payOnlyUser);
        session(['setting_id' => $this->setting->id]);

        $updateResponse = $this->patchJson(route('app.pos.drafts.update', $code), [
            'note' => 'Updated by pay-only cashier',
        ]);

        $updateResponse->assertForbidden();
        $updateResponse->assertJsonPath('code', 'POS_PERMISSION_DENIED');
    }

    public function test_manager_can_update_and_is_audited(): void
    {
        $product = $this->createProduct();
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $this->makeDraftPayload($product));
        $code = (string) $createResponse->json('code');

        $updateResponse = $this->patchJson(route('app.pos.drafts.update', $code), [
            'note' => 'Manager update',
            'total_amount' => 222222,
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('payload.note', 'Manager update');

        $draft = PosDraft::query()->where('document_number', $code)->firstOrFail();

        $this->assertDatabaseHas('pos_audit_logs', [
            'setting_id' => $this->setting->id,
            'pos_draft_id' => $draft->id,
            'action' => 'draft.updated',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_unknown_code_returns_not_found_contract(): void
    {
        $response = $this->getJson(route('app.pos.drafts.show', 'NOT-FOUND-CODE'));

        $response->assertNotFound();
        $response->assertJsonPath('code', 'POS_DRAFT_NOT_FOUND');
        $response->assertJsonStructure([
            'code',
            'message',
            'details',
            'trace_id',
        ]);
    }
}

<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\PosSession;
use App\Support\PosSessionManager;
use Illuminate\Validation\ValidationException;
use Modules\Setting\Entities\Setting;

class PosSessionLifecycleTest extends PosDraftFeatureTestCase
{
    public function test_pos_routes_require_active_session(): void
    {
        PosSession::query()->delete();

        $response = $this->get(route('app.pos.index'));

        $response->assertRedirect(route('app.pos.session'));
        $response->assertSessionHasErrors('posSession');
    }

    public function test_paused_session_blocks_pos_route_until_resumed(): void
    {
        $this->posSession->update([
            'status' => PosSession::STATUS_PAUSED,
            'paused_at' => now(),
        ]);

        $response = $this->get(route('app.pos.index'));

        $response->assertRedirect(route('app.pos.session'));
        $response->assertSessionHasErrors('posSession');
    }

    public function test_same_user_same_setting_can_resume_paused_session(): void
    {
        $this->posSession->update([
            'status' => PosSession::STATUS_PAUSED,
            'paused_at' => now(),
        ]);

        $resumed = app(PosSessionManager::class)->resume('password');

        $this->assertSame(PosSession::STATUS_ACTIVE, $resumed->status);
        $this->assertNotNull($resumed->resumed_at);
    }

    public function test_resume_rejected_when_session_setting_scope_differs(): void
    {
        $this->posSession->update([
            'status' => PosSession::STATUS_PAUSED,
            'paused_at' => now(),
        ]);

        $otherSetting = Setting::factory()->create();
        session(['setting_id' => $otherSetting->id]);

        try {
            app(PosSessionManager::class)->resume('password');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey('resumePassword', $errors);
        }
    }
}

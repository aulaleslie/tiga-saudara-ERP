<?php

namespace Tests\Feature\Modules\Setting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActiveBusinessSwitchDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = Setting::factory()->create();
        $this->settingB = Setting::factory()->create();

        $role = Role::create(['name' => 'User']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->settingA->id, ['role_id' => $role->id]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => $role->id]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->settingA->id]);
    }

    public function test_successful_switch_emits_business_context_switched_event_and_redirects_home()
    {
        Log::spy();

        $response = $this->post(route('update.active.business'), [
            'setting_id' => $this->settingB->id,
        ]);

        $response->assertRedirect(route('home'));
        $this->assertSame($this->settingB->id, session('setting_id'));

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
            return $message === 'business_context_switched'
                && $context['old_business_id'] == $this->settingA->id
                && $context['new_business_id'] == $this->settingB->id
                && $context['user_id'] == $this->user->id
                && !empty($context['session_fingerprint']);
        })->once();
    }

    public function test_denied_switch_to_inaccessible_business_does_not_change_session_or_disclose_existence()
    {
        $otherBusiness = Setting::factory()->create();

        $response = $this->post(route('update.active.business'), [
            'setting_id' => $otherBusiness->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($this->settingA->id, session('setting_id'));
    }

    public function test_denied_switch_to_nonexistent_business_returns_identical_response_shape()
    {
        $response = $this->post(route('update.active.business'), [
            'setting_id' => 999999,
        ]);

        $response->assertForbidden();
        $this->assertSame($this->settingA->id, session('setting_id'));
    }

    public function test_switch_completes_even_when_diagnostic_logging_fails()
    {
        Log::shouldReceive('log')->andThrow(new \RuntimeException('log sink unavailable'));

        $response = $this->post(route('update.active.business'), [
            'setting_id' => $this->settingB->id,
        ]);

        $response->assertRedirect(route('home'));
        $this->assertSame($this->settingB->id, session('setting_id'));
    }
}

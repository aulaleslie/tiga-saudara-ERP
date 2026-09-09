<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginLogoutSessionDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $setting = Setting::factory()->create();
        $role = Role::create(['name' => 'User']);

        $this->user = User::factory()->create([
            'email' => 'diagnostics-test@example.com',
        ]);
        $this->user->assignRole($role);
        $this->user->settings()->attach($setting->id, ['role_id' => $role->id]);
    }

    public function test_valid_credentials_authenticate_and_redirect()
    {
        $response = $this->post('/login', [
            'email' => 'diagnostics-test@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($this->user);
        $response->assertRedirect(route('home'));
    }

    public function test_successful_login_emits_auth_session_rotated_with_cause_login()
    {
        Log::spy();

        $this->post('/login', [
            'email' => 'diagnostics-test@example.com',
            'password' => 'password',
        ]);

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
            return $message === 'auth_session_rotated'
                && ($context['cause'] ?? null) === 'login'
                && $context['user_id'] === $this->user->id
                && array_key_exists('before_session_fingerprint', $context)
                && array_key_exists('after_session_fingerprint', $context);
        })->once();
    }

    public function test_invalid_credentials_fail_validation_without_a_false_rotation_event()
    {
        Log::spy();

        $response = $this->post('/login', [
            'email' => 'diagnostics-test@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');

        Log::shouldNotHaveReceived('log', function ($args) {
            return ($args[1] ?? null) === 'auth_session_rotated';
        });
    }

    public function test_logout_succeeds_via_the_real_route()
    {
        $this->actingAs($this->user)->post('/logout');

        $this->assertGuest();
    }

    public function test_logout_emits_auth_session_rotated_with_cause_logout()
    {
        Log::spy();

        $this->actingAs($this->user)->post('/logout');

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
            return $message === 'auth_session_rotated'
                && ($context['cause'] ?? null) === 'logout'
                && $context['user_id'] === $this->user->id
                && array_key_exists('before_session_fingerprint', $context)
                && array_key_exists('after_session_fingerprint', $context);
        })->once();
    }

    public function test_login_succeeds_even_when_diagnostic_logging_fails()
    {
        Log::shouldReceive('log')->andThrow(new \RuntimeException('log sink unavailable'));

        $response = $this->post('/login', [
            'email' => 'diagnostics-test@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($this->user);
        $response->assertRedirect(route('home'));
    }

    public function test_logout_succeeds_even_when_diagnostic_logging_fails()
    {
        Log::shouldReceive('log')->andThrow(new \RuntimeException('log sink unavailable'));

        $this->actingAs($this->user)->post('/logout');

        $this->assertGuest();
    }
}

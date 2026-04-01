<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TwoFactorControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TwoFactorService $twoFactorService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->twoFactorService = app(TwoFactorService::class);
        
        // Mock gate checks
        Gate::define('profiles.edit', fn () => true);
        Gate::define('users.edit', fn () => true);
    }

    /** @test */
    public function user_can_initiate_2fa_setup()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('2fa.setup'));

        $response->assertStatus(200)
            ->assertJsonStructure(['secret', 'qr_code_svg']);
    }

    /** @test */
    public function user_cannot_setup_2fa_without_authentication()
    {
        $response = $this->postJson(route('2fa.setup'));

        $response->assertStatus(401); // Unauthorized for JSON requests
    }

    /** @test */
    public function user_can_confirm_2fa_setup_with_valid_code()
    {
        // First, initiate setup
        $setupResponse = $this->actingAs($this->user)
            ->postJson(route('2fa.setup'));

        $this->assertEquals(200, $setupResponse->status(), 'Setup failed: ' . $setupResponse->getContent());
        
        $secret = $setupResponse['secret'];

        // Generate valid code
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        // Confirm setup
        $confirmResponse = $this->actingAs($this->user)
            ->postJson(route('2fa.confirm'), ['code' => $validCode]);

        $confirmResponse->assertStatus(200)
            ->assertJsonStructure(['message', 'recovery_codes']);

        // Verify user has 2FA enabled
        $this->user->refresh();
        $this->assertTrue($this->user->hasTwoFactorEnabled());
    }

    /** @test */
    public function user_gets_error_with_invalid_2fa_code()
    {
        // First, initiate setup
        $this->actingAs($this->user)->postJson(route('2fa.setup'));

        // Try to confirm with invalid code
        $response = $this->actingAs($this->user)
            ->postJson(route('2fa.confirm'), ['code' => '000000']);

        $response->assertStatus(400)
            ->assertJsonStructure(['message']);
    }

    /** @test */
    public function user_can_test_2fa_code()
    {
        // Enable 2FA first
        $secret = $this->twoFactorService->generateSecretKey();
        $this->user->update([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);
        
        // Reload user from database
        $this->user->refresh();

        // Get valid code
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        $response = $this->actingAs($this->user)
            ->postJson(route('2fa.test'), ['code' => $validCode]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message']);
    }

    /** @test */
    public function user_gets_error_with_invalid_test_code()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('2fa.test'), ['code' => '000000']);

        $response->assertStatus(400)
            ->assertJsonStructure(['message']);
    }

    /** @test */
    public function user_can_disable_2fa()
    {
        // Enable 2FA first
        $secret = $this->twoFactorService->generateSecretKey();
        $this->twoFactorService->enableTwoFactor($this->user, $secret);
        $this->user->refresh();

        $this->assertTrue($this->user->hasTwoFactorEnabled());

        // Disable 2FA
        $response = $this->actingAs($this->user)
            ->deleteJson(route('2fa.disable'));

        $response->assertStatus(200)
            ->assertJsonStructure(['message']);

        // Verify 2FA is disabled
        $this->user->refresh();
        $this->assertFalse($this->user->hasTwoFactorEnabled());
    }

    /** @test */
    public function admin_can_reset_user_2fa()
    {
        // Create admin user
        $admin = User::factory()->create();

        // Enable 2FA on target user
        $secret = $this->twoFactorService->generateSecretKey();
        $this->twoFactorService->enableTwoFactor($this->user, $secret);
        $this->user->refresh();

        $this->assertTrue($this->user->hasTwoFactorEnabled());

        // Admin resets 2FA
        $response = $this->actingAs($admin)
            ->postJson(route('2fa.admin-reset'), ['user_id' => $this->user->id]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message']);

        // Verify 2FA is reset
        $this->user->refresh();
        $this->assertFalse($this->user->hasTwoFactorEnabled());
    }

    /** @test */
    public function non_admin_cannot_reset_other_user_2fa()
    {
        // Override gate to deny for specific test
        Gate::define('users.edit', fn () => false);
        
        // Enable 2FA on target user
        $secret = $this->twoFactorService->generateSecretKey();
        $this->twoFactorService->enableTwoFactor($this->user, $secret);

        // Another user tries to reset
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->postJson(route('2fa.admin-reset'), ['user_id' => $this->user->id]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_code_requires_valid_format()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('2fa.test'), ['code' => 'invalid']);

        // Validation errors return 422
        $response->assertStatus(422)
            ->assertJsonStructure(['message']);
    }
}

<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;
use Tests\TestCase as BaseTestCase;

class TwoFactorServiceTest extends BaseTestCase
{
    use RefreshDatabase;

    protected TwoFactorService $service;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TwoFactorService::class);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function generate_secret_key_returns_valid_string()
    {
        $secret = $this->service->generateSecretKey();

        $this->assertIsString($secret);
        $this->assertNotEmpty($secret);
        $this->assertGreaterThan(10, strlen($secret));
    }

    /** @test */
    public function get_qr_code_uri_returns_valid_uri()
    {
        $secret = $this->service->generateSecretKey();
        $uri = $this->service->getQrCodeUri($this->user, $secret);

        $this->assertIsString($uri);
        $this->assertStringContainsString('otpauth://', $uri);
        $this->assertStringContainsString($this->user->email, $uri);
    }

    /** @test */
    public function render_qr_code_svg_returns_svg_string()
    {
        $secret = $this->service->generateSecretKey();
        $uri = $this->service->getQrCodeUri($this->user, $secret);
        $svg = $this->service->renderQrCodeSvg($uri);

        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    /** @test */
    public function verify_code_returns_false_without_secret()
    {
        $result = $this->service->verifyCode($this->user, '123456');

        $this->assertFalse($result);
    }

    /** @test */
    public function verify_code_returns_false_for_invalid_code()
    {
        $secret = $this->service->generateSecretKey();
        $this->user->update(['two_factor_secret' => $secret]);

        $result = $this->service->verifyCode($this->user, '000000');

        $this->assertFalse($result);
    }

    /** @test */
    public function verify_code_returns_true_for_valid_code()
    {
        $secret = $this->service->generateSecretKey();
        $this->user->update(['two_factor_secret' => $secret]);

        // Generate valid code for current time
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        $result = $this->service->verifyCode($this->user, $validCode);

        $this->assertTrue($result);
    }

    /** @test */
    public function enable_two_factor_sets_all_columns()
    {
        $secret = $this->service->generateSecretKey();
        $recoveryCodes = $this->service->enableTwoFactor($this->user, $secret);

        $this->user->refresh();

        $this->assertNotNull($this->user->two_factor_secret);
        $this->assertNotNull($this->user->two_factor_confirmed_at);
        $this->assertNotNull($this->user->two_factor_recovery_codes);
        $this->assertIsArray($recoveryCodes);
        $this->assertCount(8, $recoveryCodes);
    }

    /** @test */
    public function generate_recovery_codes_returns_correct_format()
    {
        $codes = $this->service->generateRecoveryCodes(5);

        $this->assertCount(5, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
        }
    }

    /** @test */
    public function generate_recovery_codes_returns_unique_codes()
    {
        $codes = $this->service->generateRecoveryCodes(10);

        $this->assertCount(10, $codes);
        $this->assertEquals(count($codes), count(array_unique($codes)));
    }

    /** @test */
    public function use_recovery_code_returns_false_without_codes()
    {
        $result = $this->service->useRecoveryCode($this->user, 'XXXXX-XXXXX');

        $this->assertFalse($result);
    }

    /** @test */
    public function use_recovery_code_returns_false_for_invalid_code()
    {
        $secret = $this->service->generateSecretKey();
        $this->service->enableTwoFactor($this->user, $secret);

        $result = $this->service->useRecoveryCode($this->user, 'INVALID-CODE');

        $this->assertFalse($result);
    }

    /** @test */
    public function use_recovery_code_returns_true_and_removes_code()
    {
        $secret = $this->service->generateSecretKey();
        $recoveryCodes = $this->service->enableTwoFactor($this->user, $secret);

        $codeToUse = $recoveryCodes[0];
        $result = $this->service->useRecoveryCode($this->user, $codeToUse);

        $this->assertTrue($result);

        // Verify code is removed
        $this->user->refresh();
        $remainingCodes = json_decode($this->user->two_factor_recovery_codes, true);
        $this->assertCount(7, $remainingCodes);
    }

    /** @test */
    public function use_recovery_code_returns_false_on_reuse()
    {
        $secret = $this->service->generateSecretKey();
        $recoveryCodes = $this->service->enableTwoFactor($this->user, $secret);

        $codeToUse = $recoveryCodes[0];

        // Use code first time
        $this->assertTrue($this->service->useRecoveryCode($this->user, $codeToUse));

        // Try to use again
        $result = $this->service->useRecoveryCode($this->user, $codeToUse);

        $this->assertFalse($result);
    }

    /** @test */
    public function disable_two_factor_clears_all_columns()
    {
        $secret = $this->service->generateSecretKey();
        $this->service->enableTwoFactor($this->user, $secret);

        $this->assertTrue($this->user->hasTwoFactorEnabled());

        $this->service->disableTwoFactor($this->user);

        $this->user->refresh();

        $this->assertNull($this->user->two_factor_secret);
        $this->assertNull($this->user->two_factor_confirmed_at);
        $this->assertNull($this->user->two_factor_recovery_codes);
        $this->assertFalse($this->user->hasTwoFactorEnabled());
    }

    /** @test */
    public function reset_two_factor_is_alias_for_disable()
    {
        $secret = $this->service->generateSecretKey();
        $this->service->enableTwoFactor($this->user, $secret);

        $this->assertTrue($this->user->hasTwoFactorEnabled());

        $this->service->resetTwoFactor($this->user);

        $this->user->refresh();

        $this->assertFalse($this->user->hasTwoFactorEnabled());
    }
}

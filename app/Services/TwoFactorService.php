<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Generate a new TOTP secret key
     */
    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Get the QR code URI for a user and secret
     */
    public function getQrCodeUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );
    }

    /**
     * Render QR code URI as SVG
     */
    public function renderQrCodeSvg(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        return $writer->writeString($uri);
    }

    /**
     * Verify a TOTP code for a user with ±1 period window
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        return $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            1 // ±1 period window (90 seconds total)
        );
    }

    /**
     * Enable two-factor authentication for a user
     * Returns plaintext recovery codes
     */
    public function enableTwoFactor(User $user, string $secret): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();
        $hashedCodes = array_map(fn ($code) => Hash::make($code), $recoveryCodes);

        $user->update([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => json_encode($hashedCodes),
        ]);

        return $recoveryCodes;
    }

    /**
     * Generate recovery codes in XXXXX-XXXXX format
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $part1 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
            $part2 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
            $codes[] = "{$part1}-{$part2}";
        }
        return $codes;
    }

    /**
     * Use a recovery code by verifying and removing it
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $hashedCodes = json_decode($user->two_factor_recovery_codes, true) ?? [];

        foreach ($hashedCodes as $key => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                // Remove the used code
                unset($hashedCodes[$key]);
                $user->update([
                    'two_factor_recovery_codes' => json_encode(array_values($hashedCodes)),
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Disable two-factor authentication for a user
     */
    public function disableTwoFactor(User $user): void
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    /**
     * Reset two-factor authentication (alias for disable, used by admin)
     */
    public function resetTwoFactor(User $user): void
    {
        $this->disableTwoFactor($user);
    }
}

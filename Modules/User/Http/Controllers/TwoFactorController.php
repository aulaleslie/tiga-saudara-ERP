<?php

namespace Modules\User\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class TwoFactorController extends Controller
{
    protected TwoFactorService $twoFactorService;

    public function __construct(TwoFactorService $twoFactorService)
    {
        $this->twoFactorService = $twoFactorService;
    }

    /**
     * Setup TOTP: Generate secret and return QR code URI
     */
    public function setup(Request $request): JsonResponse
    {
        abort_if(Gate::denies('profiles.edit'), 403);

        $secret = $this->twoFactorService->generateSecretKey();
        $uri = $this->twoFactorService->getQrCodeUri($request->user(), $secret);
        $qrCodeSvg = $this->twoFactorService->renderQrCodeSvg($uri);

        // Store secret in session temporarily
        session(['two_factor_setup_secret' => $secret]);

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => $qrCodeSvg,
        ]);
    }

    /**
     * Confirm TOTP setup: Verify code and enable 2FA
     */
    public function confirm(Request $request): JsonResponse
    {
        abort_if(Gate::denies('profiles.edit'), 403);

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $secret = session('two_factor_setup_secret');
        if (! $secret) {
            return response()->json([
                'message' => 'Setup not initiated. Start with /setup.',
            ], 400);
        }

        // Verify the code with the temporary secret
        $user = $request->user();
        $user->two_factor_secret = $secret; // Temporarily set for verification

        if (! $this->twoFactorService->verifyCode($user, $request->code)) {
            return response()->json([
                'message' => 'Invalid code. Please try again.',
            ], 400);
        }

        // Enable 2FA and get recovery codes
        $recoveryCodes = $this->twoFactorService->enableTwoFactor($user, $secret);

        // Clear session secret
        session()->forget('two_factor_setup_secret');

        return response()->json([
            'message' => '2FA enabled successfully',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Test TOTP code (verify without persisting)
     */
    public function test(Request $request): JsonResponse
    {
        abort_if(Gate::denies('profiles.edit'), 403);

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        if (! $this->twoFactorService->verifyCode($request->user(), $request->code)) {
            return response()->json([
                'message' => 'Invalid code.',
            ], 400);
        }

        return response()->json([
            'message' => 'Code is valid.',
        ]);
    }

    /**
     * Disable TOTP for current user
     */
    public function disable(Request $request): JsonResponse
    {
        abort_if(Gate::denies('profiles.edit'), 403);

        $this->twoFactorService->disableTwoFactor($request->user());

        return response()->json([
            'message' => '2FA disabled successfully',
        ]);
    }

    /**
     * Admin reset of TOTP for a user
     */
    public function adminReset(Request $request): JsonResponse
    {
        abort_if(Gate::denies('users.edit'), 403);

        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);
        $this->twoFactorService->resetTwoFactor($user);

        return response()->json([
            'message' => 'User 2FA reset successfully',
        ]);
    }
}

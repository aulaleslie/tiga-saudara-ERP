<?php

namespace App\Support;

use App\Models\PosSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PosSessionManager
{
    public function current(?int $userId = null): ?PosSession
    {
        $userId = $userId ?? Auth::id();

        if (! $userId) {
            return null;
        }

        return PosSession::query()
            ->where('user_id', $userId)
            ->whereIn('status', [PosSession::STATUS_ACTIVE, PosSession::STATUS_PAUSED])
            ->latest('id')
            ->first();
    }

    public function ensureActive(?int $userId = null): PosSession
    {
        $session = $this->current($userId);

        if (! $session) {
            throw new AuthorizationException('POS session is not active.');
        }

        if ($session->status === PosSession::STATUS_PAUSED) {
            throw new AuthorizationException('POS session is paused.');
        }

        return $session;
    }

    public function start(float $cashFloat, ?int $locationId = null): PosSession
    {
        $userId = Auth::id();

        if (! $userId) {
            throw new AuthorizationException('User is not authenticated.');
        }

        $existing = $this->current($userId);

        if ($existing) {
            throw ValidationException::withMessages([
                'cashFloat' => 'A POS session is already open or paused. Close it before starting a new one.',
            ]);
        }

        $device = request()->userAgent() ?? 'Unknown';

        return PosSession::create([
            'user_id' => $userId,
            'location_id' => $locationId,
            'device_name' => $device,
            'cash_float' => round($cashFloat, 2),
            'expected_cash' => round($cashFloat, 2),
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => Carbon::now(),
        ]);
    }

    public function pause(string $password): PosSession
    {
        $session = $this->ensureActive();
        $this->assertPassword($password);

        $session->update([
            'status' => PosSession::STATUS_PAUSED,
            'paused_at' => Carbon::now(),
        ]);

        return $session->fresh();
    }

    public function resume(string $password): PosSession
    {
        $session = $this->current();

        if (! $session || $session->status !== PosSession::STATUS_PAUSED) {
            throw ValidationException::withMessages([
                'resumePassword' => 'Tidak ada sesi POS yang dijeda untuk dilanjutkan.',
            ]);
        }

        $this->assertPassword($password);

        $session->update([
            'status' => PosSession::STATUS_ACTIVE,
            'resumed_at' => Carbon::now(),
        ]);

        return $session->fresh();
    }

    public function close(float $actualCash, ?float $expectedCash, string $password): PosSession
    {
        $session = $this->current();

        if (! $session || $session->status === PosSession::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'actualCash' => 'Tidak ada sesi POS aktif atau dijeda untuk ditutup.',
            ]);
        }

        $this->assertPassword($password, field: 'closePassword');

        $finalExpected = $expectedCash ?? $session->expected_cash;
        $finalExpected = $finalExpected !== null ? round($finalExpected, 2) : null;
        $actual = round($actualCash, 2);
        $discrepancy = $finalExpected === null ? null : round($actual - $finalExpected, 2);

        $session->update([
            'status' => PosSession::STATUS_CLOSED,
            'actual_cash' => $actual,
            'expected_cash' => $finalExpected,
            'discrepancy' => $discrepancy,
            'closed_at' => Carbon::now(),
        ]);

        return $session->fresh();
    }

    protected function assertPassword(string $password, string $field = 'pausePassword'): void
    {
        $user = Auth::user();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                $field => 'Kata sandi tidak valid.',
            ]);
        }
    }
}

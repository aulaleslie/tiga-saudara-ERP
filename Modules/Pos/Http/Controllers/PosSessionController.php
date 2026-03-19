<?php

namespace Modules\Pos\Http\Controllers;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Http\Requests\StorePosSessionCloseRequest;
use Modules\Pos\Http\Requests\StorePosSafeDropRequest;
use Modules\Pos\Http\Requests\StorePosSessionOpenRequest;
use Modules\Pos\Services\PosSessionCloseService;
use Modules\Pos\Services\PosSafeDropService;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Pos\Services\PosSessionMonitorService;
use Modules\Pos\Services\PosSupervisorApprovalService;
use Modules\Pos\Services\PosRolePolicyService;
use Modules\Pos\Services\PosSessionSummaryService;
use Modules\Pos\Services\PosSessionAdminCloseService;
use Modules\Pos\Services\PosSessionFinalizeService;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\SettingPosPaymentMethod;

class PosSessionController extends Controller
{
    public function index(): Renderable
    {
        $settingId = $this->currentSettingId();
        $status = request()->query('status');
        $terminalId = request()->query('terminal_id');

        $sessions = PosSession::query()
            ->with(['terminal', 'terminal.policy', 'cashier', 'cashEvents', 'checkouts'])
            ->withCount([
                'cashEvents as transaction_count' => function ($query) {
                    return $query->where('event_type', PosSessionCashEvent::EVENT_CASH_SALE_IN);
                },
                'cashEvents as safe_drops_count' => function ($query) {
                    return $query->where('event_type', PosSessionCashEvent::EVENT_SAFE_DROP_OUT);
                },
            ])
            ->withMax('cashEvents as last_activity', 'occurred_at')
            ->where('setting_id', $settingId)
            ->when($status, function ($query) use ($status) {
                return $query->where('status', $status);
            })
            ->when($terminalId, function ($query) use ($terminalId) {
                return $query->where('terminal_id', $terminalId);
            })
            ->orderBy('opened_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        // Calculate Pengambilan Kas Terkini (total cash picked up) and Sales Total for each session
        $sessions->getCollection()->transform(function ($session) {
            $cashPickedUp = $session->cashEvents
                ->where('event_type', PosSessionCashEvent::EVENT_SAFE_DROP_OUT)
                ->where('direction', PosSessionCashEvent::DIRECTION_OUT)
                ->sum('amount');

            $salesTotal = $session->checkouts
                ->where('status', PosCheckout::STATUS_POSTED)
                ->sum('grand_total');

            $session->cash_picked_up_total = round((float) $cashPickedUp, 2);
            $session->sales_total = round((float) $salesTotal, 2);
            return $session;
        });

        $terminalFilter = $terminalId ? PosTerminal::find($terminalId) : null;

        return view('pos::session.index', compact('sessions', 'status', 'terminalFilter'));
    }

    public function create(): Renderable|RedirectResponse
    {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        $hasConfiguredSaleLocations = SettingSaleLocation::query()
            ->where('setting_id', $settingId)
            ->where('is_enabled', true)
            ->exists();

        if (! $hasConfiguredSaleLocations) {
            toast('Konfigurasi lokasi penjualan belum diatur. Silakan atur terlebih dahulu.', 'error');

            return redirect()->route('pos.sessions.index');
        }

        $hasConfiguredPayments = SettingPosPaymentMethod::query()
            ->where('setting_id', $settingId)
            ->where('is_enabled', true)
            ->exists();

        if (! $hasConfiguredPayments) {
            toast('Konfigurasi pembayaran POS belum diatur. Silakan atur terlebih dahulu.', 'error');

            return redirect()->route('pos.sessions.index');
        }

        $terminals = PosTerminal::query()
            ->with('policy')
            ->where('setting_id', $settingId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $rolePolicy = app(PosRolePolicyService::class);
        $requiresTerminalSelection = $rolePolicy->requiresTerminalSelection($user);
        $roleCapabilities = $rolePolicy->capabilityFlags($user);

        return view('pos::session.open', compact('terminals', 'requiresTerminalSelection', 'roleCapabilities'));
    }

    public function store(StorePosSessionOpenRequest $request, PosSessionLifecycleService $sessionLifecycleService): RedirectResponse
    {
        $settingId = $this->currentSettingId();
        $cashierId = (int) auth()->id();
        $terminalId = $request->filled('terminal_id') ? (int) $request->input('terminal_id') : null;

        try {
            $sessionLifecycleService->openSession(
                $settingId,
                $terminalId,
                $cashierId,
                (float) $request->input('opening_float_total'),
                $request->input('opening_denominations'),
                $cashierId,
                $request->filled('notes') ? $request->string('notes')->value() : null
            );
        } catch (DomainException $exception) {
            return redirect()
                ->route('pos.sessions.create')
                ->withInput()
                ->withErrors([$this->errorFieldForMessage($exception->getMessage()) => $exception->getMessage()]);
        }

        toast('POS session opened successfully.', 'success');

        // Redirect based on user permissions
        $user = auth()->user();
        if ($user->can('pos.sell')) {
            return redirect()->route('pos.sell');
        }

        return redirect()->route('pos.sessions.index');
    }

    public function summary(int $session, PosSessionSummaryService $sessionSummaryService): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        \Illuminate\Support\Facades\Log::channel('single')->debug('POS Session Summary: Request received', [
            'session_id' => $session,
            'user_id' => $user?->id,
            'setting_id' => $settingId,
        ]);

        if (! $user) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Session Summary: No authenticated user');
            return response()->json([
                'message' => 'Authentication is required.',
            ], 403);
        }

        $posSession = PosSession::query()
            ->select(['id', 'setting_id', 'cashier_user_id'])
            ->where('id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posSession) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Session Summary: Session not found', [
                'session_id' => $session,
                'setting_id' => $settingId,
            ]);
            return response()->json([
                'message' => 'POS session not found for current setting.',
            ], 404);
        }

        $isOwner = (int) $posSession->cashier_user_id === (int) $user->id;
        $canViewSessions = $user->can('pos.sessions.view');

        if (! $isOwner && ! $canViewSessions) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Session Summary: Not authorized', [
                'session_id' => $session,
                'user_id' => $user->id,
                'is_owner' => $isOwner,
                'can_view_sessions' => $canViewSessions,
            ]);
            return response()->json([
                'message' => 'Not authorized to view POS session summary.',
            ], 403);
        }

        return response()->json(
            $sessionSummaryService->getSummary($posSession->id, (int) $user->id, $settingId)
        );
    }

    public function safeDrop(
        int $session,
        StorePosSafeDropRequest $request,
        PosSafeDropService $safeDropService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        $posSession = PosSession::query()
            ->select(['id', 'setting_id'])
            ->where('id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posSession) {
            abort(404, 'POS session not found for current setting.');
        }

        try {
            $result = $safeDropService->createSafeDrop(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                (float) $request->input('amount'),
                $request->input('denominations'),
                $request->filled('notes') ? $request->string('notes')->value() : null,
                $request->filled('supervisor_identifier') ? $request->string('supervisor_identifier')->value() : null,
                $request->filled('supervisor_pin') ? $request->string('supervisor_pin')->value() : null
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    public function closeFinalize(
        int $session,
        StorePosSessionCloseRequest $request,
        PosSessionCloseService $sessionCloseService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        \Illuminate\Support\Facades\Log::channel('single')->info('POS Close Session: Request received', [
            'session_id' => $session,
            'user_id' => $user?->id,
            'setting_id' => $settingId,
            'reason' => $request->input('reason'),
        ]);

        if (! $user) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Close Session: No authenticated user');
            return response()->json([
                'message' => 'Authentication is required.',
            ], 403);
        }

        if (! $user->can('pos.sessions.close')) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Close Session: Permission denied', [
                'user_id' => $user->id,
                'permission' => 'pos.sessions.close',
            ]);
            return response()->json([
                'message' => 'You do not have permission to close sessions.',
            ], 403);
        }

        $posSession = PosSession::query()
            ->select(['id', 'setting_id'])
            ->where('id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posSession) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Close Session: Session not found', [
                'session_id' => $session,
                'setting_id' => $settingId,
            ]);
            return response()->json([
                'message' => 'POS session not found for current setting.',
            ], 404);
        }

        try {
            \Illuminate\Support\Facades\Log::channel('single')->info('POS Close Session: Calling service', [
                'session_id' => $posSession->id,
                'user_id' => $user->id,
            ]);

            $result = $sessionCloseService->closeSession(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                $request->filled('reason') ? $request->string('reason')->value() : null
            );
        } catch (AuthorizationException $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Close Session: Authorization failed', [
                'session_id' => $session,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        } catch (DomainException $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Close Session: Domain error', [
                'session_id' => $session,
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        \Illuminate\Support\Facades\Log::channel('single')->info('POS Close Session: Success', [
            'session_id' => $session,
            'user_id' => $user->id,
        ]);
        return response()->json($result);
    }

    private function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');

        abort_if($settingId <= 0, 403, 'Setting context is required.');

        return $settingId;
    }

    private function errorFieldForMessage(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'opening float total')) {
            return 'opening_float_total';
        }

        if (str_contains($normalized, 'denomination')) {
            return 'opening_denominations';
        }

        if (str_contains($normalized, 'terminal')) {
            return 'terminal_id';
        }

        if (str_contains($normalized, 'sesi pos aktif') || str_contains($normalized, 'session aktif')) {
            return 'terminal_id';
        }

        if (str_contains($normalized, 'sales location') || str_contains($normalized, 'location')) {
            return 'terminal_id';
        }

        if (str_contains($normalized, 'payment method')) {
            return 'terminal_id';
        }

        return 'opening_float_total';
    }


    /**
     * Handle cash pickup from POS terminal with supervisor authentication
     */
    public function pickup(
        int $session,
        PosSupervisorApprovalService $approvalService,
        PosSafeDropService $safeDropService
    ): JsonResponse {
        $user = auth()->user();

        \Illuminate\Support\Facades\Log::channel('single')->info('POS Pickup Controller: Request received', [
            'session_id' => $session,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_roles' => $user?->getRoleNames()->toArray(),
            'setting_id' => session('setting_id'),
        ]);

        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        // Get setting from the session being accessed (fallback for Super Admin)
        $posSession = PosSession::query()
            ->with('terminal.policy')
            ->where('id', $session)
            ->lockForUpdate()
            ->first();

        if (! $posSession) {
            return response()->json([
                'message' => 'Sesi POS tidak ditemukan.',
            ], 404);
        }

        $settingId = (int) $posSession->setting_id;

        // Validate request
        $amount = request()->input('amount');
        $supervisorEmail = request()->input('supervisor_email');
        $supervisorPassword = request()->input('supervisor_password');

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return response()->json([
                'message' => 'Jumlah pengambilan harus lebih dari 0.',
            ], 422);
        }

        if (! $supervisorEmail || ! $supervisorPassword) {
            return response()->json([
                'message' => 'Email dan password supervisor wajib diisi.',
            ], 422);
        }

        // Validate amount against expected cash
        $expectedCash = (float) ($posSession->expected_cash_total ?? 0);
        if ((float) $amount > $expectedCash) {
            return response()->json([
                'message' => 'Jumlah pengambilan tidak boleh melebihi ekspektasi kas.',
            ], 422);
        }

        try {
            // Verify supervisor credentials and permission
            $approvalResult = $approvalService->approveSafeDrop(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                $supervisorEmail,
                $supervisorPassword
            );

            if (! $approvalResult['approved']) {
                $reason = $approvalResult['approval_result'] ?? 'UNKNOWN';
                $message = 'Kredensial supervisor tidak valid atau Anda tidak memiliki izin untuk persetujuan pengambilan kas.';

                if ($reason === 'MISSING_PERMISSION') {
                    $message = 'Supervisor tidak memiliki izin "Setujui Safe Drop POS" (pos.safeDrops.approve).';
                } elseif ($reason === 'INVALID_CREDENTIALS') {
                    $message = 'Email atau password supervisor tidak valid.';
                }

                return response()->json(['message' => $message], 403);
            }

            // Create safe drop with supervisor info
            $result = $safeDropService->createSafeDrop(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                (float) $amount,
                null, // denominations
                'Pengambilan kas dari terminal POS',
                $supervisorEmail,
                $supervisorPassword
            );

            return response()->json([
                'message' => 'Pengambilan kas berhasil.',
                'expected_cash_after' => (float) ($result['expected_cash_after'] ?? $expectedCash - (float) $amount),
            ]);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        }
    }

    public function closeAdmin(
        int $session,
        PosSessionAdminCloseService $adminCloseService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        \Illuminate\Support\Facades\Log::channel('single')->info('POS Admin Close Session: Request received', [
            'session_id' => $session,
            'user_id' => $user?->id,
            'setting_id' => $settingId,
            'reason' => request()->input('reason'),
        ]);

        if (! $user) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Admin Close Session: No authenticated user');
            return response()->json([
                'message' => 'Authentication is required.',
            ], 403);
        }

        if (! $user->can('pos.sessions.close-admin')) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Admin Close Session: Permission denied', [
                'user_id' => $user->id,
                'permission' => 'pos.sessions.close-admin',
            ]);
            return response()->json([
                'message' => 'You do not have permission to close sessions as admin.',
            ], 403);
        }

        $posSession = PosSession::query()
            ->select(['id', 'setting_id'])
            ->where('id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posSession) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Admin Close Session: Session not found', [
                'session_id' => $session,
                'setting_id' => $settingId,
            ]);
            return response()->json([
                'message' => 'POS session not found for current setting.',
            ], 404);
        }

        try {
            \Illuminate\Support\Facades\Log::channel('single')->info('POS Admin Close Session: Calling service', [
                'session_id' => $posSession->id,
                'admin_user_id' => $user->id,
            ]);

            $result = $adminCloseService->closeSessionAsAdmin(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                request()->filled('reason') ? request()->string('reason')->value() : null
            );

            \Illuminate\Support\Facades\Log::channel('single')->info('POS Admin Close Session: Success', [
                'session_id' => $session,
                'admin_user_id' => $user->id,
            ]);
            return response()->json($result);
        } catch (AuthorizationException $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Admin Close Session: Authorization failed', [
                'session_id' => $session,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        } catch (DomainException $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Admin Close Session: Domain error', [
                'session_id' => $session,
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function finalize(
        int $session,
        PosSessionFinalizeService $finalizeService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        \Illuminate\Support\Facades\Log::channel('single')->info('POS Finalize Session: Request received', [
            'session_id' => $session,
            'user_id' => $user?->id,
            'setting_id' => $settingId,
            'actual_cash_received' => request()->input('actual_cash_received'),
        ]);

        if (! $user) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Finalize Session: No authenticated user');
            return response()->json([
                'message' => 'Authentication is required.',
            ], 403);
        }

        if (! $user->can('pos.supervisor.approval')) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Finalize Session: Permission denied', [
                'user_id' => $user->id,
                'permission' => 'pos.supervisor.approval',
            ]);
            return response()->json([
                'message' => 'You do not have permission to finalize sessions.',
            ], 403);
        }

        $posSession = PosSession::query()
            ->select(['id', 'setting_id'])
            ->where('id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posSession) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Finalize Session: Session not found', [
                'session_id' => $session,
                'setting_id' => $settingId,
            ]);
            return response()->json([
                'message' => 'POS session not found for current setting.',
            ], 404);
        }

        // Validate input
        $actualCashReceived = request()->input('actual_cash_received');
        if (! is_numeric($actualCashReceived)) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Finalize Session: Invalid cash amount', [
                'session_id' => $session,
                'actual_cash_received' => $actualCashReceived,
            ]);
            return response()->json([
                'message' => 'Actual cash received must be a valid number.',
            ], 422);
        }

        if ((float) $actualCashReceived < 0) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Finalize Session: Negative cash amount', [
                'session_id' => $session,
                'actual_cash_received' => $actualCashReceived,
            ]);
            return response()->json([
                'message' => 'Actual cash received must be at least zero.',
            ], 422);
        }

        try {
            \Illuminate\Support\Facades\Log::channel('single')->info('POS Finalize Session: Calling service', [
                'session_id' => $posSession->id,
                'user_id' => $user->id,
                'actual_cash_received' => (float) $actualCashReceived,
            ]);

            $result = $finalizeService->finalizeSession(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                (float) $actualCashReceived,
                request()->filled('notes') ? request()->string('notes')->value() : null
            );
        } catch (AuthorizationException $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Finalize Session: Authorization failed', [
                'session_id' => $session,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        } catch (DomainException $exception) {
            $message = $exception->getMessage();
            $payload = [
                'message' => $message,
            ];

            if (str_contains(strtolower($message), 'terminal policy is missing')) {
                $payload['error_code'] = 'terminal_policy_missing';
            }

            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Finalize Session: Domain error', [
                'session_id' => $session,
                'error' => $message,
            ]);

            return response()->json($payload, 422);
        }

        if ((bool) ($result['blocked'] ?? false)) {
            \Illuminate\Support\Facades\Log::channel('single')->info('POS Finalize Session: Blocked (requires approval)', [
                'session_id' => $session,
                'requires_variance_approval' => $result['payload']['requires_variance_approval'] ?? false,
            ]);
            return response()->json($result['payload'], 422);
        }

        \Illuminate\Support\Facades\Log::channel('single')->info('POS Finalize Session: Success', [
            'session_id' => $session,
            'user_id' => $user->id,
        ]);
        return response()->json($result['payload']);
    }
}

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
            ->with(['terminal', 'terminal.policy', 'cashier', 'cashEvents', 'checkouts', 'transactions'])
            ->withCount([
                'cashEvents as transaction_count' => function ($query) {
                    return $query->where('event_type', PosSessionCashEvent::EVENT_CASH_SALE_IN);
                },
                'cashEvents as safe_drops_count' => function ($query) {
                    return $query->where('event_type', PosSessionCashEvent::EVENT_SAFE_DROP_OUT);
                },
                'transactions as draft_transaction_count' => function ($query) {
                    return $query->where('source_pos_session_id', '!=', null);
                },
            ])
            ->withMax('cashEvents as last_activity', 'occurred_at')
            ->withMax('cashEvents as last_cash_activity', 'occurred_at')
            ->withMax('transactions as last_transaction_created', 'created_at')
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
            abort(403, 'Otentikasi diperlukan.');
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
        $roleCapabilities = $rolePolicy->capabilityFlags($user);

        // Check for active session in other settings
        $activeSessionInOtherSetting = PosSession::query()
            ->with('setting:id,company_name')
            ->where('cashier_user_id', $user->id)
            ->where('setting_id', '!=', $settingId)
            ->active()
            ->first();

        return view('pos::session.open', compact('terminals', 'roleCapabilities', 'activeSessionInOtherSetting'));
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

    public function summary(int $session, PosSessionSummaryService $sessionSummaryService): JsonResponse|\Illuminate\View\View
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

        try {
            $summary = $sessionSummaryService->getSummary($posSession->id, (int) $user->id, $settingId);
        } catch (DomainException $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Session Summary: Domain exception occurred', [
                'session_id' => $session,
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Exception $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Session Summary: Unexpected exception occurred', [
                'session_id' => $session,
                'error' => $exception->getMessage(),
                'exception_class' => get_class($exception),
            ]);
            return response()->json([
                'message' => 'Internal server error',
            ], 500);
        }

        if (request()->header('Accept') === 'application/json' || request()->wantsJson()) {
            return response()->json($summary);
        }

        return view('pos::session.summary', $summary);
    }

    /**
     * Get detailed information for a specific checkout in a session
     */
    public function checkoutDetail(int $session, int $checkout): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Authentication is required.'], 403);
        }

        $posCheckout = PosCheckout::query()
            ->with([
                'transaction.lines.product',
                'transaction.lines.serials',
                'customer',
                'cashier',
                'paymentMethod',
                'payments.paymentMethod',
            ])
            ->where('id', $checkout)
            ->where('pos_session_id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posCheckout) {
            return response()->json(['message' => 'Checkout record not found.'], 404);
        }

        // Authorization: owner or pos.sessions.view
        $isOwner = (int) $posCheckout->cashier_user_id === (int) $user->id;
        if (! $isOwner && ! $user->can('pos.sessions.view')) {
            return response()->json(['message' => 'Not authorized to view checkout details.'], 403);
        }

        return response()->json($posCheckout);
    }

    public function safeDrop(
        int $session,
        StorePosSafeDropRequest $request,
        PosSafeDropService $safeDropService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        if (! $user) {
            abort(403, 'Otentikasi diperlukan.');
        }

        $posSession = PosSession::query()
            ->select(['id', 'setting_id'])
            ->where('id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posSession) {
            abort(404, 'Sesi POS tidak ditemukan untuk pengaturan saat ini.');
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

    public function close(
        int $session,
        StorePosSessionCloseRequest $request,
        PosSessionCloseService $sessionCloseService,
        PosSessionAdminCloseService $adminCloseService
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

        $posSession = PosSession::query()
            ->select(['id', 'setting_id', 'cashier_user_id'])
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

        // Check if user has admin close permission
        $hasAdminPermission = $user->can('pos.sessions.close-admin');

        // Check if user is the session owner
        $isSessionOwner = (int) $posSession->cashier_user_id === (int) $user->id;

        // Determine which service to use based on permissions and ownership
        try {
            if ($hasAdminPermission) {
                // Admin can close any session
                \Illuminate\Support\Facades\Log::channel('single')->info('POS Close Session: Using admin close', [
                    'session_id' => $posSession->id,
                    'admin_user_id' => $user->id,
                ]);

                $result = $adminCloseService->closeSessionAsAdmin(
                    $settingId,
                    (int) $posSession->id,
                    (int) $user->id,
                    $request->filled('reason') ? $request->string('reason')->value() : null
                );
            } elseif ($user->can('pos.sessions.close')) {
                // Non-admin user can only close their own session
                if (! $isSessionOwner) {
                    \Illuminate\Support\Facades\Log::channel('single')->error('POS Close Session: Not session owner', [
                        'session_id' => $session,
                        'user_id' => $user->id,
                        'session_cashier_id' => $posSession->cashier_user_id,
                    ]);
                    return response()->json([
                        'message' => 'You do not have permission to close this session.',
                    ], 403);
                }

                \Illuminate\Support\Facades\Log::channel('single')->info('POS Close Session: Using standard close', [
                    'session_id' => $posSession->id,
                    'user_id' => $user->id,
                ]);

                $result = $sessionCloseService->closeSession(
                    $settingId,
                    (int) $posSession->id,
                    (int) $user->id,
                    $request->filled('reason') ? $request->string('reason')->value() : null
                );
            } else {
                \Illuminate\Support\Facades\Log::channel('single')->error('POS Close Session: Permission denied', [
                    'user_id' => $user->id,
                    'permissions' => ['pos.sessions.close', 'pos.sessions.close-admin'],
                ]);
                return response()->json([
                    'message' => 'You do not have permission to close sessions.',
                ], 403);
            }
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
        $validated = request()->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'supervisor_id' => ['required', 'integer', 'gt:0'],
            'otp_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/', 'digits:6'],
        ]);

        $amount = (float) $validated['amount'];
        $supervisorId = (int) $validated['supervisor_id'];
        $otpCode = (string) $validated['otp_code'];

        // Validate amount against expected cash
        $expectedCash = (float) ($posSession->expected_cash_total ?? 0);
        if ($amount > $expectedCash) {
            return response()->json([
                'message' => 'Jumlah pengambilan tidak boleh melebihi ekspektasi kas.',
            ], 422);
        }

        try {
            // Verify supervisor via OTP
            $approvalResult = $approvalService->approveSafeDropWithOtp(
                $supervisorId,
                $otpCode,
                $posSession,
                $amount,
                (int) $user->id
            );

            if (! $approvalResult['approved']) {
                $reason = $approvalResult['reason'] ?? 'UNKNOWN';

                \Illuminate\Support\Facades\Log::channel('single')->warning('POS Pickup: Supervisor approval rejected', [
                    'session_id' => $session,
                    'user_id' => (int) $user->id,
                    'supervisor_id' => $supervisorId,
                    'reason' => $reason,
                    'approval_id' => $approvalResult['approval_id'] ?? null,
                ]);

                $message = 'Persetujuan supervisor gagal.';

                if ($reason === 'INVALID_SUPERVISOR') {
                    $message = 'Supervisor tidak ditemukan atau tidak aktif.';
                } elseif ($reason === 'INVALID_OTP') {
                    $message = 'Kode OTP tidak valid atau telah kadaluarsa.';
                } elseif ($reason === 'TOTP_NOT_CONFIGURED') {
                    $message = 'Supervisor belum mengaktifkan autentikasi dua faktor.';
                } elseif ($reason === 'MISSING_PERMISSION') {
                    $message = 'Supervisor tidak memiliki izin yang diperlukan untuk persetujuan pengambilan kas.';
                }

                return response()->json(['message' => $message], 422);
            }

            // Get supervisor for info logging
            $supervisor = \App\Models\User::find($supervisorId);
            
            // Create safe drop with supervisor info (for compatibility with existing service)
            $result = $safeDropService->createSafeDrop(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                $amount,
                null, // denominations
                'Pengambilan kas dari terminal POS',
                $supervisor?->email ?? "User #$supervisorId",
                '***otp-verified***'
            );

            return response()->json([
                'message' => 'Pengambilan kas berhasil.',
                'expected_cash_after' => (float) ($result['expected_cash_after'] ?? $expectedCash - $amount),
            ]);
        } catch (DomainException $exception) {
            \Illuminate\Support\Facades\Log::channel('single')->warning('POS Pickup: Rejected by DomainException', [
                'session_id' => $session,
                'user_id' => (int) $user->id,
                'supervisor_id' => $supervisorId,
                'amount' => $amount,
                'expected_cash' => $expectedCash,
                'message' => $exception->getMessage(),
                'origin' => $exception->getFile().':'.$exception->getLine(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
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
                request()->filled('notes') ? request()->string('notes')->value() : null,
                request()->filled('supervisor_identifier') ? request()->string('supervisor_identifier')->value() : null,
                request()->filled('supervisor_password') ? request()->string('supervisor_password')->value() : null
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

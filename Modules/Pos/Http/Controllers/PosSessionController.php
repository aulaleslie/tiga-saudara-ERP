<?php

namespace Modules\Pos\Http\Controllers;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Http\Requests\StorePosSessionCloseRequest;
use Modules\Pos\Http\Requests\StorePosSafeDropRequest;
use Modules\Pos\Http\Requests\StorePosSessionOpenRequest;
use Modules\Pos\Services\PosSessionCloseService;
use Modules\Pos\Services\PosSafeDropService;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Pos\Services\PosSessionMonitorService;
use Modules\Pos\Services\PosRolePolicyService;
use Modules\Pos\Services\PosSessionSummaryService;
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
            ->with(['terminal', 'cashier'])
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

        return redirect()->route('pos.sell');
    }

    public function summary(int $session, PosSessionSummaryService $sessionSummaryService): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $user = auth()->user();

        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        $posSession = PosSession::query()
            ->select(['id', 'setting_id', 'cashier_user_id'])
            ->where('id', $session)
            ->where('setting_id', $settingId)
            ->first();

        if (! $posSession) {
            abort(404, 'POS session not found for current setting.');
        }

        $isOwner = (int) $posSession->cashier_user_id === (int) $user->id;
        $canMonitor = $user->can('pos.monitor.access');

        if (! $isOwner && ! $canMonitor) {
            abort(403, 'Not authorized to view POS session summary.');
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
            $result = $sessionCloseService->closeSession(
                $settingId,
                (int) $posSession->id,
                (int) $user->id,
                (float) $request->input('counted_cash_total'),
                $request->input('counted_denominations'),
                $request->filled('notes') ? $request->string('notes')->value() : null,
                $request->filled('supervisor_identifier') ? $request->string('supervisor_identifier')->value() : null,
                $request->filled('supervisor_pin') ? $request->string('supervisor_pin')->value() : null
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ((bool) ($result['blocked'] ?? false)) {
            return response()->json($result['payload'], 422);
        }

        return response()->json($result['payload']);
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

    public function monitor(): Renderable
    {
        $settingId = $this->currentSettingId();

        return view('pos::monitor.index', compact('settingId'));
    }

    public function monitorApi(PosSessionMonitorService $monitorService): JsonResponse
    {
        $settingId = $this->currentSettingId();

        $summaries = $monitorService->getActiveSessionsSummary($settingId);

        return response()->json($summaries);
    }
}

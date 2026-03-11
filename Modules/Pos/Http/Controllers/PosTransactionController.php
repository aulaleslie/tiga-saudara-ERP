<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Http\Requests\StorePosTransactionLoadRequest;
use Modules\Pos\Http\Requests\StorePosTransactionSaveRequest;
use Modules\Pos\Services\Exceptions\PosTransactionConflictException;
use Modules\Pos\Services\Exceptions\PosTransactionValidationException;
use Modules\Pos\Services\PosTransactionService;
use Modules\Setting\Entities\Setting;

class PosTransactionController extends Controller
{
    public function __construct(
        private readonly PosTransactionService $transactionService
    ) {}

    /**
     * POST /pos/sell/transactions/save-and-new
     * Save current cart as DRAFT and open a new cart.
     */
    public function saveAndNew(StorePosTransactionSaveRequest $request): JsonResponse
    {
        try {
            $settingId = $this->currentSettingId();
            if ($guardResponse = $this->transactionsDisabledResponse($request, $settingId)) {
                return $guardResponse;
            }
            $posSession = $request->attributes->get('pos_active_session');

            if (!$posSession instanceof PosSession) {
                return response()->json([
                    'message' => 'Sesi POS tidak aktif.',
                ], 500);
            }

            $transaction = $this->transactionService->saveAndNew(
                $settingId,
                $posSession,
                $request->user()
            );

            return response()->json([
                'message' => 'Transaksi disimpan berhasil.',
                'transaction' => [
                    'id' => $transaction->id,
                    'code' => $transaction->code,
                    'status' => $transaction->status,
                ],
            ], 201);
        } catch (PosTransactionValidationException $e) {
            return response()->json([
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 422);
        } catch (PosTransactionConflictException $e) {
            return response()->json([
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 409);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /pos/transactions
     * Show transaction list page.
     */
    public function index(Request $request): Renderable
    {
        $settingId = $this->currentSettingId();
        abort_if(
            ! $this->transactionsEnabled($settingId),
            403,
            'Fitur transaksi POS belum diaktifkan untuk bisnis ini.'
        );

        return view('pos::transactions.index', [
            'settingId' => $settingId,
        ]);
    }

    /**
     * GET /pos/transactions/data
     * Get paginated transaction list data for AJAX/DataTables.
     */
    public function data(Request $request): JsonResponse
    {
        try {
            $settingId = $this->currentSettingId();
            if ($guardResponse = $this->transactionsDisabledResponse($request, $settingId)) {
                return $guardResponse;
            }
            $validator = Validator::make($request->query(), [
                'status' => ['nullable', 'array'],
                'status.*' => ['string'],
                'owner_user_id' => ['nullable', 'integer', 'min:1'],
                'q' => ['nullable', 'string'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Parameter filter transaksi tidak valid.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();
            $dateFrom = $validated['date_from'] ?? null;
            $dateTo = $validated['date_to'] ?? null;

            if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
                return response()->json([
                    'code' => 'INVALID_DATE_RANGE',
                    'message' => 'Rentang tanggal tidak valid: date_from harus sebelum atau sama dengan date_to.',
                ], 422);
            }

            $filters = [
                'status' => $validated['status'] ?? [],
                'owner_user_id' => $validated['owner_user_id'] ?? null,
                'q' => $validated['q'] ?? null,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ];

            $perPage = (int) ($validated['per_page'] ?? 20);
            $paginator = $this->transactionService->list($settingId, $filters, $perPage);

            return response()->json([
                'data' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memuat daftar transaksi.',
            ], 500);
        }
    }

    /**
     * GET /pos/transactions/{transaction}
     * Show transaction detail.
     */
    public function show(PosTransaction $transaction, Request $request): Renderable
    {
        $settingId = $this->currentSettingId();
        abort_if(
            ! $this->transactionsEnabled($settingId),
            403,
            'Fitur transaksi POS belum diaktifkan untuk bisnis ini.'
        );
        $this->assertSettingScope($transaction, $settingId);

        $transaction->load(['lines.serials', 'owner', 'customer']);

        return view('pos::transactions.show', [
            'transaction' => $transaction,
        ]);
    }

    /**
     * POST /pos/transactions/{transaction}/load
     * Load a DRAFT transaction into the session cart.
     */
    public function load(PosTransaction $transaction, StorePosTransactionLoadRequest $request): JsonResponse
    {
        try {
            $settingId = $this->currentSettingId();
            if ($guardResponse = $this->transactionsDisabledResponse($request, $settingId)) {
                return $guardResponse;
            }
            $this->assertSettingScope($transaction, $settingId);

            $activeSession = $request->attributes->get('pos_active_session');
            if (!$activeSession instanceof PosSession) {
                return response()->json([
                    'message' => 'Sesi POS tidak aktif.',
                ], 500);
            }

            $cartSnapshot = $this->transactionService->loadToCart(
                $settingId,
                $activeSession->id,
                $transaction,
                $request->user()
            );

            return response()->json([
                'message' => 'Transaksi dimuat ke keranjang.',
                'cart_snapshot' => $cartSnapshot,
                'transaction' => [
                    'id' => $transaction->id,
                    'code' => $transaction->code,
                    'status' => $transaction->status,
                ],
            ], 200);
        } catch (PosTransactionValidationException $e) {
            return response()->json([
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 422);
        } catch (PosTransactionConflictException $e) {
            return response()->json([
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 409);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /pos/transactions/{transaction}/cancel
     * Cancel a DRAFT or LOADED transaction.
     */
    public function cancel(PosTransaction $transaction, Request $request): JsonResponse
    {
        try {
            $settingId = $this->currentSettingId();
            if ($guardResponse = $this->transactionsDisabledResponse($request, $settingId)) {
                return $guardResponse;
            }
            $this->assertSettingScope($transaction, $settingId);

            $transaction = $this->transactionService->cancel($transaction, $request->user());

            return response()->json([
                'message' => 'Transaksi dibatalkan.',
                'transaction' => [
                    'id' => $transaction->id,
                    'code' => $transaction->code,
                    'status' => $transaction->status,
                ],
            ], 200);
        } catch (PosTransactionValidationException $e) {
            return response()->json([
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 422);
        } catch (PosTransactionConflictException $e) {
            return response()->json([
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 409);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Assert transaction belongs to current setting.
     */
    private function assertSettingScope(PosTransaction $transaction, int $settingId): void
    {
        if ($transaction->setting_id !== $settingId) {
            abort(403, 'Transaksi ini tidak termasuk dalam setting Anda.');
        }
    }

    private function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');
        abort_if($settingId <= 0, 403, 'Setting context is required.');

        return $settingId;
    }

    private function transactionsEnabled(int $settingId): bool
    {
        return (bool) Setting::query()
            ->whereKey($settingId)
            ->value('pos_transactions_enabled');
    }

    private function transactionsDisabledResponse(Request $request, int $settingId): ?JsonResponse
    {
        if ($this->transactionsEnabled($settingId)) {
            return null;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'code' => 'POS_TRANSACTIONS_DISABLED',
                'message' => 'Fitur transaksi POS belum diaktifkan untuk bisnis ini.',
            ], 403);
        }

        abort(403, 'Fitur transaksi POS belum diaktifkan untuk bisnis ini.');
    }
}

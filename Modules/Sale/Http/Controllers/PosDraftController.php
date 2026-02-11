<?php

namespace Modules\Sale\Http\Controllers;

use App\Exceptions\PosException;
use App\Services\PosAuditLogger;
use App\Support\PosMetrics;
use App\Support\PosSessionManager;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Sale\Entities\PosDraft;
use Modules\Sale\Entities\PosDraftItem;
use Modules\Sale\Entities\PosSubmitIdempotency;
use Modules\Sale\Http\Requests\StorePosDraftRequest;
use Modules\Sale\Http\Requests\StorePosSaleRequest;
use Modules\Sale\Http\Requests\SubmitPosDraftPaymentRequest;
use Modules\Sale\Http\Requests\UpdatePosDraftRequest;
use Modules\Sale\Services\PosCodeAllocator;
use Modules\Sale\Services\PosDraftLockService;
use Modules\Setting\Entities\Setting;

class PosDraftController extends Controller
{
    public function __construct(
        private readonly PosCodeAllocator $allocator,
        private readonly PosDraftLockService $lockService,
    ) {
    }

    public function store(StorePosDraftRequest $request): JsonResponse
    {
        $setting = Setting::query()->findOrFail((int) session('setting_id'));
        $this->guardDraftFlowEnabled($setting);
        $payload = $this->buildDraftPayload($request);

        if (empty($payload['cart'])) {
            throw new PosException('POS_DRAFT_STATE_INVALID', 'Keranjang kosong, draft tidak dapat dibuat.', 422);
        }

        $posSession = $request->attributes->get('pos_session') ?? app(PosSessionManager::class)->ensureActive();

        $expiryMinutes = max(1, (int) ($setting->pos_draft_expiry_minutes ?? 1440));

        $draft = DB::transaction(function () use ($setting, $request, $payload, $posSession, $expiryMinutes) {
            $draft = null;
            $attempts = 0;

            while ($attempts < 5) {
                $attempts++;

                try {
                    $draft = PosDraft::query()->create([
                        'pos_session_id' => $posSession?->id,
                        'setting_id' => $setting->id,
                        'user_id' => auth()->id(),
                        'status' => PosDraft::STATUS_AJUKAN_PEMBAYARAN,
                        'payload' => $payload,
                        'document_number' => $this->allocator->allocate($setting),
                        'expires_at' => now()->addMinutes($expiryMinutes),
                        'last_touched_at' => now(),
                    ]);

                    break;
                } catch (QueryException $exception) {
                    if (str_contains(strtolower($exception->getMessage()), 'pos_drafts_setting_doc_unique')) {
                        continue;
                    }

                    throw $exception;
                }
            }

            if (! $draft) {
                throw new PosException('POS_REFERENCE_GENERATION_FAILED', 'Gagal mengalokasikan kode POS draft.', 500);
            }

            $this->syncDraftItems($draft, $payload['cart'] ?? []);

            return $draft;
        });

        PosAuditLogger::record('draft.created', $draft, payload: [
            'item_count' => count($payload['cart'] ?? []),
            'total_amount' => $payload['total_amount'] ?? 0,
        ]);

        PosMetrics::increment('draft_created', [
            'setting_id' => $draft->setting_id,
        ]);

        return response()->json([
            'code' => $draft->document_number,
            'status' => $draft->status,
            'expires_at' => optional($draft->expires_at)->toIso8601String(),
            'totals' => [
                'total_amount' => (float) ($payload['total_amount'] ?? 0),
                'item_count' => count($payload['cart'] ?? []),
            ],
            'draft_id' => $draft->id,
        ], 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        abort_if(
            Gate::denies('pos.drafts.view') && Gate::denies('pos.create') && Gate::denies('pos.access'),
            403
        );

        $draft = $this->findDraftByCode($code);

        if ($draft->isExpired() && $draft->status === PosDraft::STATUS_AJUKAN_PEMBAYARAN) {
            $draft->update(['status' => PosDraft::STATUS_KEDALUWARSA]);
            $draft->refresh();
        }

        return response()->json([
            'draft' => [
                'id' => $draft->id,
                'code' => $draft->document_number,
                'status' => $draft->status,
                'expires_at' => optional($draft->expires_at)->toIso8601String(),
                'locked_by_user_id' => $draft->locked_by_user_id,
                'locked_until' => optional($draft->locked_until)->toIso8601String(),
                'payload' => $draft->payload,
                'items' => $draft->items,
            ],
        ]);
    }

    public function update(UpdatePosDraftRequest $request, string $code): JsonResponse
    {
        $draft = $this->findDraftByCode($code);

        if ($draft->isFinalized()) {
            throw new PosException('POS_DRAFT_STATE_INVALID', 'Draft tidak dapat diubah.', 422);
        }

        if ($draft->isExpired() && $draft->status === PosDraft::STATUS_AJUKAN_PEMBAYARAN) {
            $draft->forceFill(['status' => PosDraft::STATUS_KEDALUWARSA])->save();
            throw new PosException('POS_DRAFT_EXPIRED', 'Draft sudah kedaluwarsa.', 409);
        }

        if ($this->hasSubmitAttempt($draft)) {
            throw new PosException('POS_DRAFT_STATE_INVALID', 'Draft sedang atau sudah diproses pembayaran.', 409);
        }

        if ($draft->hasActiveLock() && (int) $draft->locked_by_user_id !== (int) auth()->id()) {
            throw new PosException('POS_LOCK_CONFLICT', 'Draft sedang dikunci kasir lain.', 409);
        }

        $payload = $this->mergeDraftPayload($draft->payload ?? [], $request->validated());

        $draft->forceFill([
            'payload' => $payload,
            'last_touched_at' => now(),
            'expires_at' => now()->addMinutes(max(1, (int) (optional($draft->setting)->pos_draft_expiry_minutes ?? 1440))),
        ])->save();

        $this->syncDraftItems($draft, $payload['cart'] ?? []);

        PosAuditLogger::record('draft.updated', $draft, payload: [
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'code' => $draft->document_number,
            'status' => $draft->status,
            'expires_at' => optional($draft->expires_at)->toIso8601String(),
            'payload' => $draft->payload,
        ]);
    }

    public function lock(Request $request, string $code): JsonResponse
    {
        abort_if(Gate::denies('pos.drafts.submit') && Gate::denies('pos.create'), 403);

        $draft = $this->findDraftByCode($code);
        $override = (bool) $request->boolean('override', false);

        if ($override && Gate::denies('pos.drafts.lock.override')) {
            throw new PosException('POS_LOCK_FORBIDDEN_OVERRIDE', 'Tidak memiliki izin override lock.', 403);
        }

        $draft = $this->lockService->acquire($draft, (int) auth()->id(), $override);

        PosAuditLogger::record('draft.locked', $draft, payload: [
            'override' => $override,
            'locked_until' => optional($draft->locked_until)->toIso8601String(),
        ]);

        PosMetrics::increment('lock_acquired', [
            'setting_id' => $draft->setting_id,
        ]);

        return response()->json([
            'code' => $draft->document_number,
            'locked_by_user_id' => $draft->locked_by_user_id,
            'locked_until' => optional($draft->locked_until)->toIso8601String(),
        ]);
    }

    public function heartbeat(string $code): JsonResponse
    {
        abort_if(Gate::denies('pos.drafts.submit') && Gate::denies('pos.create'), 403);

        $draft = $this->findDraftByCode($code);
        $draft = $this->lockService->heartbeat($draft, (int) auth()->id());

        return response()->json([
            'code' => $draft->document_number,
            'locked_until' => optional($draft->locked_until)->toIso8601String(),
        ]);
    }

    public function unlock(Request $request, string $code): JsonResponse
    {
        abort_if(Gate::denies('pos.drafts.submit') && Gate::denies('pos.create'), 403);

        $draft = $this->findDraftByCode($code);
        $override = (bool) $request->boolean('override', false);

        if ($override && Gate::denies('pos.drafts.lock.override')) {
            throw new PosException('POS_LOCK_FORBIDDEN_OVERRIDE', 'Tidak memiliki izin override lock.', 403);
        }

        $draft = $this->lockService->release($draft, (int) auth()->id(), $override);

        PosAuditLogger::record('draft.unlocked', $draft, payload: ['override' => $override]);

        return response()->json([
            'code' => $draft->document_number,
            'locked_by_user_id' => $draft->locked_by_user_id,
            'locked_until' => optional($draft->locked_until)->toIso8601String(),
        ]);
    }

    public function submitPayment(SubmitPosDraftPaymentRequest $request, string $code): JsonResponse
    {
        $draft = $this->findDraftByCode($code);

        $idempotencyKey = (string) $request->input('idempotency_key');

        $existingIdempotency = PosSubmitIdempotency::query()
            ->where('setting_id', $draft->setting_id)
            ->where('pos_draft_id', $draft->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingIdempotency && $existingIdempotency->response_payload) {
            return response()->json($existingIdempotency->response_payload, 200);
        }

        $latestIdempotency = PosSubmitIdempotency::query()
            ->where('setting_id', $draft->setting_id)
            ->where('pos_draft_id', $draft->id)
            ->latest('id')
            ->first();

        if ($latestIdempotency && $latestIdempotency->idempotency_key !== $idempotencyKey) {
            if ($latestIdempotency->response_payload) {
                return response()->json($latestIdempotency->response_payload, 200);
            }

            throw new PosException('POS_DRAFT_STATE_INVALID', 'Pembayaran draft sedang diproses.', 409);
        }

        $this->lockService->assertPayableState($draft);
        $this->lockService->ensureOwner($draft, (int) auth()->id());

        $idempotency = DB::transaction(function () use ($draft, $idempotencyKey) {
            return PosSubmitIdempotency::query()->firstOrCreate([
                'setting_id' => $draft->setting_id,
                'pos_draft_id' => $draft->id,
                'idempotency_key' => $idempotencyKey,
            ], [
                'created_by' => auth()->id(),
            ]);
        });

        if ($idempotency->response_payload) {
            return response()->json($idempotency->response_payload, 200);
        }

        $payload = $draft->payload ?? [];
        $this->hydrateCartFromDraftPayload($payload);

        $submitPayload = [
            'customer_id' => (int) ($payload['customer_id'] ?? 0),
            'tax_percentage' => (int) ($payload['tax_percentage'] ?? 0),
            'discount_percentage' => (int) ($payload['discount_percentage'] ?? 0),
            'shipping_amount' => (float) ($payload['shipping_amount'] ?? 0),
            'total_amount' => (float) ($payload['total_amount'] ?? 0),
            'paid_amount' => round((float) collect($request->input('payments', []))->sum('amount'), 2),
            'payments' => $request->input('payments', []),
            'note' => $request->input('note', $payload['note'] ?? null),
            'pos_location_assignment_id' => $request->input('pos_location_assignment_id', $payload['pos_location_assignment_id'] ?? null),
            'receipt_number' => $draft->document_number,
            'pos_draft_id' => $draft->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $storeRequest = StorePosSaleRequest::create('/app/pos', 'POST', $submitPayload);
        $storeRequest->setContainer(app())->setRedirector(app('redirect'));
        $storeRequest->setLaravelSession($request->session());
        $storeRequest->setUserResolver(static fn () => auth()->user());
        $storeRequest->attributes->set('pos_session', $request->attributes->get('pos_session') ?? app(PosSessionManager::class)->ensureActive());
        $storeRequest->validateResolved();

        $storeResponse = app(PosController::class)->store($storeRequest);

        if (session()->has('errors')) {
            $messages = session('errors')->getBag('default')->all();
            $message = $messages[0] ?? 'Gagal memproses pembayaran draft.';
            throw new PosException('POS_DRAFT_STATE_INVALID', $message, 422, ['errors' => $messages]);
        }

        $receiptId = session('pos_last_transaction_id') ?: session('pos_receipt_id');
        if (! $receiptId) {
            throw new PosException('POS_REFERENCE_GENERATION_FAILED', 'Pembayaran draft tidak menghasilkan receipt.', 500);
        }

        $receipt = \App\Models\PosReceipt::query()->with('sales')->find((int) $receiptId);
        if (! $receipt) {
            throw new PosException('POS_REFERENCE_GENERATION_FAILED', 'Receipt POS tidak ditemukan setelah pembayaran.', 500);
        }

        $draft->forceFill([
            'status' => PosDraft::STATUS_TERBAYAR,
            'pos_receipt_id' => $receipt->id,
            'submitted_at' => now(),
            'locked_by_user_id' => null,
            'locked_at' => null,
            'locked_until' => null,
            'last_touched_at' => now(),
        ])->save();

        $responsePayload = [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'linked_sales' => $receipt->sales->map(fn ($sale) => [
                'sale_id' => $sale->id,
                'reference' => $sale->reference,
                'setting_id' => $sale->setting_id,
                'total_amount' => (float) $sale->total_amount,
            ])->values()->all(),
            'change_due' => (float) $receipt->change_due,
        ];

        $idempotency->forceFill([
            'pos_receipt_id' => $receipt->id,
            'response_payload' => $responsePayload,
        ])->save();

        PosAuditLogger::record('payment.submitted', $draft, payload: [
            'pos_receipt_id' => $receipt->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        PosMetrics::increment('payment_success', [
            'setting_id' => $draft->setting_id,
        ]);

        return response()->json($responsePayload);
    }

    public function void(Request $request, string $code): JsonResponse
    {
        abort_if(Gate::denies('pos.drafts.void'), 403);

        $draft = $this->findDraftByCode($code);

        if ($draft->isExpired() && $draft->status === PosDraft::STATUS_AJUKAN_PEMBAYARAN) {
            $draft->forceFill(['status' => PosDraft::STATUS_KEDALUWARSA])->save();
            throw new PosException('POS_DRAFT_EXPIRED', 'Draft sudah kedaluwarsa.', 409);
        }

        if ($draft->status === PosDraft::STATUS_TERBAYAR) {
            throw new PosException('POS_DRAFT_ALREADY_PAID', 'Draft sudah dibayar dan tidak dapat dibatalkan.', 409);
        }

        if ($this->hasSubmitAttempt($draft)) {
            throw new PosException('POS_DRAFT_STATE_INVALID', 'Draft sedang atau sudah diproses pembayaran.', 409);
        }

        if ($draft->status === PosDraft::STATUS_DIBATALKAN) {
            return response()->json([
                'code' => $draft->document_number,
                'status' => $draft->status,
            ]);
        }

        $draft->forceFill([
            'status' => PosDraft::STATUS_DIBATALKAN,
            'locked_by_user_id' => null,
            'locked_at' => null,
            'locked_until' => null,
            'last_touched_at' => now(),
        ])->save();

        PosAuditLogger::record('draft.voided', $draft, payload: [
            'reason' => $request->input('reason'),
        ]);

        PosMetrics::increment('void_count', ['setting_id' => $draft->setting_id]);

        return response()->json([
            'code' => $draft->document_number,
            'status' => $draft->status,
        ]);
    }

    private function findDraftByCode(string $code): PosDraft
    {
        $settingId = (int) session('setting_id');

        $draft = PosDraft::query()
            ->with(['items', 'setting'])
            ->where('setting_id', $settingId)
            ->where('document_number', $code)
            ->first();

        if (! $draft) {
            throw new PosException('POS_DRAFT_NOT_FOUND', 'Draft tidak ditemukan.', 404);
        }

        $this->guardDraftFlowEnabled($draft->setting);

        return $draft;
    }

    private function hasSubmitAttempt(PosDraft $draft): bool
    {
        return PosSubmitIdempotency::query()
            ->where('setting_id', $draft->setting_id)
            ->where('pos_draft_id', $draft->id)
            ->exists();
    }

    private function buildDraftPayload(StorePosDraftRequest $request): array
    {
        $payload = $request->input('payload', []);
        if (! is_array($payload)) {
            $payload = [];
        }

        if (empty($payload['cart'])) {
            $payload['cart'] = $this->snapshotCurrentCart();
        }

        $payload['customer_id'] = $request->input('customer_id', $payload['customer_id'] ?? null);
        $payload['tax_percentage'] = (int) $request->input('tax_percentage', $payload['tax_percentage'] ?? 0);
        $payload['discount_percentage'] = (int) $request->input('discount_percentage', $payload['discount_percentage'] ?? 0);
        $payload['shipping_amount'] = (float) $request->input('shipping_amount', $payload['shipping_amount'] ?? 0);
        $payload['note'] = $request->input('note', $payload['note'] ?? null);
        $payload['pos_location_assignment_id'] = $request->input('pos_location_assignment_id', $payload['pos_location_assignment_id'] ?? null);

        $computedTotal = collect($payload['cart'] ?? [])->sum(function ($line) {
            return (float) data_get($line, 'options.sub_total', (float) data_get($line, 'price', 0) * (float) data_get($line, 'qty', 0));
        });

        $payload['total_amount'] = round((float) $request->input('total_amount', $payload['total_amount'] ?? $computedTotal), 2);

        return $payload;
    }

    private function mergeDraftPayload(array $existingPayload, array $incoming): array
    {
        $payload = $existingPayload;

        foreach (['customer_id', 'tax_percentage', 'discount_percentage', 'shipping_amount', 'total_amount', 'note', 'pos_location_assignment_id'] as $field) {
            if (array_key_exists($field, $incoming)) {
                $payload[$field] = $incoming[$field];
            }
        }

        if (isset($incoming['payload']) && is_array($incoming['payload'])) {
            $payload = array_replace_recursive($payload, $incoming['payload']);
        }

        if (empty($payload['cart'])) {
            $payload['cart'] = [];
        }

        return $payload;
    }

    private function snapshotCurrentCart(): array
    {
        return Cart::instance('sale')->content()->map(function ($item) {
            $options = method_exists($item->options, 'toArray') ? $item->options->toArray() : (array) $item->options;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'qty' => (float) $item->qty,
                'price' => (float) $item->price,
                'weight' => (float) ($item->weight ?? 0),
                'options' => $options,
            ];
        })->values()->all();
    }

    private function syncDraftItems(PosDraft $draft, array $cart): void
    {
        $draft->items()->delete();

        foreach ($cart as $line) {
            PosDraftItem::query()->create([
                'pos_draft_id' => $draft->id,
                'product_id' => data_get($line, 'options.product_id'),
                'product_name' => (string) data_get($line, 'name', ''),
                'quantity' => (int) data_get($line, 'qty', 0),
                'unit_price' => (float) data_get($line, 'price', 0),
                'sub_total' => (float) data_get($line, 'options.sub_total', (float) data_get($line, 'qty', 0) * (float) data_get($line, 'price', 0)),
                'payload' => is_array($line) ? $line : (array) $line,
            ]);
        }
    }

    private function hydrateCartFromDraftPayload(array $payload): void
    {
        $cart = Cart::instance('sale');
        $cart->destroy();

        foreach ((array) ($payload['cart'] ?? []) as $line) {
            $options = data_get($line, 'options', []);
            if ($options instanceof \Illuminate\Support\Collection) {
                $options = $options->toArray();
            }

            $cart->add([
                'id' => data_get($line, 'id'),
                'name' => (string) data_get($line, 'name', ''),
                'qty' => (float) data_get($line, 'qty', 0),
                'price' => (float) data_get($line, 'price', 0),
                'weight' => (float) data_get($line, 'weight', 0),
                'options' => is_array($options) ? $options : (array) $options,
            ]);
        }
    }

    private function guardDraftFlowEnabled(?Setting $setting): void
    {
        if (! $setting || ! (bool) $setting->pos_draft_flow_enabled) {
            Log::warning('pos.draft.flow.disabled', [
                'setting_id' => optional($setting)->id,
                'user_id' => auth()->id(),
            ]);

            throw new PosException('POS_DRAFT_FLOW_DISABLED', 'Fitur draft POS belum aktif untuk bisnis ini.', 403);
        }
    }
}

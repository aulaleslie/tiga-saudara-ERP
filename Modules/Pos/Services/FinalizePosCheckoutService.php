<?php

namespace Modules\Pos\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutPayment;
use Modules\Pos\Entities\PosCheckoutPaymentAllocation;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\PosCashDrawerService;
use Modules\Pos\Services\Exceptions\PosCheckoutConflictException;
use Modules\Pos\Services\Exceptions\PosCheckoutPostingException;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Setting\Entities\PaymentMethod;
use Throwable;

class FinalizePosCheckoutService
{
    public function __construct(
        private readonly PosCartService $cartService,
        private readonly PosCartSessionStore $cartSessionStore,
        private readonly PosCheckoutPostingAdapter $postingAdapter,
        private readonly ResolvePosStockAllocationsService $stockResolver,
        private readonly PosReceiptNumberGenerator $receiptNumberGenerator,
        private readonly PosCashDrawerService $cashDrawerService,
        private readonly ?PosTransactionService $transactionService = null,
        private readonly ?PosCheckoutPaymentNormalizationService $paymentNormalizationService = null
    ) {
    }

    /**
     * @param  array<string, mixed>  $paymentPayload
     * @param  array<string, mixed>|null  $clientContext
     * @return array{
     *     http_status:int,
     *     payload:array{
     *         pos_checkout_id:int,
     *         status:string,
     *         receipt_number:string,
     *         sale_id:int,
     *         dispatch_ids:array<int, int>,
     *         sale_payment_id:int,
     *         paid_total:float,
     *         change_total:float,
     *         idempotent_replay:bool
     *     }
     * }
     */
    public function finalize(
        int $settingId,
        PosSession $activeSession,
        int $cashierUserId,
        string $idempotencyKey,
        array $paymentPayload,
        ?array $clientContext = null
    ): array {
        if ($settingId <= 0 || $cashierUserId <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Checkout context is invalid.');
        }

        $sessionId = (int) $activeSession->id;
        $terminalId = (int) $activeSession->terminal_id;

        if ($sessionId <= 0 || $terminalId <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Active POS session context is invalid.');
        }

        $normalizedIdempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        if ($normalizedIdempotencyKey === '') {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Idempotency key is required.');
        }

        $postedCheckout = PosCheckout::query()
            ->where('setting_id', $settingId)
            ->where('idempotency_key', $normalizedIdempotencyKey)
            ->where('status', PosCheckout::STATUS_POSTED)
            ->first();

        if ($postedCheckout) {
            return [
                'http_status' => 200,
                'payload' => $this->buildReplayPayload($postedCheckout),
            ];
        }

        // Determine if legacy single-payment or multi-payment path
        $isMultiPayment = isset($paymentPayload['payments']) && is_array($paymentPayload['payments']);

        if ($isMultiPayment) {
            $payment = $this->normalizeMultiPayment($settingId, $paymentPayload['payments']);
        } else {
            $payment = $this->normalizePayment($settingId, $paymentPayload['payment'] ?? $paymentPayload);
        }

        $cartSnapshot = $this->cartService->getSnapshot($settingId, $sessionId);

        $resolvedCustomerId = (int) ($cartSnapshot['customer']['resolved_customer_id'] ?? 0);
        $resolvedCustomerId = $resolvedCustomerId > 0 ? $resolvedCustomerId : null;
        $totals = $this->validateCartAndPayment($cartSnapshot, $payment, $resolvedCustomerId);

        $payloadHash = $this->payloadHash(
            $settingId,
            $sessionId,
            $terminalId,
            $cashierUserId,
            (int) $resolvedCustomerId,
            $cartSnapshot,
            $payment
        );

        $paidTotal = $payment['amount_paid'];
        $grandTotal = $totals['grand_total'];

        // Calculate change based on whether there's cash in the payment
        if ((bool) ($payment['is_multi_payment'] ?? false)) {
            $totalCashMinor = (int) ($payment['total_cash_minor_units'] ?? 0);
            $totalCash = $totalCashMinor / 100;
            $changeTotal = round(max(0, $totalCash - $grandTotal), 2);
        } else {
            $changeTotal = $payment['is_cash']
                ? round(max(0, $paidTotal - $grandTotal), 2)
                : 0.0;
        }

        $checkoutResolution = $this->resolveCheckoutLedger(
            $settingId,
            $sessionId,
            $terminalId,
            $cashierUserId,
            (int) $resolvedCustomerId,
            $normalizedIdempotencyKey,
            $payloadHash,
            $totals,
            $payment,
            $paidTotal,
            $changeTotal,
            $clientContext,
            $cartSnapshot
        );

        $replayPayload = $checkoutResolution['replay_payload'];
        if ($replayPayload !== null) {
            return [
                'http_status' => 200,
                'payload' => $replayPayload,
            ];
        }

        $checkout = $checkoutResolution['checkout'];
        if (! $checkout instanceof PosCheckout) {
            throw new PosCheckoutPostingException('POSTING_FAILURE', 'Checkout ledger could not be initialized.');
        }

        $responsePayload = $this->postCheckout(
            checkout: $checkout,
            settingId: $settingId,
            sessionId: $sessionId,
            cashierUserId: $cashierUserId,
            customerId: (int) $resolvedCustomerId,
            cartSnapshot: $cartSnapshot,
            payment: $payment,
            paidTotal: $paidTotal,
            changeTotal: $changeTotal,
            grandTotal: $grandTotal,
            clientContext: $clientContext
        );

        $this->cartSessionStore->clearCart($settingId, $sessionId);

        return [
            'http_status' => (bool) ($responsePayload['idempotent_replay'] ?? false) ? 200 : 201,
            'payload' => $responsePayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $cartSnapshot
     * @param  array{method_code:?string,payment_method_id:?int,amount_paid:float,reference:?string}  $payment
     * @return array{subtotal:float,discount_total:float,tax_total:float,grand_total:float}
     */
    private function validateCartAndPayment(
        array $cartSnapshot,
        array $payment,
        ?int $resolvedCustomerId
    ): array {
        $lines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
        if ($lines === []) {
            throw new PosCheckoutValidationException('CART_EMPTY', 'Cart must contain at least one line item.');
        }

        if (! $resolvedCustomerId) {
            throw new PosCheckoutValidationException('CUSTOMER_UNRESOLVED', 'Customer is not resolved for checkout.');
        }

        $settingId = (int) ($cartSnapshot['setting_id'] ?? 0);
        foreach ($lines as $line) {
            if ((bool) ($line['serial_number_required'] ?? false)) {
                $this->validateSerialAssignments($line, $settingId);
            }
        }

        $totals = [
            'subtotal' => round((float) ($cartSnapshot['totals']['subtotal'] ?? 0), 2),
            'discount_total' => round((float) ($cartSnapshot['totals']['discount_total'] ?? 0), 2),
            'tax_total' => round((float) ($cartSnapshot['totals']['tax_total'] ?? 0), 2),
            'grand_total' => round((float) ($cartSnapshot['totals']['grand_total'] ?? 0), 2),
        ];

        if ($totals['grand_total'] <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Grand total must be greater than zero.');
        }

        // Handle multi-payment validation
        if ((bool) ($payment['is_multi_payment'] ?? false)) {
            $amountPaid = $payment['amount_paid'];
            $totalCashMinor = (int) ($payment['total_cash_minor_units'] ?? 0);

            if ($amountPaid + 0.0001 < $totals['grand_total']) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Payment must be fully paid.');
            }

            return $totals;
        }

        // Legacy single-payment validation
        $amountPaid = $payment['amount_paid'];
        $isCash = $payment['is_cash'];
        $requiresReference = $payment['requires_reference'];

        if (! $isCash && $amountPaid + 0.0001 < $totals['grand_total']) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Payment must be fully paid.');
        }

        if (! $isCash) {
            if (abs($amountPaid - $totals['grand_total']) > 0.0001) {
                throw new PosCheckoutValidationException(
                    'PAYMENT_INVALID',
                    'Non-cash payments must match the grand total exactly.'
                );
            }
        }

        if ($requiresReference) {
            if ($payment['reference'] === null || $payment['reference'] === '') {
                throw new PosCheckoutValidationException(
                    'PAYMENT_INVALID',
                    'This payment method requires a reference number.'
                );
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $paymentPayload
     * @return array{payment_method_id:int,amount_paid:float,reference:?string,is_cash:bool,requires_reference:bool}
     */
    private function normalizePayment(int $settingId, array $paymentPayload): array
    {
        $paymentMethodId = isset($paymentPayload['payment_method_id']) ? (int) $paymentPayload['payment_method_id'] : null;
        $amountPaid = round((float) ($paymentPayload['amount_paid'] ?? 0), 2);
        $reference = isset($paymentPayload['reference']) ? trim((string) $paymentPayload['reference']) : null;
        $reference = $reference !== '' ? $reference : null;

        if ($amountPaid <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Payment amount must be greater than zero.');
        }

        if (! $paymentMethodId || $paymentMethodId <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Payment method is required.');
        }

        $paymentMethod = PaymentMethod::query()
            ->join('setting_pos_payment_methods', 'payment_methods.id', '=', 'setting_pos_payment_methods.payment_method_id')
            ->where('setting_pos_payment_methods.setting_id', $settingId)
            ->where('setting_pos_payment_methods.is_enabled', true)
            ->where('payment_methods.id', $paymentMethodId)
            ->select('payment_methods.*')
            ->first();

        if (! $paymentMethod) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Payment method not found or not enabled for this setting.');
        }

        return [
            'payment_method_id' => $paymentMethodId,
            'amount_paid' => $amountPaid,
            'reference' => $reference,
            'is_cash' => (bool) $paymentMethod->is_cash,
            'requires_reference' => (bool) $paymentMethod->requires_reference,
        ];
    }

    /**
     * Normalize multi-payment payload using the normalization service.
     *
     * @param  int  $settingId
     * @param  array<int, array{payment_method_id:int,amount_paid:float,reference?:string}>  $paymentsPayload
     * @return array{
     *     is_multi_payment: bool,
     *     payments: array<int, array{payment_method_id:int,amount_minor_units:int,reference:?string,is_cash:bool,requires_reference:bool}>,
     *     amount_paid: float,
     *     total_cash_minor_units: int,
     *     canonical_payment_hash: string
     * }
     */
    private function normalizeMultiPayment(int $settingId, array $paymentsPayload): array
    {
        if (! $this->paymentNormalizationService) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Multi-payment service not available.');
        }

        $normalized = $this->paymentNormalizationService->normalize($settingId, $paymentsPayload);

        return [
            'is_multi_payment' => true,
            'payments' => $normalized['payments'],
            'amount_paid' => round($normalized['total_amount_minor_units'] / 100, 2),
            'total_cash_minor_units' => $normalized['total_cash_minor_units'],
            'canonical_payment_hash' => $this->paymentNormalizationService->getCanonicalPaymentHash($normalized['payments']),
        ];
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * @param  array{subtotal:float,discount_total:float,tax_total:float,grand_total:float}  $totals
     * @param  array{method_code:string,amount_paid:float,reference:string|null}  $payment
     * @param  array<string, mixed>|null  $clientContext
     * @param  array<string, mixed>  $cartSnapshot
     * @return array{checkout:PosCheckout|null,replay_payload:array<string, mixed>|null}
     */
    private function resolveCheckoutLedger(
        int $settingId,
        int $sessionId,
        int $terminalId,
        int $cashierUserId,
        int $customerId,
        string $idempotencyKey,
        string $payloadHash,
        array $totals,
        array $payment,
        float $paidTotal,
        float $changeTotal,
        ?array $clientContext,
        array $cartSnapshot
    ): array {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $settingId,
                    $sessionId,
                    $terminalId,
                    $cashierUserId,
                    $customerId,
                    $idempotencyKey,
                    $payloadHash,
                    $totals,
                    $payment,
                    $paidTotal,
                    $changeTotal,
                    $clientContext,
                    $cartSnapshot
                ) {
                    $existing = PosCheckout::query()
                        ->where('setting_id', $settingId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return [
                            'checkout' => null,
                            'replay_payload' => $this->resolveExistingCheckout($existing, $payloadHash),
                        ];
                    }

                    $checkout = PosCheckout::query()->create([
                        'setting_id' => $settingId,
                        'pos_session_id' => $sessionId,
                        'terminal_id' => $terminalId,
                        'cashier_user_id' => $cashierUserId,
                        'customer_id' => $customerId,
                        'status' => PosCheckout::STATUS_FINALIZING,
                        'idempotency_key' => $idempotencyKey,
                        'payload_hash' => $payloadHash,
                        'subtotal' => $totals['subtotal'],
                        'discount_total' => $totals['discount_total'],
                        'tax_total' => $totals['tax_total'],
                        'grand_total' => $totals['grand_total'],
                        'paid_total' => $paidTotal,
                        'change_total' => $changeTotal,
                        'payment_method_id' => $payment['payment_method_id'],
                        'payment_reference' => $payment['reference'],
                        'metadata' => [
                            'client_context' => $clientContext,
                            'cart_meta' => $cartSnapshot['meta'] ?? null,
                        ],
                    ]);

                    return [
                        'checkout' => $checkout,
                        'replay_payload' => null,
                    ];
                });
            } catch (QueryException $exception) {
                if ($attempt === 0 && $this->isUniqueConstraintViolation($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new PosCheckoutPostingException('POSTING_FAILURE', 'Checkout ledger could not be initialized.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveExistingCheckout(PosCheckout $checkout, string $payloadHash): ?array
    {
        if ((string) $checkout->payload_hash !== $payloadHash) {
            throw new PosCheckoutConflictException(
                'IDEMPOTENCY_PAYLOAD_MISMATCH',
                'Idempotency key was already used with a different payload.'
            );
        }

        if ($checkout->status === PosCheckout::STATUS_POSTED) {
            return $this->buildReplayPayload($checkout);
        }

        if ($checkout->status === PosCheckout::STATUS_FINALIZING) {
            throw new PosCheckoutConflictException(
                'IDEMPOTENCY_IN_PROGRESS',
                'Checkout finalization is currently in progress for this idempotency key.'
            );
        }

        if ($checkout->status === PosCheckout::STATUS_FAILED) {
            throw new PosCheckoutConflictException(
                'IDEMPOTENCY_PREVIOUS_FAILED',
                'A previous attempt failed for this idempotency key. Use a new idempotency key.'
            );
        }

        throw new PosCheckoutConflictException(
            'IDEMPOTENCY_IN_PROGRESS',
            'Checkout finalization is currently in progress for this idempotency key.'
        );
    }

    /**
     * @param  array<string, mixed>  $cartSnapshot
     * @param  array{method_code:string,amount_paid:float,reference:string|null}  $payment
     * @param  array<string, mixed>|null  $clientContext
     * @return array{
     *     pos_checkout_id:int,
     *     status:string,
     *     receipt_number:string,
     *     sale_id:int,
     *     dispatch_ids:array<int, int>,
     *     sale_payment_id:int,
     *     paid_total:float,
     *     change_total:float,
     *     idempotent_replay:bool
     * }
     */
    private function postCheckout(
        PosCheckout $checkout,
        int $settingId,
        int $sessionId,
        int $cashierUserId,
        int $customerId,
        array $cartSnapshot,
        array $payment,
        float $paidTotal,
        float $changeTotal,
        float $grandTotal,
        ?array $clientContext
    ): array {
        $checkoutId = (int) $checkout->id;
        $idempotencyKey = (string) $checkout->idempotency_key;

        try {
            return DB::transaction(function () use (
                $checkout,
                $settingId,
                $sessionId,
                $cashierUserId,
                $customerId,
                $cartSnapshot,
                $payment,
                $paidTotal,
                $changeTotal,
                $grandTotal,
                $checkoutId,
                $clientContext
            ) {
                $session = PosSession::query()
                    ->where('id', $sessionId)
                    ->where('setting_id', $settingId)
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    throw new PosCheckoutValidationException('PAYMENT_INVALID', 'POS session was not found.');
                }

                if ($session->status !== PosSession::STATUS_OPEN) {
                    throw new PosCheckoutValidationException('PAYMENT_INVALID', 'POS session must be OPEN to finalize checkout.');
                }

                $terminal = PosTerminal::query()
                    ->with('policy')
                    ->where('id', $session->terminal_id)
                    ->where('setting_id', $settingId)
                    ->lockForUpdate()
                    ->first();

                $cartLines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
                $lines = $this->buildStockResolverLines($cartLines);
                $resolution = $this->stockResolver->resolve($settingId, $lines);
                if (! empty($resolution['unfulfilled_lines'])) {
                    $failureDetails = $this->buildStockUnavailableDetailsPayload($resolution, $lines);

                    throw new PosCheckoutValidationException(
                        'STOCK_UNAVAILABLE',
                        'One or more items in the cart are no longer available in stock across allowed locations.',
                        [
                            'unfulfilled_lines' => $failureDetails,
                        ]
                    );
                }

                /** @var PosCheckout|null $lockedCheckout */
                $lockedCheckout = PosCheckout::query()
                    ->whereKey($checkoutId)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedCheckout) {
                    throw new PosCheckoutPostingException('POSTING_FAILURE', 'Checkout ledger record was not found.');
                }

                if ($lockedCheckout->status === PosCheckout::STATUS_POSTED) {
                    return $this->buildReplayPayload($lockedCheckout);
                }

                if ($lockedCheckout->status !== PosCheckout::STATUS_FINALIZING) {
                    throw new PosCheckoutConflictException(
                        'IDEMPOTENCY_IN_PROGRESS',
                        'Checkout is already being finalized for this idempotency key.'
                    );
                }

                $postingResult = $this->postingAdapter->post([
                    'setting_id' => $settingId,
                    'checkout_id' => $checkoutId,
                    'pos_session_id' => $sessionId,
                    'terminal_id' => (int) $session->terminal_id,
                    'cashier_user_id' => $cashierUserId,
                    'customer_id' => $customerId,
                    'payment' => $payment,
                    'cart_snapshot' => $cartSnapshot,
                    'allocations' => $resolution['allocations'],
                ]);

                $dispatchIds = array_values(array_map(
                    static fn ($id): int => (int) $id,
                    is_array($postingResult['dispatch_ids'] ?? null) ? $postingResult['dispatch_ids'] : []
                ));
                $splitGroups = $this->normalizeSplitGroupsPayload($postingResult['split_groups'] ?? []);
                $sales = is_array($postingResult['sales'] ?? null)
                    ? $postingResult['sales']
                    : [];
                $salePayments = is_array($postingResult['sale_payments'] ?? null)
                    ? $postingResult['sale_payments']
                    : [];
                $splitSummary = is_array($postingResult['split_summary'] ?? null)
                    ? $postingResult['split_summary']
                    : ($splitGroups !== []
                        ? [
                            'group_count' => count($splitGroups),
                            'groups' => $splitGroups,
                        ]
                        : null);

                $actualTaxTotal = (float) ($postingResult['actual_tax_total'] ?? $lockedCheckout->tax_total);
                $actualGrandTotal = (float) ($postingResult['actual_grand_total'] ?? $lockedCheckout->grand_total);
                $actualChangeTotal = $payment['is_cash']
                    ? round(max(0, $paidTotal - $actualGrandTotal), 2)
                    : 0.0;

                $receiptNumber = $this->receiptNumberGenerator->generate($settingId);

                $responsePayload = [
                    'pos_checkout_id' => $checkoutId,
                    'status' => PosCheckout::STATUS_POSTED,
                    'receipt_number' => $receiptNumber,
                    'sale_id' => (int) ($postingResult['sale_id'] ?? 0),
                    'dispatch_ids' => $dispatchIds,
                    'sale_payment_id' => (int) ($postingResult['sale_payment_id'] ?? 0),
                    'paid_total' => $paidTotal,
                    'change_total' => $actualChangeTotal,
                    'idempotent_replay' => false,
                ];

                // For multi-payment, include payment composition for idempotent replay
                if ((bool) ($payment['is_multi_payment'] ?? false)) {
                    $responsePayload['payments'] = is_array($payment['payments'] ?? null)
                        ? array_map(static fn ($p) => [
                            'payment_method_id' => (int) $p['payment_method_id'],
                            'amount_minor_units' => (int) $p['amount_minor_units'],
                            'reference' => $p['reference'] ?? null,
                        ], $payment['payments'])
                        : [];
                }

                if ($splitGroups !== []) {
                    $responsePayload['split_groups'] = $splitGroups;
                    $responsePayload['sales'] = array_values($sales);
                    $responsePayload['sale_payments'] = array_values($salePayments);

                    $this->persistCheckoutSplitMappings($checkoutId, $splitGroups);
                    $this->persistPaymentAllocations($checkoutId, $payment, $splitGroups);
                }

                // Determine cash amount for the session
                $cashAmountForSession = 0.0;
                if ((bool) ($payment['is_multi_payment'] ?? false)) {
                    $totalCashMinor = (int) ($payment['total_cash_minor_units'] ?? 0);
                    $cashAmountForSession = min($totalCashMinor / 100, $actualGrandTotal);
                } elseif ($payment['is_cash']) {
                    $cashAmountForSession = $actualGrandTotal;
                }

                if ($cashAmountForSession > 0) {
                    $cashEvent = PosSessionCashEvent::query()->create([
                        'setting_id' => $settingId,
                        'pos_session_id' => $sessionId,
                        'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
                        'direction' => PosSessionCashEvent::DIRECTION_IN,
                        'amount' => $cashAmountForSession,
                        'reference_type' => 'pos_checkout',
                        'reference_id' => $checkoutId,
                        'performed_by' => $cashierUserId,
                        'approved_by' => null,
                        'notes' => 'POS checkout finalization',
                        'metadata' => [
                            'sale_id' => $responsePayload['sale_id'],
                            'sale_payment_id' => $responsePayload['sale_payment_id'],
                        ],
                        'occurred_at' => now(),
                    ]);

                    $session->expected_cash_total = round((float) $session->expected_cash_total + $cashAmountForSession, 2);
                    $session->save();

                    $this->cashDrawerService->triggerDrawerOpen(
                        PosCashDrawerService::TRIGGER_CASH_SALE,
                        (int) $session->terminal_id,
                        $settingId,
                        [
                            'pos_checkout_id' => $checkoutId,
                            'cash_event_id' => $cashEvent->id,
                        ],
                        $terminal
                    );
                }

                $lockedCheckout->status = PosCheckout::STATUS_POSTED;
                $lockedCheckout->receipt_number = $receiptNumber;
                $lockedCheckout->sale_id = $responsePayload['sale_id'];
                $lockedCheckout->sale_payment_id = $responsePayload['sale_payment_id'];
                $lockedCheckout->tax_total = $actualTaxTotal;
                $lockedCheckout->grand_total = $actualGrandTotal;
                $lockedCheckout->change_total = $actualChangeTotal;
                $lockedCheckout->dispatch_ids = $dispatchIds;
                $lockedCheckout->response_payload = $responsePayload;
                $lockedCheckout->split_summary = $splitSummary;
                $lockedCheckout->failure_code = null;
                $lockedCheckout->failure_message = null;
                $lockedCheckout->metadata = $this->mergeMetadata(
                    $lockedCheckout->metadata,
                    [
                        'client_context' => $clientContext,
                        'posted_at' => now()->toISOString(),
                        'split_group_count' => is_array($splitSummary['groups'] ?? null)
                            ? count($splitSummary['groups'])
                            : 0,
                    ]
                );
                $lockedCheckout->finalized_at = now();

                if ($this->transactionService) {
                    $completedTransaction = $this->transactionService->completeFromCartSnapshot(
                        $settingId,
                        $session,
                        $cashierUserId,
                        $cartSnapshot,
                        $lockedCheckout->id
                    );

                    $lockedCheckout->pos_transaction_id = (int) $completedTransaction->id;
                }

                $lockedCheckout->save();

                return $responsePayload;
            });
        } catch (PosCheckoutConflictException $exception) {
            throw $exception;
        } catch (PosCheckoutValidationException $exception) {
            $failureMetadata = [
                'setting_id' => $settingId,
                'session_id' => $sessionId,
                'idempotency_key' => $idempotencyKey,
            ];
            if ($exception->details() !== []) {
                $failureMetadata['details'] = $exception->details();
            }

            $this->markCheckoutFailed(
                checkoutId: $checkoutId,
                failureCode: $exception->errorCode(),
                failureMessage: $exception->getMessage(),
                metadata: $failureMetadata
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->markCheckoutFailed(
                checkoutId: $checkoutId,
                failureCode: 'POSTING_FAILURE',
                failureMessage: $exception->getMessage(),
                metadata: [
                    'setting_id' => $settingId,
                    'session_id' => $sessionId,
                    'idempotency_key' => $idempotencyKey,
                    'exception_class' => $exception::class,
                ]
            );

            Log::error('POS checkout finalization failed.', [
                'setting_id' => $settingId,
                'session_id' => $sessionId,
                'idempotency_key' => $idempotencyKey,
                'checkout_id' => $checkoutId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new PosCheckoutPostingException(
                'POSTING_FAILURE',
                'Checkout finalization failed due to an internal posting error.',
                $exception
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeMetadata(?array $metadata, array $extra): array
    {
        $base = is_array($metadata) ? $metadata : [];

        return array_merge($base, $extra);
    }

    /**
     * @param  mixed  $splitGroups
     * @return array<int, array{
     *     split_key:string,
     *     source_setting_id:int,
     *     source_location_id:int,
     *     tax_bucket:string,
     *     sale_id:int,
     *     sale_payment_id:int,
     *     dispatch_ids:array<int, int>,
     *     subtotal:float,
     *     tax_total:float,
     *     grand_total:float,
     *     paid_total:float
     * }>
     */
    private function normalizeSplitGroupsPayload(mixed $splitGroups): array
    {
        if (! is_array($splitGroups)) {
            return [];
        }

        $normalized = [];
        foreach ($splitGroups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $splitKey = trim((string) ($group['split_key'] ?? ''));
            if ($splitKey === '') {
                continue;
            }

            $normalized[] = [
                'split_key' => $splitKey,
                'source_setting_id' => (int) ($group['source_setting_id'] ?? 0),
                'source_location_id' => (int) ($group['source_location_id'] ?? 0),
                'tax_bucket' => (string) ($group['tax_bucket'] ?? 'NON_TAX'),
                'sale_id' => (int) ($group['sale_id'] ?? 0),
                'sale_payment_id' => (int) ($group['sale_payment_id'] ?? 0),
                'dispatch_ids' => array_values(array_map(
                    static fn ($id): int => (int) $id,
                    is_array($group['dispatch_ids'] ?? null) ? $group['dispatch_ids'] : []
                )),
                'subtotal' => round((float) ($group['subtotal'] ?? 0), 2),
                'tax_total' => round((float) ($group['tax_total'] ?? 0), 2),
                'grand_total' => round((float) ($group['grand_total'] ?? 0), 2),
                'paid_total' => round((float) ($group['paid_total'] ?? 0), 2),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => strcmp($left['split_key'], $right['split_key']));

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $splitGroups
     */
    private function persistCheckoutSplitMappings(int $checkoutId, array $splitGroups): void
    {
        foreach ($splitGroups as $group) {
            $splitKey = trim((string) ($group['split_key'] ?? ''));
            if ($splitKey === '') {
                continue;
            }

            PosCheckoutSale::query()->updateOrCreate(
                [
                    'pos_checkout_id' => $checkoutId,
                    'split_key' => $splitKey,
                ],
                [
                    'source_setting_id' => (int) ($group['source_setting_id'] ?? 0),
                    'source_location_id' => (int) ($group['source_location_id'] ?? 0),
                    'tax_bucket' => (string) ($group['tax_bucket'] ?? 'NON_TAX'),
                    'sale_id' => (int) ($group['sale_id'] ?? 0) ?: null,
                    'sale_payment_id' => (int) ($group['sale_payment_id'] ?? 0) ?: null,
                    'dispatch_ids' => array_values(array_map(
                        static fn ($id): int => (int) $id,
                        is_array($group['dispatch_ids'] ?? null) ? $group['dispatch_ids'] : []
                    )),
                    'subtotal' => round((float) ($group['subtotal'] ?? 0), 2),
                    'tax_total' => round((float) ($group['tax_total'] ?? 0), 2),
                    'grand_total' => round((float) ($group['grand_total'] ?? 0), 2),
                    'paid_total' => round((float) ($group['paid_total'] ?? 0), 2),
                ]
            );
        }
    }

    /**
     * Persist payment entries and their allocations to split groups for traceability.
     *
     * @param  int  $checkoutId
     * @param  array{is_multi_payment:bool,payments?:array,amount_paid:float}  $payment
     * @param  array<int, array>  $splitGroups
     * @return void
     */
    private function persistPaymentAllocations(int $checkoutId, array $payment, array $splitGroups): void
    {
        if (! (bool) ($payment['is_multi_payment'] ?? false)) {
            return; // Only persist for multi-payment
        }

        $payments = is_array($payment['payments'] ?? null) ? $payment['payments'] : [];
        if (empty($payments)) {
            return;
        }

        foreach ($payments as $paymentIndex => $paymentData) {
            $paymentMethodId = (int) ($paymentData['payment_method_id'] ?? 0);
            $amountMinor = (int) ($paymentData['amount_minor_units'] ?? 0);
            $reference = $paymentData['reference'] ?? null;

            // Create payment entry
            $paymentEntry = PosCheckoutPayment::query()->create([
                'pos_checkout_id' => $checkoutId,
                'payment_method_id' => $paymentMethodId,
                'amount_minor_units' => $amountMinor,
                'reference' => $reference,
                'sequence_order' => $paymentIndex,
            ]);

            // For each split group, track that we have this payment
            // (In a full implementation, we would track the exact allocation amount per payment per group)
            foreach ($splitGroups as $splitGroup) {
                $splitKey = (string) ($splitGroup['split_key'] ?? '');
                if ($splitKey !== '') {
                    // This is a simplified traceability entry
                    // A full implementation would use the ownership-priority allocation result
                    PosCheckoutPaymentAllocation::query()->create([
                        'pos_checkout_id' => $checkoutId,
                        'pos_checkout_payment_id' => $paymentEntry->id,
                        'split_group_key' => $splitKey,
                        'allocated_amount_minor_units' => 0, // Will be calculated from split group paid_total
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function markCheckoutFailed(
        int $checkoutId,
        string $failureCode,
        string $failureMessage,
        array $metadata
    ): void {
        DB::transaction(function () use ($checkoutId, $failureCode, $failureMessage, $metadata) {
            /** @var PosCheckout|null $checkout */
            $checkout = PosCheckout::query()
                ->whereKey($checkoutId)
                ->lockForUpdate()
                ->first();

            if (! $checkout || $checkout->status === PosCheckout::STATUS_POSTED) {
                return;
            }

            $checkout->status = PosCheckout::STATUS_FAILED;
            $checkout->failure_code = $failureCode;
            $checkout->failure_message = $failureMessage;
            $checkout->metadata = $this->mergeMetadata($checkout->metadata, [
                'failure' => $metadata,
                'failed_at' => now()->toISOString(),
            ]);
            $checkout->finalized_at = now();
            $checkout->save();
        });
    }

    private function buildReplayPayload(PosCheckout $checkout): array
    {
        $storedPayload = is_array($checkout->response_payload) ? $checkout->response_payload : [];

        $payload = $storedPayload !== []
            ? $storedPayload
            : [
                'pos_checkout_id' => (int) $checkout->id,
                'status' => PosCheckout::STATUS_POSTED,
                'receipt_number' => (string) ($checkout->receipt_number ?? ''),
                'sale_id' => (int) ($checkout->sale_id ?? 0),
                'dispatch_ids' => array_values(array_map(
                    static fn ($id): int => (int) $id,
                    is_array($checkout->dispatch_ids) ? $checkout->dispatch_ids : []
                )),
                'sale_payment_id' => (int) ($checkout->sale_payment_id ?? 0),
                'paid_total' => round((float) $checkout->paid_total, 2),
                'change_total' => round((float) $checkout->change_total, 2),
                'idempotent_replay' => false,
            ];

        $payload['pos_checkout_id'] = (int) ($payload['pos_checkout_id'] ?? $checkout->id);
        $payload['status'] = (string) ($payload['status'] ?? PosCheckout::STATUS_POSTED);
        $payload['receipt_number'] = (string) ($payload['receipt_number'] ?? '');
        $payload['sale_id'] = (int) ($payload['sale_id'] ?? 0);
        $payload['sale_payment_id'] = (int) ($payload['sale_payment_id'] ?? 0);
        $payload['dispatch_ids'] = array_values(array_map(
            static fn ($id): int => (int) $id,
            is_array($payload['dispatch_ids'] ?? null) ? $payload['dispatch_ids'] : []
        ));
        $payload['paid_total'] = round((float) ($payload['paid_total'] ?? 0), 2);
        $payload['change_total'] = round((float) ($payload['change_total'] ?? 0), 2);
        $payload['idempotent_replay'] = true;

        $splitGroups = $this->normalizeSplitGroupsPayload($payload['split_groups'] ?? []);
        if ($splitGroups === []) {
            $splitGroups = $this->normalizeSplitGroupsPayload($checkout->split_summary['groups'] ?? []);
        }

        if ($splitGroups !== []) {
            $payload['split_groups'] = $splitGroups;
            $payload['sales'] = is_array($payload['sales'] ?? null) && $payload['sales'] !== []
                ? array_values($payload['sales'])
                : $this->buildSalesPayloadFromSplitGroups($splitGroups);
            $payload['sale_payments'] = is_array($payload['sale_payments'] ?? null) && $payload['sale_payments'] !== []
                ? array_values($payload['sale_payments'])
                : $this->buildSalePaymentsPayloadFromSplitGroups($splitGroups);
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $splitGroups
     * @return array<int, array<string, mixed>>
     */
    private function buildSalesPayloadFromSplitGroups(array $splitGroups): array
    {
        $sales = [];

        foreach ($splitGroups as $group) {
            $sales[] = [
                'split_key' => (string) ($group['split_key'] ?? ''),
                'sale_id' => (int) ($group['sale_id'] ?? 0),
                'source_setting_id' => (int) ($group['source_setting_id'] ?? 0),
                'source_location_id' => (int) ($group['source_location_id'] ?? 0),
                'tax_bucket' => (string) ($group['tax_bucket'] ?? 'NON_TAX'),
                'subtotal' => round((float) ($group['subtotal'] ?? 0), 2),
                'tax_total' => round((float) ($group['tax_total'] ?? 0), 2),
                'grand_total' => round((float) ($group['grand_total'] ?? 0), 2),
            ];
        }

        return $sales;
    }

    /**
     * @param  array<int, array<string, mixed>>  $splitGroups
     * @return array<int, array<string, mixed>>
     */
    private function buildSalePaymentsPayloadFromSplitGroups(array $splitGroups): array
    {
        $payments = [];

        foreach ($splitGroups as $group) {
            $payments[] = [
                'split_key' => (string) ($group['split_key'] ?? ''),
                'sale_id' => (int) ($group['sale_id'] ?? 0),
                'sale_payment_id' => (int) ($group['sale_payment_id'] ?? 0),
                'amount' => round((float) ($group['paid_total'] ?? 0), 2),
            ];
        }

        return $payments;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{
     *     line_id:int|null,
     *     product_id:int,
     *     product_code:string|null,
     *     product_name:string|null,
     *     qty:int,
     *     tax_id:int|null,
     *     serial_number_required:bool,
     *     assigned_serials:array<int, string>
     * }>
     */
    private function buildStockResolverLines(array $lines): array
    {
        $resolverLines = [];

        foreach ($lines as $lineIndex => $line) {
            if (! is_array($line)) {
                continue;
            }

            $taxId = isset($line['tax_id']) ? (int) $line['tax_id'] : null;
            $taxId = $taxId !== null && $taxId > 0 ? $taxId : null;

            $assignedSerials = array_values(array_filter(
                (array) ($line['assigned_serials'] ?? []),
                static fn ($serial): bool => is_string($serial) && trim($serial) !== ''
            ));

            $resolverLines[$lineIndex] = [
                'line_id' => isset($line['line_id']) ? (int) $line['line_id'] : null,
                'product_id' => (int) ($line['product_id'] ?? 0),
                'product_code' => isset($line['product_code']) ? (string) $line['product_code'] : null,
                'product_name' => isset($line['product_name']) ? (string) $line['product_name'] : null,
                'qty' => max(0, (int) ($line['qty'] ?? 0)),
                'tax_id' => $taxId,
                'serial_number_required' => (bool) ($line['serial_number_required'] ?? false),
                'assigned_serials' => $assignedSerials,
            ];
        }

        return $resolverLines;
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{
     *     line_index:int,
     *     line_id:int|null,
     *     product_id:int,
     *     product_code:string|null,
     *     reason_code:string,
     *     requested_qty:int,
     *     allocated_qty:int
     * }>
     */
    private function buildStockUnavailableDetailsPayload(array $resolution, array $lines): array
    {
        $rawDetails = is_array($resolution['unfulfilled_details'] ?? null)
            ? $resolution['unfulfilled_details']
            : [];
        $detailByIndex = [];

        foreach ($rawDetails as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $lineIndex = (int) ($detail['line_index'] ?? -1);
            if ($lineIndex < 0) {
                continue;
            }

            $detailByIndex[$lineIndex] = $detail;
        }

        $payload = [];
        foreach ((array) ($resolution['unfulfilled_lines'] ?? []) as $lineIndex) {
            $lineIndex = (int) $lineIndex;
            if ($lineIndex < 0) {
                continue;
            }

            $line = is_array($lines[$lineIndex] ?? null) ? $lines[$lineIndex] : [];
            $detail = is_array($detailByIndex[$lineIndex] ?? null) ? $detailByIndex[$lineIndex] : [];

            $payload[] = [
                'line_index' => $lineIndex,
                'line_id' => isset($detail['line_id'])
                    ? (int) $detail['line_id']
                    : (isset($line['line_id']) ? (int) $line['line_id'] : null),
                'product_id' => isset($detail['product_id'])
                    ? (int) $detail['product_id']
                    : (int) ($line['product_id'] ?? 0),
                'product_code' => isset($detail['product_code'])
                    ? ($detail['product_code'] !== null ? (string) $detail['product_code'] : null)
                    : (isset($line['product_code']) ? (string) $line['product_code'] : null),
                'reason_code' => isset($detail['reason_code'])
                    ? (string) $detail['reason_code']
                    : 'INSUFFICIENT_STOCK',
                'requested_qty' => isset($detail['requested_qty'])
                    ? (int) $detail['requested_qty']
                    : (int) ($line['qty'] ?? 0),
                'allocated_qty' => isset($detail['allocated_qty'])
                    ? (int) $detail['allocated_qty']
                    : 0,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{method_code:string,amount_paid:float,reference:string|null}  $payment
     */
    private function payloadHash(
        int $settingId,
        int $sessionId,
        int $terminalId,
        int $cashierUserId,
        int $customerId,
        array $snapshot,
        array $payment
    ): string {
        $normalized = [
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'terminal_id' => $terminalId,
            'cashier_user_id' => $cashierUserId,
            'customer_id' => $customerId,
            'cart' => $this->normalizeSnapshotForHash([
                'lines' => $snapshot['lines'] ?? [],
                'totals' => $snapshot['totals'] ?? [],
                'bill_discount' => $snapshot['bill_discount'] ?? [],
            ]),
        ];

        // Include payment hash - either canonical multi-payment or legacy single payment
        if ((bool) ($payment['is_multi_payment'] ?? false)) {
            $normalized['payment'] = [
                'is_multi_payment' => true,
                'canonical_hash' => $payment['canonical_payment_hash'] ?? '',
            ];
        } else {
            $normalized['payment'] = [
                'is_multi_payment' => false,
                'method_code' => strtolower((string) ($payment['method_code'] ?? '')),
                'amount_paid' => round((float) ($payment['amount_paid'] ?? 0), 2),
                'reference' => $payment['reference'] ?? null,
            ];
        }

        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($normalized),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            )
        );
    }

    /**
     * @return mixed
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * Keep hash behavior aligned with API snapshots used in feature tests:
     * numbers that are whole values become integers after JSON roundtrip.
     *
     * @param  array<string, mixed>  $snapshotPart
     * @return array<string, mixed>
     */
    private function normalizeSnapshotForHash(array $snapshotPart): array
    {
        $encoded = json_encode($snapshotPart);
        if (! is_string($encoded)) {
            return $snapshotPart;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $snapshotPart;
    }

    private function validateSerialAssignments(array $line, int $settingId): void
    {
        $assigned = (array) ($line['assigned_serials'] ?? []);
        $qty = (int) ($line['qty'] ?? 0);
        $productId = (int) ($line['product_id'] ?? 0);
        $productName = (string) ($line['product_name'] ?? 'Product');

        if (count($assigned) !== $qty) {
            throw new PosCheckoutValidationException(
                'SERIAL_INVALID',
                "Product $productName requires $qty serial number(s) but " . count($assigned) . " assigned."
            );
        }

        foreach ($assigned as $sn) {
            $record = \Modules\Product\Entities\ProductSerialNumber::query()
                ->where('product_id', $productId)
                ->where('serial_number', $sn)
                ->first();

            if (! $record) {
                throw new PosCheckoutValidationException(
                    'SERIAL_INVALID',
                    "Serial number $sn for product $productName was not found."
                );
            }

            if (strtoupper($record->status) !== 'ACTIVE' || $record->dispatch_detail_id !== null) {
                throw new PosCheckoutValidationException(
                    'SERIAL_INVALID',
                    "Serial number $sn for product $productName is no longer available."
                );
            }
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }


}

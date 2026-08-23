<?php

namespace Modules\Pos\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\SequenceNamespace;
use App\Models\User;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Services\PosCartActionAuthorizationService;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutPayment;
use Modules\Pos\Entities\PosCheckoutPaymentAllocation;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\PosCashDrawerService;
use Modules\Pos\Services\PosTemporaryPaymentImageService;
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
        private readonly PosCartMutationLock $cartMutationLock,
        private readonly ?PosTransactionService $transactionService = null,
        private readonly ?PosCheckoutPaymentNormalizationService $paymentNormalizationService = null,
        private readonly ?PosCartActionAuthorizationService $authorizationService = null
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
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Konteks checkout tidak valid.');
        }

        // Hold the cart mutation lock across the whole authoritative span:
        // snapshot capture, posting, and clear. Guarding only the final clear
        // would leave the read-to-clear window open, and a compare-and-set on
        // the clear is not sufficient either — preserving a mutated cart can
        // retain lines that were already posted, risking a duplicate sale.
        // Concurrent cart mutation therefore receives CART_BUSY rather than
        // modifying the cart being posted.
        return $this->cartMutationLock->withLock(
            $settingId,
            (int) $activeSession->id,
            fn (): array => $this->finalizeWithinLock(
                $settingId,
                $activeSession,
                $cashierUserId,
                $idempotencyKey,
                $paymentPayload,
                $clientContext
            )
        );
    }

    /**
     * @param  array<string, mixed>  $paymentPayload
     * @param  array<string, mixed>|null  $clientContext
     * @return array<string, mixed>
     */
    private function finalizeWithinLock(
        int $settingId,
        PosSession $activeSession,
        int $cashierUserId,
        string $idempotencyKey,
        array $paymentPayload,
        ?array $clientContext = null
    ): array {
        $sessionId = (int) $activeSession->id;
        $checkoutTerminal = $this->resolveCheckoutTerminal($settingId, $activeSession);
        $terminalId = (int) $checkoutTerminal->id;

        if ($sessionId <= 0 || $terminalId <= 0) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Konteks sesi POS yang aktif tidak valid.');
        }

        $normalizedIdempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        if ($normalizedIdempotencyKey === '') {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Kunci idempotency diperlukan.');
        }

        // Get cart snapshot early (immutable state before any operations)
        $cartSnapshot = $this->cartService->getSnapshot($settingId, $sessionId);

        $cartToken = (string) ($cartSnapshot['staged_payment_token'] ?? '');

        // Check for existing checkout early to handle idempotent replays
        // This must happen BEFORE any mutable operations (normalization, authorization, etc)
        $existingCheckout = PosCheckout::query()
            ->where('setting_id', $settingId)
            ->where('idempotency_key', $normalizedIdempotencyKey)
            ->first();

        $isIdempotentReplay = $existingCheckout !== null;

        // Non-posted attempts are never replayable. Preserve the established
        // lifecycle error instead of masking it with a payload comparison.
        if ($existingCheckout && $existingCheckout->status === PosCheckout::STATUS_FINALIZING) {
            throw new PosCheckoutConflictException(
                'IDEMPOTENCY_IN_PROGRESS',
                'Checkout finalization is currently in progress for this idempotency key.'
            );
        }

        if ($existingCheckout && $existingCheckout->status === PosCheckout::STATUS_FAILED) {
            throw new PosCheckoutConflictException(
                'IDEMPOTENCY_PREVIOUS_FAILED',
                'A previous attempt failed for this idempotency key. Use a new idempotency key.'
            );
        }

        // For confirmed replays, verify the payload matches before any mutable operations.
        // This protects against disabled payment methods, expired approvals, changed configuration, etc.
        if ($existingCheckout && $existingCheckout->status === PosCheckout::STATUS_POSTED) {
            // Extract immutable request fields to compute the replay fingerprint
            $isMultiPayment = isset($paymentPayload['payments']) && is_array($paymentPayload['payments']);
            $isDebt = (bool) ($paymentPayload['is_debt'] ?? false);
            $paymentTermId = isset($paymentPayload['payment_term_id']) ? (int) $paymentPayload['payment_term_id'] : null;

            // Build a minimal payment object for fingerprint computation (no validation/normalization)
            if ($isMultiPayment) {
                // For multi-payment, extract raw payment data and compute canonical hash
                $paymentsRaw = $paymentPayload['payments'] ?? [];
                $payment = $this->buildRawMultiPaymentForReplayFingerprint($paymentsRaw);
            } else {
                // For single-payment, extract raw payment data
                $payment = $this->buildRawSinglePaymentForReplayFingerprint(
                    $paymentPayload['payment'] ?? $paymentPayload,
                    $isDebt
                );
            }

            // Use the stored original cart snapshot from the initial checkout, not the current cart
            // This ensures the fingerprint matches the original checkout regardless of current cart state
            if (! empty($cartSnapshot['lines'])) {
                // Compare using current cart and current customer.
                $originalCartSnapshot = $cartSnapshot;
            } else {
                // Cleared cart: compare using stored snapshot and stored customer.
                $originalCartSnapshot = $existingCheckout->original_cart_snapshot;
                if (! is_array($originalCartSnapshot)) {
                    throw new PosCheckoutConflictException(
                        'IDEMPOTENCY_PAYLOAD_MISMATCH',
                        'Idempotency key was already used with a different payload.'
                    );
                }
            }

            // Extract customer from stored snapshot for idempotent replay verification
            $originalCustomerId = (int) ($originalCartSnapshot['customer']['resolved_customer_id'] ?? 0);
            $originalCustomerId = $originalCustomerId > 0 ? $originalCustomerId : null;

            // Compute the fingerprint using the exact same method as original checkout
            $replayFingerprint = $this->computeReplayFingerprint(
                $settingId,
                $sessionId,
                $terminalId,
                $cashierUserId,
                $originalCustomerId,
                $originalCartSnapshot,
                $payment,
                $isDebt,
                $paymentTermId
            );

            if ((string) $existingCheckout->payload_hash !== $replayFingerprint) {
                throw new PosCheckoutConflictException(
                    'IDEMPOTENCY_PAYLOAD_MISMATCH',
                    'Idempotency key was already used with a different payload.'
                );
            }

            return [
                'http_status' => 200,
                'payload' => $this->buildReplayPayload($existingCheckout),
            ];
        }

        // Extract customer from current cart for new checkout or in-progress replay
        $resolvedCustomerId = (int) ($cartSnapshot['customer']['resolved_customer_id'] ?? 0);
        $resolvedCustomerId = $resolvedCustomerId > 0 ? $resolvedCustomerId : null;

        // Not a confirmed replay - proceed with normal checkout flow
        // Now we can safely run mutable operations (validation, authorization, etc)
        $acknowledgeLifecycleWarning = (bool) ($paymentPayload['acknowledge_lifecycle_warning'] ?? false);

        // Always evaluate lifecycle warnings, regardless of acknowledgement
        $cartLines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
        $evaluator = app(\Modules\Product\Services\BundleLifecycle\ProductBundleLifecycleEvaluator::class);
        $evalResult = $evaluator->evaluatePosCartSnapshot($cartLines, $settingId);

        // Only block if warnings exist and not acknowledged
        if ($evalResult->hasWarnings() && ! $acknowledgeLifecycleWarning) {
            throw new PosCheckoutValidationException(
                'BUNDLE_LIFECYCLE_WARNING',
                'Terdapat perubahan status pada paket produk dalam transaksi ini.',
                [
                    'warning' => [
                        'code' => 'BUNDLE_LIFECYCLE_WARNING',
                        'message' => 'Terdapat perubahan status pada paket produk dalam transaksi ini. Lanjutkan untuk tetap menggunakan komposisi yang tersimpan.',
                        'items' => $evalResult->warnings,
                    ],
                ]
            );
        }

        // Determine if legacy single-payment or multi-payment path
        $isMultiPayment = isset($paymentPayload['payments']) && is_array($paymentPayload['payments']);

        $isDebt = (bool) ($paymentPayload['is_debt'] ?? false);
        $paymentTermId = isset($paymentPayload['payment_term_id']) ? (int) $paymentPayload['payment_term_id'] : null;
        $approvalToken = $paymentPayload['approval_token'] ?? null;

        if ($isMultiPayment) {
            $payment = $this->normalizeMultiPayment($settingId, $paymentPayload['payments']);
        } else {
            $payment = $this->normalizePayment($settingId, $paymentPayload['payment'] ?? $paymentPayload, $isDebt);
        }

        if ($isDebt) {
            if ($paymentTermId === null || $paymentTermId <= 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Syarat pembayaran (Term) harus dipilih untuk utang.');
            }
            $term = \Modules\Purchase\Entities\PaymentTerm::find($paymentTermId);
            if (! $term || ! $term->is_active) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Syarat pembayaran (Term) yang dipilih tidak aktif.');
            }
            if (!$this->authorizationService) {
                throw new PosCheckoutValidationException('APPROVAL_REQUIRED', 'Layanan otorisasi tidak tersedia, tidak dapat memproses utang.');
            }
            $user = User::find($cashierUserId);
            if (!$user) {
                throw new PosCheckoutValidationException('APPROVAL_REQUIRED', 'Kasir tidak valid, otorisasi ditolak.');
            }
            $authResult = $this->authorizationService->authorize(
                $user,
                PosActionApprovalRequest::ACTION_CHECKOUT_AS_DEBT,
                $approvalToken
            );
            if (!$authResult['authorized']) {
                throw new PosCheckoutValidationException('APPROVAL_REQUIRED', 'Otorisasi diperlukan untuk checkout sebagai utang.');
            }
        }

        $customerResolutionSource = (string) ($cartSnapshot['customer']['resolution_source'] ?? 'none');

        if ($resolvedCustomerId !== null && $resolvedCustomerId > 0) {
            $customer = \Modules\People\Entities\Customer::find($resolvedCustomerId);
            if (! $customer || ! $customer->is_active) {
                throw new PosCheckoutValidationException('CUSTOMER_INVALID', 'Pelanggan yang dipilih tidak aktif.');
            }
        }

        if ($isDebt && ($resolvedCustomerId === null || $resolvedCustomerId <= 0 || $customerResolutionSource !== 'selected')) {
            throw new PosCheckoutValidationException('CUSTOMER_REQUIRED', 'Pelanggan harus dipilih untuk checkout sebagai utang.');
        }

        foreach ($cartLines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId > 0) {
                $product = \Modules\Product\Entities\Product::find($productId);
                if (! $product || ! $product->is_active) {
                    $prodName = $line['product_name'] ?? ('Produk #' . $productId);
                    throw new PosCheckoutValidationException('PRODUCT_INACTIVE', "Produk '{$prodName}' tidak aktif dan tidak dapat ditransaksikan.");
                }
            }
        }

        $totals = $this->validateCartAndPayment(
            $cartSnapshot,
            $payment,
            $resolvedCustomerId,
            $isDebt,
            $settingId,
            $sessionId,
            $cashierUserId,
            $cartToken,
            $isIdempotentReplay
        );

        // Normalize presentation snapshot back to captured state
        $capturedCartSnapshot = $this->normalizeSnapshotToCaptured($cartSnapshot);

        // Build operational snapshot from captured snapshot with current product classifications
        $operationalSnapshot = $this->buildOperationalSnapshot($capturedCartSnapshot);

        // Early fulfillability gates: validate before checkout ledger creation
        $this->validateCartFulfillability($settingId, $operationalSnapshot);

        // Compute full payload hash from captured snapshot for storage
        $payloadHash = $this->payloadHash(
            $settingId,
            $sessionId,
            $terminalId,
            $cashierUserId,
            (int) $resolvedCustomerId,
            $capturedCartSnapshot,
            $payment,
            $isDebt,
            $paymentTermId
        );

        $paidTotal = $payment['amount_paid'];
        $grandTotal = $totals['grand_total'];

        // Calculate change: if any cash component is present, change is the total overpayment.
        $hasCash = (bool) ($payment['is_multi_payment'] ?? false)
            ? (int) ($payment['total_cash_minor_units'] ?? 0) > 0
            : (bool) ($payment['is_cash'] ?? false);

        $changeTotal = $hasCash
            ? round(max(0.0, $paidTotal - $grandTotal), 2)
            : 0.0;

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
            $capturedCartSnapshot,
            $isDebt,
            $paymentTermId
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
            terminalId: $terminalId,
            cashierUserId: $cashierUserId,
            customerId: (int) $resolvedCustomerId,
            cartSnapshot: $capturedCartSnapshot,
            payment: $payment,
            paidTotal: $paidTotal,
            changeTotal: $changeTotal,
            grandTotal: $grandTotal,
            clientContext: $clientContext
        );

        // The cart mutation lock is held for this whole method (see finalize()),
        // so no writer can have changed the cart since the authoritative
        // snapshot was captured. Clearing unconditionally is therefore safe and
        // removes exactly what was posted.
        $this->cartSessionStore->clearCart($settingId, $sessionId);

        return [
            'http_status' => (bool) ($responsePayload['idempotent_replay'] ?? false) ? 200 : 201,
            'payload' => $responsePayload,
        ];
    }

    /**
     * Perform preflight validation to ensure cart is fulfillable before entering payment flow.
     *
     * @param  int  $settingId
     * @param  PosSession  $activeSession
     * @return array{status:string}
     */
    public function preflight(int $settingId, PosSession $activeSession, bool $acknowledgeLifecycleWarning = false): array
    {
        $sessionId = (int) $activeSession->id;
        $cartSnapshot = $this->cartService->getSnapshot($settingId, $sessionId);

        // Always evaluate lifecycle warnings, regardless of acknowledgement
        $cartLines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
        $evaluator = app(\Modules\Product\Services\BundleLifecycle\ProductBundleLifecycleEvaluator::class);
        $evalResult = $evaluator->evaluatePosCartSnapshot($cartLines, $settingId);

        // Only block if warnings exist and not acknowledged
        if ($evalResult->hasWarnings() && ! $acknowledgeLifecycleWarning) {
            throw new PosCheckoutValidationException(
                'BUNDLE_LIFECYCLE_WARNING',
                'Terdapat perubahan status pada paket produk dalam transaksi ini.',
                [
                    'warning' => [
                        'code' => 'BUNDLE_LIFECYCLE_WARNING',
                        'message' => 'Terdapat perubahan status pada paket produk dalam transaksi ini. Lanjutkan untuk tetap menggunakan komposisi yang tersimpan.',
                        'items' => $evalResult->warnings,
                    ],
                ]
            );
        }

        $operationalSnapshot = $this->buildOperationalSnapshot($cartSnapshot);
        $this->validateCartFulfillability($settingId, $operationalSnapshot);

        return ['status' => 'OK'];
    }

    /**
     * Derive an operational snapshot from the captured snapshot by overlaying only
     * live stock_managed and serial_number_required classifications from current products.
     * All captured commercial values, product IDs, quantities, prices, taxes, discounts,
     * and bundle compositions remain unchanged.
     *
     * @param  array<string, mixed>  $capturedSnapshot
     * @return array<string, mixed>
     */
    public function buildOperationalSnapshot(array $capturedSnapshot): array
    {
        $cartLines = is_array($capturedSnapshot['lines'] ?? null) ? $capturedSnapshot['lines'] : [];
        if ($cartLines === []) {
            return $capturedSnapshot;
        }

        // Batch-load all involved products (parents and components)
        $productIds = [];
        foreach ($cartLines as $line) {
            if (! empty($line['product_id'])) {
                $productIds[] = (int) $line['product_id'];
            }
            if (! empty($line['bundle_items']) && is_array($line['bundle_items'])) {
                foreach ($line['bundle_items'] as $item) {
                    if (! empty($item['product_id'])) {
                        $productIds[] = (int) $item['product_id'];
                    }
                }
            }
        }

        $productsById = ! empty($productIds)
            ? \Modules\Product\Entities\Product::whereIn('id', array_unique($productIds))->get(['id', 'stock_managed', 'serial_number_required'])->keyBy('id')
            : collect();

        $operationalLines = [];
        foreach ($cartLines as $key => $line) {
            if (! is_array($line)) {
                $operationalLines[$key] = $line;
                continue;
            }

            $opLine = $line;
            $parentProduct = $productsById->get((int) ($line['product_id'] ?? 0));
            if ($parentProduct) {
                $opLine['stock_managed'] = (bool) $parentProduct->stock_managed;
                $opLine['serial_number_required'] = (bool) $parentProduct->serial_number_required;
            }

            if (! empty($line['bundle_items']) && is_array($line['bundle_items'])) {
                $opBundleItems = [];
                foreach ($line['bundle_items'] as $bKey => $item) {
                    if (! is_array($item)) {
                        $opBundleItems[$bKey] = $item;
                        continue;
                    }
                    $opItem = $item;
                    $compProduct = $productsById->get((int) ($item['product_id'] ?? 0));
                    if ($compProduct) {
                        $opItem['stock_managed'] = (bool) $compProduct->stock_managed;
                        $opItem['serial_number_required'] = (bool) $compProduct->serial_number_required;
                    }
                    $opBundleItems[$bKey] = $opItem;
                }
                $opLine['bundle_items'] = $opBundleItems;
            }

            $operationalLines[$key] = $opLine;
        }

        $operationalSnapshot = $capturedSnapshot;
        $operationalSnapshot['lines'] = $operationalLines;

        return $operationalSnapshot;
    }

    /**
     * Normalize a presentation/cart snapshot back to its captured classifications.
     * Restores captured_stock_managed and captured_serial_number_required to the primary fields
     * and strips presentation-only captured_* aliases before persisting original_cart_snapshot or computing payloadHash.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function normalizeSnapshotToCaptured(array $snapshot): array
    {
        $cartLines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        if ($cartLines === []) {
            return $snapshot;
        }

        $capturedLines = [];
        foreach ($cartLines as $key => $line) {
            if (! is_array($line)) {
                $capturedLines[$key] = $line;
                continue;
            }

            $capLine = $line;
            if (array_key_exists('captured_stock_managed', $capLine)) {
                $capLine['stock_managed'] = (bool) $capLine['captured_stock_managed'];
                unset($capLine['captured_stock_managed']);
            }
            if (array_key_exists('captured_serial_number_required', $capLine)) {
                $capLine['serial_number_required'] = (bool) $capLine['captured_serial_number_required'];
                unset($capLine['captured_serial_number_required']);
            }

            if (! empty($line['bundle_items']) && is_array($line['bundle_items'])) {
                $capBundleItems = [];
                foreach ($line['bundle_items'] as $bKey => $item) {
                    if (! is_array($item)) {
                        $capBundleItems[$bKey] = $item;
                        continue;
                    }
                    $capItem = $item;
                    if (array_key_exists('captured_stock_managed', $capItem)) {
                        $capItem['stock_managed'] = (bool) $capItem['captured_stock_managed'];
                        unset($capItem['captured_stock_managed']);
                    }
                    if (array_key_exists('captured_serial_number_required', $capItem)) {
                        $capItem['serial_number_required'] = (bool) $capItem['captured_serial_number_required'];
                        unset($capItem['captured_serial_number_required']);
                    }
                    $capBundleItems[$bKey] = $capItem;
                }
                $capLine['bundle_items'] = $capBundleItems;
            }

            $capturedLines[$key] = $capLine;
        }

        $capturedSnapshot = $snapshot;
        $capturedSnapshot['lines'] = $capturedLines;

        return $capturedSnapshot;
    }

    /**
     * Early policy gate: block checkout when any bundle component requires serial assignment.
     *
     * This runs BEFORE the checkout ledger record is created, so a policy block
     * does not leave behind a STATUS_FAILED checkout row.  Stock-level checks
     * remain inside postCheckout where failures are properly recorded.
     *
     * @param  array<string, mixed>  $cartSnapshot  The operational (live-overlaid) snapshot.
     * @throws PosCheckoutValidationException
     */
    /**
     * Authoritatively validate that the cart can be fulfilled (serial numbers assigned and stock available).
     *
     * @param  int  $settingId
     * @param  array<string, mixed>  $cartSnapshot
     * @return array<string, mixed> The stock resolution payload (allocations, unfulfilled info).
     * @throws PosCheckoutValidationException
     */
    public function validateCartFulfillability(int $settingId, array $cartSnapshot): array
    {
        $cartLines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
        if ($cartLines === []) {
            throw new PosCheckoutValidationException('CART_EMPTY', 'Keranjang harus berisi setidaknya satu item baris.');
        }

        foreach ($cartLines as $lineIndex => $line) {
            $isParentSerialRequired = (bool) ($line['serial_number_required'] ?? false);

            if ($isParentSerialRequired) {
                $this->validateSerialAssignments($line, $settingId);
            }

            // Validate serial assignments for bundle components
            $bundleItems = is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : [];
            $bundleItemSerials = is_array($line['bundle_item_serials'] ?? null) ? $line['bundle_item_serials'] : [];
            $parentQty = max(0, (int) ($line['qty'] ?? 0));

            foreach ($bundleItems as $itemIndex => $item) {
                $compStockManaged = (bool) ($item['stock_managed'] ?? true);
                $compSerialRequired = (bool) ($item['serial_number_required'] ?? false);

                if ($compStockManaged && $compSerialRequired) {
                    $bundleItemId = (int) ($item['bundle_item_id'] ?? 0);
                    $compSerials = array_values(array_filter(
                        (array) ($bundleItemSerials[$bundleItemId] ?? ($item['assigned_serials'] ?? [])),
                        static fn ($serial): bool => is_string($serial) && trim($serial) !== ''
                    ));
                    $compQtyPerBundle = (float) ($item['quantity_per_bundle'] ?? ($item['quantity'] ?? 1));
                    $requiredCompQty = (int) round($parentQty * $compQtyPerBundle);
                    $compName = $item['product_name'] ?? ('Produk #' . ($item['product_id'] ?? ''));

                    if (count($compSerials) !== $requiredCompQty) {
                        throw new PosCheckoutValidationException(
                            'SERIAL_INVALID',
                            "Komponen '{$compName}' membutuhkan {$requiredCompQty} nomor seri, tetapi " . count($compSerials) . ' yang diberikan.',
                            [
                                'component_serial_required' => [
                                    'line_index' => $lineIndex,
                                    'bundle_item_id' => $bundleItemId,
                                    'product_id' => (int) ($item['product_id'] ?? 0),
                                    'product_name' => $compName,
                                    'required_qty' => $requiredCompQty,
                                    'assigned_count' => count($compSerials),
                                ],
                            ]
                        );
                    }
                }
            }
        }

        $linesForResolver = $this->buildStockResolverLines($cartLines);
        $resolution = $this->stockResolver->resolve($settingId, $linesForResolver);

        if (! empty($resolution['unfulfilled_lines'])) {
            $failureDetails = $this->buildStockUnavailableDetailsPayload($resolution, $cartLines);

            throw new PosCheckoutValidationException(
                'STOCK_UNAVAILABLE',
                'One or more items in the cart are no longer available in stock across allowed locations.',
                [
                    'unfulfilled_lines' => $failureDetails,
                ]
            );
        }

        return $resolution;
    }

    /**
     * @param  array<string, mixed>  $cartSnapshot
     * @param  array{method_code:?string,payment_method_id:?int,amount_paid:float,reference:?string}  $payment
     * @return array{subtotal:float,discount_total:float,tax_total:float,grand_total:float}
     */
    private function validateCartAndPayment(
        array $cartSnapshot,
        array $payment,
        ?int $resolvedCustomerId,
        bool $isDebt = false,
        int $settingId = 0,
        int $sessionId = 0,
        int $cashierUserId = 0,
        string $cartToken = '',
        bool $isIdempotentReplay = false
    ): array {
        $lines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];
        if ($lines === []) {
            throw new PosCheckoutValidationException('CART_EMPTY', 'Keranjang harus berisi setidaknya satu item baris.');
        }

        if (! $resolvedCustomerId) {
            throw new PosCheckoutValidationException('CUSTOMER_UNRESOLVED', 'Pelanggan belum ditentukan untuk checkout.');
        }

        $settingId = (int) ($cartSnapshot['setting_id'] ?? 0);

        // Individual serial validatiton is now part of validateCartFulfillability, 
        // but we keep it here for legacy checkout flow consistency if needed.
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
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Total akhir harus lebih besar dari nol.');
        }

        // Handle multi-payment validation
        if ((bool) ($payment['is_multi_payment'] ?? false)) {
            $amountPaid = $payment['amount_paid'];
            $totalCashMinor = (int) ($payment['total_cash_minor_units'] ?? 0);

            if ($isDebt) {
                if ($amountPaid >= $totals['grand_total']) {
                    throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Uang muka (Down Payment) utang harus kurang dari total akhir.');
                }
            } else {
                if ($amountPaid + 0.0001 < $totals['grand_total']) {
                    throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Pembayaran harus dibayar sepenuhnya.');
                }
            }

            // Validate image tokens for multi-payment (only for new checkouts, not idempotent replays)
            if (!$isIdempotentReplay && $settingId > 0 && $sessionId > 0 && $cartToken !== '') {
                $imageTokens = $payment['image_tokens'] ?? [];
                if (!empty($imageTokens)) {
                    $imageService = app(PosTemporaryPaymentImageService::class);
                    foreach ($imageTokens as $imageToken) {
                        $image = $imageService->resolveImage(
                            $imageToken,
                            $settingId,
                            $sessionId,
                            $cashierUserId,
                            $cartToken
                        );
                        if (!$image) {
                            throw new PosCheckoutValidationException(
                                'PAYMENT_INVALID',
                                'Gambar pembayaran tidak ditemukan atau telah kadaluarsa.'
                            );
                        }
                    }
                }
            }

            return $totals;
        }

        // Legacy single-payment validation
        $amountPaid = $payment['amount_paid'];
        $isCash = $payment['is_cash'];
        $requiresReference = $payment['requires_reference'];

        if ($isDebt) {
            if ($amountPaid >= $totals['grand_total']) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Uang muka (Down Payment) utang harus kurang dari total akhir.');
            }
        } else {
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
        }

        if ($requiresReference) {
            if ($payment['reference'] === null || $payment['reference'] === '') {
                throw new PosCheckoutValidationException(
                    'PAYMENT_INVALID',
                    'This payment method requires a reference number.'
                );
            }
        }

        // Only validate image tokens for new checkouts, not idempotent replays.
        // Idempotent replays may have consumed the image in a previous request.
        $paymentImageToken = $payment['payment_image_token'] ?? null;
        if (!$isIdempotentReplay && $paymentImageToken !== null && $settingId > 0 && $sessionId > 0 && $cartToken !== '') {
            $imageService = app(PosTemporaryPaymentImageService::class);
            $image = $imageService->resolveImage(
                $paymentImageToken,
                $settingId,
                $sessionId,
                $cashierUserId,
                $cartToken
            );
            if (!$image) {
                throw new PosCheckoutValidationException(
                    'PAYMENT_INVALID',
                    'Gambar pembayaran tidak ditemukan atau telah kadaluarsa.'
                );
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $paymentPayload
     * @return array{payment_method_id:int,amount_paid:float,reference:?string,payment_image_token:?string,is_cash:bool,requires_reference:bool}
     */
    /**
     * Build a minimal payment structure from raw request data for replay fingerprint computation.
     * Does NOT validate or normalize - just extracts and structures fields.
     * Used only during replay fingerprint verification before mutable operations.
     *
     * @param  array{payment_method_id?:int,amount_paid?:float,reference?:string,payment_image_token?:string}  $paymentPayload
     * @param  bool  $isDebt
     * @return array{payment_method_id:int,amount_paid:float,reference:?string,payment_image_token:?string,is_multi_payment:false}
     */
    private function buildRawSinglePaymentForReplayFingerprint(array $paymentPayload, bool $isDebt = false): array
    {
        return [
            'is_multi_payment' => false,
            'payment_method_id' => isset($paymentPayload['payment_method_id']) ? (int) $paymentPayload['payment_method_id'] : 0,
            'amount_paid' => round((float) ($paymentPayload['amount_paid'] ?? 0), 2),
            'reference' => isset($paymentPayload['reference']) ? trim((string) $paymentPayload['reference']) ?: null : null,
            'payment_image_token' => isset($paymentPayload['payment_image_token']) ? trim((string) $paymentPayload['payment_image_token']) ?: null : null,
        ];
    }

    /**
     * Build a minimal multi-payment structure from raw request data for replay fingerprint computation.
     * Computes canonical payment hash without validation or configuration checks.
     * Used only during replay fingerprint verification before mutable operations.
     *
     * @param  array<int, array{payment_method_id:int,amount_paid:float,reference?:string,edc_reference?:string,stage_order?:int,payment_image_token?:string}>  $paymentsRaw
     * @return array{is_multi_payment:true,payments:array,amount_paid:float,canonical_payment_hash:string}
     */
    private function buildRawMultiPaymentForReplayFingerprint(array $paymentsRaw): array
    {
        $payments = [];
        $totalAmount = 0;

        foreach ($paymentsRaw as $paymentData) {
            $methodId = (int) ($paymentData['payment_method_id'] ?? 0);
            $amountMinor = round((float) ($paymentData['amount_paid'] ?? 0) * 100);
            $reference = isset($paymentData['reference']) ? trim((string) $paymentData['reference']) ?: null : null;
            $edcReference = isset($paymentData['edc_reference']) ? trim((string) $paymentData['edc_reference']) ?: null : null;
            $stageOrder = isset($paymentData['stage_order']) ? (int) $paymentData['stage_order'] : null;
            $imageToken = isset($paymentData['payment_image_token']) ? trim((string) $paymentData['payment_image_token']) ?: null : null;

            $totalAmount += $amountMinor;

            $payments[] = [
                'payment_method_id' => $methodId,
                'amount_minor_units' => (int) $amountMinor,
                'reference' => $reference,
                'edc_reference' => $edcReference,
                'stage_order' => $stageOrder,
                'payment_image_token' => $imageToken,
            ];
        }

        // Compute canonical hash using exact same method as PosCheckoutPaymentNormalizationService
        // Order: method_id, amount, reference, edc_reference, stage_order, image_token
        $canonicalPayments = [];
        foreach ($payments as $p) {
            $canonicalPayments[] = [
                (int) $p['payment_method_id'],
                (int) $p['amount_minor_units'],
                $p['reference'] ?? '',
                $p['edc_reference'] ?? '',
                $p['stage_order'] ?? 0,
                $p['payment_image_token'] ?? '',
            ];
        }

        // Sort for deterministic ordering (matching production normalizer)
        usort($canonicalPayments, function (array $a, array $b) {
            if ($a[0] !== $b[0]) return $a[0] <=> $b[0];  // method_id
            if ($a[1] !== $b[1]) return $a[1] <=> $b[1];  // amount
            if ($a[2] !== $b[2]) return strcmp($a[2], $b[2]);  // reference
            if ($a[3] !== $b[3]) return strcmp($a[3], $b[3]);  // edc_reference
            if ($a[4] !== $b[4]) return $a[4] <=> $b[4];  // stage_order
            return strcmp($a[5], $b[5]);  // image_token
        });

        // Use same JSON encoding flags for consistency with production normalizer
        $canonicalHash = hash('sha256', json_encode(
            $canonicalPayments,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));

        return [
            'is_multi_payment' => true,
            'payments' => $payments,
            'amount_paid' => round($totalAmount / 100, 2),
            'canonical_payment_hash' => $canonicalHash,
        ];
    }

    private function normalizePayment(int $settingId, array $paymentPayload, bool $isDebt = false): array
    {
        $paymentMethodId = isset($paymentPayload['payment_method_id']) ? (int) $paymentPayload['payment_method_id'] : null;
        $amountPaid = round((float) ($paymentPayload['amount_paid'] ?? 0), 2);
        $reference = isset($paymentPayload['reference']) ? trim((string) $paymentPayload['reference']) : null;
        $reference = $reference !== '' ? $reference : null;
        $paymentImageToken = isset($paymentPayload['payment_image_token']) ? trim((string) $paymentPayload['payment_image_token']) : null;
        $paymentImageToken = $paymentImageToken !== '' ? $paymentImageToken : null;

        if ($amountPaid <= 0 && !$isDebt) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Jumlah pembayaran harus lebih besar dari nol.');
        }

        if ($amountPaid > 0 || !$isDebt) {
            if (! $paymentMethodId || $paymentMethodId <= 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Metode pembayaran diperlukan.');
            }

            $paymentMethod = PaymentMethod::query()
                ->active()
                ->join('setting_pos_payment_methods', 'payment_methods.id', '=', 'setting_pos_payment_methods.payment_method_id')
                ->where('setting_pos_payment_methods.setting_id', $settingId)
                ->where('setting_pos_payment_methods.is_enabled', true)
                ->where('payment_methods.id', $paymentMethodId)
                ->select('payment_methods.*')
                ->first();

            if (! $paymentMethod) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Metode pembayaran tidak ditemukan atau tidak diaktifkan untuk pengaturan ini.');
            }

            if ((bool) ($paymentMethod->is_cash ?? false) && $paymentImageToken !== null) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Metode pembayaran tunai tidak boleh menyertakan gambar pembayaran.');
            }
        } else {
            $paymentMethod = null;
        }

        return [
            'payment_method_id' => $paymentMethodId,
            'amount_paid' => $amountPaid,
            'reference' => $reference,
            'payment_image_token' => $paymentImageToken,
            'is_cash' => (bool) ($paymentMethod?->is_cash ?? false),
            'requires_reference' => (bool) ($paymentMethod?->requires_reference ?? false),
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
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Layanan pembayaran multi tidak tersedia.');
        }

        $normalized = $this->paymentNormalizationService->normalize($settingId, $paymentsPayload);

        // Extract first payment method and reference for ledger persistence.
        // Multi-payment checkouts must have a primary payment method on the pos_checkouts record
        // for compatibility with the posting adapter and reporting queries.
        $firstPayment = $normalized['payments'][0] ?? null;

        return [
            'is_multi_payment' => true,
            'payments' => $normalized['payments'],
            'amount_paid' => round($normalized['total_amount_minor_units'] / 100, 2),
            'total_cash_minor_units' => $normalized['total_cash_minor_units'],
            'canonical_payment_hash' => $this->paymentNormalizationService->getCanonicalPaymentHash($normalized['payments']),
            'payment_method_id' => $firstPayment['payment_method_id'] ?? null,
            'reference' => $firstPayment['reference'] ?? null,
            'is_cash' => $firstPayment['is_cash'] ?? false,
            'image_tokens' => array_filter(array_map(fn($p) => $p['payment_image_token'] ?? null, $normalized['payments'])),
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
        array $cartSnapshot,
        bool $isDebt = false,
        ?int $paymentTermId = null
    ): array {
        $resolutionCallback = function () use (
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
            $cartSnapshot,
            $isDebt,
            $paymentTermId
        ): array {
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

            $checkoutMetadata = [
                'client_context' => $clientContext,
                'cart_meta' => $cartSnapshot['meta'] ?? null,
                'is_debt' => $isDebt,
                'payment_term_id' => $paymentTermId,
            ];

            // Persist image token for single-payment fingerprint verification
            if (isset($payment['payment_image_token'])) {
                $checkoutMetadata['payment_image_token'] = $payment['payment_image_token'];
            }

            // Persist multi-payment composition and image tokens for fingerprint verification
            if ((bool) ($payment['is_multi_payment'] ?? false)) {
                $checkoutMetadata['canonical_payment_hash'] = $payment['canonical_payment_hash'] ?? null;
                if (isset($payment['image_tokens']) && !empty($payment['image_tokens'])) {
                    $checkoutMetadata['image_tokens'] = array_values($payment['image_tokens']);
                }
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
                'original_cart_snapshot' => [
                    'lines' => $cartSnapshot['lines'] ?? [],
                    'totals' => $cartSnapshot['totals'] ?? [],
                    'bill_discount' => $cartSnapshot['bill_discount'] ?? [],
                    'note' => $cartSnapshot['note'] ?? null,
                    'customer' => $cartSnapshot['customer'] ?? [],
                ],
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'paid_total' => $paidTotal,
                'change_total' => $changeTotal,
                'payment_method_id' => $payment['payment_method_id'],
                'payment_reference' => $payment['reference'],
                'note' => $cartSnapshot['note'] ?? null,
                'metadata' => $checkoutMetadata,
            ]);

            return [
                'checkout' => $checkout,
                'replay_payload' => null,
            ];
        };

        return $this->executeLedgerResolutionWithConflictRetry($resolutionCallback, [
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'terminal_id' => $terminalId,
        ]);
    }

    /**
     * Runs a checkout-ledger resolution callback inside a transaction, retrying the
     * complete transaction (not just a failed insert) on a bounded budget for MySQL
     * deadlocks (1213 / SQLSTATE 40001) and, separately, the exact checkout
     * idempotency-key unique race. Any other exception propagates immediately.
     *
     * If a caller already owns an active transaction, this resolution cannot safely
     * own retry: opening a nested transaction/savepoint here would let a deadlock
     * "succeed" locally only to be rolled back later by the real outer owner.
     * The callback then executes exactly once and any conflict propagates to
     * whichever caller owns the outermost transaction.
     *
     * @param  Closure(): array<string, mixed>  $resolutionCallback
     * @param  array<string, mixed>  $logContext
     * @return array<string, mixed>
     */
    public function executeLedgerResolutionWithConflictRetry(\Closure $resolutionCallback, array $logContext = []): array
    {
        if (DB::transactionLevel() > 0) {
            return $resolutionCallback();
        }

        $maxDeadlockAttempts = 3;
        $maxUniqueRaceAttempts = 2;
        $deadlockAttempt = 0;
        $uniqueRaceAttempt = 0;

        while (true) {
            try {
                return DB::transaction($resolutionCallback);
            } catch (QueryException $exception) {
                $isDeadlock = $this->isDeadlockConflict($exception);
                $isUniqueRace = !$isDeadlock && $this->isCheckoutIdempotencyUniqueConflict($exception);

                if (!$isDeadlock && !$isUniqueRace) {
                    throw $exception;
                }

                if ($isDeadlock) {
                    $deadlockAttempt++;
                } else {
                    $uniqueRaceAttempt++;
                }

                $budgetExhausted = $isDeadlock
                    ? $deadlockAttempt >= $maxDeadlockAttempts
                    : $uniqueRaceAttempt >= $maxUniqueRaceAttempts;

                $logPayload = array_merge($logContext, [
                    'deadlock_attempt' => $deadlockAttempt,
                    'unique_race_attempt' => $uniqueRaceAttempt,
                    'is_deadlock' => $isDeadlock,
                    'is_unique_race' => $isUniqueRace,
                ]);

                if ($budgetExhausted) {
                    Log::error('pos_checkout.ledger_resolution_terminal_conflict', $logPayload);

                    throw $exception;
                }

                Log::warning('pos_checkout.ledger_resolution_conflict_retrying', $logPayload);

                if ($isDeadlock) {
                    usleep(random_int(10000, 50000)); // 10ms - 50ms jitter
                }
            }
        }
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
        int $terminalId,
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
        $sequenceNamespaces = [];
        $sequenceAllocator = app(DocumentSequenceAllocator::class);

        // executeWholeOperationWithConflictRetry() owns the outermost transaction and
        // deadlock/collision retry ONLY when no transaction is already active; when nested
        // (DocumentSequenceAllocator::executeOnceWithinExistingTransaction()) it runs the
        // posting closure with NO transaction/savepoint boundary of its own — by design, so
        // a nested caller keeps full control of its own atomicity.
        //
        // No real production caller invokes finalize()/postCheckout() nested inside an
        // existing transaction today (verified: the only entry point is
        // PosSellController::checkoutFinalize(), with no transaction-wrapping middleware).
        // The nested case is exercised only by RefreshDatabase-based tests, which wrap the
        // whole HTTP call in their own transaction.
        //
        // To keep that nested case from silently leaving partial Sale/SaleDetails/payment/
        // dispatch/sequence writes behind (which a bare, un-boundaried closure would), this
        // deliberately opens a nested SAVEPOINT here via DB::transaction() when
        // DB::transactionLevel() > 0. This is a conscious deviation from "never open a
        // savepoint" for the sake of RefreshDatabase test compatibility, not a fully
        // general nested-caller contract:
        //   - The savepoint DOES roll back this posting attempt's own writes on exception,
        //     independent of when/whether the outer transaction itself later resolves.
        //   - It does NOT give markCheckoutFailed()'s FAILED-state write independent
        //     durability: markCheckoutFailed() also runs via DB::transaction(), which is
        //     itself just another savepoint at this nesting level, not a real commit. If the
        //     outer (caller-owned) transaction is later rolled back instead of committed,
        //     the FAILED status written here is rolled back along with it.
        //   - Nested POS finalization is therefore supported on a best-effort basis: safe
        //     for what RefreshDatabase test wrapping requires (no partial posting artifacts
        //     visible while the outer transaction is still open, e.g. mid-test assertions),
        //     but a genuine future nested business caller wanting independently durable
        //     FAILED-state persistence would need to persist that itself after its own
        //     outer transaction commits — this method does not provide that guarantee.
        // The outermost (real production) path is completely unaffected: this closure is a
        // no-op there and executeWholeOperationWithConflictRetry() keeps sole ownership of
        // the transaction and its deadlock/collision retry loop.
        $postingBoundary = DB::transactionLevel() > 0
            ? static fn (\Closure $callback) => DB::transaction($callback)
            : static fn (\Closure $callback) => $callback();

        try {
            return $postingBoundary(fn () => $sequenceAllocator->executeWholeOperationWithConflictRetry(function () use (
                $checkout,
                $settingId,
                $sessionId,
                $terminalId,
                $cashierUserId,
                $customerId,
                $cartSnapshot,
                $payment,
                $paidTotal,
                $changeTotal,
                $grandTotal,
                $checkoutId,
                $clientContext,
                &$sequenceNamespaces
            ) {
                $sequenceNamespaces = [];
                $session = PosSession::query()
                    ->where('id', $sessionId)
                    ->where('setting_id', $settingId)
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Sesi POS tidak ditemukan.');
                }

                if ($session->status !== PosSession::STATUS_OPEN) {
                    throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Sesi POS harus DIBUKA untuk menyelesaikan checkout.');
                }

                $terminal = PosTerminal::query()
                    ->with('policy')
                    ->where('id', $terminalId)
                    ->where('setting_id', $settingId)
                    ->lockForUpdate()
                    ->first();

                if (! $terminal) {
                    throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Terminal checkout POS tidak ditemukan.');
                }

                $operationalSnapshot = $this->buildOperationalSnapshot($cartSnapshot);
                $resolution = $this->validateCartFulfillability($settingId, $operationalSnapshot);

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

                $posTransactionCode = null;
                if ($this->transactionService) {
                    $posTransactionCode = $this->transactionService->resolveCodeFromCartSnapshot($settingId, $cartSnapshot);
                }

                $isDebt = (bool) ($lockedCheckout->metadata['is_debt'] ?? false);
                $paymentTermId = $lockedCheckout->metadata['payment_term_id'] ?? null;
                $checkoutDate = now()->toDateString();
                $dueDate = $checkoutDate;

                if ($isDebt && $paymentTermId) {
                    $term = \Modules\Purchase\Entities\PaymentTerm::query()->find($paymentTermId);
                    if ($term) {
                        $dueDate = now()->addDays((int) $term->longevity)->toDateString();
                    }
                }

                $postingResult = $this->postingAdapter->post([
                    'setting_id' => $settingId,
                    'checkout_id' => $checkoutId,
                    'pos_session_id' => $sessionId,
                    'terminal_id' => $terminalId,
                    'cashier_user_id' => $cashierUserId,
                    'customer_id' => $customerId,
                    'payment' => $payment,
                    'cart_snapshot' => $operationalSnapshot,
                    'allocations' => $resolution['allocations'],
                    'is_debt' => $isDebt,
                    'payment_term_id' => $paymentTermId,
                    'checkout_date' => $checkoutDate,
                    'due_date' => $dueDate,
                    'pos_transaction_code' => $posTransactionCode,
                    'sequence_namespace_collector' => static function (array $namespaces) use (&$sequenceNamespaces): void {
                        foreach ($namespaces as $namespace) {
                            if ($namespace instanceof SequenceNamespace) {
                                $sequenceNamespaces[$namespace->canonicalKey()] = $namespace;
                            }
                        }
                    },
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

                // Calculate actual change: if any cash component is present, change is the total overpayment.
                $hasCash = (bool) ($payment['is_multi_payment'] ?? false)
                    ? (int) ($payment['total_cash_minor_units'] ?? 0) > 0
                    : (bool) ($payment['is_cash'] ?? false);

                $actualChangeTotal = $hasCash
                    ? round(max(0.0, $paidTotal - $actualGrandTotal), 2)
                    : 0.0;

                $receiptNumber = $this->receiptNumberGenerator->generate($settingId);

                $salePaymentId = (int) ($postingResult['sale_payment_id'] ?? 0);

                $responsePayload = [
                    'pos_checkout_id' => $checkoutId,
                    'status' => PosCheckout::STATUS_POSTED,
                    'receipt_number' => $receiptNumber,
                    'sale_id' => (int) ($postingResult['sale_id'] ?? 0),
                    'dispatch_ids' => $dispatchIds,
                    'sale_payment_id' => $salePaymentId > 0 ? $salePaymentId : null,
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
                }

                $hppWarnings = is_array($postingResult['hpp_warnings'] ?? null) ? $postingResult['hpp_warnings'] : [];
                if ($hppWarnings !== []) {
                    $responsePayload['hpp_warnings'] = $hppWarnings;
                }

                if ((bool) ($payment['is_multi_payment'] ?? false)) {
                    $this->persistPaymentAllocations($checkoutId, $payment, $splitGroups);
                }

                // Determine cash amount for the session
                $cashAmountForSession = 0.0;
                if ((bool) ($payment['is_multi_payment'] ?? false)) {
                    $totalCashMinor = (int) ($payment['total_cash_minor_units'] ?? 0);
                    $cashAmountForSession = $totalCashMinor / 100;
                } elseif ($payment['is_cash']) {
                    $cashAmountForSession = $paidTotal;
                }

                if ($cashAmountForSession > 0) {
                    // Record cash sale inflow event for session tracking
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

                    // Track change given to customer as outflow event to maintain accurate expected cash total.
                    // When a customer pays with cash and receives change, the expected_cash_total must reflect
                    // what should physically be in the drawer: opening_float + cash_sales - change_given - safe_drops
                    if ($actualChangeTotal > 0) {
                        PosSessionCashEvent::query()->create([
                            'setting_id' => $settingId,
                            'pos_session_id' => $sessionId,
                            'event_type' => PosSessionCashEvent::EVENT_CHANGE_OUT,
                            'direction' => PosSessionCashEvent::DIRECTION_OUT,
                            'amount' => $actualChangeTotal,
                            'reference_type' => 'pos_checkout',
                            'reference_id' => $checkoutId,
                            'performed_by' => $cashierUserId,
                            'approved_by' => null,
                            'notes' => 'Change given to customer',
                            'metadata' => [
                                'sale_id' => $responsePayload['sale_id'],
                                'sale_payment_id' => $responsePayload['sale_payment_id'],
                            ],
                            'occurred_at' => now(),
                        ]);

                        $session->expected_cash_total = round((float) $session->expected_cash_total - $actualChangeTotal, 2);
                    }

                    $session->save();

                    DB::afterCommit(function () use ($terminalId, $settingId, $checkoutId, $cashEvent, $terminal) {
                        $this->cashDrawerService->triggerDrawerOpen(
                            PosCashDrawerService::TRIGGER_CASH_SALE,
                            $terminalId,
                            $settingId,
                            [
                                'pos_checkout_id' => $checkoutId,
                                'cash_event_id' => $cashEvent->id,
                            ],
                            $terminal
                        );
                    });
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
                        $lockedCheckout->id,
                        $resolution['allocations'],
                        $posTransactionCode
                    );

                    $lockedCheckout->pos_transaction_id = (int) $completedTransaction->id;
                }

                $lockedCheckout->save();

                return $responsePayload;
            }, static function () use (&$sequenceNamespaces): array {
                return array_values($sequenceNamespaces);
            }, 3));
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
        } catch (PosCheckoutPostingException $exception) {
            $this->markCheckoutFailed(
                checkoutId: $checkoutId,
                failureCode: $exception->errorCode(),
                failureMessage: $exception->getMessage(),
                metadata: [
                    'setting_id' => $settingId,
                    'session_id' => $sessionId,
                    'idempotency_key' => $idempotencyKey,
                    'exception_class' => $exception::class,
                ]
            );

            Log::error('POS checkout posting failed.', [
                'setting_id' => $settingId,
                'session_id' => $sessionId,
                'idempotency_key' => $idempotencyKey,
                'checkout_id' => $checkoutId,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            $failureMetadata = [
                'setting_id' => $settingId,
                'session_id' => $sessionId,
                'idempotency_key' => $idempotencyKey,
                'exception_class' => $exception::class,
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ];

            try {
                $this->markCheckoutFailed(
                    checkoutId: $checkoutId,
                    failureCode: 'POSTING_FAILURE',
                    failureMessage: $exception->getMessage(),
                    metadata: $failureMetadata
                );
            } catch (Throwable $markFailedEx) {
                Log::error('Failed to mark checkout as failed.', [
                    'checkout_id' => $checkoutId,
                    'marking_exception' => $markFailedEx::class,
                    'marking_message' => $markFailedEx->getMessage(),
                    'marking_file' => $markFailedEx->getFile(),
                    'marking_line' => $markFailedEx->getLine(),
                    'original_exception' => $exception::class,
                    'original_message' => $exception->getMessage(),
                    'original_file' => $exception->getFile(),
                    'original_line' => $exception->getLine(),
                ]);
            }

            Log::error('POS checkout finalization failed.', [
                'setting_id' => $settingId,
                'session_id' => $sessionId,
                'idempotency_key' => $idempotencyKey,
                'checkout_id' => $checkoutId,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw new PosCheckoutPostingException(
                'POSTING_FAILURE',
                'Checkout finalization failed due to an internal posting error.',
                $exception
            );
        }
    }

    private function resolveCheckoutTerminal(int $settingId, PosSession $activeSession): PosTerminal
    {
        $terminalId = (int) $activeSession->terminal_id;

        if ($terminalId > 0) {
            $terminal = PosTerminal::query()
                ->with('policy')
                ->where('id', $terminalId)
                ->where('setting_id', $settingId)
                ->first();

            if (! $terminal) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Terminal POS tidak ditemukan untuk sesi aktif.');
            }

            return $terminal;
        }

        $terminal = PosTerminal::query()->firstOrCreate(
            [
                'setting_id' => $settingId,
                'code' => 'MGR-INTERVENTION',
            ],
            [
                'name' => 'Manager Intervention',
                'is_active' => true,
                'metadata' => [
                    'system_terminal' => true,
                    'manager_intervention' => true,
                ],
            ]
        );

        $terminal->policy()->firstOrCreate([], [
            'require_session_open' => true,
            'require_opening_float' => false,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => null,
            'auto_open_drawer_on_session_open' => false,
            'auto_open_drawer_on_cash_sale' => false,
            'auto_open_drawer_on_pickup' => false,
            'auto_open_drawer_on_close' => false,
            'require_pickup_supervisor_approval' => false,
            'metadata' => [
                'system_terminal' => true,
                'manager_intervention' => true,
            ],
        ]);

        return $terminal->fresh(['policy']) ?? $terminal;
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
            $currentMetadata = is_array($checkout->metadata) ? $checkout->metadata : [];
            $checkout->metadata = array_merge($currentMetadata, [
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

            $isParentStockManaged = (bool) ($line['stock_managed'] ?? true);
            $isParentSerialRequired = (bool) ($line['serial_number_required'] ?? false);

            $taxId = isset($line['tax_id']) ? (int) $line['tax_id'] : null;
            $taxId = $taxId !== null && $taxId > 0 ? $taxId : null;

            $assignedSerials = array_values(array_filter(
                (array) ($line['assigned_serials'] ?? []),
                static fn ($serial): bool => is_string($serial) && trim($serial) !== ''
            ));

            $qty = max(0, (int) ($line['qty'] ?? 0));

            // Only add parent to resolver if stock is managed
            if ($isParentStockManaged) {
                $resolverLines["{$lineIndex}_P"] = [
                    'line_id' => isset($line['line_id']) ? (int) $line['line_id'] : null,
                    'product_id' => (int) ($line['product_id'] ?? 0),
                    'product_code' => isset($line['product_code']) ? (string) $line['product_code'] : null,
                    'product_name' => isset($line['product_name']) ? (string) $line['product_name'] : null,
                    'qty' => $qty,
                    'tax_id' => $taxId,
                    'serial_number_required' => $isParentSerialRequired,
                    'assigned_serials' => $assignedSerials,
                ];
            }

            // Add bundle items if present and stock managed
            $bundleItems = is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : [];
            $bundleItemSerials = is_array($line['bundle_item_serials'] ?? null) ? $line['bundle_item_serials'] : [];
            foreach ($bundleItems as $itemIndex => $item) {
                $isCompStockManaged = (bool) ($item['stock_managed'] ?? false);
                $isCompSerialRequired = (bool) ($item['serial_number_required'] ?? false);

                if ($isCompStockManaged) {
                    $bundleItemId = (int) ($item['bundle_item_id'] ?? 0);
                    $compAssignedSerials = array_values(array_filter(
                        (array) ($bundleItemSerials[$bundleItemId] ?? ($item['assigned_serials'] ?? [])),
                        static fn ($serial): bool => is_string($serial) && trim($serial) !== ''
                    ));

                    $compQtyPerBundle = (float) ($item['quantity_per_bundle'] ?? ($item['quantity'] ?? 1));
                    $compNeededQty = (int) round($qty * $compQtyPerBundle);

                    $resolverLines["{$lineIndex}_C_{$itemIndex}"] = [
                        'line_id' => isset($line['line_id']) ? (int) $line['line_id'] : null, // Grouped by parent line_id
                        'product_id' => (int) ($item['product_id'] ?? 0),
                        'product_code' => null, // Name/code not strictly needed for resolver but good for logs
                        'product_name' => isset($item['product_name']) ? (string) $item['product_name'] : null,
                        'qty' => $compNeededQty,
                        'tax_id' => null, // Resolver resolves child's own tax configuration per source setting
                        'serial_number_required' => $isCompSerialRequired,
                        'assigned_serials' => $compAssignedSerials,
                    ];
                }
            }
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

            // Use detail's product info if available (from resolver), otherwise fall back to line
            $productName = isset($detail['product_name'])
                ? ($detail['product_name'] !== null ? (string) $detail['product_name'] : null)
                : (isset($line['product_name']) ? (string) $line['product_name'] : null);

            $productCode = isset($detail['product_code'])
                ? ($detail['product_code'] !== null ? (string) $detail['product_code'] : null)
                : (isset($line['product_code']) ? (string) $line['product_code'] : null);

            $payload[] = [
                'line_index' => $lineIndex,
                'line_id' => isset($detail['line_id'])
                    ? (int) $detail['line_id']
                    : (isset($line['line_id']) ? (int) $line['line_id'] : null),
                'product_id' => isset($detail['product_id'])
                    ? (int) $detail['product_id']
                    : (int) ($line['product_id'] ?? 0),
                'product_name' => $productName,
                'product_code' => $productCode,
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
     * Compute the canonical replay fingerprint from request data (before any mutations).
     * This is the same computation as payloadHash() but extracted so it can be called
     * early on replay, before any mutable authorization/validation.
     *
     * @param  int  $settingId
     * @param  int  $sessionId
     * @param  int  $terminalId
     * @param  int  $cashierUserId
     * @param  int|null  $customerId
     * @param  array<string, mixed>  $cartSnapshot
     * @param  array<string, mixed>  $payment
     * @param  bool  $isDebt
     * @param  int|null  $paymentTermId
     * @return string SHA256 hex digest
     */
    private function computeReplayFingerprint(
        int $settingId,
        int $sessionId,
        int $terminalId,
        int $cashierUserId,
        ?int $customerId,
        array $cartSnapshot,
        array $payment,
        bool $isDebt = false,
        ?int $paymentTermId = null
    ): string {
        $normalized = [
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'terminal_id' => $terminalId,
            'cashier_user_id' => $cashierUserId,
            'customer_id' => $customerId,
            'is_debt' => $isDebt,
            'payment_term_id' => $paymentTermId,
            'cart' => $this->normalizeSnapshotForHash([
                'lines' => $cartSnapshot['lines'] ?? [],
                'totals' => $cartSnapshot['totals'] ?? [],
                'bill_discount' => $cartSnapshot['bill_discount'] ?? [],
                'note' => $cartSnapshot['note'] ?? null,
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
                'payment_method_id' => (int) ($payment['payment_method_id'] ?? 0),
                'amount_paid' => round((float) ($payment['amount_paid'] ?? 0), 2),
                'reference' => $payment['reference'] ?? null,
                'payment_image_token' => $payment['payment_image_token'] ?? null,
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
     * @param  array<string, mixed>  $snapshot
     * @param  array{method_code:string,amount_paid:float,reference:string|null}  $payment
     */
    private function payloadHash(
        int $settingId,
        int $sessionId,
        int $terminalId,
        int $cashierUserId,
        ?int $customerId,
        array $snapshot,
        array $payment,
        bool $isDebt = false,
        ?int $paymentTermId = null
    ): string {
        // Use the same canonical replay fingerprint computation
        // This ensures original checkout hash and replay verification use identical logic
        return $this->computeReplayFingerprint(
            $settingId,
            $sessionId,
            $terminalId,
            $cashierUserId,
            $customerId,
            $snapshot,
            $payment,
            $isDebt,
            $paymentTermId
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
                "Product $productName requires $qty serial number(s) but " . count($assigned) . " assigned.",
                [
                    'invalid_lines' => [
                        [
                            'product_id' => $productId,
                            'product_name' => $productName,
                            'message' => "Membutuhkan $qty serial, hanya " . count($assigned) . " ditetapkan.",
                        ]
                    ]
                ]
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
                    "Serial number $sn for product $productName was not found.",
                    [
                        'invalid_lines' => [
                            [
                                'product_id' => $productId,
                                'product_name' => $productName,
                                'message' => "Nomor seri $sn tidak ditemukan.",
                            ]
                        ]
                    ]
                );
            }

            if (strtoupper($record->status) !== 'ACTIVE' || $record->dispatch_detail_id !== null) {
                throw new PosCheckoutValidationException(
                    'SERIAL_INVALID',
                    "Serial number $sn for product $productName is no longer available.",
                    [
                        'invalid_lines' => [
                            [
                                'product_id' => $productId,
                                'product_name' => $productName,
                                'message' => "Nomor seri $sn sudah tidak tersedia (Status: {$record->status}).",
                            ]
                        ]
                    ]
                );
            }
        }
    }

    /**
     * Determines whether a QueryException represents a MySQL deadlock (1213) or
     * serialization failure (SQLSTATE 40001). These are transient and safe to retry
     * by rerunning the entire failed transaction from scratch.
     */
    public function isDeadlockConflict(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '40001' || $driverCode === 1213;
    }

    /**
     * Determines whether a QueryException represents exactly the checkout idempotency
     * unique-key race on `pos_checkouts (setting_id, idempotency_key)` — the
     * `pos_checkouts_setting_idempotency_unique` index (MySQL) — never any other
     * PosCheckout unique constraint or unrelated integrity violation.
     */
    public function isCheckoutIdempotencyUniqueConflict(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        $isMysqlUniqueViolation = $sqlState === '23000' && $driverCode === 1062;
        if ($isMysqlUniqueViolation && str_contains($message, 'pos_checkouts_setting_idempotency_unique')) {
            return true;
        }

        $isSqliteUniqueViolation = $driverCode === 19;
        if ($isSqliteUniqueViolation
            && str_contains($message, 'unique constraint failed')
            && str_contains($message, 'pos_checkouts.setting_id')
            && str_contains($message, 'pos_checkouts.idempotency_key')
        ) {
            return true;
        }

        return false;
    }
}

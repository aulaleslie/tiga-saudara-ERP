<?php

namespace App\Livewire\Purchase;

use App\Services\EffectiveDocumentBusinessResolver;
use App\Services\IdempotencyService;
use App\Services\DocumentReferenceService;
use Carbon\Carbon;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Purchase\Services\PurchaseNormalizer;
use Modules\Purchase\Services\PurchaseTaxInclusionResolver;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Throwable;

class CreateForm extends Component
{
    public $reference;
    public $supplier_id;
    public $supplier_purchase_number;
    public $tax_ref_no;
    public $date;
    public $due_date;
    public $payment_term;
    public $note;
    public array $tags = [];
    public $duplicatePurchase = null;
    public $shipping = 0;
    public $global_discount = 0;
    public $is_tax_included = false;
    public string $idempotencyToken;
    public bool $isPkp = false;
    public ?int $selectedSettingId = null;

    public bool $dueDateIsManual = false;
    public string $global_discount_type = 'percentage';
    public bool $suppressAutoDueDate = false;
    public int $dueDateRenderVersion = 0;
    private array $paymentTermLongevityCache = [];
    private ?int $lastAppliedPaymentTermId = null;

    public function mount(string $idempotencyToken, ?int $duplicateId = null): void
    {
        $this->idempotencyToken = $idempotencyToken;
        $this->selectedSettingId = (int) session('setting_id');
        $this->isPkp = $this->isPkpEnabled();
        // New PKP purchases default to tax-included; duplicates keep the source purchase value.
        $this->is_tax_included = $this->isPkp && $duplicateId === null;
        $this->reference = 'PR'; // This can be dynamic if needed
        $this->date = now()->format('Y-m-d');
        $this->due_date = now()->format('Y-m-d');
        $this->supplier_purchase_number = null;
        $this->tax_ref_no = null;
        // Ensure a fresh cart when starting a new purchase
        Cart::instance('purchase')->destroy();

        $this->syncPaymentTermAndDueDate($this->resolveDefaultPaymentTermId());

        if ($duplicateId) {
            $this->prefillFromPurchase((int) $duplicateId);
        }
    }

    #[On('tagsUpdated')]
    public function handleTagsUpdated(array $tags): void
    {
        $this->tags = $tags;
    }

    #[On('business-selector-changed')]
    public function handleBusinessSelectorChanged(?int $settingId): void
    {
        if ($settingId === null) {
            $this->selectedSettingId = (int) session('setting_id');
        } else {
            $this->selectedSettingId = $settingId;
        }
        // Rehydrate tax context for the new business
        $this->rehydrateTaxContext();
        // Dispatch context change to child components
        $this->dispatch('document-business-context-changed', $this->selectedSettingId);
    }

    public function updatedSelectedSettingId(): void
    {
        $this->rehydrateTaxContext();
        // Dispatch context change to child components
        $this->dispatch('document-business-context-changed', $this->selectedSettingId);
    }

    private function rehydrateTaxContext(): void
    {
        $previousPkpState = $this->isPkp;
        $this->isPkp = $this->isPkpEnabled();

        $cart = Cart::instance('purchase');
        $cartItems = $cart->content();
        if ($cartItems->isEmpty()) {
            return;
        }

        // If business changed from PKP to non-PKP, remove tax data
        if ($previousPkpState && !$this->isPkp) {
            foreach ($cartItems as $item) {
                $newOptions = $item->options;
                unset($newOptions['product_tax']);
                $newOptions['product_tax_amount'] = 0.0;
                $newOptions['sub_total'] = $newOptions['sub_total_before_tax'] ?? $newOptions['sub_total'];
                $cart->update($item->rowId, ['options' => $newOptions]);
            }
        }
    }

    private function syncPaymentTermAndDueDate(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->applyPaymentTermSelection($paymentTermId, $syncDropdown);
    }

    private function perfLog(string $message, array $context = []): void
    {
        if (! config('performance.livewire_hotpath_debug')) {
            return;
        }

        Log::info($message, $context);
    }

    private function purchaseSubmitDebugEnabled(): bool
    {
        return (bool) config('performance.purchase_submit_debug');
    }

    private function purchaseSubmitDebug(string $event, array $context = []): void
    {
        if (! $this->purchaseSubmitDebugEnabled()) {
            return;
        }

        Log::info($event, $this->purchaseSubmitBaseContext($context));
    }

    private function purchaseSubmitInfo(string $event, array $context = []): void
    {
        if (! $this->purchaseSubmitDebugEnabled()) {
            return;
        }

        Log::info($event, $this->purchaseSubmitBaseContext($context));
    }

    private function purchaseSubmitWarning(string $event, array $context = []): void
    {
        Log::warning($event, $this->purchaseSubmitBaseContext($context));
    }

    private function purchaseSubmitBaseContext(array $extra = []): array
    {
        $cart = Cart::instance('purchase');
        $cartItems = $cart->content();

        return array_merge([
            'flow' => 'purchase.create',
            'user_id' => auth()->id(),
            'setting_id' => session('setting_id'),
            'component' => static::class,
            'idempotency_token' => $this->idempotencyToken ?? null,
            'duplicate_purchase_id' => $this->duplicatePurchase?->id,
            'supplier_id_state' => $this->supplier_id,
            'payment_term_state' => $this->payment_term,
            'date' => $this->date,
            'due_date' => $this->due_date,
            'due_date_is_manual' => $this->dueDateIsManual,
            'is_pkp' => $this->isPkp,
            'cart_count' => (int) $cart->count(),
            'cart_total_sub_total' => (float) $cartItems->sum(fn ($item) => (float) ($item->options['sub_total'] ?? 0)),
            'transaction_level' => DB::transactionLevel(),
            'has_note' => filled($this->note),
            'note_length' => is_string($this->note) ? strlen($this->note) : 0,
            'has_tax_ref_no' => filled($this->tax_ref_no),
            'has_supplier_purchase_number' => filled($this->supplier_purchase_number),
            'tag_count' => count($this->tags),
        ], $extra);
    }

    private function flattenValidationErrors(ValidationException $e): array
    {
        $flattened = [];

        foreach ($e->errors() as $field => $messages) {
            $flattened[$field] = implode(' | ', array_map(static fn ($message) => (string) $message, (array) $messages));
        }

        return $flattened;
    }

    private function isSuspiciousHiddenIdArg(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $trimmed = trim($value);

        return $trimmed !== '' && ! preg_match('/^\d+$/', $trimmed);
    }

    private function isCustomPaymentTerm(?int $termId): bool
    {
        $customTermId = PaymentTerm::customTermId();

        return $customTermId !== null && $termId !== null && $termId === $customTermId;
    }

    private function normalizePaymentTermId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value['paymentTermId'] ?? $value['payment_term_id'] ?? $value[0] ?? null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeSupplierId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value['supplier_id'] ?? $value['supplierId'] ?? $value['id'] ?? $value[0] ?? null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function currentPaymentTermId(): ?int
    {
        return $this->normalizePaymentTermId($this->payment_term);
    }

    private function currentSupplierId(): ?int
    {
        return $this->normalizeSupplierId($this->supplier_id);
    }

    private function resolveSupplierPaymentTermId(?int $supplierId): ?int
    {
        $defaultPaymentTermId = $this->resolveDefaultPaymentTermId();
        if (! $supplierId) {
            return $defaultPaymentTermId;
        }

        $supplierPaymentTermId = Supplier::query()->whereKey($supplierId)->value('payment_term_id');

        return $supplierPaymentTermId ? (int) $supplierPaymentTermId : $defaultPaymentTermId;
    }

    private function resolvePaymentTermLongevity(?int $termId): ?int
    {
        if (! $termId) {
            return null;
        }

        if (array_key_exists($termId, $this->paymentTermLongevityCache)) {
            return $this->paymentTermLongevityCache[$termId];
        }

        $longevity = PaymentTerm::query()->whereKey($termId)->value('longevity');
        $resolvedLongevity = $longevity === null ? null : (int) $longevity;
        $this->paymentTermLongevityCache[$termId] = $resolvedLongevity;

        return $resolvedLongevity;
    }

    private function expectedDueDateForTerm(?int $termId): ?string
    {
        if (! $this->date) {
            return null;
        }

        if (! $termId) {
            return $this->date;
        }

        $longevity = $this->resolvePaymentTermLongevity($termId);
        if ($longevity === null) {
            return $this->date;
        }

        return Carbon::parse($this->date)
            ->addDays($longevity)
            ->format('Y-m-d');
    }

    private function shouldSkipSupplierReapply(?int $supplierId, ?int $resolvedTermId): bool
    {
        if ($this->suppressAutoDueDate) {
            return false;
        }

        if ($this->currentSupplierId() !== $supplierId) {
            return false;
        }

        if ($this->currentPaymentTermId() !== $resolvedTermId) {
            return false;
        }

        if ($this->dueDateIsManual || $this->isCustomPaymentTerm($resolvedTermId)) {
            return true;
        }

        $expectedDueDate = $this->expectedDueDateForTerm($resolvedTermId);

        return $expectedDueDate !== null && $expectedDueDate === $this->due_date;
    }

    private function shouldSkipPaymentTermReapply(?int $incomingTermId): bool
    {
        if ($this->suppressAutoDueDate) {
            return false;
        }

        if ($this->lastAppliedPaymentTermId !== $incomingTermId) {
            return false;
        }

        if ($this->dueDateIsManual || $this->isCustomPaymentTerm($incomingTermId)) {
            return true;
        }

        $expectedDueDate = $this->expectedDueDateForTerm($incomingTermId);

        return $expectedDueDate !== null && $expectedDueDate === $this->due_date;
    }

    private function applySupplierSelection(?int $supplierId): void
    {
        $resolvedTermId = $this->resolveSupplierPaymentTermId($supplierId);
        if ($this->shouldSkipSupplierReapply($supplierId, $resolvedTermId)) {
            $this->perfLog('DEBUG: applySupplierSelection skipped (no-op)', [
                'supplier_id' => $supplierId,
                'payment_term' => $resolvedTermId,
            ]);
            return;
        }

        $this->supplier_id = $supplierId;
        $this->applyPaymentTermSelection($resolvedTermId, true);
    }

    private function applyPaymentTermSelection(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $currentPaymentTermSnapshot = $this->currentPaymentTermId();
        $targetPaymentTermId = $paymentTermId ?: null;

        $this->perfLog('DEBUG: applyPaymentTermSelection start', [
            'paymentTermId' => $targetPaymentTermId,
            'syncDropdown' => $syncDropdown,
        ]);

        $this->payment_term = $targetPaymentTermId;
        $this->lastAppliedPaymentTermId = $targetPaymentTermId;

        if (! $this->suppressAutoDueDate) {
            if ($this->isCustomPaymentTerm($this->payment_term)) {
                $this->dueDateIsManual = true;
            } else {
                $this->dueDateIsManual = false;
                $this->updateDueDateFromPaymentTerm();
            }
        }

        if ($syncDropdown && $currentPaymentTermSnapshot !== $targetPaymentTermId) {
            $this->dispatch('setPaymentTerm', $this->payment_term)
                ->to(PaymentTermSearchDropdown::class);
        }
    }

    private function bumpDueDateRenderVersion(): void
    {
        $this->dueDateRenderVersion++;
    }

    private function resolveDefaultPaymentTermId(): ?int
    {
        // Fallback to COD (Cash On Delivery)
        // This uses string literal 'cod' or 'cash on delivery' in the query
        return PaymentTerm::defaultCodTermId();
    }

    private function isPkpEnabled(): bool
    {
        $settingId = $this->selectedSettingId ?? (int) session('setting_id');
        if ($settingId <= 0) {
            return false;
        }

        return (bool) (Setting::query()->whereKey($settingId)->value('is_pkp') ?? false);
    }

    private function ensureCartTaxesForPkp($cartItems): void
    {
        if (! $this->isPkpEnabled()) {
            return;
        }

        // Check if any taxes exist globally
        if (Tax::query()->count() === 0) {
            throw ValidationException::withMessages([
                'cart' => "Tidak ada data pajak tersedia. Bisnis PKP wajib mengatur setidaknya satu data pajak.",
            ]);
        }

        foreach ($cartItems as $index => $item) {
            $taxId = $item->options['product_tax'] ?? null;
            if (empty($taxId)) {
                throw ValidationException::withMessages([
                    'cart' => "Produk '{$item->name}' wajib memilih pajak karena bisnis PKP.",
                ]);
            }

            $tax = Tax::query()->find($taxId);

            if (!$tax) {
                throw ValidationException::withMessages([
                    'cart' => "Pajak untuk produk '{$item->name}' tidak valid.",
                ]);
            }
        }
    }

    public function updatedPaymentTerm($value): void
    {
        $this->perfLog('DEBUG: updatedPaymentTerm called', ['value' => $value]);
        $paymentTermId = $this->normalizePaymentTermId($value);
        if ($this->suppressAutoDueDate) {
            $this->payment_term = $paymentTermId;
            $this->lastAppliedPaymentTermId = $paymentTermId;
            return;
        }

        if ($this->shouldSkipPaymentTermReapply($paymentTermId)) {
            $this->perfLog('DEBUG: updatedPaymentTerm skipped (no-op)', ['value' => $paymentTermId]);
            return;
        }

        $this->applyPaymentTermSelection($paymentTermId);
    }

    #[On('payment-term-changed')]
    public function handlePaymentTermChanged($paymentTermId = null): void
    {
        $normalizedTermId = $this->normalizePaymentTermId($paymentTermId);

        if ($this->suppressAutoDueDate) {
            $this->payment_term = $normalizedTermId;
            $this->lastAppliedPaymentTermId = $normalizedTermId;
            return;
        }

        if ($this->shouldSkipPaymentTermReapply($normalizedTermId)) {
            $this->perfLog('DEBUG: handlePaymentTermChanged skipped (no-op)', [
                'value' => $normalizedTermId,
            ]);
            return;
        }

        $this->applyPaymentTermSelection($normalizedTermId);
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $this->perfLog('DEBUG: updateDueDateFromPaymentTerm start', [
            'date' => $this->date,
            'payment_term' => $this->payment_term,
            'suppressAutoDueDate' => $this->suppressAutoDueDate,
            'dueDateIsManual' => $this->dueDateIsManual,
        ]);

        $previousDueDate = $this->due_date;
        $nextDueDate = $this->date;

        $termId = $this->payment_term ? (int) $this->payment_term : null;
        if (! $termId || ! $this->date) {
            $this->due_date = $nextDueDate;
            if ($previousDueDate !== $this->due_date) {
                $this->bumpDueDateRenderVersion();
            }
            $this->perfLog('DEBUG: updateDueDateFromPaymentTerm abort: missing termId or date');
            return;
        }

        $longevity = $this->resolvePaymentTermLongevity($termId);
        if ($longevity === null) {
            $this->due_date = $nextDueDate;
            if ($previousDueDate !== $this->due_date) {
                $this->bumpDueDateRenderVersion();
            }
            $this->perfLog('DEBUG: updateDueDateFromPaymentTerm abort: PaymentTerm not found', ['termId' => $termId]);
            return;
        }

        $nextDueDate = Carbon::parse($this->date)
            ->addDays($longevity)
            ->format('Y-m-d');

        $this->due_date = $nextDueDate;
        if ($previousDueDate !== $this->due_date) {
            $this->bumpDueDateRenderVersion();
        }
            
        $this->perfLog('DEBUG: updateDueDateFromPaymentTerm success', [
            'new_due_date' => $this->due_date,
            'longevity' => $longevity,
        ]);
    }

    public function updatedDate($value): void
    {
        if ($this->dueDateIsManual) {
            return;
        }
        $this->updateDueDateFromPaymentTerm();
    }

    public function updatedDueDate($value): void
    {
        $this->dueDateIsManual = true;

        $customTermId = PaymentTerm::customTermId();
        if ($customTermId && (int) $this->payment_term !== $customTermId) {
            $this->suppressAutoDueDate = true;
            try {
                $this->payment_term = $customTermId;
                $this->lastAppliedPaymentTermId = $customTermId;
                $this->dispatch('setPaymentTerm', $customTermId)
                    ->to(PaymentTermSearchDropdown::class);
            } finally {
                $this->suppressAutoDueDate = false;
            }
        }
    }


    public function updatedSupplierId($value): void
    {
        $supplierId = $this->normalizeSupplierId($value);
        $this->perfLog('DEBUG: updatedSupplierId called', ['value' => $supplierId]);
        $this->applySupplierSelection($supplierId);
    }

    #[On('supplierSelected')]
    public function handleSupplierSelected($supplier_id): void
    {
        $this->perfLog('DEBUG: handleSupplierSelected called', ['supplier_id' => $supplier_id]);
        $supplierId = $this->normalizeSupplierId($supplier_id);
        $this->applySupplierSelection($supplierId);
    }

    #[On('shippingUpdated')]
    public function handleShippingUpdated($shipping)
    {
        $this->shipping = $shipping;
    }

    #[On('globalDiscountUpdated')]
    public function handleGlobalDiscountUpdated($discount)
    {
        $this->global_discount = $discount;
    }

    #[On('globalDiscountTypeUpdated')]
    public function handleGlobalDiscountTypeUpdated($type)
    {
        $this->global_discount_type = $type;
    }

    #[On('taxIncludedUpdated')]
    public function handleTaxIncludedUpdated(bool $included)
    {
        $this->is_tax_included = $included;
    }



    #[On('supplierCreated')]
    public function handleSupplierCreated(array $supplier): void
    {
        // Supplier was just created, the dropdown will auto-select it
        // We need to set the payment term from the newly created supplier
        $supplierId = $this->normalizeSupplierId($supplier['id'] ?? null);
        $paymentTermId = isset($supplier['payment_term_id']) && $supplier['payment_term_id']
            ? (int) $supplier['payment_term_id']
            : $this->resolveDefaultPaymentTermId();

        if ($this->shouldSkipSupplierReapply($supplierId, $paymentTermId)) {
            $this->perfLog('DEBUG: handleSupplierCreated skipped (no-op)', [
                'supplier_id' => $supplierId,
                'payment_term' => $paymentTermId,
            ]);
            return;
        }

        $this->supplier_id = $supplierId;
        $this->applyPaymentTermSelection($paymentTermId, true);
    }

    private function prefillFromPurchase(int $purchaseId): void
    {
        $this->purchaseSubmitDebug('purchase.duplicate.prefill.start', [
            'requested_duplicate_purchase_id' => $purchaseId,
            'current_setting_id' => session('setting_id'),
        ]);

        $purchase = Purchase::with(['purchaseDetails.product', 'tags'])->withArchived()->find($purchaseId);
        if (! $purchase) {
            $this->purchaseSubmitWarning('purchase.duplicate.prefill.skipped', [
                'requested_duplicate_purchase_id' => $purchaseId,
                'reason' => 'not_found',
            ]);
            return;
        }

        if ((int) $purchase->setting_id !== (int) session('setting_id')) {
            $this->purchaseSubmitWarning('purchase.duplicate.prefill.skipped', [
                'requested_duplicate_purchase_id' => $purchaseId,
                'source_purchase_id' => $purchase->id,
                'source_setting_id' => (int) $purchase->setting_id,
                'reason' => 'setting_mismatch',
            ]);
            return;
        }

        $today = now()->format('Y-m-d');
        $taxInclusionResolution = app(PurchaseTaxInclusionResolver::class)->resolveForDuplicate($purchase);
        $resolvedTaxIncluded = (bool) $taxInclusionResolution['effective'];
        $purchase->is_tax_included = $resolvedTaxIncluded;

        if ($taxInclusionResolution['used_fallback']) {
            $this->purchaseSubmitWarning('purchase.duplicate.prefill.tax_inclusion_resolved', [
                'source_purchase_id' => $purchase->id,
                'stored_is_tax_included' => $taxInclusionResolution['stored'],
                'inferred_is_tax_included' => $taxInclusionResolution['inferred'],
                'used_fallback' => $taxInclusionResolution['used_fallback'],
                'reason' => $taxInclusionResolution['reason'],
            ]);
        } elseif ($taxInclusionResolution['reason'] === 'ambiguous_keep_stored') {
            $this->purchaseSubmitWarning('purchase.duplicate.prefill.tax_inclusion_resolved', [
                'source_purchase_id' => $purchase->id,
                'stored_is_tax_included' => $taxInclusionResolution['stored'],
                'inferred_is_tax_included' => $taxInclusionResolution['inferred'],
                'used_fallback' => $taxInclusionResolution['used_fallback'],
                'reason' => $taxInclusionResolution['reason'],
            ]);
        } elseif ($taxInclusionResolution['reason'] === 'no_inferable_lines_keep_stored') {
            $this->purchaseSubmitInfo('purchase.duplicate.prefill.tax_inclusion_resolved', [
                'source_purchase_id' => $purchase->id,
                'stored_is_tax_included' => $taxInclusionResolution['stored'],
                'inferred_is_tax_included' => $taxInclusionResolution['inferred'],
                'used_fallback' => $taxInclusionResolution['used_fallback'],
                'reason' => $taxInclusionResolution['reason'],
            ]);
        }

        $this->duplicatePurchase = $purchase;
        $this->supplier_id = $purchase->supplier_id;
        $this->payment_term = $purchase->payment_term_id;
        $this->lastAppliedPaymentTermId = $this->currentPaymentTermId();
        $this->note = $purchase->note;
        $this->date = $today;
        $this->due_date = $today;
        $this->dueDateIsManual = false;
        $this->updateDueDateFromPaymentTerm();
        $this->dueDateIsManual = $this->isCustomPaymentTerm($this->payment_term ? (int) $this->payment_term : null);
        $this->tags = $purchase->tags->pluck('name')->toArray();
        $this->supplier_purchase_number = null;
        $this->tax_ref_no = null;

        if ($purchase->discount_percentage > 0) {
            $this->global_discount_type = 'percentage';
            $this->global_discount = $purchase->discount_percentage;
        } elseif ($purchase->discount_amount > 0) {
            $this->global_discount_type = 'fixed';
            $this->global_discount = $purchase->discount_amount;
        } else {
            $this->global_discount_type = 'percentage';
            $this->global_discount = 0;
        }

        $this->shipping = $purchase->shipping_amount ?? 0;
        $this->is_tax_included = $resolvedTaxIncluded;

        $this->purchaseSubmitInfo('purchase.duplicate.prefill.loaded', [
            'source_purchase_id' => $purchase->id,
            'source_supplier_id' => $purchase->supplier_id,
            'source_payment_term_id' => $purchase->payment_term_id,
            'source_detail_count' => (int) $purchase->purchaseDetails->count(),
            'date_reset_to_today' => true,
        ]);

        // Ensure dropdown UI is in sync with the prefilled payment term
        // Do not call syncPaymentTermAndDueDate() here because it may overwrite
        // the duplicated purchase's due_date which we've explicitly set above.
        if ($this->payment_term) {
            $this->dispatch('setPaymentTerm', $this->payment_term)
                ->to(PaymentTermSearchDropdown::class);
        } else {
            $this->dispatch('setPaymentTerm', null)
                ->to(PaymentTermSearchDropdown::class);
        }

        $cart = Cart::instance('purchase');
        $cart->destroy();

        foreach ($purchase->purchaseDetails as $detail) {
            $product = $detail->product;
            $subTotalBeforeTax = $detail->sub_total - $detail->product_tax_amount;
            $productTax = $this->isPkp ? $detail->tax_id : null;
            $normalizedTaxAmount = $this->isPkp ? (float) $detail->product_tax_amount : 0.0;
            $normalizedSubTotal = $this->isPkp ? (float) $detail->sub_total : (float) $subTotalBeforeTax;

            $cart->add([
                'id' => $detail->product_id,
                'name' => $detail->product_name,
                'qty' => $detail->quantity,
                'price' => $detail->price,
                'weight' => 1,
                'options' => [
                    'product_discount' => $detail->product_discount_amount,
                    'product_discount_type' => $detail->product_discount_type,
                    'sub_total' => $normalizedSubTotal,
                    'sub_total_before_tax' => $subTotalBeforeTax,
                    'product_tax_amount' => $normalizedTaxAmount,
                    'code' => $detail->product_code,
                    'stock' => $product?->product_quantity ?? 0,
                    'unit' => $product?->product_unit ?? '',
                    'product_tax' => $productTax,
                    'unit_price' => $detail->unit_price,
                    'last_purchase_price' => $product?->last_purchase_price ?? null,
                    'average_purchase_price' => $product?->average_purchase_price ?? null,
                ],
            ]);
        }

        $this->purchaseSubmitDebug('purchase.duplicate.prefill.cart_seeded', [
            'source_purchase_id' => $purchase->id,
            'detail_count' => (int) $purchase->purchaseDetails->count(),
            'seeded_cart_count' => (int) $cart->count(),
            'seeded_cart_total_sub_total' => (float) $cart->content()->sum(fn ($item) => (float) ($item->options['sub_total'] ?? 0)),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function submit(?string $supplierId = null, ?string $paymentTermId = null)
    {
        $rawSupplierArg = $supplierId;
        $rawPaymentTermArg = $paymentTermId;
        $parsedSupplierArg = $this->normalizeSupplierId($supplierId);
        $parsedPaymentTermArg = $this->normalizePaymentTermId($paymentTermId);

        $this->purchaseSubmitDebug('purchase.submit.start', [
            'hidden_supplier_arg' => $rawSupplierArg,
            'hidden_payment_term_arg' => $rawPaymentTermArg,
            'supplier_id_state_before_submit' => $this->supplier_id,
            'payment_term_state_before_submit' => $this->payment_term,
            'duplicate_mode' => $this->duplicatePurchase !== null,
        ]);

        $this->purchaseSubmitDebug('purchase.submit.hidden_payload_parsed', [
            'hidden_supplier_arg' => $rawSupplierArg,
            'hidden_payment_term_arg' => $rawPaymentTermArg,
            'parsed_supplier_arg' => $parsedSupplierArg,
            'parsed_payment_term_arg' => $parsedPaymentTermArg,
            'supplier_arg_changed_by_parse' => ($rawSupplierArg !== null && $rawSupplierArg !== '') && ((string) $parsedSupplierArg !== $rawSupplierArg),
            'payment_term_arg_changed_by_parse' => ($rawPaymentTermArg !== null && $rawPaymentTermArg !== '') && ((string) $parsedPaymentTermArg !== $rawPaymentTermArg),
        ]);

        if ($this->isSuspiciousHiddenIdArg($rawSupplierArg) || $this->isSuspiciousHiddenIdArg($rawPaymentTermArg)) {
            $this->purchaseSubmitWarning('purchase.submit.hidden_payload_invalid', [
                'hidden_supplier_arg' => $rawSupplierArg,
                'hidden_payment_term_arg' => $rawPaymentTermArg,
            ]);
        }

        // Use passed values from hidden inputs if available (bypasses broken wire:model binding)
        if ($supplierId !== null && $supplierId !== '') {
            $this->supplier_id = (int) $supplierId;
        }
        if ($paymentTermId !== null && $paymentTermId !== '') {
            $this->payment_term = (int) $paymentTermId;
            $this->lastAppliedPaymentTermId = (int) $paymentTermId;
        }

        // Fallback: Re-sync payment_term from supplier if still not set
        if ($this->supplier_id && !$this->payment_term) {
            $supplierPaymentTerm = Supplier::query()->whereKey($this->supplier_id)->value('payment_term_id');
            $paymentTermId = $supplierPaymentTerm ? (int) $supplierPaymentTerm : $this->resolveDefaultPaymentTermId();
            $this->applyPaymentTermSelection($paymentTermId);

            $this->purchaseSubmitDebug('purchase.submit.payment_term_fallback_applied', [
                'supplier_id' => $this->supplier_id,
                'resolved_payment_term_id' => $paymentTermId,
            ]);
        }

        $purchase = null;
        $failureStage = 'before_validation';
        $transactionStarted = false;

        try {
            $this->purchaseSubmitDebug('purchase.submit.before_validation', [
                'hidden_supplier_arg' => $rawSupplierArg,
                'hidden_payment_term_arg' => $rawPaymentTermArg,
            ]);

            $failureStage = 'authorization_check';
            // Resolve effective document business with authorization early to catch permission errors before validation
            $resolvedBusiness = app(EffectiveDocumentBusinessResolver::class)
                ->resolve($this->selectedSettingId);

            $failureStage = 'validation';
            $this->validate([
                'supplier_id' => 'required|exists:suppliers,id',
                'supplier_purchase_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchases', 'supplier_purchase_number')
                        ->where('setting_id', $resolvedBusiness['setting_id'])
                        ->whereNull('archived_at'),
                ],
                'tax_ref_no' => 'nullable|string|max:255|unique:purchases,tax_ref_no,NULL,id,setting_id,' . $resolvedBusiness['setting_id'],
                'date' => 'required|date',
                'due_date' => 'required|date|after_or_equal:date',
                'payment_term' => 'required|exists:payment_terms,id',
                'note' => 'nullable|string|max:1000',
            ], [
                'supplier_id.required' => 'Pilih pemasok terlebih dahulu.',
                'supplier_id.exists' => 'Pemasok yang dipilih tidak valid.',
                'supplier_purchase_number.unique' => 'Nomor pembelian pemasok sudah digunakan.',
                'tax_ref_no.unique' => 'Nomor Faktur Pajak sudah digunakan.',
                'date.required' => 'Tanggal pembelian wajib diisi.',
                'date.date' => 'Format tanggal tidak valid.',
                'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
                'due_date.date' => 'Format tanggal tidak valid.',
                'due_date.after_or_equal' => 'Tanggal jatuh tempo harus lebih besar dari atau sama dengan tanggal pembelian.',
                'payment_term.required' => 'Pilih jatuh tempo terlebih dahulu.',
                'payment_term.exists' => 'Jatuh tempo yang dipilih tidak valid.',
            ]);

            $failureStage = 'cart_check';
            $cart = Cart::instance('purchase');

            if ($cart->count() === 0) {
                $this->purchaseSubmitWarning('purchase.submit.aborted_empty_cart');
                $this->dispatch('notify', ['type' => 'error', 'message' => 'Produk harus dipilih']);
                return;
            }

            $failureStage = 'ensure_cart_taxes_for_pkp';
            $this->ensureCartTaxesForPkp($cart->content());

            $failureStage = 'idempotency_claim';
            if (! IdempotencyService::claim($this->idempotencyToken, 'purchases.store', auth()->id())) {
                $this->purchaseSubmitWarning('purchase.submit.idempotency_claim_failed', [
                    'token' => $this->idempotencyToken,
                ]);
                session()->flash('error', 'Permintaan pembelian sudah diproses. Silakan tunggu sebelum mencoba lagi.');
                return;
            }

            $failureStage = 'db_transaction_begin';
            DB::beginTransaction();
            $transactionStarted = true;
            $this->purchaseSubmitDebug('purchase.submit.transaction_begin');

            // Use the already-resolved business from authorization check
            $setting_id = $resolvedBusiness['setting_id'];

            $failureStage = 'calculating_totals';
            $cartItems = $cart->content();
            $resolvedTaxIncluded = $this->isPkp ? (bool) $this->is_tax_included : false;
            $resolvedTaxRefNo = $this->isPkp ? ($this->tax_ref_no ?: null) : null;
            $discount_amount = $this->global_discount_type === 'fixed' ? (float) $this->global_discount : 0.0;
            $discount_percentage = $this->global_discount_type === 'percentage' ? (float) $this->global_discount : 0.0;
            $normalizedPurchase = app(PurchaseNormalizer::class)->normalize([
                'discount_percentage' => $discount_percentage,
                'discount_amount' => $discount_amount,
                'shipping_amount' => $this->shipping,
                'paid_amount' => 0,
                'tax_id' => null,
                'tax_percentage' => 0,
            ], $cartItems, $this->isPkpEnabled());
            $header = $normalizedPurchase['header'];

            $failureStage = 'purchase_create';
            $purchase = DocumentReferenceService::createPurchaseWithReference([
                'date' => $this->date,
                'due_date' => $this->due_date,
                'supplier_id' => $this->supplier_id,
                'supplier_purchase_number' => $this->supplier_purchase_number ?: null,
                'tax_ref_no' => $resolvedTaxRefNo,
                'discount_percentage' => $header['discount_percentage'],
                'discount_amount' => $header['discount_amount'],
                'shipping_amount' => $header['shipping_amount'],
                'tax_id' => $header['tax_id'],
                'tax_percentage' => $header['tax_percentage'],
                'tax_amount' => $header['tax_amount'],
                'total_amount' => $header['total_amount'],
                'due_amount' => $header['due_amount'],
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_term_id' => $this->payment_term,
                'note' => $this->note,
                'setting_id' => $setting_id,
                'paid_amount' => 0.0,
                'is_tax_included' => $resolvedTaxIncluded,
                'payment_method' => '',
            ]);

            $this->purchaseSubmitInfo('purchase.submit.purchase_created', [
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'payment_term_id' => $purchase->payment_term_id,
                'status' => $purchase->status,
                'total_amount' => (float) $purchase->total_amount,
                'tax_amount' => (float) $purchase->tax_amount,
            ]);

            $failureStage = 'tags_sync';
            $purchase->syncTags($this->tags);
            $this->purchaseSubmitDebug('purchase.submit.tags_synced', [
                'purchase_id' => $purchase->id,
                'tag_count' => count($this->tags),
            ]);

            $failureStage = 'details_create';
            $detailCount = 0;
            $detailQuantityTotal = 0;
            $detailTaxTotal = 0.0;
            foreach ($normalizedPurchase['details'] as $item) {
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_code' => $item['product_code'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'price' => $item['price'],
                    'product_discount_type' => $item['product_discount_type'],
                    'product_discount_amount' => $item['product_discount_amount'],
                    'sub_total' => $item['sub_total'],
                    'product_tax_amount' => $item['product_tax_amount'],
                    'tax_id' => $item['tax_id'],
                ]);

                $detailCount++;
                $detailQuantityTotal += (int) $item['quantity'];
                $detailTaxTotal += (float) $item['product_tax_amount'];
            }

            $this->purchaseSubmitDebug('purchase.submit.details_created', [
                'purchase_id' => $purchase->id,
                'detail_count' => $detailCount,
                'detail_quantity_total' => $detailQuantityTotal,
                'detail_tax_total' => $detailTaxTotal,
            ]);

            $failureStage = 'commit';
            DB::commit();
            $this->purchaseSubmitInfo('purchase.submit.committed', [
                'purchase_id' => $purchase->id,
            ]);
            $cart->destroy();

            $successMessage = 'Pembelian Ditambahkan!';
            if ((int) $setting_id !== (int) session('setting_id')) {
                $targetBusiness = Setting::find($setting_id);
                if ($targetBusiness) {
                    $successMessage = "Pembelian berhasil dibuat untuk {$targetBusiness->company_name} dengan referensi {$purchase->reference}";
                }
            }
            session()->flash('success', $successMessage);
            return redirect()->route('purchases.index');

        } catch (ValidationException $e) {
            if ($transactionStarted && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->purchaseSubmitWarning('purchase.submit.validation_failed', [
                'failure_stage' => $failureStage,
                'hidden_supplier_arg' => $rawSupplierArg,
                'hidden_payment_term_arg' => $rawPaymentTermArg,
                'validation_errors' => $this->flattenValidationErrors($e),
                'duplicate_mode' => $this->duplicatePurchase !== null,
            ]);
            throw $e;
        } catch (\Exception $e) {
            if ($transactionStarted && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('purchase.submit.exception', $this->purchaseSubmitBaseContext([
                'failure_stage' => $failureStage,
                'hidden_supplier_arg' => $rawSupplierArg,
                'hidden_payment_term_arg' => $rawPaymentTermArg,
                'purchase_id' => $purchase?->id,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]));

            session()->flash('error', 'Gagal menyimpan pembelian. Silakan coba lagi.');
            return;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase.create-form', [
            'supplierIdForView' => $this->supplier_id === null ? '' : (string) $this->supplier_id,
            'paymentTermForView' => $this->payment_term === null ? '' : (string) $this->payment_term,
            'dueDateForView' => $this->due_date ?? '',
        ]);
    }
}

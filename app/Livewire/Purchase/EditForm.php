<?php

namespace App\Livewire\Purchase;
use Carbon\Carbon;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Setting\Entities\Setting;
use Throwable;

class EditForm extends Component
{
    public $purchaseId;
    public $reference;
    public $supplier_id;
    public $supplier_purchase_number;
    public $tax_ref_no;
    public $date;
    public $due_date;
    public $payment_term;
    public $note;
    public $purchase;

    public array $tags = [];

    public $listeners = [
        'tagsUpdated' => 'handleTagsUpdated',
        'shippingUpdated' => 'handleShippingUpdated',
        'globalDiscountUpdated' => 'handleGlobalDiscountUpdated',
        'globalDiscountTypeUpdated' => 'handleGlobalDiscountTypeUpdated',
        'taxIncludedUpdated' => 'handleTaxIncludedUpdated',
        'supplierCreated' => 'handleSupplierCreated',
        'supplierSelected' => 'handleSupplierSelected',
        'payment-term-changed' => 'handlePaymentTermChanged',
    ];

    public $shipping = 0;
    public $global_discount = 0;
    public string $global_discount_type = 'percentage';
    public $is_tax_included = false;
    public bool $dueDateIsManual = false;
    public bool $suppressAutoDueDate = false;
    public int $dueDateRenderVersion = 0;

    public function mount($purchaseId): void
    {
        $this->purchaseId = $purchaseId;
        $this->purchase = Purchase::with('purchaseDetails')->findOrFail($purchaseId);

        // Rule: Partially or Fully Received -> Hard Block
        if (in_array($this->purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])) {
            abort(403, 'Tidak dapat mengubah pembelian yang sudah diterima barangnya.');
        }

        // Rule: Approved -> Require explicit permission
        if ($this->purchase->status === Purchase::STATUS_APPROVED) {
            if (!auth()->user()->can('purchases.approved.edit')) {
                abort(403, 'Anda tidak memiliki akses untuk mengubah pembelian yang sudah disetujui.');
            }
        }

        $this->reference = $this->purchase->reference;
        $this->supplier_id = $this->purchase->supplier_id;
        $this->supplier_purchase_number = $this->purchase->supplier_purchase_number;
        $this->tax_ref_no = $this->purchase->tax_ref_no;
        $this->date = $this->purchase->date;
        $this->due_date = $this->purchase->due_date;
        $this->payment_term = $this->purchase->payment_term_id;
        $this->dueDateIsManual = $this->isCustomPaymentTerm($this->payment_term ? (int) $this->payment_term : null);
        $this->note = $this->purchase->note;
        $this->shipping = $this->purchase->shipping_amount ?? 0;
        $this->is_tax_included = (bool) $this->purchase->is_tax_included;
        if ($this->purchase->discount_percentage > 0) {
            $this->global_discount_type = 'percentage';
            $this->global_discount = $this->purchase->discount_percentage;
        } elseif ($this->purchase->discount_amount > 0) {
            $this->global_discount_type = 'fixed';
            $this->global_discount = $this->purchase->discount_amount;
        } else {
            $this->global_discount_type = 'percentage';
            $this->global_discount = 0;
        }

        $this->tags = $this->purchase->tags->pluck('name')->toArray();
        $this->restoreCart();

        // Ensure the payment term dropdown UI matches the loaded payment term
        if ($this->payment_term) {
            $this->dispatch('setPaymentTerm', $this->payment_term)
                ->to(PaymentTermSearchDropdown::class);
        } else {
            $this->dispatch('setPaymentTerm', null)
                ->to(PaymentTermSearchDropdown::class);
        }
    }

    public function handleTagsUpdated(array $tags): void
    {
        $this->tags = $tags;
    }

    private function syncPaymentTermAndDueDate(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->applyPaymentTermSelection($paymentTermId, $syncDropdown);
    }

    private function resolveDefaultPaymentTermId(): ?int
    {
        return PaymentTerm::defaultCodTermId();
    }

    private function bumpDueDateRenderVersion(): void
    {
        $this->dueDateRenderVersion++;
    }

    private function isPkpEnabled(): bool
    {
        $settingId = (int) session('setting_id');
        if ($settingId <= 0) {
            return false;
        }

        return (bool) (Setting::query()->whereKey($settingId)->value('is_pkp') ?? false);
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
            'flow' => 'purchase.edit',
            'user_id' => auth()->id(),
            'setting_id' => session('setting_id'),
            'component' => static::class,
            'purchase_id' => $this->purchaseId,
            'supplier_id_state' => $this->supplier_id,
            'payment_term_state' => $this->payment_term,
            'date' => $this->date,
            'due_date' => $this->due_date,
            'due_date_is_manual' => $this->dueDateIsManual,
            'is_pkp' => $this->isPkpEnabled(),
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

    private function ensureCartTaxesForPkp($cartItems): void
    {
        if (! $this->isPkpEnabled()) {
            return;
        }

        foreach ($cartItems as $item) {
            $taxId = $item->options['product_tax'] ?? null;
            if (empty($taxId)) {
                throw ValidationException::withMessages([
                    'cart' => "Produk '{$item->name}' wajib memilih pajak karena bisnis PKP.",
                ]);
            }
        }
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

    private function applyPaymentTermSelection(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->payment_term = $paymentTermId ?: null;

        if (! $this->suppressAutoDueDate) {
            if ($this->isCustomPaymentTerm($this->payment_term)) {
                $this->dueDateIsManual = true;
            } else {
                $this->dueDateIsManual = false;
                $this->updateDueDateFromPaymentTerm();
            }
        }

        if ($syncDropdown) {
            $this->dispatch('setPaymentTerm', $this->payment_term)
                ->to(PaymentTermSearchDropdown::class);
        }
    }

    public function updatedPaymentTerm($value): void
    {
        $paymentTermId = $this->normalizePaymentTermId($value);

        if ($this->suppressAutoDueDate) {
            $this->payment_term = $paymentTermId;
            return;
        }

        $this->applyPaymentTermSelection($paymentTermId);
    }

    public function handlePaymentTermChanged($paymentTermId = null): void
    {
        $normalizedTermId = $this->normalizePaymentTermId($paymentTermId);

        if ($this->suppressAutoDueDate) {
            $this->payment_term = $normalizedTermId;
            return;
        }

        $this->applyPaymentTermSelection($normalizedTermId);
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $previousDueDate = $this->due_date;
        $nextDueDate = $this->date;

        $termId = $this->payment_term ? (int) $this->payment_term : null;
        if (! $termId || ! $this->date) {
            $this->due_date = $nextDueDate;
            if ($previousDueDate !== $this->due_date) {
                $this->bumpDueDateRenderVersion();
            }
            return;
        }

        $term = PaymentTerm::find($termId);
        if (! $term) {
            $this->due_date = $nextDueDate;
            if ($previousDueDate !== $this->due_date) {
                $this->bumpDueDateRenderVersion();
            }
            return;
        }

        $nextDueDate = Carbon::parse($this->date)
            ->addDays($term->longevity)
            ->format('Y-m-d');

        $this->due_date = $nextDueDate;
        if ($previousDueDate !== $this->due_date) {
            $this->bumpDueDateRenderVersion();
        }
    }

    public function updatedDate($value): void
    {
        if ($this->dueDateIsManual) {
            return;
        }

        $this->updateDueDateFromPaymentTerm();
    }

    public function updatedSupplierId($value): void
    {
        $this->handleSupplierSelected($value);
    }

    public function handleSupplierSelected($supplier_id): void
    {
        $supplierId = $supplier_id ?: null;
        $this->supplier_id = $supplierId;

        $paymentTermId = $this->resolveDefaultPaymentTermId();
        if ($supplierId) {
            $supplier = Supplier::find($supplierId);
            if ($supplier?->payment_term_id) {
                $paymentTermId = (int) $supplier->payment_term_id;
            }
        }

        $this->applyPaymentTermSelection($paymentTermId, true);
    }

    public function updatedDueDate($value): void
    {
        $this->dueDateIsManual = true;

        $customTermId = PaymentTerm::customTermId();
        if ($customTermId && (int) $this->payment_term !== $customTermId) {
            $this->suppressAutoDueDate = true;
            try {
                $this->payment_term = $customTermId;
                $this->dispatch('setPaymentTerm', $customTermId)
                    ->to(PaymentTermSearchDropdown::class);
            } finally {
                $this->suppressAutoDueDate = false;
            }
        }
    }

    public function restoreCart(): void
    {
        Cart::instance('purchase')->destroy();
        $cart = Cart::instance('purchase');

        foreach ($this->purchase->purchaseDetails as $detail) {
            $subTotal = (float) $detail->sub_total;
            $taxAmount = (float) ($detail->product_tax_amount ?? 0);
            $subTotalBeforeTax = max(0, $subTotal - $taxAmount);

            $discountInput = $detail->product_discount_amount;
            if ($detail->product_discount_type === 'percentage') {
                $discountInput = $detail->price > 0 ? ($detail->product_discount_amount / $detail->price) * 100 : 0;
            }

            $cart->add([
                'id' => $detail->product_id,
                'name' => $detail->product_name,
                'qty' => $detail->quantity,
                'price' => $detail->price,
                'weight' => 1,
                'options' => [
                    'product_discount' => $detail->product_discount_amount,
                    'product_discount_input' => round($discountInput, 2),
                    'product_discount_type' => $detail->product_discount_type,
                    'sub_total' => $detail->sub_total,
                    'code' => $detail->product_code,
                    'stock' => $detail->product->product_quantity ?? 0,
                    'product_tax' => $detail->tax_id,
                    'unit_price' => $detail->unit_price,
                    'sub_total_before_tax' => $subTotalBeforeTax,
                ]
            ]);
        }
    }

    public function handleShippingUpdated($shipping)
    {
        $this->shipping = $shipping;
    }

    public function handleGlobalDiscountUpdated($discount)
    {
        $this->global_discount = $discount;
    }

    public function handleGlobalDiscountTypeUpdated($type): void
    {
        $this->global_discount_type = $type;
    }

    public function handleTaxIncludedUpdated(bool $included)
    {
        $this->is_tax_included = $included;
    }

    public function handleSupplierCreated(array $supplier): void
    {
        // Supplier was just created, the dropdown will auto-select it
        // We need to set the payment term from the newly created supplier
        $this->supplier_id = $supplier['id'] ?? null;
        $paymentTermId = isset($supplier['payment_term_id']) && $supplier['payment_term_id']
            ? (int) $supplier['payment_term_id']
            : $this->resolveDefaultPaymentTermId();

        $this->applyPaymentTermSelection($paymentTermId, true);
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
            'purchase_status' => $this->purchase?->status,
            'payment_status' => $this->purchase?->payment_status,
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
        }

        // Fallback: Re-sync payment_term from supplier if still not set
        if ($this->supplier_id && !$this->payment_term) {
            $supplier = Supplier::find($this->supplier_id);
            $paymentTermId = $supplier?->payment_term_id
                ? (int) $supplier->payment_term_id
                : $this->resolveDefaultPaymentTermId();
            $this->applyPaymentTermSelection($paymentTermId);

            $this->purchaseSubmitDebug('purchase.submit.payment_term_fallback_applied', [
                'supplier_id' => $this->supplier_id,
                'resolved_payment_term_id' => $paymentTermId,
            ]);
        }

        $failureStage = 'before_validation';
        $purchase = null;
        $transactionStarted = false;

        try {
            $purchase = $this->purchase;

            $this->purchaseSubmitDebug('purchase.submit.before_validation', [
                'hidden_supplier_arg' => $rawSupplierArg,
                'hidden_payment_term_arg' => $rawPaymentTermArg,
            ]);

            $failureStage = 'validation';
            $this->validate([
                'supplier_id' => 'required|exists:suppliers,id',
                'supplier_purchase_number' => 'nullable|string|max:255|unique:purchases,supplier_purchase_number,' . $this->purchaseId . ',id,setting_id,' . session('setting_id'),
                'tax_ref_no' => 'nullable|string|max:255|unique:purchases,tax_ref_no,' . $this->purchaseId . ',id,setting_id,' . session('setting_id'),
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
            $cartItems = $cart->content();
            $this->ensureCartTaxesForPkp($cartItems);

            $failureStage = 'db_transaction_begin';
            DB::beginTransaction();
            $transactionStarted = true;
            $this->purchaseSubmitDebug('purchase.submit.transaction_begin');

            $purchase = $this->purchase; // already loaded in mount()

            $failureStage = 'calculating_totals';
            $total_sub_total = $cartItems->sum(fn($item) => $item->options['sub_total']);
            $shipping = (float) $this->shipping;
            $globalDiscount = is_numeric($this->global_discount) ? (float) $this->global_discount : 0;
            $discount_amount = $this->global_discount_type === 'fixed' ? $globalDiscount : 0;
            $discount_percentage = $this->global_discount_type === 'percentage' ? $globalDiscount : 0;
            $tax_amount = 0;

            foreach ($cartItems as $item) {
                $sub_total = $item->options['sub_total'] ?? 0;
                $sub_total_before_tax = $item->options['sub_total_before_tax'] ?? 0;
                $tax_amount += ($sub_total - $sub_total_before_tax);
            }

            if ($discount_percentage > 0) {
                $global_discount_amount = $total_sub_total * ($discount_percentage/100);
            } else {
                $global_discount_amount = $discount_amount;
            }

            $total_amount = $total_sub_total - $global_discount_amount + $shipping;

            $supplierPurchaseNumber = $this->supplier_purchase_number ?: null;
            $taxRefNo = $this->tax_ref_no ?: null;

            $failureStage = 'purchase_update';
            $purchase->update([
                'date' => $this->date,
                'due_date' => $this->due_date,
                'discount_percentage' => $discount_percentage,
                'discount_amount' => $discount_amount,
                'shipping_amount' => $shipping,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'due_amount' => $total_amount,
                'is_tax_included' => $this->is_tax_included,
                'supplier_id' => $this->supplier_id,
                'supplier_purchase_number' => $supplierPurchaseNumber,
                'tax_ref_no' => $taxRefNo,
                'note' => $this->note,
                'payment_term_id' => $this->payment_term,
            ]);

            $this->purchaseSubmitInfo('purchase.submit.purchase_updated', [
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'payment_term_id' => $purchase->payment_term_id,
                'status' => $purchase->status,
                'total_amount' => (float) $purchase->total_amount,
                'tax_amount' => (float) $purchase->tax_amount,
            ]);

            $failureStage = 'tags_sync';
            $this->purchase->syncTags($this->tags);
            $this->purchaseSubmitDebug('purchase.submit.tags_synced', [
                'purchase_id' => $purchase->id,
                'tag_count' => count($this->tags),
            ]);

            // Remove old details
            $failureStage = 'details_delete';
            $deletedDetailCount = (int) $purchase->purchaseDetails()->delete();
            $this->purchaseSubmitDebug('purchase.submit.details_deleted', [
                'purchase_id' => $purchase->id,
                'deleted_detail_count' => $deletedDetailCount,
            ]);

            // Re-add from cart
            $failureStage = 'details_recreate';
            $detailCount = 0;
            $detailQuantityTotal = 0;
            $detailTaxTotal = 0.0;
            foreach ($cartItems as $item) {
                $product_tax_amount = $item->options['sub_total'] - ($item->options['sub_total_before_tax'] ?? 0);

                $purchase->purchaseDetails()->create([
                    'product_id' => $item->id,
                    'product_name' => $item->name,
                    'product_code' => $item->options['code'],
                    'quantity' => $item->qty,
                    'unit_price' => $item->options['unit_price'],
                    'price' => $item->price,
                    'product_discount_type' => $item->options['product_discount_type'],
                    'product_discount_amount' => $item->options['product_discount'],
                    'sub_total' => $item->options['sub_total'],
                    'product_tax_amount' => $product_tax_amount,
                    'tax_id' => $item->options['product_tax'],
                ]);

                $detailCount++;
                $detailQuantityTotal += (int) $item->qty;
                $detailTaxTotal += (float) $product_tax_amount;
            }

            $this->purchaseSubmitDebug('purchase.submit.details_recreated', [
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
            Cart::instance('purchase')->destroy();

            session()->flash('success', 'Pembelian berhasil diperbarui.');
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
                'purchase_id' => $purchase?->id ?? $this->purchaseId,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]));

            session()->flash('error', 'Gagal memperbarui pembelian. Silakan coba lagi.');
            return;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase.edit-form', [
            'supplierIdForView' => $this->supplier_id === null ? '' : (string) $this->supplier_id,
            'paymentTermForView' => $this->payment_term === null ? '' : (string) $this->payment_term,
            'dueDateForView' => $this->due_date ?? '',
        ]);
    }
}

<?php

namespace App\Livewire\Purchase;

use App\Services\IdempotencyService;
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
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Setting\Entities\Setting;
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

    public bool $dueDateIsManual = false;
    public bool $suppressAutoDueDate = false;
    public int $dueDateRenderVersion = 0;

    public function mount(string $idempotencyToken, ?int $duplicateId = null): void
    {
        $this->idempotencyToken = $idempotencyToken;
        $this->isPkp = $this->isPkpEnabled();
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

    private function syncPaymentTermAndDueDate(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->applyPaymentTermSelection($paymentTermId, $syncDropdown);
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
        Log::info('DEBUG: applyPaymentTermSelection start', ['paymentTermId' => $paymentTermId, 'syncDropdown' => $syncDropdown]);
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
        $settingId = (int) session('setting_id');
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

        foreach ($cartItems as $index => $item) {
            $taxId = $item->options['product_tax'] ?? null;
            if (empty($taxId)) {
                throw ValidationException::withMessages([
                    'payment_term' => 'Semua produk wajib memilih pajak karena bisnis PKP.',
                ]);
            }
        }
    }

    public function updatedPaymentTerm($value): void
    {
        Log::info('DEBUG: updatedPaymentTerm called', ['value' => $value]);
        $paymentTermId = $this->normalizePaymentTermId($value);
        if ($this->suppressAutoDueDate) {
            $this->payment_term = $paymentTermId;
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
            return;
        }

        $this->applyPaymentTermSelection($normalizedTermId);
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        Log::info('DEBUG: updateDueDateFromPaymentTerm start', [
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
            Log::info('DEBUG: updateDueDateFromPaymentTerm abort: missing termId or date');
            return;
        }

        $term = PaymentTerm::find($termId);
        if (! $term) {
            $this->due_date = $nextDueDate;
            if ($previousDueDate !== $this->due_date) {
                $this->bumpDueDateRenderVersion();
            }
            Log::info('DEBUG: updateDueDateFromPaymentTerm abort: PaymentTerm not found', ['termId' => $termId]);
            return;
        }

        $nextDueDate = Carbon::parse($this->date)
            ->addDays($term->longevity)
            ->format('Y-m-d');

        $this->due_date = $nextDueDate;
        if ($previousDueDate !== $this->due_date) {
            $this->bumpDueDateRenderVersion();
        }
            
        Log::info('DEBUG: updateDueDateFromPaymentTerm success', ['new_due_date' => $this->due_date, 'longevity' => $term->longevity]);
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
                $this->dispatch('setPaymentTerm', $customTermId)
                    ->to(PaymentTermSearchDropdown::class);
            } finally {
                $this->suppressAutoDueDate = false;
            }
        }
    }


    public function updatedSupplierId($value): void
    {
        Log::info('DEBUG: updatedSupplierId called', ['value' => $value]);
        $this->handleSupplierSelected($value);
    }

    #[On('supplierSelected')]
    public function handleSupplierSelected($supplier_id): void
    {
        Log::info('DEBUG: handleSupplierSelected called', ['supplier_id' => $supplier_id]);
        $supplierId = $this->normalizeSupplierId($supplier_id);
        $this->supplier_id = $supplierId;

        // Default to COD
        $paymentTermId = $this->resolveDefaultPaymentTermId();

        if ($supplierId) {
            $supplier = Supplier::find($supplierId);
            // If supplier has a specific valid payment term, use it
            if ($supplier && !empty($supplier->payment_term_id)) {
                $paymentTermId = (int) $supplier->payment_term_id;
            }
        }

        $this->applyPaymentTermSelection($paymentTermId, true);
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
        $this->supplier_id = $supplier['id'] ?? null;
        $paymentTermId = isset($supplier['payment_term_id']) && $supplier['payment_term_id']
            ? (int) $supplier['payment_term_id']
            : $this->resolveDefaultPaymentTermId();

        $this->applyPaymentTermSelection($paymentTermId, true);
    }

    private function prefillFromPurchase(int $purchaseId): void
    {
        $purchase = Purchase::with(['purchaseDetails.product', 'tags'])->withArchived()->find($purchaseId);
        if (! $purchase || (int) $purchase->setting_id !== (int) session('setting_id')) {
            return;
        }

        $this->duplicatePurchase = $purchase;
        $this->supplier_id = $purchase->supplier_id;
        $this->payment_term = $purchase->payment_term_id;
        $this->note = $purchase->note;
        $this->date = Carbon::parse($purchase->date)->format('Y-m-d');
        $this->due_date = Carbon::parse($purchase->due_date)->format('Y-m-d');
        $this->dueDateIsManual = $this->isCustomPaymentTerm($this->payment_term ? (int) $this->payment_term : null);
        $this->tags = $purchase->tags->pluck('name')->toArray();
        $this->supplier_purchase_number = null;
        $this->tax_ref_no = null;

        if ($purchase->discount_percentage > 0) {
            $this->global_discount = $purchase->discount_percentage;
        } elseif ($purchase->discount_amount > 0) {
            $this->global_discount = $purchase->discount_amount;
        } else {
            $this->global_discount = 0;
        }

        $this->shipping = $purchase->shipping_amount ?? 0;
        $this->is_tax_included = (bool) $purchase->is_tax_included;

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

            $cart->add([
                'id' => $detail->product_id,
                'name' => $detail->product_name,
                'qty' => $detail->quantity,
                'price' => $detail->price,
                'weight' => 1,
                'options' => [
                    'product_discount' => $detail->product_discount_amount,
                    'product_discount_type' => $detail->product_discount_type,
                    'sub_total' => $detail->sub_total,
                    'sub_total_before_tax' => $subTotalBeforeTax,
                    'code' => $detail->product_code,
                    'stock' => $product?->product_quantity ?? 0,
                    'unit' => $product?->product_unit ?? '',
                    'product_tax' => $detail->tax_id,
                    'unit_price' => $detail->unit_price,
                    'last_purchase_price' => $product?->last_purchase_price ?? null,
                    'average_purchase_price' => $product?->average_purchase_price ?? null,
                ],
            ]);
        }
    }

    /**
     * @throws Throwable
     */
    public function submit(?string $supplierId = null, ?string $paymentTermId = null)
    {
        // Use passed values from hidden inputs if available (bypasses broken wire:model binding)
        if ($supplierId !== null && $supplierId !== '') {
            $this->supplier_id = $supplierId;
        }
        if ($paymentTermId !== null && $paymentTermId !== '') {
            $this->payment_term = $paymentTermId;
        }
        
        // Fallback: Re-sync payment_term from supplier if still not set
        if ($this->supplier_id && !$this->payment_term) {
            $supplier = Supplier::find($this->supplier_id);
            $paymentTermId = $supplier?->payment_term_id ? (int) $supplier->payment_term_id : $this->resolveDefaultPaymentTermId();
            $this->applyPaymentTermSelection($paymentTermId);
        }

        $purchase = null;

        try {
            $this->validate([
                'supplier_id' => 'required|exists:suppliers,id',
                'supplier_purchase_number' => 'nullable|string|max:255|unique:purchases,supplier_purchase_number,NULL,id,setting_id,' . session('setting_id'),
                'tax_ref_no' => 'nullable|string|max:255|unique:purchases,tax_ref_no,NULL,id,setting_id,' . session('setting_id'),
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

            $cart = Cart::instance('purchase');

            if ($cart->count() === 0) {
                $this->dispatch('notify', ['type' => 'error', 'message' => 'Produk harus dipilih']);
                return;
            }

            $this->ensureCartTaxesForPkp($cart->content());

            if (! IdempotencyService::claim($this->idempotencyToken, 'purchases.store', auth()->id())) {
                session()->flash('error', 'Permintaan pembelian sudah diproses. Silakan tunggu sebelum mencoba lagi.');
                return;
            }

            DB::beginTransaction();

            $setting_id = session('setting_id');

            // Global discount and tax calculations
            $cartItems = $cart->content();
            $total_sub_total = $cartItems->sum(fn($item) => $item->options['sub_total']);
            $shipping = $this->shipping;
            $discount_amount = $this->global_discount > 100 ? $this->global_discount : 0;
            $discount_percentage = $this->global_discount > 100 ? 0 : $this->global_discount;
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

            $purchase = Purchase::create([
                'date' => $this->date,
                'due_date' => $this->due_date,
                'supplier_id' => $this->supplier_id,
                'supplier_purchase_number' => $this->supplier_purchase_number ?: null,
                'tax_ref_no' => $this->tax_ref_no ?: null,
                'discount_percentage' => $discount_percentage,
                'discount_amount' => $discount_amount,
                'shipping_amount' => $shipping,
                'tax_id' => null,
                'tax_percentage' => 0,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'due_amount' => $total_amount,
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_term_id' => $this->payment_term,
                'note' => $this->note,
                'setting_id' => $setting_id,
                'paid_amount' => 0.0,
                'is_tax_included' => $this->is_tax_included,
                'payment_method' => '',
            ]);

            Log::info('Purchase Submit', [
                'tags' => $this->tags,
            ]);
            $purchase->syncTags($this->tags);

            foreach ($cartItems as $item) {
                $product_tax_amount = $item->options['sub_total'] - ($item->options['sub_total_before_tax'] ?? 0);

                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
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
            }

            DB::commit();
            $cart->destroy();

            session()->flash('success', 'Pembelian Ditambahkan!');
            return redirect()->route('purchases.index');

        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('Livewire Purchase Store Failed: ' . $e->getMessage());

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

<?php

namespace App\Livewire\Sale;

use App\Services\EffectiveDocumentBusinessResolver;
use App\Services\IdempotencyService;
use Carbon\Carbon;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Setting;

class CreateForm extends Component
{
    public $reference;
    public $customerId;
    public $customerName;
    public $date;
    public $dueDate;
    public $paymentTermId;
    public $paymentTerms = [];
    public $note;
    public $tax_ref_no;
    public $shipping = 0;
    public $global_discount = 0;
    public string $global_discount_type = 'percentage';
    public bool $is_tax_included = false;
    public array $tags = [];
    public string $idempotencyToken;
    public bool $isPkp = false;
    public ?int $selectedSettingId = null;
    public bool $dueDateIsManual = false;
    public bool $suppressAutoDueDate = false;
    public int $dueDateRenderVersion = 0;

    protected $listeners = [
        'customerSelected' => 'handleCustomerSelected',
        'confirmSubmit' => 'submit',
        'tagsUpdated' => 'handleTagsUpdated',
        'payment-term-changed' => 'handlePaymentTermChanged',
        'shippingUpdated' => 'handleShippingUpdated',
        'globalDiscountUpdated' => 'handleGlobalDiscountUpdated',
        'globalDiscountTypeUpdated' => 'handleGlobalDiscountTypeUpdated',
        'taxIncludedUpdated' => 'handleTaxIncludedUpdated',
        'business-selector-changed' => 'handleBusinessSelectorChanged',
    ];

    public function mount(string $idempotencyToken)
    {
        $this->idempotencyToken = $idempotencyToken;
        $this->selectedSettingId = (int) session('setting_id');
        $this->isPkp = $this->isPkpEnabled();
        $this->is_tax_included = $this->isPkp;
        $this->reference = 'SL';
        $this->date = now()->format('Y-m-d');
        $this->dueDate = now()->format('Y-m-d');
        $this->paymentTerms = PaymentTerm::all();
        $this->tax_ref_no = null;
        $this->syncPaymentTermAndDueDate($this->resolveDefaultPaymentTermId());
    }

    public function handleTagsUpdated(array $tags): void
    {
        $this->tags = $tags;
    }

    public function handleBusinessSelectorChanged(?int $settingId): void
    {
        if ($settingId === null) {
            $this->selectedSettingId = (int) session('setting_id');
        } else {
            $this->selectedSettingId = $settingId;
        }
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

        $cart = Cart::instance('sale');
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

    private function applyPaymentTermSelection(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->paymentTermId = $paymentTermId ?: null;

        if (! $this->suppressAutoDueDate) {
            if ($this->isCustomPaymentTerm($this->paymentTermId)) {
                $this->dueDateIsManual = true;
            } else {
                $this->dueDateIsManual = false;
                $this->updateDueDateFromPaymentTerm();
            }
        }

        if ($syncDropdown) {
            $this->dispatch('setPaymentTerm', $this->paymentTermId)
                ->to(PaymentTermSearchDropdown::class);
        }
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

        foreach ($cartItems as $item) {
            $taxId = $item->options['product_tax'] ?? null;
            if (empty($taxId)) {
                $this->dispatch('notify', [
                    'type'    => 'error',
                    'message' => 'Semua produk wajib memilih pajak karena bisnis PKP.'
                ]);

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'customerId' => 'Semua produk wajib memilih pajak karena bisnis PKP.',
                ]);
            }

            $tax = Tax::query()->find($taxId);

            if (!$tax) {
                $this->dispatch('notify', [
                    'type'    => 'error',
                    'message' => "Pajak untuk produk '{$item->name}' tidak valid."
                ]);

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'cart' => "Pajak untuk produk '{$item->name}' tidak valid.",
                ]);
            }
        }
    }

    public function handleCustomerSelected($customer): void
    {
        if (is_object($customer) && method_exists($customer, 'toArray')) {
            $customer = $customer->toArray();
        } elseif (!is_array($customer)) {
            $customerModel = Customer::find(is_numeric($customer) ? (int) $customer : null);
            $customer = $customerModel?->toArray();
        }

        if (! $customer || ! isset($customer['id'])) {
            return;
        }

        // Handle the customer selection from CustomerSearchDropdown
        $this->customerId = $customer['id'] ?? null;
        $this->customerName = $customer['customer_name'] ?? $customer['contact_name'] ?? null;
        
        // Sync payment term from the selected customer
        $paymentTermId = isset($customer['payment_term_id']) && $customer['payment_term_id']
            ? (int) $customer['payment_term_id']
            : $this->resolveDefaultPaymentTermId();
        $this->applyPaymentTermSelection($paymentTermId, true);
    }

    public function updatedCustomerId($value): void
    {
        $customerId = $value ?: null;
        $this->customerId = $customerId;

        $paymentTermId = $this->resolveDefaultPaymentTermId();
        if ($customerId) {
            $customer = Customer::find($customerId);
            $this->customerName = $customer?->customer_name ?? $customer?->contact_name;
            if ($customer?->payment_term_id) {
                $paymentTermId = (int) $customer->payment_term_id;
            }
        }

        $this->applyPaymentTermSelection($paymentTermId, true);
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $previousDueDate = $this->dueDate;
        $this->dueDate = $this->date;

        $termId = $this->paymentTermId ? (int) $this->paymentTermId : null;
        if (! $termId || ! $this->date) {
            if ($previousDueDate !== $this->dueDate) {
                $this->bumpDueDateRenderVersion();
            }
            return;
        }

        $term = PaymentTerm::find($termId);
        if (! $term) {
            if ($previousDueDate !== $this->dueDate) {
                $this->bumpDueDateRenderVersion();
            }
            return;
        }

        $this->dueDate = Carbon::parse($this->date)
            ->addDays($term->longevity)
            ->format('Y-m-d');

        if ($previousDueDate !== $this->dueDate) {
            $this->bumpDueDateRenderVersion();
        }
    }

    private function bumpDueDateRenderVersion(): void
    {
        $this->dueDateRenderVersion++;
    }

    public function updatedPaymentTermId($value): void
    {
        $paymentTermId = $this->normalizePaymentTermId($value);

        if ($this->suppressAutoDueDate) {
            $this->paymentTermId = $paymentTermId;
            return;
        }

        $this->applyPaymentTermSelection($paymentTermId);
    }

    public function handlePaymentTermChanged($paymentTermId = null): void
    {
        $normalizedTermId = $this->normalizePaymentTermId($paymentTermId);

        if ($this->suppressAutoDueDate) {
            $this->paymentTermId = $normalizedTermId;
            return;
        }

        $this->applyPaymentTermSelection($normalizedTermId);
    }

    public function updatedDueDate($value): void
    {
        $this->dueDateIsManual = true;

        $customTermId = PaymentTerm::customTermId();
        if ($customTermId && (int) $this->paymentTermId !== $customTermId) {
            $this->suppressAutoDueDate = true;
            try {
                $this->paymentTermId = $customTermId;
                $this->dispatch('setPaymentTerm', $customTermId)
                    ->to(PaymentTermSearchDropdown::class);
            } finally {
                $this->suppressAutoDueDate = false;
            }
        }
    }

    public function updatedDate($value): void
    {
        if ($this->dueDateIsManual) {
            return;
        }
        $this->updateDueDateFromPaymentTerm();
    }

    public function submit(?string $customerId = null, ?string $paymentTermId = null)
    {
        Log::info('Sale create submit called', [
            'customerId_param' => $customerId,
            'paymentTermId_param' => $paymentTermId,
            'customerId_state' => $this->customerId,
            'paymentTermId_state' => $this->paymentTermId,
        ]);

        $this->dispatch('sale:submit-start');

        // Use passed values from hidden inputs if available
        if ($customerId !== null && $customerId !== '') {
            $this->customerId = $customerId;
        }
        if ($paymentTermId !== null && $paymentTermId !== '') {
            $this->paymentTermId = $paymentTermId;
        }

        // Fallback: Re-sync payment_term from customer if still not set
        if ($this->customerId && !$this->paymentTermId) {
            $customer = Customer::find($this->customerId);
            $paymentTermId = $customer?->payment_term_id ? (int) $customer->payment_term_id : $this->resolveDefaultPaymentTermId();
            $this->applyPaymentTermSelection($paymentTermId);
        }

        $failureStage = 'before_validation';
        $sale = null;

        try {
            Log::info('Sale create validating', [
                'customerId' => $this->customerId,
                'paymentTermId' => $this->paymentTermId,
                'date' => $this->date,
                'dueDate' => $this->dueDate,
            ]);

            $failureStage = 'authorization_check';
            $resolvedBusiness = app(EffectiveDocumentBusinessResolver::class)
                ->resolve($this->selectedSettingId);

            $failureStage = 'validation';
            $this->validate([
                'customerId'     => 'required|exists:customers,id',
                'date'           => 'required|date',
                'dueDate'        => 'required|date|after_or_equal:date',
                'paymentTermId'  => 'required|exists:payment_terms,id',
                'note'           => 'nullable|string|max:1000',
                'tax_ref_no'     => 'nullable|string|max:255',
            ], [
                'customerId.required'   => 'Pilih pelanggan terlebih dahulu.',
                'customerId.exists'     => 'Pelanggan tidak valid.',
                'paymentTermId.required' => 'Pilih term pembayaran terlebih dahulu.',
                'paymentTermId.exists'  => 'Term pembayaran yang dipilih tidak valid.',
                'dueDate.after_or_equal'=> 'Tanggal jatuh tempo harus ≥ tanggal jual.',
                'tax_ref_no.max'        => 'Nomor Faktur Pajak maksimal 255 karakter.',
            ]);

            $failureStage = 'cart_check';
            if (Cart::instance('sale')->count() === 0) {
                Log::warning('Sale create aborted: empty cart');
                $this->dispatch('notify', [
                    'type'    => 'error',
                    'message' => 'Produk harus dipilih.'
                ]);
                return;
            }

            $failureStage = 'ensure_cart_taxes_for_pkp';
            $cartItems = Cart::instance('sale')->content();
            $this->ensureCartTaxesForPkp($cartItems);

            $failureStage = 'idempotency_claim';
            if (! IdempotencyService::claim($this->idempotencyToken, 'sales.store', auth()->id())) {
                Log::warning('Sale create idempotency claim failed', [
                    'token' => $this->idempotencyToken,
                    'user' => auth()->id(),
                ]);
                session()->flash('error', 'Permintaan penjualan sudah diproses. Silakan tunggu sebelum mencoba lagi.');
                return;
            }

            $failureStage = 'calculating_totals';
            $shipping = (float) $this->shipping;
            $globalDiscount = (float) $this->global_discount;
            $discountAmount = $this->global_discount_type === 'fixed' ? $globalDiscount : 0.0;
            $discountPercentage = $this->global_discount_type === 'percentage' ? $globalDiscount : 0.0;

            $setting_id = $resolvedBusiness['setting_id'];
            $isPkp = $resolvedBusiness['is_pkp'];

            $data = [
                'date'               => $this->date,
                'due_date'           => $this->dueDate,
                'customer_id'        => $this->customerId,
                'tax_id'             => null,
                'tax_percentage'     => 0,
                'discount_percentage'=> $discountPercentage,
                'discount_amount'    => $discountAmount,
                'shipping_amount'    => $shipping,
                'status'             => Sale::STATUS_DRAFTED,
                'payment_status'     => 'Unpaid',
                'payment_term_id'    => $this->paymentTermId,
                'note'               => $this->note,
                'setting_id'         => $setting_id,
                'paid_amount'        => 0.0,
                'is_tax_included'    => $isPkp ? (bool) $this->is_tax_included : false,
                'payment_method'     => '',
                'tax_ref_no'         => $isPkp ? ($this->tax_ref_no ?: null) : null,
                'tags'               => $this->tags,
            ];

            $failureStage = 'sale_create';
            $saleService = app(SaleService::class);
            $sale = $saleService->createSale($data, $cartItems);

            $failureStage = 'commit';
            Cart::instance('sale')->destroy();
            $targetBusiness = Setting::find($setting_id);
            $targetBusinessName = $targetBusiness?->company_name ?? 'Unknown';
            session()->flash('success', "Penjualan '$sale->reference' untuk $targetBusinessName berhasil ditambahkan!");
            Log::info('Sale create completed', ['sale_id' => $sale->id, 'reference' => $sale->reference]);
            return redirect()->route('sales.index');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Sale create validation failed', [
                'failure_stage' => $failureStage,
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (Exception $e) {
            Log::error('Livewire Sale Create Failed: ' . $e->getMessage(), [
                'failure_stage' => $failureStage,
                'exception' => $e,
            ]);
            $this->dispatch('notify', [
                'type'    => 'error',
                'message' => str_replace("\n", '<br>', $e->getMessage())
            ]);
        } finally {
            $this->dispatch('sale:submit-finish');
        }
    }

    public function render()
    {
        return view('livewire.sale.create-form', [
            'dueDateForView' => $this->dueDate ?? '',
        ]);
    }

    public function handleShippingUpdated($shipping): void
    {
        $this->shipping = is_numeric($shipping) ? (float) $shipping : 0.0;
    }

    public function handleGlobalDiscountUpdated($discount): void
    {
        $this->global_discount = is_numeric($discount) ? (float) $discount : 0.0;
    }

    public function handleGlobalDiscountTypeUpdated($type): void
    {
        $this->global_discount_type = $type === 'fixed' ? 'fixed' : 'percentage';
    }

    public function handleTaxIncludedUpdated(bool $included): void
    {
        $this->is_tax_included = $included;
    }
}

<?php

namespace App\Livewire\Sale;

use Carbon\Carbon;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Services\SaleService;

class EditForm extends Component
{
    public Sale $sale;

    public $reference;
    public $customerId;
    public $customerName;
    public $date;
    public $dueDate;
    public $paymentTermId;
    public $paymentTerms = [];
    public $note;
    public $tax_ref_no;
    public bool $is_tax_included = false;
    public array $tags = [];
    public bool $isPkp = false;
    public bool $dueDateIsManual = false;
    public bool $suppressAutoDueDate = false;
    public int $dueDateRenderVersion = 0;

    protected $listeners = [
        'customerSelected' => 'handleCustomerSelected',
        'customerCreated' => 'handleCustomerCreated',
        'confirmUpdate'   => 'update',
        'tagsUpdated'     => 'handleTagsUpdated',
        'payment-term-changed' => 'handlePaymentTermChanged',
        'taxIncludedUpdated' => 'handleTaxIncludedUpdated',
    ];

    public function mount(Sale $sale)
    {
        $sale->loadMissing(['customer', 'tags', 'saleDetails.bundleItems', 'saleDetails.product']);

        // Rule: Partially or Fully Dispatched -> Hard Block
        if (in_array($sale->status, [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])) {
            abort(403, 'Tidak dapat mengubah penjualan yang sudah dikirim barangnya.');
        }

        // Rule: Approved -> Require explicit permission
        if ($sale->status === Sale::STATUS_APPROVED) {
            if (!auth()->user()->can('sales.approved.edit')) {
                abort(403, 'Anda tidak memiliki akses untuk mengubah penjualan yang sudah disetujui.');
            }
        }

        $this->sale          = $sale;
        $this->isPkp         = $this->isPkpEnabled();
        $this->reference     = $sale->reference;
        $this->customerId    = $sale->customer_id;
        $this->customerName  = $sale->customer?->customer_name;
        $this->date          = Carbon::parse($sale->date)->format('Y-m-d');
        $this->dueDate       = Carbon::parse($sale->due_date)->format('Y-m-d');
        $this->paymentTermId = $sale->payment_term_id;
        $this->dueDateIsManual = $this->isCustomPaymentTerm($this->paymentTermId ? (int) $this->paymentTermId : null);
        $this->note          = $sale->note;
        $this->tax_ref_no    = $sale->tax_ref_no;
        $this->is_tax_included = (bool) $sale->is_tax_included;
        $this->tags          = $sale->tags->pluck('name')->map(fn($n) => is_array($n) ? ($n['en'] ?? reset($n)) : $n)->toArray();
        $this->paymentTerms  = PaymentTerm::all();

        // Rebuild the cart from the existing sale details
        Cart::instance('sale')->destroy();

        foreach ($sale->saleDetails as $detail) {
            $product   = $detail->product;
            $stockData = $product
                ? ProductStock::where('product_id', $product->id)
                    ->selectRaw('SUM(quantity_non_tax) as quantity_non_tax, SUM(quantity_tax) as quantity_tax')
                    ->first()
                : null;

            $subtotalBeforeTax = round((float) ($detail->sub_total - $detail->product_tax_amount), 2);
            $normalizedTaxId = $this->isPkp ? $detail->tax_id : null;
            $normalizedTaxAmount = $this->isPkp ? (float) $detail->product_tax_amount : 0.0;
            $normalizedSubTotal = $this->isPkp ? (float) $detail->sub_total : $subtotalBeforeTax;

            // build the options *array*
            $options = [
                'product_id'             => $detail->product_id,
                'product_discount'       => $detail->product_discount_amount,
                'product_discount_type'  => $detail->product_discount_type,
                'sub_total'              => $normalizedSubTotal,
                'sub_total_before_tax'   => $subtotalBeforeTax,
                'product_tax_amount'     => $normalizedTaxAmount,
                'code'                   => $detail->product_code,
                'stock'                  => $product?->product_quantity ?? 0,
                'unit'                   => $product?->product_unit,
                'unit_price'             => $detail->unit_price,
                'product_tax'            => $normalizedTaxId,
                'sale_price'             => $product?->sale_price ?? $detail->unit_price,
                'tier_1_price'           => $product?->tier_1_price ?? $product?->sale_price ?? $detail->unit_price,
                'tier_2_price'           => $product?->tier_2_price ?? $product?->sale_price ?? $detail->unit_price,
                'quantity_non_tax'       => $stockData->quantity_non_tax ?? 0,
                'quantity_tax'           => $stockData->quantity_tax ?? 0,
                // bundles below
            ];

            $bundleItems = [];
            foreach ($detail->bundleItems as $b) {
                $bundleItems[] = [
                    'bundle_id'      => $b->bundle_id,
                    'bundle_item_id' => $b->bundle_item_id,
                    'product_id'     => $b->product_id,
                    'name'           => $b->name,
                    'price'          => $b->price,
                    'quantity_per_bundle' => $detail->quantity > 0 ? (float) ($b->quantity / $detail->quantity) : (float) $b->quantity,
                    'quantity'       => $b->quantity,
                    'sub_total'      => $b->sub_total,
                ];
            }
            $bundleTotal = (float) collect($bundleItems)->sum('sub_total');
            $normalizedUnitPrice = (float) $detail->unit_price;
            $normalizedPrice = (float) $detail->price;
            if (! $this->isPkp && $detail->quantity > 0) {
                $parentSubTotalBeforeTax = max(0, $subtotalBeforeTax - $bundleTotal);
                $normalizedUnitPrice = round(($parentSubTotalBeforeTax / $detail->quantity) + (float) $detail->product_discount_amount, 2);
                $normalizedPrice = $normalizedUnitPrice;
            }
            $options['bundle_items'] = $bundleItems;
            $options['bundle_price'] = $bundleTotal;
            $options['unit_price'] = $normalizedUnitPrice;

            // pass options as array, not object
            Cart::instance('sale')->add([
                'id'      => $detail->id,
                'name'    => $detail->product_name,
                'qty'     => $detail->quantity,
                'price'   => $normalizedPrice,
                'weight'  => 1,
                'options' => $options,
            ]);
        }
    }

    private function syncPaymentTermAndDueDate(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->applyPaymentTermSelection($paymentTermId, $syncDropdown);
    }

    private function resolveDefaultPaymentTermId(): ?int
    {
        return PaymentTerm::defaultCodTermId();
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

    public function handleCustomerCreated(array $customer): void
    {
        $this->customerId = $customer['id'] ?? null;
        $this->customerName = $customer['customer_name'] ?? $customer['contact_name'] ?? null;
        $paymentTermId = isset($customer['payment_term_id']) && $customer['payment_term_id']
            ? (int) $customer['payment_term_id']
            : $this->resolveDefaultPaymentTermId();

        $this->applyPaymentTermSelection($paymentTermId, true);
    }

    public function handleTagsUpdated(array $tags): void
    {
        $this->tags = $tags;
    }

    public function handleTaxIncludedUpdated(bool $included): void
    {
        $this->is_tax_included = $included;
    }

    private function ensureCartTaxesForPkp($cartItems): void
    {
        if (! $this->isPkpEnabled()) {
            return;
        }

        foreach ($cartItems as $item) {
            $taxId = $item->options['product_tax'] ?? null;
            if (empty($taxId)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'paymentTermId' => 'Semua produk wajib memilih pajak karena bisnis PKP.',
                ]);
            }
        }
    }

    public function update(?string $customerId = null, ?string $paymentTermId = null)
    {
        Log::info('Sale update called', [
            'sale_id' => $this->sale->id,
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
            $paymentTermId = $customer?->payment_term_id
                ? (int) $customer->payment_term_id
                : $this->resolveDefaultPaymentTermId();
            $this->applyPaymentTermSelection($paymentTermId);
        }

        try {
            Log::info('Sale update validating', [
                'sale_id' => $this->sale->id,
                'customerId' => $this->customerId,
                'paymentTermId' => $this->paymentTermId,
                'date' => $this->date,
                'dueDate' => $this->dueDate,
            ]);

            $this->validate([
                'customerId'    => 'required|exists:customers,id',
                'date'          => 'required|date',
                'dueDate'       => 'required|date|after_or_equal:date',
                'paymentTermId' => 'required|exists:payment_terms,id',
                'note'          => 'nullable|string|max:1000',
                'tax_ref_no'    => 'nullable|string|max:255',
            ], [
                'customerId.required'    => 'Pilih pelanggan terlebih dahulu.',
                'customerId.exists'      => 'Pelanggan tidak valid.',
                'paymentTermId.required' => 'Pilih term pembayaran terlebih dahulu.',
                'paymentTermId.exists'   => 'Term pembayaran yang dipilih tidak valid.',
                'dueDate.after_or_equal' => 'Tanggal jatuh tempo harus ≥ tanggal jual.',
                'tax_ref_no.max'         => 'Nomor Faktur Pajak maksimal 255 karakter.',
            ]);

            if (Cart::instance('sale')->count() === 0) {
                Log::warning('Sale update aborted: empty cart', ['sale_id' => $this->sale->id]);
                $this->dispatch('notify', [
                    'type'    => 'error',
                    'message' => 'Produk harus dipilih.',
                ]);
                return;
            }

            try {
                Log::info('Sale update persisting', [
                    'sale_id' => $this->sale->id,
                    'cart_count' => Cart::instance('sale')->count(),
                    'customerId' => $this->customerId,
                    'paymentTermId' => $this->paymentTermId,
                ]);

                $cartItems = Cart::instance('sale')->content();
                $this->ensureCartTaxesForPkp($cartItems);
                $data = [
                    'date' => $this->date,
                    'due_date' => $this->dueDate,
                    'reference' => $this->reference,
                    'customer_id' => $this->customerId,
                    'tax_id' => null,
                    'tax_percentage' => 0,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'paid_amount' => 0,
                    'status' => $this->sale->status,
                    'payment_term_id' => $this->paymentTermId,
                    'payment_method' => $this->sale->payment_method ?: 'Cash',
                    'note' => $this->note,
                    'tax_ref_no' => $this->tax_ref_no ?: null,
                    'is_tax_included' => (bool) $this->is_tax_included,
                    'tags' => $this->tags,
                ];

                app(SaleService::class)->updateSale($this->sale, $data, $cartItems);

                Cart::instance('sale')->destroy();
                session()->flash('success', 'Penjualan Diperbaharui!');
                Log::info('Sale update completed', ['sale_id' => $this->sale->id]);
                return redirect()->route('sales.index');
            } catch (Exception $e) {
                Log::error('Livewire Sale Update Failed: ' . $e->getMessage());
                session()->flash('error', 'Gagal memperbaharui penjualan. Silakan coba lagi.');
            }
        } finally {
            $this->dispatch('sale:submit-finish');
        }
    }

    private function isPkpEnabled(): bool
    {
        $settingId = (int) session('setting_id');
        if ($settingId <= 0) {
            return false;
        }

        return (bool) (\Modules\Setting\Entities\Setting::query()->whereKey($settingId)->value('is_pkp') ?? false);
    }

    public function render()
    {
        return view('livewire.sale.edit-form', [
            'dueDateForView' => $this->dueDate ?? '',
        ]);
    }
}

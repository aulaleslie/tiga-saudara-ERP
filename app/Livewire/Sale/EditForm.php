<?php

namespace App\Livewire\Sale;

use App\Services\EffectiveDocumentBusinessResolver;
use App\Services\MonetaryEdit\MonetaryEditException;
use Carbon\Carbon;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Services\SaleMonetaryEditService;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

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
    public ?int $selectedSettingId = null;
    public bool $dueDateIsManual = false;
    public bool $suppressAutoDueDate = false;
    public int $dueDateRenderVersion = 0;
    public $editMode;
    public ?string $lifecycleWarning = null;
    public bool $acknowledgeLifecycleWarning = false;

    /**
     * Editable header monetary values, hydrated from the Sale and kept in sync
     * with the product cart. These are the only header figures the restricted
     * post-dispatch mode may change.
     */
    public $global_discount = 0;
    public string $global_discount_type = 'percentage';
    public $shipping = 0;

    protected $listeners = [
        'customerSelected' => 'handleCustomerSelected',
        'confirmUpdate'   => 'update',
        'tagsUpdated'     => 'handleTagsUpdated',
        'payment-term-changed' => 'handlePaymentTermChanged',
        'taxIncludedUpdated' => 'handleTaxIncludedUpdated',
        'business-selector-changed' => 'handleBusinessSelectorChanged',
        'globalDiscountUpdated' => 'handleGlobalDiscountUpdated',
        'globalDiscountTypeUpdated' => 'handleGlobalDiscountTypeUpdated',
        'shippingUpdated' => 'handleShippingUpdated',
    ];

    public function handleGlobalDiscountUpdated($discount): void
    {
        $this->global_discount = $discount;
    }

    public function handleGlobalDiscountTypeUpdated($type): void
    {
        $this->global_discount_type = $type;
    }

    public function handleShippingUpdated($shipping): void
    {
        $this->shipping = $shipping;
    }

    public function mount(Sale $sale)
    {
        $sale->loadMissing(['customer', 'tags', 'saleDetails.bundleItems', 'saleDetails.product']);

        $editMode = $sale->resolveEditMode();
        if ($editMode === Sale::EDIT_MODE_NONE) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah penjualan ini pada status saat ini.');
        }
        $this->editMode = $editMode;

        $this->sale          = $sale;
        $this->selectedSettingId = $sale->setting_id;
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

        // Hydrate the editable header monetary values from the document, using
        // the same percentage-or-fixed convention as the product cart.
        $this->shipping = $sale->shipping_amount ?? 0;
        if ($sale->discount_percentage > 0) {
            $this->global_discount_type = 'percentage';
            $this->global_discount = $sale->discount_percentage;
        } elseif ($sale->discount_amount > 0) {
            $this->global_discount_type = 'fixed';
            $this->global_discount = $sale->discount_amount;
        } else {
            $this->global_discount_type = 'percentage';
            $this->global_discount = 0;
        }

        // Rebuild the cart from the existing sale details
        Cart::instance('sale')->destroy();

        foreach ($sale->saleDetails as $detail) {
            $product   = $detail->product;
            $stockData = $product
                ? ProductStock::where('product_id', $product->id)
                    ->selectRaw('SUM(quantity_non_tax) as quantity_non_tax, SUM(quantity_tax) as quantity_tax')
                    ->first()
                : null;

            // Edit-load hydration: stored monetary values are authoritative. Never
            // recompute the line total from price x quantity here.
            // A non-PKP context discards any stored tax, so the authoritative total
            // there is the stored DPP rather than the tax-inclusive total.
            $storedTax = $this->isPkp ? round((float) $detail->product_tax_amount, 2) : 0.0;
            $storedTotal = $this->isPkp
                ? round((float) $detail->sub_total, 2)
                : round((float) $detail->sub_total - (float) $detail->product_tax_amount, 2);
            $storedDpp = round($storedTotal - $storedTax, 2);
            $normalizedTaxId = $this->isPkp ? $detail->tax_id : null;

            $pricingSource = $detail->pricing_source ?? 'manual_unit_price';

            $pricingMetadata = $this->resolveSalePricingMetadata(
                (int) $detail->product_id,
                (float) $detail->unit_price
            );

            // build the options *array*
            $options = [
                'product_id'             => $detail->product_id,
                'product_discount'       => $detail->product_discount_amount,
                'product_discount_type'  => $detail->product_discount_type,
                'sub_total'              => $storedTotal,
                'sub_total_before_tax'   => $storedDpp,
                'product_tax_amount'     => $storedTax,
                'code'                   => $detail->product_code,
                'stock'                  => $product?->product_quantity ?? 0,
                'unit'                   => $product?->product_unit,
                'unit_price'             => $detail->unit_price,
                'product_tax'            => $normalizedTaxId,
                'sale_price'             => $pricingMetadata['sale_price'],
                'tier_1_price'           => $pricingMetadata['tier_1_price'],
                'tier_2_price'           => $pricingMetadata['tier_2_price'],
                'quantity_non_tax'       => $stockData->quantity_non_tax ?? 0,
                'quantity_tax'           => $stockData->quantity_tax ?? 0,
                'pricing_source'         => $pricingSource,
                // bundles below
            ];

            $bundleItems = [];
            foreach ($detail->bundleItems as $b) {
                // Task 3.2: Normalize hydrated component prices to non-billable 0.
                $bundleItems[] = [
                    'bundle_id'      => $b->bundle_id,
                    'bundle_item_id' => $b->bundle_item_id,
                    'product_id'     => $b->product_id,
                    'name'           => $b->name,
                    'price'          => 0.0,
                    'quantity_per_bundle' => $detail->quantity > 0 ? (float) ($b->quantity / $detail->quantity) : (float) $b->quantity,
                    'quantity'       => $b->quantity,
                    'sub_total'      => 0.0,
                ];
            }
            // Task 1.2/3.2: Bundle add-on price is now 0.0 for selected bundles.
            $bundleTotal = 0.0;

            $normalizedUnitPrice = (float) $detail->unit_price;
            $normalizedPrice = (float) $detail->price;
            if (! $this->isPkp && $detail->quantity > 0 && $pricingSource !== 'manual_line_total') {
                // Reversal logic still applies but bundleTotal is now 0 for new bundles.
                $parentSubTotalBeforeTax = max(0, $storedDpp - $bundleTotal);
                $normalizedUnitPrice = round(($parentSubTotalBeforeTax / $detail->quantity) + (float) $detail->product_discount_amount, 2);
                $normalizedPrice = $normalizedUnitPrice;
            }
            $options['bundle_items'] = $bundleItems;
            $options['bundle_price'] = $bundleTotal;
            $options['unit_price'] = $normalizedUnitPrice;

            // Task 1.3: Add stable metadata for bundled rows.
            if (!empty($bundleItems)) {
                $options['is_bundled_row'] = true;
                $options['bundle_id'] = $bundleItems[0]['bundle_id'];
            }

            // pass options as array, not object
            $cartItem = Cart::instance('sale')->add([
                'id'      => $detail->id,
                'name'    => $detail->product_name,
                'qty'     => $detail->quantity,
                'price'   => $normalizedPrice,
                'weight'  => 1,
                'options' => $options,
            ]);

            // Cart::add() may derive its own row total; restore the authoritative trio.
            Cart::instance('sale')->update($cartItem->rowId, [
                'options' => array_merge($cartItem->options->toArray(), [
                    'sub_total' => $storedTotal,
                    'sub_total_before_tax' => $storedDpp,
                    'product_tax_amount' => $storedTax,
                ]),
            ]);
        }

        // Evaluate bundle lifecycle for loaded items and notify if warnings exist
        $saleDetails = $sale->saleDetails->loadMissing('bundleItems');
        $evaluator = app(\Modules\Product\Services\BundleLifecycle\ProductBundleLifecycleEvaluator::class);
        $evalResult = $evaluator->evaluateSalesSnapshot($saleDetails, (int) $sale->setting_id);
        if ($evalResult->hasWarnings()) {
            $count = count($evalResult->warnings);
            $this->lifecycleWarning = "Terdapat {$count} paket produk dalam penjualan ini dengan perubahan status atau kedaluwarsa. Data tersimpan tetap dipertahankan.";
            session()->flash('warning', $this->lifecycleWarning);
            $this->dispatch('sale:initial-lifecycle-warning', [
                'message' => $this->lifecycleWarning,
                'items' => $evalResult->warnings,
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

    public function handleTagsUpdated(array $tags): void
    {
        $this->tags = $tags;
    }

    public function handleBusinessSelectorChanged(?int $settingId): void
    {
        if ($settingId === null || $settingId === $this->sale->setting_id) {
            $this->selectedSettingId = $this->sale->setting_id;
        } else {
            // Can only change business if draft
            if ($this->sale->status !== Sale::STATUS_DRAFTED) {
                return;
            }
            $this->selectedSettingId = $settingId;
        }
        $this->rehydrateTaxContext();
        // Dispatch context change to child components
        $this->dispatch('document-business-context-changed', $this->selectedSettingId);
    }

    public function updatedSelectedSettingId(): void
    {
        if ($this->sale->status !== Sale::STATUS_DRAFTED && $this->selectedSettingId !== $this->sale->setting_id) {
            // Revert if trying to change business on non-draft
            $this->selectedSettingId = $this->sale->setting_id;
            return;
        }
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

        // Resolve authority from the persisted record before any submitted
        // header value is applied, so a monetary-only save never even stages a
        // customer or payment-term change.
        $editMode = $this->sale->resolveEditMode();
        if ($editMode === Sale::EDIT_MODE_NONE) {
            $this->dispatch('sale:submit-finish');
            abort(403, 'Anda tidak memiliki akses untuk memperbarui penjualan ini pada status saat ini.');
        }
        if ($editMode === Sale::EDIT_MODE_MONETARY_ONLY) {
            return $this->submitMonetaryOnly();
        }

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

        $failureStage = 'before_validation';

        try {
            Log::info('Sale update validating', [
                'sale_id' => $this->sale->id,
                'customerId' => $this->customerId,
                'paymentTermId' => $this->paymentTermId,
                'date' => $this->date,
                'dueDate' => $this->dueDate,
            ]);

            $failureStage = 'authorization_check';
            $resolvedBusiness = app(EffectiveDocumentBusinessResolver::class)
                ->resolve($this->selectedSettingId);
            $businessChanged = $resolvedBusiness['setting_id'] !== $this->sale->setting_id;

            if ($businessChanged && $this->sale->status !== Sale::STATUS_DRAFTED) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'setting_id' => 'Bisnis hanya dapat diubah untuk dokumen yang masih dalam status draft.',
                ]);
            }

            $failureStage = 'validation';
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

            $failureStage = 'cart_check';
            if (Cart::instance('sale')->count() === 0) {
                Log::warning('Sale update aborted: empty cart', ['sale_id' => $this->sale->id]);
                $this->dispatch('notify', [
                    'type'    => 'error',
                    'message' => 'Produk harus dipilih.',
                ]);
                return;
            }

            $failureStage = 'ensure_cart_taxes_for_pkp';
            $cartItems = Cart::instance('sale')->content();
            $this->ensureCartTaxesForPkp($cartItems);

            // Evaluate bundle lifecycle on updated cart items snapshot
            $evaluator = app(\Modules\Product\Services\BundleLifecycle\ProductBundleLifecycleEvaluator::class);
            $evalResult = $evaluator->evaluateSalesSnapshot($cartItems, (int) $resolvedBusiness['setting_id']);
            if ($evalResult->hasWarnings() && ! $this->acknowledgeLifecycleWarning) {
                $this->dispatch('sale:submit-finish');
                $this->dispatch('sale:lifecycle-warning', [
                    'message' => 'Terdapat perubahan status pada paket produk dalam penjualan ini.',
                    'items' => $evalResult->warnings,
                ]);
                return;
            }

            $failureStage = 'calculating_totals';
            $isPkp = $resolvedBusiness['is_pkp'];
            $setting_id = $resolvedBusiness['setting_id'];

            $data = [
                'date' => $this->date,
                'due_date' => $this->dueDate,
                'customer_id' => $this->customerId,
                'tax_id' => null,
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'paid_amount' => $this->sale->paid_amount,
                'status' => $this->sale->status,
                'payment_term_id' => $this->paymentTermId,
                'payment_method' => $this->sale->payment_method ?: 'Cash',
                'note' => $this->note,
                'tax_ref_no' => $isPkp ? ($this->tax_ref_no ?: null) : null,
                'is_tax_included' => $isPkp ? (bool) $this->is_tax_included : false,
                'tags' => $this->tags,
            ];

            // If business changed, updateSale will handle atomic reference allocation and move
            if ($businessChanged) {
                $data['setting_id'] = $setting_id;
            }

            $failureStage = 'sale_update';
            $updatedSale = app(SaleService::class)->updateSale($this->sale, $data, $cartItems);
            $this->reference = $updatedSale->reference;

            $failureStage = 'commit';
            Cart::instance('sale')->destroy();
            $targetBusiness = Setting::find($setting_id);
            $targetBusinessName = $targetBusiness?->company_name ?? 'Unknown';
            $message = "Penjualan '$this->reference'";
            if ($businessChanged) {
                $message .= " dipindahkan ke $targetBusinessName";
            }
            $message .= " berhasil diperbarui!";
            session()->flash('success', $message);
            Log::info('Sale update completed', ['sale_id' => $this->sale->id, 'reference' => $this->reference]);
            return redirect()->route('sales.index');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Sale update validation failed', [
                'failure_stage' => $failureStage,
                'sale_id' => $this->sale->id,
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (Exception $e) {
            Log::error('Livewire Sale Update Failed: ' . $e->getMessage(), [
                'failure_stage' => $failureStage,
                'sale_id' => $this->sale->id,
                'exception' => $e,
            ]);
            session()->flash('error', 'Gagal memperbaharui penjualan. Silakan coba lagi.');
        } finally {
            $this->dispatch('sale:submit-finish');
        }
    }

    private function isPkpEnabled(): bool
    {
        $settingId = $this->selectedSettingId ?? (int) session('setting_id');
        if ($settingId <= 0) {
            return false;
        }

        return (bool) (Setting::query()->whereKey($settingId)->value('is_pkp') ?? false);
    }

    private function resolveSalePricingMetadata(int $productId, float $fallbackPrice): array
    {
        $priceRow = ProductPrice::query()
            ->forProduct($productId)
            ->forSetting((int) session('setting_id'))
            ->first();

        $salePrice = (float) ($priceRow?->sale_price ?? $fallbackPrice);
        $tier1Price = (float) ($priceRow?->tier_1_price ?? $salePrice);
        $tier2Price = (float) ($priceRow?->tier_2_price ?? $salePrice);

        return [
            'sale_price' => $salePrice,
            'tier_1_price' => $tier1Price > 0 ? $tier1Price : $salePrice,
            'tier_2_price' => $tier2Price > 0 ? $tier2Price : $salePrice,
        ];
    }

    /**
     * Post-dispatch save. Delegates to the single protected persistence path so
     * this never reaches SaleService::updateSale(), whose delete-and-recreate
     * branch would drop dispatch links and regenerate HPP cost snapshots.
     */
    private function submitMonetaryOnly()
    {
        Log::info('Sale update monetary-only started', ['sale_id' => $this->sale->id]);

        if (Cart::instance('sale')->count() === 0) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Produk tidak boleh kosong.']);
            $this->dispatch('sale:submit-finish');
            return;
        }

        $cartItems = Cart::instance('sale')->content();

        try {
            $this->ensureCartTaxesForPkp($cartItems);

            // Only monetary header inputs are passed. PKP status and the
            // tax-inclusive flag are derived server-side from the locked
            // document's own business, never from this component's state.
            app(SaleMonetaryEditService::class)->apply($this->sale, $cartItems, [
                'global_discount' => $this->global_discount,
                'global_discount_type' => $this->global_discount_type,
                'shipping' => $this->shipping,
            ]);
        } catch (MonetaryEditException $e) {
            Log::warning('sale.submit.monetary_only.rejected', [
                'sale_id' => $this->sale->id,
                'message' => $e->getMessage(),
            ]);
            $this->dispatch('notify', ['type' => 'error', 'message' => $e->getMessage()]);
            $this->dispatch('sale:submit-finish');
            throw \Illuminate\Validation\ValidationException::withMessages([$e->field() => $e->getMessage()]);
        } catch (Exception $e) {
            Log::error('sale.submit.monetary_only.exception', [
                'sale_id' => $this->sale->id,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Gagal memperbarui penjualan moneter.');
            $this->dispatch('sale:submit-finish');
            return;
        }

        $this->sale->refresh();
        Cart::instance('sale')->destroy();
        $this->dispatch('sale:submit-finish');

        session()->flash('success', "Pembaruan moneter penjualan '{$this->sale->reference}' berhasil.");
        return redirect()->route('sales.index');
    }

    public function render()
    {
        return view('livewire.sale.edit-form', [
            'dueDateForView' => $this->dueDate ?? '',
        ]);
    }
}

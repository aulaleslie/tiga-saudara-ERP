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
use Livewire\Component;
use Illuminate\Validation\ValidationException;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
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
    public $listeners = [
        'tagsUpdated' => 'handleTagsUpdated',
        'shippingUpdated'        => 'handleShippingUpdated',
        'globalDiscountUpdated'  => 'handleGlobalDiscountUpdated',
        'taxIncludedUpdated'    => 'handleTaxIncludedUpdated',

        'supplierCreated' => 'handleSupplierCreated',
    ];


    public $shipping = 0;
    public $global_discount = 0;
    public $is_tax_included = false;
    public string $idempotencyToken;

    public function mount(string $idempotencyToken, ?int $duplicateId = null): void
    {
        $this->idempotencyToken = $idempotencyToken;
        $this->reference = 'PR'; // This can be dynamic if needed
        $this->date = now()->format('Y-m-d');
        $this->due_date = now()->format('Y-m-d');
        $this->supplier_purchase_number = null;
        $this->tax_ref_no = null;
        // Ensure a fresh cart when starting a new purchase
        Cart::instance('purchase')->destroy();

        if ($duplicateId) {
            $this->prefillFromPurchase((int) $duplicateId);
        }
    }

    public function handleTagsUpdated(array $tags): void
    {
        $this->tags = $tags;
    }

    private function syncPaymentTermAndDueDate(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->payment_term = $paymentTermId ?: null;
        $this->updateDueDateFromPaymentTerm();

        if ($syncDropdown) {
            // Sync the payment term dropdown UI
            $this->dispatch('setPaymentTerm', $this->payment_term)
                ->to(PaymentTermSearchDropdown::class);
        }
    }

    public function updatedPaymentTerm($value): void
    {
        $this->syncPaymentTermAndDueDate($value ? (int) $value : null);
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $this->due_date = $this->date;

        $termId = $this->payment_term ? (int) $this->payment_term : null;
        if (! $termId || ! $this->date) {
            return;
        }

        $term = PaymentTerm::find($termId);
        if (! $term) {
            return;
        }

        $this->due_date = Carbon::parse($this->date)
            ->addDays($term->longevity)
            ->format('Y-m-d');
    }

    public function updatedDate($value): void
    {
        $this->updateDueDateFromPaymentTerm();
    }

    public function updatedSupplierId($value): void
    {
        $supplierId = $value ?: null;
        $this->supplier_id = $supplierId;

        $paymentTermId = null;
        if ($supplierId) {
            $supplier = Supplier::find($supplierId);
            $paymentTermId = $supplier?->payment_term_id ? (int) $supplier->payment_term_id : null;
        }

        $this->syncPaymentTermAndDueDate($paymentTermId, true);
        $this->dispatch('supplierSelected', $supplierId);
    }

    public function handleShippingUpdated($shipping)
    {
        $this->shipping = $shipping;
    }

    public function handleGlobalDiscountUpdated($discount)
    {
        $this->global_discount = $discount;
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
        $paymentTermId = isset($supplier['payment_term_id']) ? (int) $supplier['payment_term_id'] : null;

        $this->syncPaymentTermAndDueDate($paymentTermId, true);
    }

    private function prefillFromPurchase(int $purchaseId): void
    {
        $purchase = Purchase::with(['purchaseDetails.product', 'tags'])->find($purchaseId);
        if (! $purchase || (int) $purchase->setting_id !== (int) session('setting_id')) {
            return;
        }

        $this->duplicatePurchase = $purchase;
        $this->supplier_id = $purchase->supplier_id;
        $this->payment_term = $purchase->payment_term_id;
        $this->note = $purchase->note;
        $this->date = Carbon::parse($purchase->date)->format('Y-m-d');
        $this->due_date = Carbon::parse($purchase->due_date)->format('Y-m-d');
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
            if ($supplier && $supplier->payment_term_id) {
                $this->payment_term = $supplier->payment_term_id;
                $this->updateDueDateFromPaymentTerm();
            }
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
        return view('livewire.purchase.create-form');
    }
}

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
use Throwable;

class EditForm extends Component
{
    public $purchaseId;
    public $reference;
    public $supplier_id;
    public $supplier_purchase_number;
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
        'taxIncludedUpdated' => 'handleTaxIncludedUpdated',
        'supplierCreated' => 'handleSupplierCreated',
    ];

    public $shipping = 0;
    public $global_discount = 0;
    public $is_tax_included = false;

    public function mount($purchaseId): void
    {
        $this->purchaseId = $purchaseId;
        $this->purchase = Purchase::with('purchaseDetails')->findOrFail($purchaseId);

        $this->reference = $this->purchase->reference;
        $this->supplier_id = $this->purchase->supplier_id;
        $this->supplier_purchase_number = $this->purchase->supplier_purchase_number;
        $this->date = $this->purchase->date;
        $this->due_date = $this->purchase->due_date;
        $this->payment_term = $this->purchase->payment_term_id;
        $this->note = $this->purchase->note;

        $this->tags = $this->purchase->tags->pluck('name')->toArray();

        $this->restoreCart();
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

    public function restoreCart(): void
    {
        Cart::instance('purchase')->destroy();
        $cart = Cart::instance('purchase');

        foreach ($this->purchase->purchaseDetails as $detail) {
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
                    'code' => $detail->product_code,
                    'stock' => $detail->product->product_quantity ?? 0,
                    'product_tax' => $detail->tax_id,
                    'unit_price' => $detail->unit_price,
                    'sub_total_before_tax' => $detail->unit_price * $detail->quantity,
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

        try {
            $this->validate([
                'supplier_id' => 'required|exists:suppliers,id',
                'supplier_purchase_number' => 'nullable|string|max:255',
                'date' => 'required|date',
                'due_date' => 'required|date|after_or_equal:date',
                'payment_term' => 'required|exists:payment_terms,id',
                'note' => 'nullable|string|max:1000',
            ], [
                'supplier_id.required' => 'Pilih pemasok terlebih dahulu.',
                'supplier_id.exists' => 'Pemasok yang dipilih tidak valid.',
                'date.required' => 'Tanggal pembelian wajib diisi.',
                'date.date' => 'Format tanggal tidak valid.',
                'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
                'due_date.date' => 'Format tanggal tidak valid.',
                'due_date.after_or_equal' => 'Tanggal jatuh tempo harus lebih besar dari atau sama dengan tanggal pembelian.',
                'payment_term.required' => 'Pilih jatuh tempo terlebih dahulu.',
                'payment_term.exists' => 'Jatuh tempo yang dipilih tidak valid.',
            ]);

            if (Cart::instance('purchase')->count() === 0) {
                $this->dispatch('notify', ['type' => 'error', 'message' => 'Produk harus dipilih']);
                return;
            }

            DB::beginTransaction();

            $purchase = $this->purchase; // already loaded in mount()

            $cartItems = Cart::instance('purchase')->content();

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

            $supplierPurchaseNumber = $this->supplier_purchase_number ?: null;

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
                'note' => $this->note,
                'payment_term_id' => $this->payment_term,
            ]);

            Log::info('Purchase Edit Submit', [
                'tags' => $this->tags,
            ]);
            $this->purchase->syncTags($this->tags);

            // Remove old details
            $purchase->purchaseDetails()->delete();

            // Re-add from cart
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
            }

            DB::commit();
            Cart::instance('purchase')->destroy();

            session()->flash('success', 'Pembelian berhasil diperbarui.');
            return redirect()->route('purchases.index');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Edit Purchase Failed: ' . $e->getMessage());

            session()->flash('error', 'Gagal memperbarui pembelian. Silakan coba lagi.');
            return;
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase.edit-form');
    }
}

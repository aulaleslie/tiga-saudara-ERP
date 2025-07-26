<?php

namespace App\Livewire\Purchase;

use Carbon\Carbon;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Throwable;

class EditForm extends Component
{
    public $purchaseId;
    public $reference;
    public $supplier_id;
    public $date;
    public $due_date;
    public $payment_term;
    public $note;
    public $purchase;

    public $paymentTerms = [];
    public array $tags = [];

    protected $listeners = [
        'supplierSelected' => 'handleSupplierSelected',
        'confirmSubmit' => 'submit',
        'tagsUpdated' => 'handleTagsUpdated',
    ];

    public function mount($purchaseId): void
    {
        $this->purchaseId = $purchaseId;
        $this->purchase = Purchase::with('purchaseDetails')->findOrFail($purchaseId);

        $this->reference = $this->purchase->reference;
        $this->supplier_id = $this->purchase->supplier_id;
        $this->date = $this->purchase->date;
        $this->due_date = $this->purchase->due_date;
        $this->payment_term = $this->purchase->payment_term_id;
        $this->note = $this->purchase->note;
        $this->paymentTerms = PaymentTerm::where('setting_id', session('setting_id'))->get();

        $this->tags = $this->purchase->tags->pluck('name')->toArray();

        $this->restoreCart();
    }

    public function handleTagsUpdated(array $tags)
    {
        $this->tags = $tags;
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

    public function submit()
    {
        $this->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:date',
            'payment_term' => 'required|exists:payment_terms,id',
            'note' => 'nullable|string|max:1000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ], [
            'supplier_id.required' => 'Pilih pemasok terlebih dahulu.',
            'supplier_id.exists' => 'Pemasok yang dipilih tidak valid.',
            'date.required' => 'Tanggal pembelian wajib diisi.',
            'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
            'payment_term.required' => 'Term pembayaran harus dipilih.',
        ]);

        if (Cart::instance('purchase')->count() === 0) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Produk harus dipilih']);
            return;
        }

        try {
            DB::transaction(function () {
                $purchase = $this->purchase; // already loaded in mount()

                $updateData = array_filter([
                    'date' => $this->date !== $purchase->date ? $this->date : null,
                    'due_date' => $this->due_date !== $purchase->due_date ? $this->due_date : null,
                    'supplier_id' => $this->supplier_id !== $purchase->supplier_id ? $this->supplier_id : null,
                    'note' => $this->note !== $purchase->note ? $this->note : null,
                    'payment_term_id' => $this->payment_term !== $purchase->payment_term_id ? $this->payment_term : null,
                ], fn($value) => !is_null($value));

                if (!empty($updateData)) {
                    $purchase->update($updateData);
                }

                $this->purchase->syncTags($this->tags);

                // Remove old details
                $purchase->purchaseDetails()->delete();

                // Re-add from cart
                foreach (Cart::instance('purchase')->content() as $item) {
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

                Cart::instance('purchase')->destroy();
            });

            session()->flash('success', 'Pembelian berhasil diperbarui.');
            return redirect()->route('purchases.index');
        } catch (Throwable $e) {
            Log::error('Edit Purchase Failed', ['error' => $e->getMessage()]);
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Terjadi kesalahan saat memperbarui pembelian.']);
            $this->restoreCart(); // Rehydrate cart for UX
        }
    }

    public function updatedSupplierId($supplier)
    {
        $this->supplier_id = $supplier['id'];
        $this->supplier_name = $supplier['supplier_name'];
    }

    public function updatedPaymentTerm()
    {
        $this->updateDueDateFromPaymentTerm();
    }

    public function updatedDate()
    {
        $this->updateDueDateFromPaymentTerm();
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $term = PaymentTerm::find($this->payment_term);
        if ($term) {
            $this->due_date = Carbon::parse($this->date)->addDays($term->longevity)->format('Y-m-d');
        }
    }

    public function handleSupplierSelected($supplier)
    {
        Log::info('Updated supplier id: ', ['$supplier' => $supplier]);
        if (is_null($supplier)) {
            $this->supplier_id = null;
            $this->supplier_name = '';
            return;
        }

        $this->updatedSupplierId($supplier);
    }

    public function render()
    {
        return view('livewire.purchase.edit-form');
    }
}

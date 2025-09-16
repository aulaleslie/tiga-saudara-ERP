<?php

namespace App\Livewire\Purchase;

use Carbon\Carbon;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Throwable;

class CreateForm extends Component
{
    public $reference;
    public $supplier_id;
    public $supplier_name; // To sync with SupplierLoader
    public $date;
    public $due_date;
    public $payment_term;
    public $note;
    public array $tags = [];
    public $listeners = [
        'supplierSelected' => 'handleSupplierSelected',
        'confirmSubmit' => 'submit',
        'tagsUpdated' => 'handleTagsUpdated',
        'shippingUpdated'        => 'handleShippingUpdated',
        'globalDiscountUpdated'  => 'handleGlobalDiscountUpdated',
        'taxIncludedUpdated'    => 'handleTaxIncludedUpdated',
    ];

    public $paymentTerms = [];

    public $shipping = 0;
    public $global_discount = 0;
    public $is_tax_included = false;

    public function mount(): void
    {
        $this->reference = 'PR'; // This can be dynamic if needed
        $this->date = now()->format('Y-m-d');
        $this->due_date = now()->format('Y-m-d');
        $this->paymentTerms = PaymentTerm::all();
    }

    public function updatedSupplierId($value): void
    {
        $supplier = Supplier::find($value);
        if ($supplier && $supplier->payment_term_id) {
            $this->payment_term = $supplier->payment_term_id;
            $this->updateDueDateFromPaymentTerm();
        }
    }

    public function handleTagsUpdated(array $tags): void
    {
        $this->tags = $tags;
    }

    public function updatedPaymentTerm($value): void
    {
        $this->payment_term = (int) $value;
        $this->updateDueDateFromPaymentTerm();
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $termId = (int) $this->payment_term;
        if ($termId) {
            $term = PaymentTerm::find($termId);
            if ($term) {
                $date = Carbon::parse($this->date);
                $this->due_date = $date->addDays($term->longevity)->format('Y-m-d');
            }
        }
    }

    public function updatedDate($value): void
    {
        $this->updateDueDateFromPaymentTerm();
    }

    public function handleSupplierSelected($supplier): void
    {
        Log::info('Updated supplier id: ', ['$supplier' => $supplier]);
        if ($supplier) {
            $this->supplier_id = $supplier['id'];
            $this->supplier_name = $supplier['supplier_name'];
            $this->updatedSupplierId($supplier['id']);
        } else {
            $this->supplier_id = null;
            $this->supplier_name = null;
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

    /**
     * @throws Throwable
     */
    public function submit()
    {
        $this->validate([
            'supplier_id' => 'required|exists:suppliers,id',
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

        $cart = Cart::instance('purchase');

        if ($cart->count() === 0) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Produk harus dipilih']);
            return;
        }

        DB::beginTransaction();

        try {
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
                'discount_percentage' => $discount_percentage,
                'discount_amount' => $discount_amount,
                'shipping_amount' => $shipping,
                'tax_id' => null,
                'tax_percentage' => 0,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'due_amount' => $total_amount,
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'unpaid',
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

        } catch (\Exception $e) {
            DB::rollBack();
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

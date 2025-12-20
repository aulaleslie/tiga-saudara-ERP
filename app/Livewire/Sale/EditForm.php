<?php

namespace App\Livewire\Sale;

use Carbon\Carbon;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;

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

    protected $listeners = [
        'customerSelected' => 'handleCustomerSelected',
        'customerCreated' => 'handleCustomerCreated',
        'confirmUpdate'   => 'update',
        'taxCreated'      => 'handleTaxCreated',
    ];

    public function mount(Sale $sale)
    {
        $this->sale          = $sale;
        $this->reference     = $sale->reference;
        $this->customerId    = $sale->customer_id;
        $this->customerName  = $sale->customer?->customer_name;
        $this->date          = Carbon::parse($sale->date)->format('Y-m-d');
        $this->dueDate       = Carbon::parse($sale->due_date)->format('Y-m-d');
        $this->paymentTermId = $sale->payment_term_id;
        $this->note          = $sale->note;
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

            $subtotalBeforeTax = $detail->sub_total - $detail->product_tax_amount;

            // build the options *array*
            $options = [
                'product_id'             => $detail->product_id,
                'product_discount'       => $detail->product_discount_amount,
                'product_discount_type'  => $detail->product_discount_type,
                'sub_total'              => $detail->sub_total,
                'sub_total_before_tax'   => $subtotalBeforeTax,
                'code'                   => $detail->product_code,
                'stock'                  => $product?->product_quantity ?? 0,
                'unit'                   => $product?->product_unit,
                'unit_price'             => $detail->unit_price,
                'product_tax'            => $detail->tax_id,
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
                    'quantity'       => $b->quantity,
                    'sub_total'      => $b->sub_total,
                ];
            }
            $options['bundle_items'] = $bundleItems;
            $options['bundle_price'] = collect($bundleItems)->sum('sub_total');

            // pass options as array, not object
            Cart::instance('sale')->add([
                'id'      => $detail->id,
                'name'    => $detail->product_name,
                'qty'     => $detail->quantity,
                'price'   => $detail->price,
                'weight'  => 1,
                'options' => $options,
            ]);
        }
    }

    private function syncPaymentTermAndDueDate(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->paymentTermId = $paymentTermId ?: null;
        $this->updateDueDateFromPaymentTerm();

        if ($syncDropdown) {
            $this->dispatch('setPaymentTerm', $this->paymentTermId)
                ->to(PaymentTermSearchDropdown::class);
        }
    }

    public function handleCustomerSelected($customer): void
    {
        // Handle the customer selection from CustomerSearchDropdown
        $this->customerId = $customer['id'] ?? null;
        $this->customerName = $customer['customer_name'] ?? $customer['contact_name'] ?? null;
        
        // Sync payment term from the selected customer
        $paymentTermId = isset($customer['payment_term_id']) ? (int) $customer['payment_term_id'] : null;
        $this->syncPaymentTermAndDueDate($paymentTermId, true);
    }

    public function updatedCustomerId($value): void
    {
        $customerId = $value ?: null;
        $this->customerId = $customerId;

        $paymentTermId = null;
        if ($customerId) {
            $customer = Customer::find($customerId);
            $this->customerName = $customer?->customer_name ?? $customer?->contact_name;
            $paymentTermId = $customer?->payment_term_id ? (int) $customer->payment_term_id : null;
        }

        $this->syncPaymentTermAndDueDate($paymentTermId, true);
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $this->dueDate = $this->date;

        $termId = $this->paymentTermId ? (int) $this->paymentTermId : null;
        if (! $termId || ! $this->date) {
            return;
        }

        $term = PaymentTerm::find($termId);
        if (! $term) {
            return;
        }

        $this->dueDate = Carbon::parse($this->date)
            ->addDays($term->longevity)
            ->format('Y-m-d');
    }

    public function updatedPaymentTermId($value): void
    {
        $this->syncPaymentTermAndDueDate($value ? (int) $value : null);
    }

    public function updatedDate($value): void
    {
        $this->updateDueDateFromPaymentTerm();
    }

    public function handleCustomerCreated(array $customer): void
    {
        $this->customerId = $customer['id'] ?? null;
        $this->customerName = $customer['customer_name'] ?? $customer['contact_name'] ?? null;
        $paymentTermId = isset($customer['payment_term_id']) ? (int) $customer['payment_term_id'] : null;

        $this->syncPaymentTermAndDueDate($paymentTermId, true);
    }

    public function handleTaxCreated($data): void
    {
        $this->dispatch('taxCreated', $data);
    }

    public function update(?string $customerId = null, ?string $paymentTermId = null)
    {
        // Use passed values from hidden inputs if available
        if ($customerId !== null && $customerId !== '') {
            $this->customerId = $customerId;
        }
        if ($paymentTermId !== null && $paymentTermId !== '') {
            $this->paymentTermId = $paymentTermId;
        }

        $this->validate([
            'customerId'    => 'required|exists:customers,id',
            'date'          => 'required|date',
            'dueDate'       => 'required|date|after_or_equal:date',
            'paymentTermId' => 'required|exists:payment_terms,id',
            'note'          => 'nullable|string|max:1000',
        ], [
            'customerId.required'    => 'Pilih pelanggan terlebih dahulu.',
            'customerId.exists'      => 'Pelanggan tidak valid.',
            'paymentTermId.required' => 'Pilih term pembayaran terlebih dahulu.',
            'paymentTermId.exists'   => 'Term pembayaran yang dipilih tidak valid.',
            'dueDate.after_or_equal' => 'Tanggal jatuh tempo harus ≥ tanggal jual.',
        ]);

        if (Cart::instance('sale')->count() === 0) {
            $this->dispatch('notify', [
                'type'    => 'error',
                'message' => 'Produk harus dipilih.',
            ]);
            return;
        }

        DB::beginTransaction();

        try {
            $cartItems      = Cart::instance('sale')->content();
            $totalSub       = $cartItems->sum(fn($i) => $i->options->sub_total);
            $taxAmount      = $cartItems->sum(fn($i) => $i->options->sub_total - ($i->options->sub_total_before_tax ?? 0));
            $globalDiscount = 0;
            $shipping       = 0;
            $grandTotal     = $totalSub - $globalDiscount + $shipping;

            // Update sale header
            $this->sale->update([
                'date'               => $this->date,
                'due_date'           => $this->dueDate,
                'customer_id'        => $this->customerId,
                'customer_name'      => Customer::findOrFail($this->customerId)->customer_name,
                'tax_amount'         => $taxAmount,
                'discount_percentage'=> 0,
                'discount_amount'    => $globalDiscount,
                'shipping_amount'    => $shipping,
                'total_amount'       => $grandTotal,
                'due_amount'         => $grandTotal,
                'payment_term_id'    => $this->paymentTermId,
                'note'               => $this->note,
            ]);

            // Remove old details & bundles
            SaleBundleItem::where('sale_id', $this->sale->id)->delete();
            SaleDetails::where('sale_id', $this->sale->id)->delete();

            // Re-insert details & bundles
            foreach ($cartItems as $item) {
                $lineTax = $item->options->sub_total - ($item->options->sub_total_before_tax ?? 0);

                $detail = SaleDetails::create([
                    'sale_id'                 => $this->sale->id,
                    'product_id'              => $item->options->product_id,
                    'product_name'            => $item->name,
                    'product_code'            => $item->options->code,
                    'quantity'                => $item->qty,
                    'unit_price'              => $item->options->unit_price,
                    'price'                   => $item->price,
                    'product_discount_type'   => $item->options->product_discount_type,
                    'product_discount_amount' => $item->options->product_discount,
                    'sub_total'               => $item->options->sub_total,
                    'product_tax_amount'      => $lineTax,
                    'tax_id'                  => $item->options->product_tax,
                ]);

                foreach ($item->options->bundle_items ?? [] as $b) {
                    SaleBundleItem::create([
                        'sale_detail_id' => $detail->id,
                        'sale_id'        => $this->sale->id,
                        'bundle_id'      => $b['bundle_id']      ?? null,
                        'bundle_item_id' => $b['bundle_item_id'] ?? null,
                        'product_id'     => $b['product_id'],
                        'name'           => $b['name'],
                        'price'          => $b['price'],
                        'quantity'       => $b['quantity'],
                        'sub_total'      => $b['sub_total'],
                    ]);
                }
            }

            DB::commit();

            Cart::instance('sale')->destroy();
            session()->flash('success', 'Penjualan Diperbaharui!');
            return redirect()->route('sales.index');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Livewire Sale Update Failed: '.$e->getMessage());
            session()->flash('error', 'Gagal memperbaharui penjualan. Silakan coba lagi.');
        }
    }

    public function render()
    {
        return view('livewire.sale.edit-form');
    }
}

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
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;

class EditForm extends Component
{
    public Sale $sale;

    public $reference;
    public $customerId;
    public $date;
    public $dueDate;
    public $paymentTermId;
    public $paymentTerms = [];
    public $note;

    protected $listeners = [
        'customerSelected' => 'handleCustomerSelected',
        'confirmUpdate'    => 'update',
    ];

    public function mount(Sale $sale)
    {
        $this->sale           = $sale;
        $this->reference      = $sale->reference;
        $this->customerId     = $sale->customer_id;
        $this->date           = Carbon::parse($sale->date)->format('Y-m-d');
        $this->dueDate        = Carbon::parse($sale->due_date)->format('Y-m-d');
        $this->paymentTermId  = $sale->payment_term_id;
        $this->note           = $sale->note;
        $this->paymentTerms   = PaymentTerm::all();

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

    public function handleCustomerSelected($customer)
    {
        $this->customerId = $customer['id'];
    }

    public function updatedPaymentTermId($value)
    {
        $term = PaymentTerm::find($value);
        if ($term) {
            $this->dueDate = Carbon::parse($this->date)
                ->addDays($term->longevity)
                ->format('Y-m-d');
        }
    }

    public function updatedDate($value)
    {
        // re-compute due date if date changes
        $this->updatedPaymentTermId($this->paymentTermId);
    }

    public function update()
    {
        $this->validate([
            'customerId'    => 'required|exists:customers,id',
            'date'          => 'required|date',
            'dueDate'       => 'required|date|after_or_equal:date',
            'paymentTermId' => 'required|exists:payment_terms,id',
            'note'          => 'nullable|string|max:1000',
        ], [
            'customerId.required'    => 'Pilih pelanggan terlebih dahulu.',
            'customerId.exists'      => 'Pelanggan tidak valid.',
            'dueDate.after_or_equal' => 'Tanggal jatuh tempo harus ≥ tanggal jual.',
        ]);

        if (Cart::instance('sale')->count() === 0) {
            $this->dispatchBrowserEvent('notify', [
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

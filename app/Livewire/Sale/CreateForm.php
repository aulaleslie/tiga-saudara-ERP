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
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;

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

    protected $listeners = [
        'customerSelected' => 'handleCustomerSelected',
        'confirmSubmit' => 'submit',
    ];

    public function mount()
    {
        // You can leave reference blank—Sale::boot() will generate it on save,
        // or generate here if you prefer.
        $this->reference = 'SL'; // This can be dynamic if needed
        $this->date = now()->format('Y-m-d');
        $this->due_date = now()->format('Y-m-d');
        $this->paymentTerms = PaymentTerm::all();
    }

    public function updatedCustomerId($value)
    {
        $customer = Customer::find($value);
        if ($customer && $customer->payment_term_id) {
            $this->paymentTermId = $customer->payment_term_id;
            $this->updateDueDateFromPaymentTerm();
        }
    }

    public function handleCustomerSelected($customer)
    {
        $this->customerId = $customer['id'];
        $this->customerName = $customer['contact_name'];
        $this->updatedCustomerId($customer['id']);
    }

    private function updateDueDateFromPaymentTerm(): void
    {
        $term = PaymentTerm::find($this->paymentTermId);
        if ($term) {
            $date = Carbon::parse($this->date);
            $this->dueDate = $date->addDays($term->longevity)->format('Y-m-d');
        }
    }

    public function updatedPaymentTermId($value)
    {
        $term = PaymentTerm::find($value);
        if ($term) {
            $this->dueDate = Carbon::parse($this->date)
                ->addDays($term->longevity)
                ->format('Y-m-d');
        } else {
            $this->dueDate = $this->date;
        }
    }

    public function updatedDate($value)
    {
        $this->updateDueDateFromPaymentTerm();
    }

    public function submit()
    {
        $this->validate([
            'customerId'     => 'required|exists:customers,id',
            'date'           => 'required|date',
            'dueDate'        => 'required|date|after_or_equal:date',
            'paymentTermId'  => 'required|exists:payment_terms,id',
            'note'           => 'nullable|string|max:1000',
        ], [
            'customerId.required'   => 'Pilih pelanggan terlebih dahulu.',
            'customerId.exists'     => 'Pelanggan tidak valid.',
            'dueDate.after_or_equal'=> 'Tanggal jatuh tempo harus ≥ tanggal jual.',
        ]);

        if (Cart::instance('sale')->count() === 0) {
            $this->dispatchBrowserEvent('notify', [
                'type'    => 'error',
                'message' => 'Produk harus dipilih.'
            ]);
            return;
        }

        DB::beginTransaction();

        try {
            $settingId = session('setting_id');
            $cartItems = Cart::instance('sale')->content();

            // Totals
            $totalSub       = $cartItems->sum(fn($i) => $i->options['sub_total']);
            $taxAmount      = $cartItems->sum(fn($i) => $i->options['sub_total'] - ($i->options['sub_total_before_tax'] ?? 0));
            $globalDiscount = 0;
            $shipping       = 0;
            $grandTotal     = $totalSub - $globalDiscount + $shipping;

            // Create Sale
            $sale = Sale::create([
                'date'               => $this->date,
                'due_date'           => $this->dueDate,
                'customer_id'        => $this->customerId,
                'customer_name'      => Customer::findOrFail($this->customerId)->customer_name,
                'tax_id'             => null,
                'tax_percentage'     => 0,
                'tax_amount'         => $taxAmount,
                'discount_percentage'=> 0,
                'discount_amount'    => $globalDiscount,
                'shipping_amount'    => $shipping,
                'total_amount'       => $grandTotal,
                'due_amount'         => $grandTotal,
                'status'             => Sale::STATUS_DRAFTED,
                'payment_status'     => 'unpaid',
                'payment_term_id'    => $this->paymentTermId,
                'note'               => $this->note,
                'setting_id'         => $settingId,
                'paid_amount'        => 0.0,
                'is_tax_included'    => false,
                'payment_method'     => '',
            ]);

            // Details & Bundles
            foreach ($cartItems as $item) {
                $lineTax = $item->options['sub_total'] - ($item->options['sub_total_before_tax'] ?? 0);
                $detail = SaleDetails::create([
                    'sale_id'                 => $sale->id,
                    'product_id'              => $item->options['product_id'],
                    'product_name'            => $item->name,
                    'product_code'            => $item->options['code'],
                    'quantity'                => $item->qty,
                    'unit_price'              => $item->options['unit_price'],
                    'price'                   => $item->price,
                    'product_discount_type'   => $item->options['product_discount_type'],
                    'product_discount_amount' => $item->options['product_discount'],
                    'sub_total'               => $item->options['sub_total'],
                    'product_tax_amount'      => $lineTax,
                    'tax_id'                  => $item->options['product_tax'],
                ]);

                foreach ($item->options['bundle_items'] ?? [] as $b) {
                    SaleBundleItem::create([
                        'sale_detail_id' => $detail->id,
                        'sale_id'        => $sale->id,
                        'bundle_id'      => $b['bundle_id'] ?? null,
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
            session()->flash('success', 'Penjualan Ditambahkan!');
            return redirect()->route('sales.index');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Livewire Sale Create Failed: ' . $e->getMessage());
            session()->flash('error', 'Gagal menyimpan penjualan. Silakan coba lagi.');
        }
    }

    public function render()
    {
        return view('livewire.sale.create-form');
    }
}

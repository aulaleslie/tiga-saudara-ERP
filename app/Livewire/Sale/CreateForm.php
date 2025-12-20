<?php

namespace App\Livewire\Sale;

use App\Services\IdempotencyService;
use Carbon\Carbon;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SaleCartAggregator;

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
    public string $idempotencyToken;

    protected $listeners = [
        'customerSelected' => 'handleCustomerSelected',
        'customerCreated' => 'handleCustomerCreated',
        'confirmSubmit' => 'submit',
        'taxCreated' => 'handleTaxCreated',
    ];

    public function mount(string $idempotencyToken)
    {
        $this->idempotencyToken = $idempotencyToken;
        $this->reference = 'SL';
        $this->date = now()->format('Y-m-d');
        $this->dueDate = now()->format('Y-m-d');
        $this->paymentTerms = PaymentTerm::all();
    }

    private function syncPaymentTermAndDueDate(?int $paymentTermId, bool $syncDropdown = false): void
    {
        $this->paymentTermId = $paymentTermId ?: null;
        $this->updateDueDateFromPaymentTerm();

        if ($syncDropdown) {
            // Sync the payment term dropdown UI
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
        // Customer was just created, the dropdown will auto-select it
        // We need to set the payment term from the newly created customer
        $this->customerId = $customer['id'] ?? null;
        $this->customerName = $customer['customer_name'] ?? $customer['contact_name'] ?? null;
        $paymentTermId = isset($customer['payment_term_id']) ? (int) $customer['payment_term_id'] : null;

        $this->syncPaymentTermAndDueDate($paymentTermId, true);
    }

    public function handleTaxCreated($data): void
    {
        // This will be handled by the product cart component
        $this->dispatch('taxCreated', $data);
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
            if ($customer && $customer->payment_term_id) {
                $this->paymentTermId = $customer->payment_term_id;
                $this->updateDueDateFromPaymentTerm();
            }
        }

        try {
            Log::info('Sale create validating', [
                'customerId' => $this->customerId,
                'paymentTermId' => $this->paymentTermId,
                'date' => $this->date,
                'dueDate' => $this->dueDate,
            ]);

            $this->validate([
                'customerId'     => 'required|exists:customers,id',
                'date'           => 'required|date',
                'dueDate'        => 'required|date|after_or_equal:date',
                'paymentTermId'  => 'required|exists:payment_terms,id',
                'note'           => 'nullable|string|max:1000',
            ], [
                'customerId.required'   => 'Pilih pelanggan terlebih dahulu.',
                'customerId.exists'     => 'Pelanggan tidak valid.',
                'paymentTermId.required' => 'Pilih term pembayaran terlebih dahulu.',
                'paymentTermId.exists'  => 'Term pembayaran yang dipilih tidak valid.',
                'dueDate.after_or_equal'=> 'Tanggal jatuh tempo harus ≥ tanggal jual.',
            ]);

            if (Cart::instance('sale')->count() === 0) {
                Log::warning('Sale create aborted: empty cart');
                $this->dispatch('notify', [
                    'type'    => 'error',
                    'message' => 'Produk harus dipilih.'
                ]);
                // Generate new idempotency token so next attempt is fresh
                $this->idempotencyToken = (string) Str::uuid();
                return;
            }

            if (! IdempotencyService::claim($this->idempotencyToken, 'sales.store', auth()->id())) {
                Log::warning('Sale create idempotency claim failed', [
                    'token' => $this->idempotencyToken,
                    'user' => auth()->id(),
                ]);
                // Refresh token so user can retry
                $this->idempotencyToken = (string) Str::uuid();
                session()->flash('error', 'Permintaan penjualan sudah diproses. Silakan tunggu sebelum mencoba lagi.');
                return;
            }

            DB::beginTransaction();

            try {
                Log::info('Sale create persisting', [
                    'cart_count' => Cart::instance('sale')->count(),
                    'customerId' => $this->customerId,
                    'paymentTermId' => $this->paymentTermId,
                ]);

                $settingId = session('setting_id');
                $cartItems = Cart::instance('sale')->content();
                $aggregatedItems = SaleCartAggregator::aggregate($cartItems);

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
                    'payment_status'     => 'Unpaid',
                    'payment_term_id'    => $this->paymentTermId,
                    'note'               => $this->note,
                    'setting_id'         => $settingId,
                    'paid_amount'        => 0.0,
                    'is_tax_included'    => false,
                    'payment_method'     => '',
                ]);

                Log::info('Sale created', ['sale_id' => $sale->id, 'reference' => $sale->reference]);

                // Details & Bundles
                foreach ($aggregatedItems as $item) {
                    $detail = SaleDetails::create([
                        'sale_id'                 => $sale->id,
                        'product_id'              => $item['product_id'],
                        'product_name'            => $item['product_name'],
                        'product_code'            => $item['product_code'],
                        'quantity'                => $item['quantity'],
                        'unit_price'              => round((float) $item['unit_price'], 2),
                        'price'                   => round((float) $item['price'], 2),
                        'product_discount_type'   => $item['product_discount_type'],
                        'product_discount_amount' => round((float) $item['product_discount_amount'], 2),
                        'sub_total'               => round((float) $item['sub_total'], 2),
                        'product_tax_amount'      => round((float) $item['product_tax_amount'], 2),
                        'tax_id'                  => $item['tax_id'],
                    ]);

                    foreach ($item['bundle_items'] ?? [] as $b) {
                        SaleBundleItem::create([
                            'sale_detail_id' => $detail->id,
                            'sale_id'        => $sale->id,
                            'bundle_id'      => $b['bundle_id'] ?? null,
                            'bundle_item_id' => $b['bundle_item_id'] ?? null,
                            'product_id'     => $b['product_id'],
                            'name'           => $b['name'],
                            'price'          => round((float) ($b['price'] ?? 0), 2),
                            'quantity'       => $b['quantity'],
                            'sub_total'      => round((float) ($b['sub_total'] ?? 0), 2),
                        ]);
                    }
                }

                DB::commit();

                Cart::instance('sale')->destroy();
                session()->flash('success', 'Penjualan Ditambahkan!');
                Log::info('Sale create completed', ['sale_id' => $sale->id]);
                return redirect()->route('sales.index');
            } catch (Exception $e) {
                DB::rollBack();
                Log::error('Livewire Sale Create Failed: ' . $e->getMessage());
                session()->flash('error', 'Gagal menyimpan penjualan. Silakan coba lagi.');
            }
        } finally {
            $this->dispatch('sale:submit-finish');
        }
    }

    public function render()
    {
        return view('livewire.sale.create-form');
    }
}

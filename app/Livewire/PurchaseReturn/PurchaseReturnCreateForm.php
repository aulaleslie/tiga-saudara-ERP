<?php

namespace App\Livewire\PurchaseReturn;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnGood;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\PurchasesReturn\Entities\SupplierCredit;

class PurchaseReturnCreateForm extends Component
{
    use WithFileUploads;

    public $supplier_id = '';
    public $date;
    public $rows = [];
    public $note;
    public $grand_total = 0.0;
    public $location_id = null;
    public $return_type = '';
    public $cash_proof;
    public $replacement_goods = [];

    protected $listeners = [
        'supplierSelected' => 'handleSupplierSelected',
        'updateRows' => 'handleUpdatedRows',
        'purchaseReturnLocationSelected' => 'handleLocationSelected',
        'replacementProductSelected' => 'handleReplacementProductSelected',
    ];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    public function handleSupplierSelected($supplier): void
    {
        if ($supplier) {
            Log::info('Updated supplier id', ['supplier' => $supplier]);
            $this->supplier_id = $supplier['id'];
        } else {
            $this->supplier_id = null;
        }
    }

    public function handleLocationSelected($location): void
    {
        $this->location_id = $location['id'] ?? null;
        $this->dispatch('locationUpdated', $this->location_id);
    }

    public function addReplacementGood(): void
    {
        $this->replacement_goods[] = [
            'product_id' => null,
            'product_name' => '',
            'product_code' => '',
            'quantity' => 1,
            'unit_value' => 0.0,
            'sub_total' => 0.0,
        ];
    }

    public function removeReplacementGood($index): void
    {
        if (isset($this->replacement_goods[$index])) {
            unset($this->replacement_goods[$index]);
            $this->replacement_goods = array_values($this->replacement_goods);
        }
    }

    public function recalculateReplacement($index): void
    {
        if (! isset($this->replacement_goods[$index])) {
            return;
        }

        $quantity = max(0, (int) ($this->replacement_goods[$index]['quantity'] ?? 0));
        $unitValue = (float) ($this->replacement_goods[$index]['unit_value'] ?? 0);
        $this->replacement_goods[$index]['quantity'] = $quantity;
        $this->replacement_goods[$index]['unit_value'] = $unitValue;
        $this->replacement_goods[$index]['sub_total'] = round($quantity * $unitValue, 2);
    }

    public function handleReplacementProductSelected($payload): void
    {
        $index = $payload['index'] ?? null;
        $product = $payload['product'] ?? [];

        if ($index === null || ! isset($this->replacement_goods[$index])) {
            return;
        }

        $this->replacement_goods[$index]['product_id'] = $product['id'];
        $this->replacement_goods[$index]['product_name'] = $product['product_name'];
        $this->replacement_goods[$index]['product_code'] = $product['product_code'] ?? '';
        $this->replacement_goods[$index]['unit_value'] = (float) ($product['last_purchase_price'] ?? 0);
        $this->recalculateReplacement($index);
    }

    public function handleUpdatedRows($updatedRows): void
    {
        $this->rows = $updatedRows;
        $this->grand_total = $this->calculateReturnTotal();
        Log::info('Rows updated', ['rows' => $this->rows, 'grand_total' => $this->grand_total]);
    }

    protected function calculateReturnTotal(): float
    {
        return round(collect($this->rows)->sum(function ($row) {
            return (float) ($row['total'] ?? 0);
        }), 2);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'required|exists:suppliers,id',
            'date' => 'required|date',
            'location_id' => 'required|exists:locations,id',
            'return_type' => 'required|in:exchange,deposit,cash',
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.quantity' => 'required|integer|min:1',
            'rows.*.purchase_order_id' => 'nullable|exists:purchases,id',
            'cash_proof' => 'nullable|file|max:4096|mimes:jpg,jpeg,png,pdf',
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Pilih pemasok terlebih dahulu.',
            'supplier_id.exists' => 'Pemasok yang dipilih tidak valid.',
            'date.required' => 'Tanggal retur wajib diisi.',
            'date.date' => 'Format tanggal tidak valid.',
            'location_id.required' => 'Lokasi wajib dipilih.',
            'location_id.exists' => 'Lokasi yang dipilih tidak valid.',
            'return_type.required' => 'Pilih metode penyelesaian retur.',
            'return_type.in' => 'Metode penyelesaian retur tidak valid.',
            'rows.required' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.array' => 'Format produk tidak valid.',
            'rows.min' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.*.product_id.required' => 'Silakan pilih produk.',
            'rows.*.product_id.exists' => 'Produk yang dipilih tidak valid.',
            'rows.*.quantity.required' => 'Jumlah produk harus diisi.',
            'rows.*.quantity.integer' => 'Jumlah produk harus berupa angka.',
            'rows.*.quantity.min' => 'Jumlah produk minimal 1.',
            'rows.*.purchase_order_id.exists' => 'Nomor purchase order tidak valid.',
            'cash_proof.file' => 'Bukti pengembalian harus berupa berkas.',
            'cash_proof.mimes' => 'Format bukti tidak didukung.',
            'cash_proof.max' => 'Ukuran bukti maksimal 4MB.',
        ];
    }

    protected function calculateCreditAmount(): float
    {
        return round(collect($this->rows)->sum(function ($row) {
            $unit = (float) ($row['purchase_price'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            return $unit * $qty;
        }), 2);
    }

    /**
     * @throws ValidationException
     */
    public function submit()
    {
        Log::info('Submitting purchase return form', get_object_vars($this));

        $data = [
            'supplier_id' => $this->supplier_id,
            'date' => $this->date,
            'location_id' => $this->location_id,
            'return_type' => $this->return_type,
            'rows' => $this->rows,
            'cash_proof' => $this->cash_proof,
        ];

        if ($this->return_type === 'exchange') {
            $data['replacement_goods'] = $this->replacement_goods;
        }

        try {
            $validator = Validator::make($data, $this->rules(), $this->messages());

            $validator->after(function ($validator) {
                $productIds = [];
                foreach ($this->rows as $index => $row) {
                    $productId = $row['product_id'] ?? null;
                    $qty = (int) ($row['quantity'] ?? 0);
                    $availableTax = (int) ($row['available_quantity_tax'] ?? 0);
                    $availableNonTax = (int) ($row['available_quantity_non_tax'] ?? 0);
                    $totalAvailable = $availableTax + $availableNonTax;

                    if ($qty > $totalAvailable) {
                        $validator->errors()->add("rows.$index.quantity", "Jumlah retur tidak boleh melebihi stok tersedia ({$totalAvailable}).");
                    }

                    if (! empty($row['serial_number_required']) && empty($row['serial_numbers'])) {
                        $validator->errors()->add("rows.$index.serial_numbers", 'Produk memerlukan nomor seri.');
                    }

                    if ($productId !== null) {
                        if (in_array($productId, $productIds)) {
                            $validator->errors()->add("rows.$index.product_id", 'Produk ini sudah dipilih sebelumnya.');
                        } else {
                            $productIds[] = $productId;
                        }
                    }

                    if (! empty($row['serial_numbers'])) {
                        $serialNumbers = collect($row['serial_numbers'])
                            ->map(fn ($item) => is_array($item) ? ($item['serial_number'] ?? null) : $item)
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();

                        $existing = ProductSerialNumber::query()
                            ->whereIn('serial_number', $serialNumbers)
                            ->where('is_broken', true)
                            ->pluck('serial_number')
                            ->unique()
                            ->values()
                            ->all();

                        $missing = array_diff($serialNumbers, $existing);
                        $extra = array_diff($existing, $serialNumbers);

                        if (! empty($missing) || ! empty($extra)) {
                            $validator->errors()->add(
                                "rows.$index.serial_numbers",
                                'Nomor seri tidak valid atau tidak rusak: ' . implode(', ', array_merge($missing, $extra))
                            );
                        }
                    }
                }

                if ($this->return_type === 'exchange') {
                    if (empty($this->replacement_goods)) {
                        $validator->errors()->add('replacement_goods', 'Tambahkan setidaknya satu produk pengganti.');
                    } else {
                        foreach ($this->replacement_goods as $idx => $replacement) {
                            if (empty($replacement['product_id'])) {
                                $validator->errors()->add("replacement_goods.$idx.product_id", 'Produk pengganti wajib dipilih.');
                            }

                            if ((int) ($replacement['quantity'] ?? 0) <= 0) {
                                $validator->errors()->add("replacement_goods.$idx.quantity", 'Jumlah pengganti harus lebih dari 0.');
                            }
                        }
                    }
                }

                if ($this->return_type === 'cash' && empty($this->cash_proof)) {
                    $validator->errors()->add('cash_proof', 'Unggah bukti pengembalian tunai.');
                }

                if ($this->return_type === 'deposit' && $this->calculateCreditAmount() <= 0) {
                    $validator->errors()->add('rows', 'Nilai retur tidak valid untuk dijadikan deposit.');
                }
            });

            $validator->validate();
            $this->dispatch('updateTableErrors', []);

            $total = $this->calculateReturnTotal();
            $paidAmount = in_array($this->return_type, ['exchange', 'deposit', 'cash'], true) ? $total : 0.0;
            $dueAmount = round(max($total - $paidAmount, 0), 2);
            $paymentStatus = $dueAmount > 0 ? 'Unpaid' : 'Paid';
            $paymentMethod = $this->return_type === 'cash' ? 'Cash' : 'Settlement';
            $proofPath = null;

            if ($this->return_type === 'cash' && $this->cash_proof) {
                $proofPath = $this->cash_proof->store('purchase-returns/proofs', 'public');
            }

            DB::transaction(function () use ($total, $paidAmount, $dueAmount, $paymentStatus, $paymentMethod, $proofPath) {
                $supplier = Supplier::find($this->supplier_id);

                $purchaseReturn = PurchaseReturn::create([
                    'date' => $this->date,
                    'supplier_id' => $this->supplier_id,
                    'supplier_name' => optional($supplier)->supplier_name ?? '-',
                    'setting_id' => session('setting_id'),
                    'location_id' => $this->location_id,
                    'tax_percentage' => 0,
                    'tax_amount' => 0,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => $total,
                    'paid_amount' => $paidAmount,
                    'due_amount' => $dueAmount,
                    'approval_status' => 'pending',
                    'return_type' => $this->return_type,
                    'status' => 'Pending Approval',
                    'payment_status' => $paymentStatus,
                    'payment_method' => $paymentMethod,
                    'note' => $this->note,
                    'cash_proof_path' => $proofPath,
                ]);

                foreach ($this->rows as $row) {
                    $serialNumberIds = collect($row['serial_numbers'] ?? [])
                        ->map(fn ($sn) => is_array($sn) ? ($sn['id'] ?? null) : null)
                        ->filter()
                        ->values()
                        ->all();

                    PurchaseReturnDetail::create([
                        'purchase_return_id' => $purchaseReturn->id,
                        'po_id' => $row['purchase_order_id'] ?? null,
                        'product_id' => $row['product_id'],
                        'product_name' => $row['product_name'],
                        'product_code' => $row['product_code'] ?? '',
                        'quantity' => (int) $row['quantity'],
                        'unit_price' => (float) ($row['purchase_price'] ?? 0),
                        'price' => (float) ($row['purchase_price'] ?? 0),
                        'sub_total' => (float) ($row['total'] ?? 0),
                        'product_discount_amount' => 0,
                        'product_tax_amount' => 0,
                        'serial_number_ids' => $serialNumberIds,
                    ]);
                }

                if ($this->return_type === 'exchange') {
                    foreach ($this->replacement_goods as $replacement) {
                        if (empty($replacement['product_id'])) {
                            continue;
                        }

                        $quantity = (int) ($replacement['quantity'] ?? 0);
                        $unitValue = (float) ($replacement['unit_value'] ?? 0);

                        PurchaseReturnGood::create([
                            'purchase_return_id' => $purchaseReturn->id,
                            'product_id' => $replacement['product_id'],
                            'product_name' => $replacement['product_name'],
                            'product_code' => $replacement['product_code'] ?? null,
                            'quantity' => $quantity,
                            'unit_value' => $unitValue,
                            'sub_total' => round($quantity * $unitValue, 2),
                        ]);
                    }
                }

                if ($this->return_type === 'deposit') {
                    $creditAmount = $this->calculateCreditAmount();
                    SupplierCredit::create([
                        'supplier_id' => $this->supplier_id,
                        'purchase_return_id' => $purchaseReturn->id,
                        'amount' => $creditAmount,
                        'remaining_amount' => $creditAmount,
                        'status' => 'open',
                    ]);
                }

                if ($this->return_type === 'cash') {
                    PurchaseReturnPayment::create([
                        'purchase_return_id' => $purchaseReturn->id,
                        'amount' => $total,
                        'date' => $this->date,
                        'reference' => 'PRPAY/' . $purchaseReturn->reference,
                        'payment_method' => 'Cash',
                        'payment_method_id' => null,
                        'note' => 'Pengembalian tunai',
                    ]);
                }
            });

            session()->flash('success', 'Retur pembelian berhasil disimpan.');
            return redirect()->route('purchase-returns.index');
        } catch (ValidationException $e) {
            Log::warning('Validation failed for purchase return', ['errors' => $e->validator->errors()->getMessages()]);
            $this->dispatch('updateTableErrors', $e->validator->errors()->getMessages());
            throw $e;
        } catch (Exception $e) {
            Log::error('Failed to save purchase return', ['message' => $e->getMessage()]);
            session()->flash('error', 'Terjadi kesalahan saat menyimpan retur pembelian.');
        }

        return null;
    }

    public function render(): Factory|Application|View
    {
        $errors = $this->getErrorBag()->messages();

        if (! empty($errors)) {
            $this->dispatch('updateTableErrors', $errors);
        }

        return view('livewire.purchase-return.purchase-return-create-form', [
            'rows' => $this->rows,
            'replacement_goods' => $this->replacement_goods,
        ]);
    }
}

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
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;

class PurchaseReturnCreateForm extends Component
{

    public $supplier_id = '';
    public $date;
    public $rows = [];
    public $note;
    public $grand_total = 0.0;
    public $location_id = null;

    public string $formTitle = 'Buat Retur Pembelian';
    public string $submitLabel = 'Proses Retur';
    public bool $approvalLocked = false;
    public ?string $supplierName = null;
    public ?string $locationName = null;

    protected $listeners = [
        'supplierSelected' => 'handleSupplierSelected',
        'updateRows' => 'handleUpdatedRows',
        'purchaseReturnLocationSelected' => 'handleLocationSelected',
    ];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
        $this->formTitle = 'Buat Retur Pembelian';
        $this->submitLabel = 'Proses Retur';
    }

    public function handleSupplierSelected($supplier): void
    {
        if ($supplier) {
            Log::info('Updated supplier id', ['supplier' => $supplier]);
            $this->supplier_id = $supplier['id'];
            $this->supplierName = $supplier['supplier_name'] ?? null;
        } else {
            $this->supplier_id = null;
            $this->supplierName = null;
        }
    }

    public function handleLocationSelected($location): void
    {
        $this->location_id = $location['id'] ?? null;
        $this->locationName = $location['name'] ?? null;
        $this->dispatch('locationUpdated', $this->location_id);
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
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.quantity' => 'required|integer|min:1',
            'rows.*.purchase_order_id' => 'nullable|exists:purchases,id',
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
            'rows.required' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.array' => 'Format produk tidak valid.',
            'rows.min' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.*.product_id.required' => 'Silakan pilih produk.',
            'rows.*.product_id.exists' => 'Produk yang dipilih tidak valid.',
            'rows.*.quantity.required' => 'Jumlah produk harus diisi.',
            'rows.*.quantity.integer' => 'Jumlah produk harus berupa angka.',
            'rows.*.quantity.min' => 'Jumlah produk minimal 1.',
            'rows.*.purchase_order_id.exists' => 'Nomor purchase order tidak valid.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function submit()
    {
        Log::info('Submitting purchase return form', get_object_vars($this));

        try {
            $prepared = $this->validateAndPrepare();

            DB::transaction(function () use ($prepared) {
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
                    'total_amount' => round($prepared['total'], 2),
                    'paid_amount' => round($prepared['paidAmount'], 2),
                    'due_amount' => round($prepared['dueAmount'], 2),
                    'approval_status' => 'pending',
                    'return_type' => null,
                    'status' => 'Pending Approval',
                    'payment_status' => $prepared['paymentStatus'],
                    'payment_method' => 'Pending',
                    'note' => $this->note,
                    'cash_proof_path' => null,
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
            });

            session()->flash('success', 'Retur pembelian berhasil disimpan dan menunggu persetujuan.');
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

    protected function validateAndPrepare(): array
    {
        $data = [
            'supplier_id' => $this->supplier_id,
            'date' => $this->date,
            'location_id' => $this->location_id,
            'rows' => $this->rows,
        ];

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

            if ($this->calculateReturnTotal() <= 0) {
                $validator->errors()->add('rows', 'Nilai retur harus lebih dari 0.');
            }
        });

        $validator->validate();
        $this->dispatch('updateTableErrors', []);

        $total = $this->calculateReturnTotal();
        $paidAmount = $this->resolvePaidAmount($total);
        $dueAmount = round(max($total - $paidAmount, 0), 2);
        $paymentStatus = $dueAmount > 0 ? 'Unpaid' : 'Paid';

        return [
            'total' => $total,
            'paidAmount' => $paidAmount,
            'dueAmount' => $dueAmount,
            'paymentStatus' => $paymentStatus,
        ];
    }

    protected function resolvePaidAmount(float $total): float
    {
        return 0.0;
    }

    public function render(): Factory|Application|View
    {
        $errors = $this->getErrorBag()->messages();

        if (! empty($errors)) {
            $this->dispatch('updateTableErrors', $errors);
        }

        return view('livewire.purchase-return.purchase-return-create-form', [
            'rows' => $this->rows,
        ]);
    }
}

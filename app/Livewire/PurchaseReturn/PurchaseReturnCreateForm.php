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
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;

class PurchaseReturnCreateForm extends Component
{
    public $supplier_id = '';
    public $date;
    public $rows = []; // Centralized rows state
    public $note;
    public $grand_total = 0;

    protected $listeners = [
        'supplierSelected' => 'handleSupplierSelected',
        'updateRows' => 'handleUpdatedRows', // Receive row updates from table
    ];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    public function handleSupplierSelected($supplier)
    {
        if ($supplier) {
            Log::info('Updated supplier id: ', ['$supplier' => $supplier]);
            $this->supplier_id = $supplier['id'];
        } else {
            $this->supplier_id = null;
        }
    }

    public function handleUpdatedRows($updatedRows): void
    {
        $this->rows = $updatedRows;
        $this->grand_total = collect($this->rows)
            ->sum(fn($row) => isset($row['total']) ? (int) $row['total'] : 0);
        Log::info("Rows updated: ", $this->rows);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'required|exists:suppliers,id',
            'date' => 'required|date',
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
        Log::info('All public $this properties on submit:', get_object_vars($this));

        try {
            Validator::make(['supplier_id' => $this->supplier_id,
                'date' => $this->date,
                'rows' => $this->rows,
            ], $this->rules(), $this->messages())
                ->after(function ($validator) {
                    $productIds = [];
                    foreach ($this->rows as $index => $row) {
                        $productId = $row['product_id'] ?? null;
                        $qty = (int) ($row['quantity'] ?? 0);
                        $broken = (int) ($row['product_quantity'] ?? 0);

                        if ($qty > $broken) {
                            $validator->errors()->add("rows.$index.quantity", "Jumlah retur tidak boleh melebihi stok rusak ({$broken}).");
                        }

                        if ($row['serial_number_required'] && empty($row['serial_numbers'])) {
                            $validator->errors()->add("rows.$index.serial_numbers", "Produk memerlukan nomor seri.");
                        }

                        if (!is_null($productId)) {
                            if (in_array($productId, $productIds)) {
                                $validator->errors()->add("rows.$index.product_id", "Produk ini sudah dipilih sebelumnya.");
                            } else {
                                $productIds[] = $productId;
                            }
                        }

                        // ✅ SERIAL UNIQUENESS CHECK (see below)
                        if (!empty($row['serial_numbers'])) {
                            $serial_numbers = collect($row['serial_numbers'])->map(function ($item) {
                                return is_array($item) ? ($item['serial_number'] ?? null) : $item;
                            })->filter()->unique()->values()->all();

                            // Fetch matching serials from DB (only broken ones)
                            $existing = ProductSerialNumber::whereIn('serial_number', $serial_numbers)
                                ->where('is_broken', true)
                                ->pluck('serial_number')
                                ->unique()
                                ->values()
                                ->all();

                            $missing = array_diff($serial_numbers, $existing);
                            $extra   = array_diff($existing, $serial_numbers);

                            if (!empty($missing) || !empty($extra)) {
                                $validator->errors()->add(
                                    "rows.$index.serial_numbers",
                                    "Nomor seri tidak valid atau tidak rusak: " .
                                    implode(', ', array_merge($missing, $extra))
                                );
                            }

                        }
                    }
                })
                ->validate();

            Log::info("Form submitted");
            $this->dispatch('updateTableErrors', []);

            DB::beginTransaction();

            // Create Purchase Return
            $purchaseReturn = PurchaseReturn::create([
                'date' => $this->date,
                'supplier_id' => $this->supplier_id,
                'supplier_name' => optional($this->rows[0])['supplier_name'] ?? 'N/A',
                'total_amount' => $this->grand_total,
                'paid_amount' => 0,
                'due_amount' => $this->grand_total,
                'status' => 'PENDING',
                'payment_status' => 'UNPAID',
                'payment_method' => '',
                'note' => $this->note,
            ]);

            foreach ($this->rows as $row) {
                $serialNumberIds = collect($row['serial_numbers'] ?? [])
                    ->map(fn($sn) => is_array($sn) ? $sn['id'] ?? null : null)
                    ->filter()
                    ->values()
                    ->all();

                PurchaseReturnDetail::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'po_id' => $row['purchase_order_id'] ?? null,
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'],
                    'product_code' => $row['product_code'] ?? '',
                    'quantity' => $row['quantity'],
                    'unit_price' => (int) $row['purchase_price'],
                    'price' => (int) $row['purchase_price'], // Optional: same as unit_price
                    'sub_total' => $row['total'],
                    'product_discount_amount' => 0,
                    'product_tax_amount' => 0,
                    'serial_number_ids' => !empty($serialNumberIds) ? json_encode($serialNumberIds) : null,
                ]);
            }

            DB::commit();

            session()->flash('success', 'Retur pembelian berhasil disimpan.');
            return redirect()->route('purchase-returns.index');
        } catch (ValidationException $e) {
            Log::error('updateTableErrors', $e->validator->errors()->getMessages());
            $this->dispatch('updateTableErrors', $e->validator->errors()->getMessages());
            return null;
        } catch (Exception $e) {
            Log::error('Failed to save purchase return', ['message' => $e->getMessage()]);
            session()->flash('error', 'Terjadi kesalahan saat menyimpan retur pembelian.');
            return null;
        }
    }

    public function render(): Factory|Application|View
    {
        $errors = $this->getErrorBag()->messages();
        Log::info("Form render called: ", ['errors' => $errors]);

        if (!empty($errors)) {
            $this->dispatch('updateTableErrors', $errors);
        }

        return view('livewire.purchase-return.purchase-return-create-form', [
            'rows' => $this->rows,
        ]);
    }
}

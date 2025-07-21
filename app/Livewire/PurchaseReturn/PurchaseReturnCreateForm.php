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
                    foreach ($this->rows as $index => $row) {
                        $qty = (int) ($row['quantity'] ?? 0);
                        $broken = (int) ($row['product_quantity'] ?? 0);

                        if ($qty > $broken) {
                            $validator->errors()->add("rows.$index.quantity", "Jumlah retur tidak boleh melebihi stok rusak ({$broken}).");
                        }

                        if ($row['serial_number_required'] && empty($row['serial_numbers'])) {
                            $validator->errors()->add("rows.$index.serial_numbers", "Produk memerlukan nomor seri.");
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

                            // ✅ Check if all match exactly
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
            // continue with saving...

        } catch (ValidationException $e) {
            Log::error('updateTableErrors', $e->validator->errors()->getMessages());
            $this->dispatch('updateTableErrors', $e->validator->errors()->getMessages());
//            throw $e; // rethrow for Livewire to show errors too
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

<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class PurchaseReturnCreateForm extends Component
{
    public $supplier_id = '';
    public $date;
    public $rows = []; // Centralized rows state

    protected $listeners = [
        'supplierSelected' => 'supplierUpdated',
        'updateRows' => 'handleUpdatedRows', // Receive row updates from table
    ];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    // ✅ Update supplier ID when a supplier is selected
    public function supplierUpdated($supplier): void
    {
        $this->supplier_id = $supplier['id'];
        $this->rows = []; // Reset product rows when supplier changes
        Log::info("Supplier updated: ", ["supplier_id" => $this->supplier_id]);
    }

    // ✅ Handle row updates from `PurchaseReturnTable`
    public function handleUpdatedRows($updatedRows): void
    {
        $this->rows = $updatedRows;
        Log::info("Rows updated: ", $this->rows);
    }

    // ✅ Validation Rules
    public function rules()
    {
        return [
            'supplier_id' => 'required|exists:suppliers,id',
            'date' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.quantity' => 'required|integer|min:1',
            'rows.*.purchase_order_id' => 'required|exists:purchases,id',
        ];
    }

    public function messages()
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

            'rows.*.purchase_order_id.required' => 'Nomor purchase order harus dipilih.',
            'rows.*.purchase_order_id.exists' => 'Nomor purchase order tidak valid.',

            'rows.*.serial_numbers.required' => 'Serial number diperlukan untuk produk ini.',
            'rows.*.serial_numbers.array' => 'Format serial number tidak valid.',
        ];
    }

    // ✅ Ensure serial numbers match the quantity for serialized products
    public function validateSerialNumbers()
    {
        foreach ($this->rows as $index => $row) {
            if (!empty($row['serial_number_required']) && $row['quantity'] > 0) {
                $serialCount = count($row['serial_numbers'] ?? []);
                if ($serialCount !== (int) $row['quantity']) {
                    $this->addError("rows.$index.serial_numbers", "Jumlah serial number harus sesuai dengan jumlah yang dikembalikan.");
                }
            }
        }
    }

    // ✅ Submit the form
    public function submit()
    {
        // ✅ Log Before Validation
        Log::info("Before Validation: ", [
            'supplier_id' => $this->supplier_id,
            'date' => $this->date,
            'rows' => $this->rows
        ]);

        $this->validate();

        $productIds = array_column($this->rows, 'product_id');
        if (count($productIds) !== count(array_unique($productIds))) {
            $this->addError('rows', 'Tidak boleh ada produk yang sama dalam daftar retur.');
            return;
        }

        $this->validateSerialNumbers();

        // ✅ Log Validation Passed
        Log::info("Validation Passed: ", [
            'supplier_id' => $this->supplier_id,
            'date' => $this->date,
            'rows' => $this->rows
        ]);

        session()->flash('message', 'Purchase return successfully validated!');
    }


    public function render(): Factory|Application|View
    {
        $errors = $this->getErrorBag()->messages();
        Log::info("Form render called: ", ['errors' => $errors]);

        // ✅ If there are validation errors, dispatch an event to refresh the table
        if (!empty($errors)) {
            $this->dispatch('updateTableErrors', $errors);
        }

        return view('livewire.purchase-return.purchase-return-create-form', [
            'rows' => $this->rows, // ✅ Pass `rows` explicitly
        ]);
    }
}

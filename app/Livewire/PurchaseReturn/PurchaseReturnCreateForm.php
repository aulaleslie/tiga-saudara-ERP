<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
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

    public function submit()
    {
        Log::info("Before Validation: ", [
            'supplier_id' => $this->supplier_id,
            'date' => $this->date,
            'rows' => $this->rows
        ]);

        $this->validate();

        // Ensure no duplicate products
        $productIds = array_column($this->rows, 'product_id');
        if (count($productIds) !== count(array_unique($productIds))) {
            $this->addError('rows', 'Tidak boleh ada produk yang sama dalam daftar retur.');
            return;
        }

        try {
            DB::beginTransaction();

            // Create the Purchase Return Record
            $purchaseReturn = PurchaseReturn::create([
                'date' => $this->date,
                'supplier_id' => $this->supplier_id,
                'supplier_name'=> '',
                'status' => 'DRAFT', // Default document status
                'total_amount' => 0,
                'paid_amount' => 0,
                'due_amount' => 0,
                'payment_method' => '',
                'payment_status' => '',
                'note' => $this->note,
            ]);

            foreach ($this->rows as $row) {
                if (empty($row['product_id']) || empty($row['quantity']) || empty($row['purchase_order_id'])) {
                    continue;
                }

                PurchaseReturnDetail::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'product_id' => $row['product_id'],
                    'po_id' => $row['purchase_order_id'],
                ]);
            }

            DB::commit();

            session()->flash('message', 'Purchase return successfully saved as draft!');
            redirect()->route('purchase-returns.index');
            return;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving purchase return: ', ['error' => $e->getMessage()]);
            $this->addError('form', 'Terjadi kesalahan saat menyimpan retur pembelian.');
            return;
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

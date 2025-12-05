<?php

namespace App\Livewire\PurchaseReturn;

use App\Livewire\PurchaseReturn\Concerns\ValidatesPurchaseReturnForm;
use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\People\Entities\Supplier;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;

class PurchaseReturnCreateForm extends Component
{
    use ValidatesPurchaseReturnForm;


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

    /**
     * @throws ValidationException
     */
    public function submit()
    {
        Log::info('Submitting purchase return form', get_object_vars($this));

        $this->dispatchBrowserEvent('purchase-return:submit-start');

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
        } finally {
            $this->dispatchBrowserEvent('purchase-return:submit-finish');
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

        $validator = $this->makePurchaseReturnValidator($data);

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

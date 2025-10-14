<?php

namespace App\Livewire\SalesReturn;

use App\Livewire\SalesReturn\Concerns\ValidatesSaleReturnForm;
use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class SaleReturnCreateForm extends Component
{
    use ValidatesSaleReturnForm;

    public ?int $sale_id = null;
    public string $date;
    public array $rows = [];
    public ?string $note = null;
    public float $grand_total = 0.0;

    public string $formTitle = 'Buat Retur Penjualan';
    public string $submitLabel = 'Simpan Retur';

    public ?string $saleReference = null;
    public ?string $customerName = null;
    public ?string $locationName = null;
    public bool $approvalLocked = false;

    protected $listeners = [
        'saleReferenceSelected' => 'handleSaleSelected',
        'updateRows' => 'handleUpdatedRows',
    ];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    public function render(): View|Factory|Application
    {
        $errors = $this->getErrorBag()->messages();
        if (! empty($errors)) {
            $this->dispatch('updateTableErrors', $errors);
        }

        return view('livewire.sales-return.sale-return-create-form', [
            'rows' => $this->rows,
        ]);
    }

    public function handleSaleSelected(array $saleData): void
    {
        $saleId = $saleData['id'] ?? null;

        if (! $saleId) {
            $this->sale_id = null;
            $this->saleReference = null;
            $this->customerName = null;
            $this->rows = [];
            $this->grand_total = 0;
            $this->dispatch('hydrateSaleReturnRows', $this->rows, $this->sale_id, null);
            return;
        }

        $sale = Sale::query()
            ->with(['customer', 'saleDetails', 'saleDispatches.details.product', 'saleDispatches.details.location'])
            ->find($saleId);

        if (! $sale) {
            session()->flash('error', 'Penjualan tidak ditemukan.');
            return;
        }

        $this->sale_id = $sale->id;
        $this->saleReference = $sale->reference;
        $this->customerName = $sale->customer_name ?: optional($sale->customer)->customer_name;

        $this->rows = $this->mapRowsFromSale($sale);

        if (empty($this->rows)) {
            session()->flash('warning', 'Data pengiriman untuk penjualan yang dipilih tidak ditemukan.');
        }

        $this->grand_total = $this->calculateReturnTotal();

        $this->dispatch('hydrateSaleReturnRows', $this->rows, $this->sale_id, null);
    }

    public function handleUpdatedRows(array $rows): void
    {
        $this->rows = $rows;
        $this->grand_total = $this->calculateReturnTotal();
    }

    protected function mapRowsFromSale(Sale $sale, ?int $excludeSaleReturnId = null): array
    {
        $dispatchDetails = DispatchDetail::query()
            ->with(['product', 'location'])
            ->where(function ($query) use ($sale) {
                $query->where('sale_id', $sale->id)
                    ->orWhereHas('dispatch', function ($dispatchQuery) use ($sale) {
                        $dispatchQuery->where('sale_id', $sale->id);
                    });
            })
            ->get()
            ->unique('id')
            ->values();

        if ($dispatchDetails->isEmpty()) {
            return [];
        }

        $saleDetails = $sale->saleDetails
            ->groupBy('product_id');

        $returnedQuantities = SaleReturnDetail::query()
            ->selectRaw('dispatch_detail_id, SUM(quantity) as total')
            ->whereIn('dispatch_detail_id', $dispatchDetails->pluck('id')->all())
            ->when($excludeSaleReturnId, function ($query) use ($excludeSaleReturnId) {
                $query->where('sale_return_id', '!=', $excludeSaleReturnId);
            })
            ->whereHas('saleReturn', function ($query) {
                $query->whereNotIn('approval_status', ['rejected']);
            })
            ->groupBy('dispatch_detail_id')
            ->get()
            ->keyBy('dispatch_detail_id');

        return $dispatchDetails->map(function (DispatchDetail $detail) use ($saleDetails, $returnedQuantities) {
            $saleDetail = optional($saleDetails->get($detail->product_id))->first();
            $dispatched = (int) $detail->dispatched_quantity;
            $returned = (int) optional($returnedQuantities->get($detail->id))->total;
            $available = max($dispatched - $returned, 0);

            if ($available <= 0) {
                return null;
            }

            $unitPrice = $saleDetail ? (float) ($saleDetail->unit_price ?? $saleDetail->price ?? 0) : 0.0;

            return [
                'dispatch_detail_id' => $detail->id,
                'sale_detail_id' => $saleDetail->id ?? null,
                'product_id' => $detail->product_id,
                'product_name' => $detail->product->product_name ?? '-',
                'product_code' => $detail->product->product_code ?? null,
                'serial_number_required' => (bool) ($detail->product->serial_number_required ?? false),
                'serial_numbers' => [],
                'quantity' => 0,
                'available_quantity' => $available,
                'dispatched_quantity' => $dispatched,
                'returned_quantity' => $returned,
                'unit_price' => $unitPrice,
                'total' => 0,
                'location_id' => $detail->location_id,
                'location_name' => optional($detail->location)->name,
                'tax_id' => $detail->tax_id ?? null,
            ];
        })->filter()->values()->all();
    }

    protected function calculateReturnTotal(): float
    {
        return round(collect($this->rows)->sum(function ($row) {
            return (float) ($row['total'] ?? ((int) ($row['quantity'] ?? 0) * (float) ($row['unit_price'] ?? 0)));
        }), 2);
    }

    public function submit()
    {
        try {
            $prepared = $this->validateAndPrepare();

            DB::transaction(function () use ($prepared) {
                $sale = Sale::find($this->sale_id);
                $customerId = optional($sale)->customer_id;
                $customerName = optional($sale)->customer_name ?: optional(optional($sale)->customer)->customer_name;
                $settingId = optional($sale)->setting_id ?: session('setting_id');

                $locationId = $this->determineLocationId($prepared['rows']);

                $saleReturn = SaleReturn::create([
                    'date' => $this->date,
                    'sale_id' => $this->sale_id,
                    'sale_reference' => optional($sale)->reference,
                    'customer_id' => $customerId,
                    'customer_name' => $customerName ?? '-',
                    'setting_id' => $settingId,
                    'location_id' => $locationId,
                    'tax_percentage' => 0,
                    'tax_amount' => 0,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => $prepared['total'],
                    'paid_amount' => 0,
                    'due_amount' => $prepared['total'],
                    'status' => 'Pending Approval',
                    'approval_status' => 'pending',
                    'payment_status' => 'Unpaid',
                    'payment_method' => 'Pending',
                    'note' => $this->note,
                    'return_type' => null,
                ]);

                foreach ($prepared['rows'] as $row) {
                    $serialIds = collect($row['serial_numbers'] ?? [])
                        ->map(fn ($serial) => is_array($serial) ? ($serial['id'] ?? null) : null)
                        ->filter()
                        ->values()
                        ->all();

                    SaleReturnDetail::create([
                        'sale_return_id' => $saleReturn->id,
                        'sale_detail_id' => $row['sale_detail_id'] ?? null,
                        'dispatch_detail_id' => $row['dispatch_detail_id'],
                        'location_id' => $row['location_id'] ?? null,
                        'tax_id' => $row['tax_id'] ?? null,
                        'product_id' => $row['product_id'],
                        'product_name' => $row['product_name'] ?? '-',
                        'product_code' => $row['product_code'] ?? null,
                        'quantity' => (int) $row['quantity'],
                        'unit_price' => (float) ($row['unit_price'] ?? 0),
                        'price' => (float) ($row['unit_price'] ?? 0),
                        'sub_total' => (float) ($row['total'] ?? 0),
                        'product_discount_amount' => 0,
                        'product_tax_amount' => 0,
                        'serial_number_ids' => $serialIds,
                    ]);
                }
            });

            session()->flash('success', 'Retur penjualan berhasil disimpan dan menunggu persetujuan.');
            return redirect()->route('sale-returns.index');
        } catch (ValidationException $e) {
            Log::warning('Validasi retur penjualan gagal', ['errors' => $e->validator->errors()->getMessages()]);
            $this->dispatch('updateTableErrors', $e->validator->errors()->getMessages());
            throw $e;
        } catch (Exception $e) {
            Log::error('Gagal menyimpan retur penjualan', ['message' => $e->getMessage()]);
            session()->flash('error', 'Terjadi kesalahan saat menyimpan retur penjualan.');
        }

        return null;
    }

    protected function validateAndPrepare(): array
    {
        $data = [
            'sale_id' => $this->sale_id,
            'date' => $this->date,
            'rows' => $this->rows,
        ];

        $validator = $this->makeSaleReturnValidator($data);
        $validator->validate();

        $validRows = collect($this->rows)
            ->filter(fn ($row) => (int) ($row['quantity'] ?? 0) > 0)
            ->values()
            ->all();

        $total = round(collect($validRows)->sum(fn ($row) => (float) ($row['total'] ?? 0)), 2);

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'rows' => 'Nilai retur harus lebih dari 0.',
            ]);
        }

        $this->dispatch('updateTableErrors', []);

        return [
            'total' => $total,
            'rows' => $validRows,
        ];
    }

    protected function determineLocationId(array $rows): ?int
    {
        $locations = collect($rows)
            ->pluck('location_id')
            ->filter()
            ->unique()
            ->values();

        return $locations->count() === 1 ? $locations->first() : null;
    }
}

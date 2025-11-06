<?php

namespace App\Livewire\SalesReturn;

use App\Livewire\SalesReturn\Concerns\ValidatesSaleReturnForm;
use App\Support\SalesReturn\SaleReturnEligibilityService;
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

    protected function eligibilityService(): SaleReturnEligibilityService
    {
        return app(SaleReturnEligibilityService::class);
    }

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

        $prefetchedRows = collect($saleData['rows'] ?? [])->map(function ($row) {
            return is_array($row) ? $row : [];
        })->filter(fn ($row) => ! empty($row))->values();

        if ($prefetchedRows->isEmpty()) {
            $sale = Sale::query()
                ->with(['customer', 'saleDetails.product', 'saleDetails.bundleItems', 'saleDispatches.details.product', 'saleDispatches.details.location'])
                ->find($saleId);

            if (! $sale) {
                session()->flash('error', 'Penjualan tidak ditemukan.');
                return;
            }

            if (! $this->eligibilityService()->isSaleEligible($sale)) {
                session()->flash('error', 'Penjualan belum siap untuk diretur.');
                return;
            }

            $summary = $this->eligibilityService()->summariseSale($sale);

            if ($summary['returnable_lines'] === 0) {
                session()->flash('error', 'Tidak ada produk yang dapat diretur dari penjualan ini.');
                return;
            }

            $saleReference = $sale->reference;
            $customerName = $sale->customer_name ?: optional($sale->customer)->customer_name;
            $rows = $summary['rows']->map(fn ($row) => $row)->all();
        } else {
            $saleReference = $saleData['reference'] ?? null;
            $customerName = $saleData['customer_name'] ?? null;
            $rows = $prefetchedRows->all();
        }

        $saleReference ??= $saleData['reference'] ?? null;
        $customerName ??= $saleData['customer_name'] ?? null;

        $this->sale_id = $saleId;
        $this->saleReference = $saleReference;
        $this->customerName = $customerName;

        $this->rows = $rows;
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
        $summary = $this->eligibilityService()->summariseSale($sale, $excludeSaleReturnId);

        return $summary['rows']->map(fn ($row) => $row)->all();
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

            // Validate sale exists before transaction
            $sale = Sale::find($this->sale_id);
            if (! $sale) {
                throw new Exception('Penjualan tidak ditemukan.');
            }

            DB::transaction(function () use ($prepared, $sale) {
                $customerId = $sale->customer_id;
                $customerName = $sale->customer_name ?: optional($sale->customer)->customer_name;
                $settingId = $sale->setting_id ?: session('setting_id');

                if (! $settingId) {
                    throw new Exception('Setting ID tidak valid.');
                }

                $locationId = $this->determineLocationId($prepared['rows']);

                $saleReturn = SaleReturn::create([
                    'date' => $this->date,
                    'sale_id' => $this->sale_id,
                    'sale_reference' => $sale->reference,
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
                        ->unique()
                        ->values()
                        ->all();

                    // Validate product exists
                    if (empty($row['product_id'])) {
                        throw new Exception('Product ID tidak valid untuk salah satu item.');
                    }

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

        if (empty($validRows)) {
            throw ValidationException::withMessages([
                'rows' => 'Tidak ada produk dengan kuantitas valid untuk diretur.',
            ]);
        }

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

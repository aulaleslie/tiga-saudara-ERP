<?php

namespace App\Livewire\SalesReturn;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnGood;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;

class SaleReturnSettlementForm extends Component
{
    use WithFileUploads;

    public SaleReturn $saleReturn;
    public int $saleReturnId;
    public array $settlementLines = [];
    public bool $isReadOnly = false;
    public array $unpaidSales = [];
    public $locations = [];

    public function mount(int $saleReturnId): void
    {
        $this->saleReturnId = $saleReturnId;
        $this->locations = Location::all();
        $this->loadSaleReturn();
    }

    protected function loadSaleReturn(): void
    {
        $this->saleReturn = SaleReturn::with([
            'saleReturnDetails.product',
            'saleReturnDetails.saleDetail.sale',
            'saleReturnDetails.location',
            'settlementItems',
        ])->findOrFail($this->saleReturnId);

        // Header level read-only check (if completed)
        if ($this->saleReturn->status === 'Completed') {
            $this->isReadOnly = true;
        }

        $this->settlementLines = [];

        foreach ($this->saleReturn->saleReturnDetails as $detail) {
            // Map existing settlement items for this detail if they exist
            $existingSettlements = $this->saleReturn->settlementItems
                ->where('sale_return_detail_id', $detail->id);

            if ($detail->product->serial_number_required) {
                // Get ProductSerialNumber entities
                $snEntities = ProductSerialNumber::whereIn('id', $detail->serial_number_ids ?? [])->get();
                
                foreach ($snEntities as $snEntity) {
                    $existing = $existingSettlements->where('product_serial_number_id', $snEntity->id)->first();
                    
                    $this->settlementLines[] = [
                        'id' => $existing->id ?? null,
                        'detail_id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product->product_name,
                        'product_code' => $detail->product->product_code,
                        'serial_number' => $snEntity->serial_number,
                        'serial_number_id' => $snEntity->id,
                        'method' => $existing->method ?? '',
                        'nominal' => (float) ($existing->nominal ?? $detail->unit_price),
                        'max_nominal' => (float) $detail->unit_price,
                        'target_sale_id' => $existing->target_sale_id ?? null,
                        'status' => $existing->status ?? SaleReturnItemSettlement::STATUS_DRAFT,
                        'rejection_reason' => $existing->rejection_reason ?? null,
                        'new_serial_number' => $existing->new_serial_number ?? null,
                        'location_id' => $existing->location_id ?? null,
                        'notes' => $existing->notes ?? null,
                        'proof_file' => null, // for upload
                    ];
                }
            } else {
                $existing = $existingSettlements->whereNull('product_serial_number_id')->first();
                
                $this->settlementLines[] = [
                    'id' => $existing->id ?? null,
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->product_name,
                    'product_code' => $detail->product->product_code,
                    'serial_number' => null,
                    'serial_number_id' => null,
                    'method' => $existing->method ?? '',
                    'nominal' => (float) ($existing->nominal ?? $detail->sub_total),
                    'max_nominal' => (float) $detail->sub_total,
                    'target_sale_id' => $existing->target_sale_id ?? null,
                    'status' => $existing->status ?? SaleReturnItemSettlement::STATUS_DRAFT,
                    'rejection_reason' => $existing->rejection_reason ?? null,
                    'quantity' => $detail->quantity,
                    'location_id' => $existing->location_id ?? null,
                    'notes' => $existing->notes ?? null,
                    'proof_file' => null, // for upload
                ];
            }
        }

        $this->loadUnpaidSales();
    }

    protected function loadUnpaidSales(): void
    {
        if (!$this->saleReturn->customer_id) {
            $this->unpaidSales = [];
            return;
        }

        $productIds = $this->saleReturn->saleReturnDetails->pluck('product_id')->unique();
        $this->unpaidSales = [];

        foreach ($productIds as $productId) {
            $this->unpaidSales[$productId] = Sale::where('customer_id', $this->saleReturn->customer_id)
                ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])
                ->whereHas('saleDetails', function ($query) use ($productId) {
                    $query->where('product_id', $productId);
                })
                ->with(['saleDetails' => function ($query) use ($productId) {
                    $query->where('product_id', $productId)->select('id', 'sale_id', 'product_id', 'quantity', 'unit_price');
                }])
                ->select(['id', 'reference', 'due_amount', 'total_amount', 'paid_amount', 'date'])
                ->orderBy('date', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($sale) use ($productId) {
                    $statusLabel = ' (Partial)';
                    if ((float) $sale->due_amount <= 0) {
                        $statusLabel = ' (Paid)';
                    } elseif ((float) $sale->paid_amount <= 0) {
                        $statusLabel = ' (Unpaid)';
                    }
                    
                    $saleDetail = $sale->saleDetails->where('product_id', $productId)->first();
                    
                    return [
                        'id' => $sale->id,
                        'label' => $sale->reference . $statusLabel . ($sale->due_amount > 0 ? ' - Due: ' . format_currency($sale->due_amount / 100) : ''),
                        'text' => $sale->reference,
                        'due_amount' => $sale->due_amount / 100,
                        'product_unit_price' => (float) ($saleDetail?->unit_price / 100 ?? 0),
                    ];
                })
                ->toArray();
        }
    }

    public function updatedSettlementLines($value, $key)
    {
        if (Str::endsWith($key, '.method')) {
            $index = explode('.', $key)[0];
            $method = $this->settlementLines[$index]['method'] ?? '';
            
            if (in_array($method, [SaleReturnDetail::METHOD_CASH_REFUND])) {
                $this->settlementLines[$index]['nominal'] = $this->settlementLines[$index]['max_nominal'];
            } elseif ($method === SaleReturnDetail::METHOD_PRODUCT_REPAIR || $method === SaleReturnDetail::METHOD_UNPROCESSED) {
                $this->settlementLines[$index]['nominal'] = 0;
            }
        }

        if (Str::endsWith($key, '.target_sale_id')) {
            $index = explode('.', $key)[0];
            $line = &$this->settlementLines[$index];
            $method = $line['method'] ?? '';
            
            if ($method === SaleReturnDetail::METHOD_MODIFY_SALE && !empty($line['target_sale_id'])) {
                $productId = $line['product_id'];
                $selectedSale = collect($this->unpaidSales[$productId] ?? [])->firstWhere('id', $line['target_sale_id']);
                
                if ($selectedSale) {
                    $unitPrice = (float) $selectedSale['product_unit_price'];
                    $quantity = (float) ($line['quantity'] ?? 1);
                    $newNominal = $unitPrice * $quantity;
                    $line['nominal'] = min($newNominal, $line['max_nominal']);
                }
            }
        }
    }

    protected function rulesForLineSubmit(int $index): array
    {
        $line = $this->settlementLines[$index];
        $maxNominal = $line['max_nominal'] ?? 0;

        if ($line['method'] === SaleReturnDetail::METHOD_PRODUCT_REPAIR) {
            if ($line['serial_number_id']) {
                $rules["settlementLines.{$index}.new_serial_number"] = 'required|string|different:settlementLines.' . $index . '.serial_number|exists:product_serial_numbers,serial_number';
            } else {
                $rules["settlementLines.{$index}.location_id"] = 'required|exists:locations,id';
            }
        }

        if ($line['method'] === SaleReturnDetail::METHOD_CASH_REFUND) {
            $rules["settlementLines.{$index}.proof_file"] = 'nullable|image|max:2048';
        }

        if ($line['method'] === SaleReturnDetail::METHOD_UNPROCESSED) {
            $rules["settlementLines.{$index}.notes"] = 'required|string|max:500';
        }

        return $rules;
    }

    public function submitLine(int $index)
    {
        $this->validate($this->rulesForLineSubmit($index));

        try {
            DB::transaction(function () use ($index) {
                $line = $this->settlementLines[$index];
                
                $proofPath = $line['id'] ? SaleReturnItemSettlement::find($line['id'])->proof_path : null;
                if ($line['proof_file']) {
                    $proofPath = $line['proof_file']->store('sale_return_settlements', 'public');
                }

                $settlement = SaleReturnItemSettlement::updateOrCreate(
                    [
                        'sale_return_id' => $this->saleReturn->id,
                        'sale_return_detail_id' => $line['detail_id'],
                        'product_serial_number_id' => $line['serial_number_id'],
                    ],
                    [
                        'method' => $line['method'],
                        'nominal' => $line['nominal'],
                        'target_sale_id' => $line['target_sale_id'] ?? null,
                        'location_id' => $line['location_id'] ?? null,
                        'new_serial_number' => $line['new_serial_number'] ?? null,
                        'notes' => $line['notes'] ?? null,
                        'proof_path' => $proofPath,
                        'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
                        'submitted_at' => now(),
                        'submitted_by' => Auth::id(),
                        'rejected_at' => null,
                        'rejected_by' => null,
                        'rejection_reason' => null,
                    ]
                );

                $this->settlementLines[$index]['id'] = $settlement->id;
                $this->settlementLines[$index]['status'] = $settlement->status;
            });

            session()->flash('success', 'Baris penyelesaian dikirim untuk persetujuan.');
        } catch (Exception $e) {
            Log::error('Failed to submit sale return settlement line', [
                'sale_return_id' => $this->saleReturn->id,
                'index' => $index,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat mengirim baris penyelesaian.');
        }
    }

    public function resetLine(int $index)
    {
        $line = $this->settlementLines[$index];
        if ($line['status'] !== SaleReturnItemSettlement::STATUS_REJECTED) {
            return;
        }

        try {
            SaleReturnItemSettlement::where('id', $line['id'])->update([
                'status' => SaleReturnItemSettlement::STATUS_DRAFT,
                'rejection_reason' => null,
            ]);

            $this->settlementLines[$index]['status'] = SaleReturnItemSettlement::STATUS_DRAFT;
            $this->settlementLines[$index]['rejection_reason'] = null;
        } catch (Exception $e) {
            Log::error('Failed to reset sale return settlement line', [
                'sale_return_id' => $this->saleReturn->id,
                'index' => $index,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat mereset baris.');
        }
    }

    public function submit()
    {
        if ($this->isReadOnly) {
            return null;
        }

        // Finalize logic if all lines are approved or similar?
        // Actually, in the current workflow, we want to allow "Bulk Draft Save" if needed
        // but the main goal is per-row approval.
        
        session()->flash('info', 'Gunakan tombol "Kirim" pada setiap baris untuk mengajukan penyelesaian.');
        return null;
    }

    public function render(): Factory|Application|View
    {
        return view('livewire.sales-return.sale-return-settlement-form', [
            'saleReturn' => $this->saleReturn,
            'total' => (float) $this->saleReturn->total_amount,
            'isReadOnly' => $this->isReadOnly,
            'methods' => SaleReturnDetail::selectableSettlementMethods(),
            'allMethods' => SaleReturnDetail::settlementMethods(),
            'unpaidSales' => $this->unpaidSales,
        ]);
    }
}

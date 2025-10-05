<?php

namespace App\Livewire\SalesReturn\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;
use Modules\SalesReturn\Entities\SaleReturnDetail;

trait ValidatesSaleReturnForm
{
    protected ?int $editingSaleReturnId = null;

    protected function saleReturnRules(): array
    {
        return [
            'sale_id' => 'required|exists:sales,id',
            'date' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.dispatch_detail_id' => 'required|exists:dispatch_details,id',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.quantity' => 'required|integer|min:0',
        ];
    }

    protected function saleReturnMessages(): array
    {
        return [
            'sale_id.required' => 'Pilih referensi penjualan terlebih dahulu.',
            'sale_id.exists' => 'Referensi penjualan tidak valid.',
            'date.required' => 'Tanggal retur wajib diisi.',
            'date.date' => 'Format tanggal tidak valid.',
            'rows.required' => 'Tidak ada data produk yang dapat diretur.',
            'rows.array' => 'Format data produk tidak valid.',
            'rows.min' => 'Tidak ada produk yang dapat diretur.',
            'rows.*.dispatch_detail_id.required' => 'Informasi pengiriman produk tidak valid.',
            'rows.*.dispatch_detail_id.exists' => 'Detail pengiriman tidak ditemukan.',
            'rows.*.product_id.required' => 'Produk wajib diisi.',
            'rows.*.product_id.exists' => 'Produk tidak valid.',
            'rows.*.quantity.required' => 'Jumlah retur wajib diisi.',
            'rows.*.quantity.integer' => 'Jumlah retur harus berupa angka bulat.',
            'rows.*.quantity.min' => 'Jumlah retur tidak boleh negatif.',
        ];
    }

    protected function makeSaleReturnValidator(array $data): LaravelValidator
    {
        $validator = Validator::make($data, $this->saleReturnRules(), $this->saleReturnMessages());

        $validator->after(function (LaravelValidator $validator) {
            $this->applySaleReturnAfterValidation($validator);
        });

        return $validator;
    }

    protected function applySaleReturnAfterValidation(LaravelValidator $validator): void
    {
        $saleId = (int) ($this->sale_id ?? 0);
        $dispatchDetails = $this->collectDispatchDetails();
        $serialUsage = $this->collectSerialAssignments();

        foreach ($this->rows as $index => $row) {
            $dispatchDetailId = (int) ($row['dispatch_detail_id'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);

            if ($dispatchDetailId === 0) {
                continue;
            }

            $dispatchDetail = $dispatchDetails[$dispatchDetailId] ?? null;
            if (! $dispatchDetail) {
                $validator->errors()->add("rows.$index.dispatch_detail_id", 'Detail pengiriman tidak ditemukan atau tidak valid.');
                continue;
            }

            if ($saleId && (int) $dispatchDetail->sale_id !== $saleId) {
                $validator->errors()->add("rows.$index.dispatch_detail_id", 'Produk tidak berasal dari penjualan yang dipilih.');
            }

            $available = $this->resolveAvailableQuantity($dispatchDetailId, $row, $dispatchDetail->dispatched_quantity ?? 0);
            if ($quantity > $available) {
                $validator->errors()->add(
                    "rows.$index.quantity",
                    "Jumlah retur melebihi jumlah yang dapat dikembalikan ({$available})."
                );
            }

            $serialNumbers = collect($row['serial_numbers'] ?? [])
                ->map(function ($serial) {
                    if (is_array($serial)) {
                        return [
                            'id' => $serial['id'] ?? null,
                            'serial_number' => $serial['serial_number'] ?? null,
                        ];
                    }

                    return is_string($serial) ? ['id' => null, 'serial_number' => $serial] : null;
                })
                ->filter(fn ($serial) => ! empty($serial['serial_number']) || ! empty($serial['id']))
                ->values();

            $requiresSerial = ! empty($row['serial_number_required']);
            if ($requiresSerial && $serialNumbers->isEmpty()) {
                $validator->errors()->add("rows.$index.serial_numbers", 'Produk ini memerlukan nomor seri.');
            }

            if ($serialNumbers->isNotEmpty() && $quantity !== $serialNumbers->count()) {
                $validator->errors()->add(
                    "rows.$index.serial_numbers",
                    'Jumlah nomor seri tidak sesuai dengan kuantitas yang diretur.'
                );
            }

            if ($serialNumbers->isNotEmpty()) {
                $selectedIds = $serialNumbers
                    ->map(fn ($serial) => $serial['id'] ?? null)
                    ->filter()
                    ->values();

                if ($selectedIds->isEmpty()) {
                    // IDs missing, attempt to resolve by serial number text
                    $serials = ProductSerialNumber::query()
                        ->where('dispatch_detail_id', $dispatchDetailId)
                        ->whereIn('serial_number', $serialNumbers->pluck('serial_number')->filter()->all())
                        ->get()
                        ->keyBy('serial_number');

                    $selectedIds = $serialNumbers
                        ->map(fn ($serial) => optional($serials->get($serial['serial_number']))->id)
                        ->filter()
                        ->values();
                }

                if ($selectedIds->count() !== $serialNumbers->count()) {
                    $validator->errors()->add(
                        "rows.$index.serial_numbers",
                        'Sebagian nomor seri tidak ditemukan pada pengiriman terkait.'
                    );
                    continue;
                }

                $invalidSerials = $selectedIds->filter(function ($serialId) use ($dispatchDetailId, $serialUsage) {
                    $assignment = $serialUsage[$serialId] ?? null;
                    if (! $assignment) {
                        return true;
                    }

                    if ((int) $assignment['dispatch_detail_id'] !== $dispatchDetailId) {
                        return true;
                    }

                    if (! empty($assignment['reserved']) && (int) $assignment['reserved'] !== (int) $this->editingSaleReturnId) {
                        return true;
                    }

                    return false;
                });

                if ($invalidSerials->isNotEmpty()) {
                    $validator->errors()->add(
                        "rows.$index.serial_numbers",
                        'Nomor seri tidak sesuai dengan pengiriman atau sudah diretur pada dokumen lain.'
                    );
                }
            }
        }

        if (method_exists($this, 'calculateReturnTotal') && $this->calculateReturnTotal() <= 0) {
            $validator->errors()->add('rows', 'Nilai retur harus lebih dari 0.');
        }
    }

    protected function resolveAvailableQuantity(int $dispatchDetailId, array $row, int $dispatchedQuantity): int
    {
        $existingQuantity = (int) ($row['original_quantity'] ?? 0);

        $returned = SaleReturnDetail::query()
            ->where('dispatch_detail_id', $dispatchDetailId)
            ->when($this->editingSaleReturnId, function ($query) {
                $query->where('sale_return_id', '!=', $this->editingSaleReturnId);
            })
            ->whereHas('saleReturn', function ($query) {
                $query->whereNotIn('approval_status', ['rejected']);
            })
            ->sum('quantity');

        $available = max($dispatchedQuantity - $returned, 0);

        return $available + $existingQuantity;
    }

    protected function collectDispatchDetails(): array
    {
        $ids = collect($this->rows)
            ->pluck('dispatch_detail_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DispatchDetail::query()
            ->with('product')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    protected function collectSerialAssignments(): array
    {
        $serialIds = collect($this->rows)
            ->flatMap(function ($row) {
                $serials = $row['serial_numbers'] ?? [];
                return collect($serials)->map(function ($serial) {
                    if (is_array($serial)) {
                        return $serial['id'] ?? null;
                    }

                    return null;
                });
            })
            ->filter()
            ->unique()
            ->values();

        if ($serialIds->isEmpty()) {
            return [];
        }

        $assignments = ProductSerialNumber::query()
            ->select(['id', 'dispatch_detail_id'])
            ->whereIn('id', $serialIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($serial) => [
                'dispatch_detail_id' => $serial->dispatch_detail_id,
            ])->all();

        $reservations = SaleReturnDetail::query()
            ->where(function ($query) use ($serialIds) {
                foreach ($serialIds as $serialId) {
                    $query->orWhereJsonContains('serial_number_ids', $serialId);
                }
            })
            ->whereHas('saleReturn', function ($query) {
                $query->whereNotIn('approval_status', ['rejected']);
            })
            ->get(['sale_return_id', 'serial_number_ids']);

        foreach ($reservations as $reservation) {
            $ids = collect($reservation->serial_number_ids ?? [])
                ->filter()
                ->values();

            foreach ($ids as $id) {
                if (! isset($assignments[$id])) {
                    continue;
                }

                $assignments[$id]['reserved'] = $reservation->sale_return_id;
            }
        }

        return $assignments;
    }

    public function rules(): array
    {
        return $this->saleReturnRules();
    }

    public function messages(): array
    {
        return $this->saleReturnMessages();
    }
}

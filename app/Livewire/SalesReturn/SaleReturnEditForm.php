<?php

namespace App\Livewire\SalesReturn;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class SaleReturnEditForm extends SaleReturnCreateForm
{
    public SaleReturn $saleReturn;

    public function mount(?SaleReturn $saleReturn = null): void
    {
        parent::mount();

        if (! $saleReturn) {
            abort(404);
        }

        $this->saleReturn = $saleReturn->loadMissing([
            'sale',
            'sale.customer',
            'saleReturnDetails.product',
            'saleReturnDetails.dispatchDetail.product',
            'saleReturnDetails.dispatchDetail.location',
        ]);

        $this->formTitle = 'Ubah Retur Penjualan';
        $this->submitLabel = 'Simpan Perubahan';
        $this->editingSaleReturnId = $this->saleReturn->id;

        $approvalStatus = strtolower((string) $this->saleReturn->approval_status);
        $this->approvalLocked = $approvalStatus === 'approved';

        $this->sale_id = $this->saleReturn->sale_id;
        $this->saleReference = $this->saleReturn->sale_reference ?? optional($this->saleReturn->sale)->reference;
        $this->customerName = $this->saleReturn->customer_name;

        $date = $this->saleReturn->date;
        $this->date = $date instanceof Carbon ? $date->format('Y-m-d') : ($date ?: now()->format('Y-m-d'));
        $this->note = $this->saleReturn->note;

        $sale = $this->saleReturn->sale ?? ($this->sale_id ? Sale::find($this->sale_id) : null);
        if (! $sale && $this->sale_id) {
            $sale = Sale::query()->with(['saleDispatches.details.product', 'saleDispatches.details.location'])->find($this->sale_id);
        }

        $baseRows = $sale ? collect($this->mapRowsFromSale($sale, $this->saleReturn->id)) : collect();
        $rowsByDispatch = $baseRows->keyBy('dispatch_detail_id');

        $serialIds = $this->saleReturn->saleReturnDetails
            ->pluck('serial_number_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $serialMap = $serialIds->isEmpty()
            ? collect()
            : ProductSerialNumber::query()->whereIn('id', $serialIds)->get()->keyBy('id');

        foreach ($this->saleReturn->saleReturnDetails as $detail) {
            $dispatch = $detail->dispatchDetail ?? DispatchDetail::query()->with(['product', 'location'])->find($detail->dispatch_detail_id);

            if (! $dispatch) {
                continue;
            }

            $row = $rowsByDispatch->get($dispatch->id);

            if (! $row) {
                $unitPrice = (float) ($detail->unit_price ?? $detail->price ?? 0);
                $row = [
                    'dispatch_detail_id' => $dispatch->id,
                    'sale_detail_id' => $detail->sale_detail_id,
                    'product_id' => $dispatch->product_id,
                    'product_name' => optional($dispatch->product)->product_name ?? '-',
                    'product_code' => optional($dispatch->product)->product_code,
                    'serial_number_required' => (bool) optional($dispatch->product)->serial_number_required,
                    'serial_numbers' => [],
                    'quantity' => 0,
                    'available_quantity' => (int) $dispatch->dispatched_quantity,
                    'dispatched_quantity' => (int) $dispatch->dispatched_quantity,
                    'returned_quantity' => 0,
                    'unit_price' => $unitPrice,
                    'total' => 0,
                    'location_id' => $dispatch->location_id,
                    'location_name' => optional($dispatch->location)->name,
                    'tax_id' => $dispatch->tax_id,
                ];
            }

            $serialNumbers = collect($detail->serial_number_ids ?? [])
                ->map(function ($id) use ($serialMap) {
                    $serial = $serialMap->get($id);
                    return $serial ? ['id' => $serial->id, 'serial_number' => $serial->serial_number] : null;
                })
                ->filter()
                ->values()
                ->all();

            $row['serial_numbers'] = $serialNumbers;
            $row['quantity'] = (int) $detail->quantity;
            $row['original_quantity'] = (int) $detail->quantity;
            $row['unit_price'] = (float) ($detail->unit_price ?? $row['unit_price'] ?? 0);
            $row['total'] = (float) ($detail->sub_total ?? $row['unit_price'] * $row['quantity']);

            $row['available_quantity'] = $this->resolveAvailableQuantity(
                $dispatch->id,
                $row,
                (int) $dispatch->dispatched_quantity
            );

            $rowsByDispatch->put($dispatch->id, $row);
        }

        $this->rows = $rowsByDispatch->values()->all();
        $this->grand_total = $this->calculateReturnTotal();

        $this->dispatch('hydrateSaleReturnRows', $this->rows, $this->sale_id, $this->saleReturn->id);
    }

    public function submit()
    {
        if ($this->approvalLocked) {
            session()->flash('error', 'Retur yang telah disetujui tidak dapat diubah.');
            return null;
        }

        try {
            $prepared = $this->validateAndPrepare();

            DB::transaction(function () use ($prepared) {
                $sale = $this->sale_id ? Sale::find($this->sale_id) : null;
                $customerId = $sale ? $sale->customer_id : $this->saleReturn->customer_id;
                $customerName = $sale ? ($sale->customer_name ?: optional($sale->customer)->customer_name) : $this->saleReturn->customer_name;
                $settingId = $sale ? ($sale->setting_id ?: session('setting_id')) : $this->saleReturn->setting_id;

                $locationId = $this->determineLocationId($prepared['rows']);

                $paidAmount = (float) ($this->saleReturn->paid_amount ?? 0);
                $paidAmount = round(min($paidAmount, $prepared['total']), 2);
                $dueAmount = round(max($prepared['total'] - $paidAmount, 0), 2);
                $paymentStatus = $dueAmount <= 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');

                $this->saleReturn->update([
                    'date' => $this->date,
                    'sale_id' => $this->sale_id,
                    'sale_reference' => $sale ? $sale->reference : $this->saleReturn->sale_reference,
                    'customer_id' => $customerId,
                    'customer_name' => $customerName ?? '-',
                    'setting_id' => $settingId,
                    'location_id' => $locationId,
                    'total_amount' => $prepared['total'],
                    'paid_amount' => $paidAmount,
                    'due_amount' => $dueAmount,
                    'payment_status' => $paymentStatus,
                    'note' => $this->note,
                ]);

                $this->saleReturn->saleReturnDetails()->delete();

                foreach ($prepared['rows'] as $row) {
                    $serialIds = collect($row['serial_numbers'] ?? [])
                        ->map(fn ($serial) => is_array($serial) ? ($serial['id'] ?? null) : null)
                        ->filter()
                        ->values()
                        ->all();

                    SaleReturnDetail::create([
                        'sale_return_id' => $this->saleReturn->id,
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

            session()->flash('success', 'Retur penjualan berhasil diperbarui.');
            return redirect()->route('sale-returns.show', $this->saleReturn->id);
        } catch (ValidationException $e) {
            Log::warning('Validasi pembaruan retur penjualan gagal', [
                'sale_return_id' => $this->saleReturn->id,
                'errors' => $e->validator->errors()->getMessages(),
            ]);
            $this->dispatch('updateTableErrors', $e->validator->errors()->getMessages());
            throw $e;
        } catch (Exception $e) {
            Log::error('Gagal memperbarui retur penjualan', [
                'sale_return_id' => $this->saleReturn->id,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat memperbarui retur penjualan.');
        }

        return null;
    }
}

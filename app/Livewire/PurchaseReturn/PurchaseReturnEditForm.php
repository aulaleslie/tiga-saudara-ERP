<?php

namespace App\Livewire\PurchaseReturn;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;

class PurchaseReturnEditForm extends PurchaseReturnCreateForm
{
    public PurchaseReturn $purchaseReturn;

    public function mount(?PurchaseReturn $purchaseReturn = null): void
    {
        parent::mount();

        if (! $purchaseReturn) {
            abort(404);
        }

        $this->purchaseReturn = $purchaseReturn->loadMissing([
            'purchaseReturnDetails.product',
            'purchaseReturnDetails.product.baseUnit',
            'purchaseReturnDetails.location.setting',
            'purchaseReturnDetails.purchase',
            'supplier',
        ]);

        $this->formTitle = 'Ubah Retur Pembelian';
        $this->submitLabel = 'Simpan Perubahan';

        $status = strtolower((string) $this->purchaseReturn->approval_status);
        $this->approvalLocked = $status === 'approved';

        // Rule: Dispatched -> Hard Block
        if (!is_null($this->purchaseReturn->return_dispatched_at)) {
            abort(403, 'Tidak dapat mengubah retur pembelian yang sudah dikirim barangnya.');
        }

        // Rule: Approved -> Set supplier locked
        if ($this->approvalLocked) {
            $this->supplierLocked = true;
        }

        $dispatchStatus = strtolower((string) $this->purchaseReturn->return_dispatch_status);
        $this->dispatchLocked = in_array($dispatchStatus, ['approved', 'dispatched'], true);

        $this->supplier_id = $this->purchaseReturn->supplier_id;
        $this->supplierName = optional($this->purchaseReturn->supplier)->supplier_name
            ?? $this->purchaseReturn->supplier_name;
        $date = $this->purchaseReturn->date;
        $this->date = $date instanceof Carbon
            ? $date->format('Y-m-d')
            : ($date ?: now()->format('Y-m-d'));
        $this->note = $this->purchaseReturn->note;

        $this->rows = $this->mapRowsFromPurchaseReturn();
        $this->grand_total = $this->calculateReturnTotal();
    }

    public function submit()
    {
        $this->grand_total = round($this->calculateReturnTotal(), 2);

        if (! empty($this->getErrorBag()->messages())) {
            $this->dispatch('updateTableErrors', $this->getErrorBag()->messages());
        }

        Log::info('Updating purchase return form', [
            'purchase_return_id' => $this->purchaseReturn->id,
            'payload' => get_object_vars($this),
        ]);

        try {
            // Re-verify milestone
            if (!is_null($this->purchaseReturn->return_dispatched_at)) {
                session()->flash('error', 'Tidak dapat memperbarui retur pembelian yang sudah dikirim barangnya.');
                return null;
            }

            // Lock supplier check
            if (strtolower((string) $this->purchaseReturn->approval_status) === 'approved') {
                if ((int) $this->supplier_id !== (int) $this->purchaseReturn->supplier_id) {
                    session()->flash('error', 'Pemasok tidak dapat diubah setelah retur disetujui.');
                    return null;
                }
            }

            $prepared = $this->validateAndPrepare();

            $this->grand_total = round($prepared['total'], 2);
            $this->dispatch('updateTableErrors', []);

            DB::transaction(function () use ($prepared) {
                $supplier = Supplier::find($this->supplier_id);

                $this->purchaseReturn->update([
                    'date' => $this->date,
                    'supplier_id' => $this->supplier_id,
                    'supplier_name' => optional($supplier)->supplier_name ?? '-',
                    'total_amount' => round($prepared['total'], 2),
                    'paid_amount' => round($prepared['paidAmount'], 2),
                    'due_amount' => round($prepared['dueAmount'], 2),
                    'payment_status' => $prepared['paymentStatus'],
                    'note' => $this->note,
                ]);

                $this->purchaseReturn->purchaseReturnDetails()->delete();

                foreach ($this->rows as $row) {
                    $serialNumberIds = collect($row['serial_numbers'] ?? [])
                        ->map(fn ($sn) => is_array($sn) ? ($sn['id'] ?? null) : null)
                        ->filter()
                        ->values()
                        ->all();

                    PurchaseReturnDetail::create([
                        'purchase_return_id' => $this->purchaseReturn->id,
                        'po_id' => $row['purchase_order_id'] ?? null,
                        'product_id' => $row['product_id'],
                        'product_name' => $row['product_name'],
                        'product_code' => $row['product_code'] ?? '',
                        'quantity' => (int) $row['quantity'],
                        'location_id' => $row['location_id'],
                        'unit_price' => (float) ($row['purchase_price'] ?? 0),
                        'price' => (float) ($row['purchase_price'] ?? 0),
                        'sub_total' => (float) ($row['total'] ?? 0),
                        'product_discount_amount' => 0,
                        'product_tax_amount' => 0,
                        'serial_number_ids' => $serialNumberIds,
                    ]);
                }
            });

            session()->flash('success', 'Retur pembelian berhasil diperbarui.');
            return redirect()->route('purchase-returns.show', $this->purchaseReturn);
        } catch (ValidationException $e) {
            Log::warning('Validation failed while updating purchase return', [
                'purchase_return_id' => $this->purchaseReturn->id,
                'errors' => $e->validator->errors()->getMessages(),
            ]);
            $this->dispatch('updateTableErrors', $e->validator->errors()->getMessages());
            throw $e;
        } catch (Exception $e) {
            Log::error('Failed to update purchase return', [
                'purchase_return_id' => $this->purchaseReturn->id,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat memperbarui retur pembelian.');
        }

        return null;
    }

    protected function resolvePaidAmount(float $total): float
    {
        $paid = (float) ($this->purchaseReturn->paid_amount ?? 0);
        return round(min($paid, $total), 2);
    }

    protected function mapRowsFromPurchaseReturn(): array
    {
        $details = $this->purchaseReturn->purchaseReturnDetails;

        $serialIds = $details
            ->pluck('serial_number_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        $serials = empty($serialIds)
            ? collect()
            : ProductSerialNumber::query()
                ->with(['location.setting'])
                ->whereIn('id', $serialIds)
                ->get()
                ->keyBy('id');

        return $details->map(function (PurchaseReturnDetail $detail) use ($serials) {
            $product = $detail->product;
            $serialNumbers = collect($detail->serial_number_ids ?? [])
                ->map(function ($id) use ($serials) {
                    $serial = $serials[$id] ?? null;
                    return $serial ? [
                        'id' => $serial->id,
                        'serial_number' => $serial->serial_number,
                        'location_id' => $serial->location_id,
                        'location_name' => $serial->location->name ?? null,
                        'location_label' => ($serial->location->setting->company_name ?? 'N/A') . ' - ' . ($serial->location->name ?? 'N/A'),
                    ] : null;
                })
                ->filter()
                ->values()
                ->all();

            $purchase = $detail->purchase ?? ($detail->po_id ? Purchase::find($detail->po_id) : null);
            $purchaseDate = null;
            if ($purchase) {
                $date = $purchase->date;
                if ($date instanceof Carbon) {
                    $purchaseDate = $date->format('Y-m-d');
                } elseif (is_string($date)) {
                    $purchaseDate = $date;
                }
            }

            $rowLocationId = $detail->location_id;
            $rowLocationLabel = null;
            if ($detail->location) {
                $companyName = $detail->location->setting->company_name ?? 'N/A';
                $rowLocationLabel = $companyName . ' - ' . $detail->location->name;
            }
            if (!empty($serialNumbers)) {
                if (!$rowLocationId) {
                    $rowLocationId = $serialNumbers[0]['location_id'] ?? null;
                }
                $rowLocationLabel = $serialNumbers[0]['location_label'] ?? $rowLocationLabel;
            }

            $stockAtLocation = 0;
            if ($detail->product_id && $rowLocationId) {
                $stockAtLocation = ProductStock::where('product_id', $detail->product_id)
                    ->where('location_id', $rowLocationId)
                    ->value('quantity') ?? 0;
            }

            $serialRequired = (bool) optional($product)->serial_number_required;

            return [
                'product_id' => $detail->product_id,
                'product_name' => $detail->product_name ?? optional($product)->product_name,
                'product_code' => $detail->product_code ?? optional($product)->product_code,
                'unit_name' => optional($product)->baseUnit->short_name ?? '-',
                'quantity' => (int) $detail->quantity,
                'location_id' => $rowLocationId,
                'location_name' => $rowLocationLabel ?? '-',
                'location_locked' => $serialRequired,
                'purchase_order_id' => $detail->po_id,
                'purchase_order_date' => $purchaseDate,
                'purchase_price' => (float) ($detail->unit_price ?? $detail->price ?? 0),
                'serial_numbers' => $serialNumbers,
                'serial_number_required' => $serialRequired,
                'total' => (float) ($detail->sub_total ?? (($detail->unit_price ?? 0) * $detail->quantity)),
                'stock_at_location' => $stockAtLocation,
                'available_quantity_tax' => 0,
                'available_quantity_non_tax' => 0,
            ];
        })->values()->toArray();
    }
}

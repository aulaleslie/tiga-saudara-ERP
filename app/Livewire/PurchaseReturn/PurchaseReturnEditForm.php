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
            'purchaseReturnDetails.purchase',
            'supplier',
            'location.setting',
        ]);

        $this->formTitle = 'Ubah Retur Pembelian';
        $this->submitLabel = 'Simpan Perubahan';

        $status = strtolower((string) $this->purchaseReturn->approval_status);
        $this->approvalLocked = $status === 'approved';

        $this->supplier_id = $this->purchaseReturn->supplier_id;
        $this->supplierName = optional($this->purchaseReturn->supplier)->supplier_name
            ?? $this->purchaseReturn->supplier_name;
        $date = $this->purchaseReturn->date;
        $this->date = $date instanceof Carbon
            ? $date->format('Y-m-d')
            : ($date ?: now()->format('Y-m-d'));
        $this->location_id = $this->purchaseReturn->location_id;
        $location = $this->purchaseReturn->location;
        $company = $location?->setting?->company_name;
        $this->locationName = $location
            ? trim($location->name . ($company ? ' - ' . $company : ''))
            : null;
        $this->note = $this->purchaseReturn->note;

        $this->rows = $this->mapRowsFromPurchaseReturn();
        $this->grand_total = $this->calculateReturnTotal();

        if ($this->location_id) {
            $this->dispatch('locationUpdated', $this->location_id);
        }
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
            $prepared = $this->validateAndPrepare();

            $this->grand_total = round($prepared['total'], 2);
            $this->dispatch('updateTableErrors', []);

            DB::transaction(function () use ($prepared) {
                $supplier = Supplier::find($this->supplier_id);

                $this->purchaseReturn->update([
                    'date' => $this->date,
                    'supplier_id' => $this->supplier_id,
                    'supplier_name' => optional($supplier)->supplier_name ?? '-',
                    'location_id' => $this->location_id,
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
            : ProductSerialNumber::query()->whereIn('id', $serialIds)->get()->keyBy('id');

        $productIds = $details->pluck('product_id')->filter()->unique()->values();

        $stocks = [];
        if ($this->location_id && $productIds->isNotEmpty()) {
            $stocks = ProductStock::query()
                ->where('location_id', $this->location_id)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');
        }

        return $details->map(function (PurchaseReturnDetail $detail) use ($serials, $stocks) {
            $product = $detail->product;
            $stock = $stocks[$detail->product_id] ?? null;
            $serialNumbers = collect($detail->serial_number_ids ?? [])
                ->map(function ($id) use ($serials) {
                    $serial = $serials[$id] ?? null;
                    return $serial ? ['id' => $serial->id, 'serial_number' => $serial->serial_number] : null;
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

            return [
                'product_id' => $detail->product_id,
                'product_name' => $detail->product_name ?? optional($product)->product_name,
                'product_code' => $detail->product_code ?? optional($product)->product_code,
                'quantity' => (int) $detail->quantity,
                'purchase_order_id' => $detail->po_id,
                'purchase_order_date' => $purchaseDate,
                'purchase_price' => (float) ($detail->unit_price ?? $detail->price ?? 0),
                'serial_numbers' => $serialNumbers,
                'serial_number_required' => (bool) optional($product)->serial_number_required,
                'total' => (float) ($detail->sub_total ?? (($detail->unit_price ?? 0) * $detail->quantity)),
                'available_quantity_tax' => (int) ($stock->quantity_tax ?? 0),
                'available_quantity_non_tax' => (int) ($stock->quantity_non_tax ?? 0),
            ];
        })->values()->toArray();
    }
}

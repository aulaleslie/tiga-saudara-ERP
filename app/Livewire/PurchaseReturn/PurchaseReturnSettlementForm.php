<?php

namespace App\Livewire\PurchaseReturn;

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
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnGood;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\PurchasesReturn\Entities\SupplierCredit;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnSettlementForm extends Component
{
    use WithFileUploads;

    public PurchaseReturn $purchaseReturn;
    public int $purchaseReturnId;
    public array $settlementLines = [];
    public $cash_proof;
    public bool $isReadOnly = false;
    public array $unpaidPurchases = [];

    public function mount(int $purchaseReturnId): void
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.submit'), 403);
        $this->purchaseReturnId = $purchaseReturnId;
        $this->loadPurchaseReturn();
    }

    protected function loadPurchaseReturn(): void
    {
        $this->purchaseReturn = PurchaseReturn::with([
            'purchaseReturnDetails.product',
            'purchaseReturnDetails.location',
            'settlementItems',
            'settlement',
        ])->findOrFail($this->purchaseReturnId);

        // Check if settlement is already approved or further
        if ($this->purchaseReturn->settlement && 
            in_array($this->purchaseReturn->settlement->status, ['pending', 'approved', 'executing', 'completed'])) {
            $this->isReadOnly = true;
        }

        $this->settlementLines = [];

        foreach ($this->purchaseReturn->purchaseReturnDetails as $detail) {
            // Map existing settlement items for this detail if they exist
            $existingSettlements = $this->purchaseReturn->settlementItems
                ->where('purchase_return_detail_id', $detail->id);

            if ($detail->product->serial_number_required) {
                // Get ProductSerialNumber entities to get their IDs
                $snEntities = \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $detail->serial_number_ids ?? [])->get();
                
                foreach ($snEntities as $snEntity) {
                    $existing = $existingSettlements->where('product_serial_number_id', $snEntity->id)->first();
                    
                    $this->settlementLines[] = [
                        'detail_id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product->product_name,
                        'product_code' => $detail->product->product_code,
                        'serial_number' => $snEntity->serial_number,
                        'serial_number_id' => $snEntity->id,
                        'method' => $existing->method ?? '',
                        'nominal' => (float) ($existing->nominal ?? $detail->unit_price),
                        'max_nominal' => (float) $detail->unit_price,
                        'target_purchase_id' => $existing->target_purchase_id ?? null,
                    ];
                }
            } else {
                $existing = $existingSettlements->whereNull('product_serial_number_id')->first();
                
                $this->settlementLines[] = [
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->product_name,
                    'product_code' => $detail->product->product_code,
                    'serial_number' => null,
                    'serial_number_id' => null,
                    'method' => $existing->method ?? '',
                    'nominal' => (float) ($existing->nominal ?? $detail->sub_total),
                    'max_nominal' => (float) $detail->sub_total,
                    'target_purchase_id' => $existing->target_purchase_id ?? null,
                    'quantity' => $detail->quantity,
                ];
            }
        }

        // Load unpaid purchases for MODIFY_PURCHASE method
        $this->loadUnpaidPurchases();
    }

    protected function loadUnpaidPurchases(): void
    {
        if (!$this->purchaseReturn->supplier_id) {
            $this->unpaidPurchases = [];
            return;
        }

        $this->unpaidPurchases = Purchase::where('supplier_id', $this->purchaseReturn->supplier_id)
            ->where('due_amount', '>', 0)
            ->whereIn('status', [
                Purchase::STATUS_RECEIVED,
                Purchase::STATUS_RECEIVED_PARTIALLY,
                Purchase::STATUS_APPROVED,
            ])
            ->select(['id', 'reference', 'due_amount', 'total_amount'])
            ->orderBy('date', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'label' => $purchase->reference . ' - Sisa: ' . format_currency($purchase->due_amount),
                    'due_amount' => $purchase->due_amount,
                ];
            })
            ->toArray();
    }

    /**
     * Check if any settlement line uses the CASH method.
     */
    public function hasCashMethod(): bool
    {
        return collect($this->settlementLines)->contains(function ($line) {
            return strtoupper($line['method'] ?? '') === PurchaseReturnDetail::METHOD_CASH;
        });
    }

    /**
     * Check if any settlement line uses the MODIFY_PURCHASE method.
     */
    public function hasModifyPurchaseMethod(): bool
    {
        return collect($this->settlementLines)->contains(function ($line) {
            return strtoupper($line['method'] ?? '') === PurchaseReturnDetail::METHOD_MODIFY_PURCHASE;
        });
    }

    protected function rules(): array
    {
        $rules = [
            'settlementLines.*.method' => 'required|string',
            'settlementLines.*.nominal' => 'required|numeric|min:0',
            'cash_proof' => 'nullable|file|max:4096|mimes:jpg,jpeg,png,pdf',
        ];

        // Add conditional validation for MODIFY_PURCHASE and nominal max value
        foreach ($this->settlementLines as $index => $line) {
            $maxNominal = $line['max_nominal'] ?? 0;
            $rules["settlementLines.{$index}.nominal"] = "required|numeric|min:0|max:{$maxNominal}";

            if (strtoupper($line['method'] ?? '') === PurchaseReturnDetail::METHOD_MODIFY_PURCHASE) {
                $rules["settlementLines.{$index}.target_purchase_id"] = 'required|exists:purchases,id';
            }
        }

        // Require cash_proof if any line uses CASH method
        if ($this->hasCashMethod()) {
            $rules['cash_proof'] = 'required|file|max:4096|mimes:jpg,jpeg,png,pdf';
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'settlementLines.*.method.required' => 'Pilih metode penyelesaian.',
            'settlementLines.*.nominal.required' => 'Nilai penyelesaian wajib diisi.',
            'settlementLines.*.nominal.numeric' => 'Nilai penyelesaian harus berupa angka.',
            'settlementLines.*.nominal.min' => 'Nilai penyelesaian tidak boleh negatif.',
            'settlementLines.*.nominal.max' => 'Nilai penyelesaian tidak boleh melebihi nilai barang.',
            'settlementLines.*.target_purchase_id.required' => 'Pilih nota pembelian untuk metode Ubah Nota Pembelian.',
            'settlementLines.*.target_purchase_id.exists' => 'Nota pembelian tidak valid.',
            'cash_proof.required' => 'Bukti pengembalian tunai wajib diunggah jika ada item dengan metode Pengembalian Tunai.',
            'cash_proof.max' => 'Ukuran bukti maksimal 4MB.',
        ];
    }

    public function settlementTotal(): float
    {
        return (float) $this->purchaseReturn->total_amount;
    }

    public function submit()
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.submit'), 403);

        if ($this->isReadOnly) {
            session()->flash('info', 'Penyelesaian sudah dikunci.');
            return null;
        }

        $this->validate();

        $storedProof = null;
        if ($this->cash_proof && !is_string($this->cash_proof)) {
            $storedProof = $this->cash_proof->store('purchase-returns/proofs', 'public');
        }

        try {
            DB::transaction(function () use ($storedProof) {
                // Delete existing granular settlements
                \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::where('purchase_return_id', $this->purchaseReturn->id)->delete();

                foreach ($this->settlementLines as $line) {
                    \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
                        'purchase_return_id' => $this->purchaseReturn->id,
                        'purchase_return_detail_id' => $line['detail_id'],
                        'product_serial_number_id' => $line['serial_number_id'],
                        'method' => $line['method'],
                        'nominal' => $line['nominal'],
                        'target_purchase_id' => $line['target_purchase_id'],
                    ]);
                }

                // Update or create header settlement record
                $this->purchaseReturn->settlement()->updateOrCreate(
                    ['purchase_return_id' => $this->purchaseReturn->id],
                    [
                        'method' => 'mixed',
                        'status' => 'pending',
                        'submitted_by' => Auth::id(),
                        'submitted_at' => now(),
                        'cash_proof_path' => $storedProof ?: ($this->purchaseReturn->settlement->cash_proof_path ?? null),
                    ]
                );
            });

            session()->flash('success', 'Penyelesaian berhasil disimpan.');
            return redirect()->route('purchase-returns.show', $this->purchaseReturn->id);
        } catch (Exception $e) {
            Log::error('Failed to save purchase return settlement lines', [
                'purchase_return_id' => $this->purchaseReturn->id,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat menyimpan penyelesaian.');
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.purchase-return.purchase-return-settlement-form', [
            'methods' => PurchaseReturnDetail::settlementMethods(),
            'total' => $this->purchaseReturn->total_amount,
            'unpaidPurchases' => $this->unpaidPurchases,
        ]);
    }
}

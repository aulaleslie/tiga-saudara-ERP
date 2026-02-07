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
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnGood;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\PurchasesReturn\Entities\SupplierCredit;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnSettlementForm extends Component
{

    public PurchaseReturn $purchaseReturn;
    public int $purchaseReturnId;
    public array $settlementLines = [];
    public bool $isReadOnly = false;
    public bool $canViewPrice = false;
    public array $unpaidPurchases = [];

    public function mount(int $purchaseReturnId): void
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.submit'), 403);
        $this->canViewPrice = \Illuminate\Support\Facades\Gate::allows('purchaseReturns.viewPrice');
        $this->purchaseReturnId = $purchaseReturnId;
        $this->loadPurchaseReturn();
    }

    protected function loadPurchaseReturn(): void
    {
        $this->purchaseReturn = PurchaseReturn::with([
            'purchaseReturnDetails.product',
            'purchaseReturnDetails.purchase',
            'purchaseReturnDetails.location',
            'settlementItems',
            'settlement',
        ])->findOrFail($this->purchaseReturnId);

        // Check if settlement is already approved or further
        if ($this->purchaseReturn->settlement && 
            in_array(\Illuminate\Support\Str::lower($this->purchaseReturn->settlement->status), ['pending', 'approved', 'executing', 'completed'])) {
            $this->isReadOnly = true;
        }

        $this->settlementLines = [];

        foreach ($this->purchaseReturn->purchaseReturnDetails as $detail) {
            // Map existing settlement items for this detail if they exist
            $existingSettlements = $this->purchaseReturn->settlementItems
                ->where('purchase_return_detail_id', $detail->id);

            if ($detail->product->serial_number_required) {
                // Get ProductSerialNumber entities to get their IDs
                $snEntities = \Modules\Product\Entities\ProductSerialNumber::with(['receivedNoteDetail.purchaseDetail.purchase'])
                    ->whereIn('id', $detail->serial_number_ids ?? [])
                    ->get();
                
                foreach ($snEntities as $snEntity) {
                    $existing = $existingSettlements->where('product_serial_number_id', $snEntity->id)->first();
                    $originPurchase = $snEntity->receivedNoteDetail?->purchaseDetail?->purchase;
                    $originPurchaseId = $originPurchase?->id;
                    $originPurchasePaymentStatus = $originPurchase?->payment_status;
                    
                    $this->settlementLines[] = [
                        'id' => $existing->id ?? null, // Track existing DB ID
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
                        'origin_purchase_id' => $originPurchaseId,
                        'origin_purchase_payment_status' => $originPurchasePaymentStatus,
                        'status' => $existing->status ?? \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT,
                        'rejection_reason' => $existing->rejection_reason ?? null,
                    ];
                }
            } else {
                $existing = $existingSettlements->whereNull('product_serial_number_id')->first();
                $originPurchase = $detail->purchase;
                $originPurchaseId = $originPurchase?->id;
                $originPurchaseStatus = $originPurchase?->payment_status;
                $originTotal = (float) ($originPurchase?->total_amount ?? 0);
                $originPaid = (float) ($originPurchase?->paid_amount ?? 0);
                $originDue = (float) ($originPurchase?->due_amount ?? 0);
                $originRef = $originPurchase?->supplier_purchase_number ?: $originPurchase?->reference;
                $originLabel = $originRef ?? '';
                if ($originPurchase) {
                    if ($originDue <= 0) {
                        $originLabel .= ' (Lunas)';
                    } elseif ($originPaid > 0) {
                        $originLabel .= ' (Sebagian)';
                    } else {
                        $originLabel .= ' (Belum Bayar)';
                    }
                }

                $defaultTargetPurchaseId = $existing->target_purchase_id ?? null;
                if (!$defaultTargetPurchaseId && $originPurchaseId && $originPaid <= 0 && $detail->sub_total <= $originDue) {
                    $defaultTargetPurchaseId = $originPurchaseId;
                }
                
                $this->settlementLines[] = [
                    'id' => $existing->id ?? null, // Track existing DB ID
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->product_name,
                    'product_code' => $detail->product->product_code,
                    'serial_number' => null,
                    'serial_number_id' => null,
                    'method' => $existing->method ?? '',
                    'nominal' => (float) ($existing->nominal ?? $detail->sub_total),
                    'max_nominal' => (float) $detail->sub_total,
                    'target_purchase_id' => $defaultTargetPurchaseId,
                    'quantity' => $detail->quantity,
                    'origin_purchase_id' => $originPurchaseId,
                    'origin_purchase_payment_status' => $originPurchaseStatus,
                    'origin_purchase_total_amount' => $originTotal,
                    'origin_purchase_paid_amount' => $originPaid,
                    'origin_purchase_due_amount' => $originDue,
                    'origin_purchase_label' => $originLabel,
                    'status' => $existing->status ?? \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT,
                    'rejection_reason' => $existing->rejection_reason ?? null,
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

        // Purchases for MODIFY_PURCHASE (Paid/Partial/Unpaid)
        // Grouped by product_id to show only relevant purchases per line
        $productIds = $this->purchaseReturn->purchaseReturnDetails->pluck('product_id')->unique();
        
        $this->unpaidPurchases = [];
        
        foreach ($productIds as $productId) {
            // Purchases for MODIFY_PURCHASE (Paid/Partial/Unpaid)
            $this->unpaidPurchases[$productId]['MODIFY_PURCHASE'] = Purchase::where('supplier_id', $this->purchaseReturn->supplier_id)
                ->whereIn('status', [
                    Purchase::STATUS_RECEIVED,
                    Purchase::STATUS_RECEIVED_PARTIALLY,
                    Purchase::STATUS_APPROVED,
                ])
                // Removing payment_status filter to allow Paid/Partial purchases for modification

                ->whereHas('purchaseDetails', function ($query) use ($productId) {
                    $query->where('product_id', $productId);
                })
                ->with(['purchaseDetails' => function ($query) use ($productId) {
                    $query->where('product_id', $productId)->select('id', 'purchase_id', 'product_id', 'quantity', 'unit_price');
                }])
                ->select(['id', 'reference', 'supplier_purchase_number', 'due_amount', 'total_amount', 'paid_amount', 'date'])
                ->orderBy('date', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($purchase) use ($productId) {
                    $ref = $purchase->supplier_purchase_number ?: $purchase->reference;
                    $statusLabel = ' (Sebagian)';
                    if ((float) $purchase->due_amount <= 0) {
                        $statusLabel = ' (Lunas)';
                    } elseif ((float) $purchase->paid_amount <= 0) {
                        $statusLabel = ' (Belum Bayar)';
                    }
                    
                    $purchaseDetail = $purchase->purchaseDetails->where('product_id', $productId)->first();
                    $productQty = $purchaseDetail?->quantity ?? 0;
                    $productUnitPrice = (float) ($purchaseDetail?->unit_price ?? 0);
                    
                    return [
                        'id' => $purchase->id,
                        'label' => $ref . $statusLabel . ($purchase->due_amount > 0 ? ' - Sisa: ' . format_currency($purchase->due_amount) : ''),
                        'text' => $ref,
                        'due_amount' => $purchase->due_amount,
                        'product_quantity' => $productQty,
                        'product_unit_price' => $productUnitPrice,
                    ];
                })
                ->toArray();
        }
    }

    public function getCreditPurchasesProperty(): array
    {
         if (!$this->purchaseReturn->supplier_id) {
            return [];
        }

        // Purchases for CREDIT (Suggested as debt deduction/offset)
        // Requirement: "any unpaid purchase number that belong to the same supplier"
        
        return Purchase::where('supplier_id', $this->purchaseReturn->supplier_id)
            ->where('due_amount', '>', 0) // Unpaid
            ->whereIn('status', [
                Purchase::STATUS_RECEIVED,
                Purchase::STATUS_RECEIVED_PARTIALLY,
                Purchase::STATUS_APPROVED,
                'Completed',
            ])
            ->select(['id', 'reference', 'supplier_purchase_number', 'due_amount', 'total_amount', 'date'])
            ->orderBy('date', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($purchase) {
                $ref = $purchase->supplier_purchase_number ?: $purchase->reference;
                return [
                    'id' => $purchase->id,
                    'label' => $ref . ' - Sisa: ' . format_currency($purchase->due_amount),
                    'text' => $ref,
                ];
            })
            ->toArray();
    }

    public function getMethodsForLine(int $index): array
    {
        $line = $this->settlementLines[$index];
        return \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::selectableSettlementMethods();
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
            'settlementLines.*.method' => 'nullable|string|in:' . implode(',', array_keys(\Modules\PurchasesReturn\Entities\PurchaseReturnDetail::selectableSettlementMethods())),
            'settlementLines.*.nominal' => 'required|numeric|min:0',
        ];

        // Add conditional validation for nominal max value
        foreach ($this->settlementLines as $index => $line) {
            $maxNominal = $line['max_nominal'] ?? 0;
            $rules["settlementLines.{$index}.nominal"] = "required|numeric|min:0|max:{$maxNominal}";
        }

        return $rules;
    }

    protected function rulesForLineSubmit(int $index): array
    {
        $line = $this->settlementLines[$index];
        $maxNominal = $line['max_nominal'] ?? 0;

        $rules = [
            "settlementLines.{$index}.method" => 'required|string|in:' . implode(',', array_keys(\Modules\PurchasesReturn\Entities\PurchaseReturnDetail::selectableSettlementMethods())),
            "settlementLines.{$index}.nominal" => "required|numeric|min:0|max:{$maxNominal}",
        ];

        if (in_array(strtoupper($line['method'] ?? ''), [
            PurchaseReturnDetail::METHOD_MODIFY_PURCHASE,
            PurchaseReturnDetail::METHOD_CREDIT,
            PurchaseReturnDetail::METHOD_CASH
        ])) {
            $rules["settlementLines.{$index}.target_purchase_id"] = 'required|exists:purchases,id';
        }


        return $rules;
    }

    public function updatedSettlementLines($value, $key)
    {
        // Check if the update is on a method field
        if (Str::endsWith($key, '.method')) {
            // Get the index
            $index = explode('.', $key)[0];
            
            // Check the new method
            $method = $this->settlementLines[$index]['method'] ?? '';
            
            // If the method implies hidden nominal (not CREDIT or CASH), reset nominal to max
            // Adjust logic based on business rule. Usually "Repair" = No refund? Or Full Refund?
            // "Modify Purchase" = Full Refund (reduction).
            // "Credit" = Editable.
            // "Cash" = Editable.
            // "Repair" = 0? Or user shouldn't care?
            // "Broken Stock" = ?
            
            // Based on View, we hide input for !CREDIT && !CASH.
            // If hidden, we assume it's "Automatic".
            // For MODIFY_PURCHASE -> Max Nominal.
            // For REPAIR/BROKEN_STOCK -> ?
            // Let's assume Max Nominal for now or 0 if it's not a financial settlement?
            // Actually, "Product Repair" might involve NO money back. So 0.
            // "Broken Stock" might be replacement -> 0.
            // "Modify Purchase" -> Money back (Modify Invoice) -> Max Nominal.
            
            if (in_array($method, [PurchaseReturnDetail::METHOD_MODIFY_PURCHASE, PurchaseReturnDetail::METHOD_CASH])) {
                // Return full value for modification or cash refund
                 $this->settlementLines[$index]['nominal'] = $this->settlementLines[$index]['max_nominal'];

                 // Auto-select purchase for serial number
                 $serialNumberId = $this->settlementLines[$index]['serial_number_id'] ?? null;
                 if ($serialNumberId) {
                     $sn = ProductSerialNumber::with(['receivedNoteDetail.purchaseDetail.purchase'])
                         ->find($serialNumberId);
                     
                     $purchase = $sn?->receivedNoteDetail?->purchaseDetail?->purchase ?? null;
                         if ($purchase) {
                             $this->settlementLines[$index]['target_purchase_id'] = $purchase->id;
                             
                             // Ensure it's in the list
                             $this->ensurePurchaseInList($purchase, $method, $this->settlementLines[$index]['product_id']);
                         }
                 } else {
                     // Auto-select purchase for non-serial when origin is unpaid and return <= due
                     $originPurchaseId = $this->settlementLines[$index]['origin_purchase_id'] ?? null;
                     $originPaid = (float) ($this->settlementLines[$index]['origin_purchase_paid_amount'] ?? 0);
                     $originDue = (float) ($this->settlementLines[$index]['origin_purchase_due_amount'] ?? 0);
                     $returnValue = (float) ($this->settlementLines[$index]['nominal'] ?? 0);

                     if ($originPurchaseId && $originPaid <= 0 && $returnValue <= $originDue) {
                         $this->settlementLines[$index]['target_purchase_id'] = $originPurchaseId;
                     }
                 }
            }
            // Determine current status and whether the line is editable
            $status = $this->settlementLines[$index]['status'] ?? \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT;
            $isEditable = in_array($status, [\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT, \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_REJECTED]);

            if (!in_array($method, [PurchaseReturnDetail::METHOD_CREDIT, PurchaseReturnDetail::METHOD_CASH])) {
                // For non-financial methods or empty selection, revert nominal back to document price
                // but only when the line is still editable. Do not override submitted/locked lines.
                if ($isEditable) {
                    $this->settlementLines[$index]['nominal'] = $this->settlementLines[$index]['max_nominal'];
                    // Ensure client-side formatted input updates immediately
                }
            } else {
                // CREDIT or CASH -> Keep existing or set to max if empty
                if (empty($this->settlementLines[$index]['nominal'])) {
                    $this->settlementLines[$index]['nominal'] = $this->settlementLines[$index]['max_nominal'];
                }
            }
        }

        // Handle target_purchase_id change to recalculate nominal for MODIFY_PURCHASE
        if (Str::endsWith($key, '.target_purchase_id')) {
            $index = explode('.', $key)[0];
            $line = &$this->settlementLines[$index];
            $method = strtoupper($line['method'] ?? '');
            
            if ($method === PurchaseReturnDetail::METHOD_MODIFY_PURCHASE && !empty($line['target_purchase_id'])) {
                $productId = $line['product_id'];
                $targetPurchaseId = $line['target_purchase_id'];
                
                // Find the selected purchase in the unpaidPurchases list
                $purchaseList = $this->unpaidPurchases[$productId]['MODIFY_PURCHASE'] ?? [];
                $selectedPurchase = collect($purchaseList)->firstWhere('id', $targetPurchaseId);
                
                if ($selectedPurchase) {
                    $unitPrice = (float) ($selectedPurchase['product_unit_price'] ?? 0);
                    $quantity = (float) ($line['quantity'] ?? 1);
                    $newNominal = $unitPrice * $quantity;
                    
                    // Apply max nominal cap from original return value
                    $maxNominal = (float) ($line['max_nominal'] ?? $newNominal);
                    $line['nominal'] = min($newNominal, $maxNominal);
                }
            }
        }
    }

    protected function ensurePurchaseInList($purchase, $method, $productId = null): void
    {
        $ref = $purchase->supplier_purchase_number ?: $purchase->reference;
        
        // Ticket 2: Update label for paid items in auto-select
        $statusLabel = '';
        if ($purchase->due_amount <= 0) {
            $statusLabel = ' (Lunas)';
        } elseif (($purchase->paid_amount ?? 0) > 0) {
            $statusLabel = ' (Sebagian)';
        }

        $newItem = [
            'id' => $purchase->id,
            'label' => $ref . $statusLabel . ($purchase->due_amount > 0 ? ' - Sisa: ' . format_currency($purchase->due_amount) : ''),
            'text' => $ref,
            'due_amount' => $purchase->due_amount,
            'product_quantity' => $purchase->purchaseDetails->where('product_id', $productId)->first()?->quantity ?? 0,
            'product_unit_price' => (float) ($purchase->purchaseDetails->where('product_id', $productId)->first()?->unit_price ?? 0),
        ];

        if (($method === PurchaseReturnDetail::METHOD_MODIFY_PURCHASE || $method === PurchaseReturnDetail::METHOD_CASH) && $productId) {
            $subMethod = $method === PurchaseReturnDetail::METHOD_MODIFY_PURCHASE ? 'MODIFY_PURCHASE' : 'CASH';
            if (!isset($this->unpaidPurchases[$productId][$subMethod])) {
                $this->unpaidPurchases[$productId][$subMethod] = [];
            }
            if (!collect($this->unpaidPurchases[$productId][$subMethod])->contains('id', $purchase->id)) {
                $this->unpaidPurchases[$productId][$subMethod][] = $newItem;
            }
        }
        // Note: For CREDIT, it's a dynamic property and usually includes Paid items
        // which the auto-select purchase might be. If we need to support CREDIT auto-select,
        // we'd need to modify getCreditPurchasesProperty.
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
        ];
    }

    public function settlementTotal(): float
    {
        return (float) $this->purchaseReturn->total_amount;
    }

    /**
     * Submit a single line for approval.
     */
    public function submitLine(int $index)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.submit'), 403);

        $this->validate($this->rulesForLineSubmit($index));

        try {
            DB::transaction(function () use ($index) {
                $line = $this->settlementLines[$index];
                
                // Update specific settlement item
                $settlement = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::updateOrCreate(
                    [
                        'purchase_return_id' => $this->purchaseReturn->id,
                        'purchase_return_detail_id' => $line['detail_id'],
                        'product_serial_number_id' => $line['serial_number_id'],
                    ],
                    [
                        'method' => $line['method'],
                        'nominal' => $line['nominal'],
                        'target_purchase_id' => $line['target_purchase_id'],
                        'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_SUBMITTED,
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
            Log::error('Failed to submit purchase return settlement line', [
                'purchase_return_id' => $this->purchaseReturn->id,
                'index' => $index,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat mengirim baris penyelesaian.');
        }
    }

    /**
     * Reset a rejected line to draft.
     */
    public function resetLine(int $index)
    {
        $line = $this->settlementLines[$index];
        if ($line['status'] !== \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_REJECTED) {
            return;
        }

        try {
            DB::transaction(function () use ($index) {
                $line = $this->settlementLines[$index];
                
                \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::where([
                    'purchase_return_id' => $this->purchaseReturn->id,
                    'purchase_return_detail_id' => $line['detail_id'],
                    'product_serial_number_id' => $line['serial_number_id'],
                ])->update([
                    'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT,
                    'method' => null,
                    'nominal' => $line['max_nominal'],
                    'target_purchase_id' => null,
                    'rejection_reason' => null,
                ]);

                $this->settlementLines[$index]['status'] = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT;
                $this->settlementLines[$index]['method'] = '';
                $this->settlementLines[$index]['nominal'] = $line['max_nominal'];
                $this->settlementLines[$index]['target_purchase_id'] = null;
                $this->settlementLines[$index]['rejection_reason'] = null;
            });
        } catch (Exception $e) {
            Log::error('Failed to reset purchase return settlement line', [
                'purchase_return_id' => $this->purchaseReturn->id,
                'index' => $index,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat mereset baris penyelesaian.');
        }
    }

    public function submit()
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.submit'), 403);

        if ($this->isReadOnly) {
            session()->flash('info', 'Penyelesaian sudah dikunci.');
            return null;
        }

        $this->validate();

        try {
            DB::transaction(function () {
                foreach ($this->settlementLines as $line) {
                    // Only update editable lines (Draft or Rejected)
                    if ($line['status'] !== \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT && 
                        $line['status'] !== \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_REJECTED) {
                        continue;
                    }

                    \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::updateOrCreate(
                        [
                            'purchase_return_id' => $this->purchaseReturn->id,
                            'purchase_return_detail_id' => $line['detail_id'],
                            'product_serial_number_id' => $line['serial_number_id'],
                        ],
                        [
                            'method' => $line['method'] ?: null,
                            'nominal' => $line['nominal'],
                            'target_purchase_id' => $line['target_purchase_id'],
                            'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_DRAFT,
                        ]
                    );
                }

                // Update or create header settlement record
                // Status derivation will be handled in Ticket 4, for now keep it consistent
                $this->purchaseReturn->settlement()->updateOrCreate(
                    ['purchase_return_id' => $this->purchaseReturn->id],
                    [
                        'method' => 'mixed',
                        'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_SUBMITTED,
                        'submitted_by' => Auth::id(),
                        'submitted_at' => now(),
                    ]
                );
            });

            session()->flash('success', 'Penyelesaian berhasil disimpan sebagai draft.');
            return redirect()->route('purchase-returns.show', $this->purchaseReturn->id);
        } catch (Exception $e) {
            Log::error('Failed to save purchase return settlement lines as draft', [
                'purchase_return_id' => $this->purchaseReturn->id,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat menyimpan draft penyelesaian.');
        }

        return null;
    }

    public function render(): View
    {
        $detailCounts = collect($this->settlementLines)
            ->groupBy('detail_id')
            ->map
            ->count()
            ->toArray();

        // Ensure creditPurchases is available
        $creditPurchases = $this->getCreditPurchasesProperty();

        return view('livewire.purchase-return.purchase-return-settlement-form', [
            'methods' => PurchaseReturnDetail::selectableSettlementMethods(),
            'allMethods' => PurchaseReturnDetail::settlementMethods(),
            'total' => $this->purchaseReturn->total_amount,
            'unpaidPurchases' => $this->unpaidPurchases,
            'creditPurchases' => $creditPurchases, // Pass it explicitly
            'displayReturnType' => 'Per-Item / Mixed',
            'detailCounts' => $detailCounts,
        ]);
    }
}

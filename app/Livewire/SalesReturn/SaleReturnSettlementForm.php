<?php

namespace App\Livewire\SalesReturn;

use App\Constants\PaymentStatus;
use App\Support\SalesReturn\SalesReturnWorkflowResolver;
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
use Livewire\Attributes\Locked;
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

    // Identity of the document is fixed at mount: clients can never re-point the component.
    #[Locked]
    public SaleReturn $saleReturn;
    #[Locked]
    public int $saleReturnId;
    public array $settlementLines = [];
    #[Locked]
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

        // POS-owned / mixed-history returns stay readable here but are never mutable.
        if (app(SalesReturnWorkflowResolver::class)->isPosLinkedReturn($this->saleReturn)) {
            $this->isReadOnly = true;
        }

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
                    [$derived, $block] = $this->derivedValue($detail, $snEntity->id, $existing);
                    
                    $this->settlementLines[] = [
                        'id' => $existing->id ?? null,
                        'detail_id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product->product_name,
                        'product_code' => $detail->product->product_code,
                        'serial_number' => $snEntity->serial_number,
                        'serial_number_id' => $snEntity->id,
                        'method' => $existing->method ?? '',
                        'nominal' => (float) ($existing->nominal ?? $derived),
                        'max_nominal' => (float) $derived,
                        'financial_block' => $block,
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
                [$derived, $block] = $this->derivedValue($detail, null, $existing);
                
                $this->settlementLines[] = [
                    'id' => $existing->id ?? null,
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->product_name,
                    'product_code' => $detail->product->product_code,
                    'serial_number' => null,
                    'serial_number_id' => null,
                    'method' => $existing->method ?? '',
                    'nominal' => (float) ($existing->nominal ?? $derived),
                    'max_nominal' => (float) $derived,
                    'financial_block' => $block,
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

    /**
     * Attributable value from the persisted source sale (display/default only; the server re-derives
     * it on submit and approval). A block message means financial approval is not possible yet.
     *
     * @return array{0: float, 1: ?string}
     */
    protected function derivedValue(SaleReturnDetail $detail, ?int $serialId, ?SaleReturnItemSettlement $existing): array
    {
        if ($existing && $existing->status === SaleReturnItemSettlement::STATUS_APPROVED) {
            return [(float) $existing->nominal, null];   // already committed: shown from evidence
        }

        // A detached probe (no shared relation graph with the component's hydrated models).
        $probe = new SaleReturnItemSettlement([
            'sale_return_id' => $this->saleReturnId, 'sale_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serialId, 'method' => SaleReturnDetail::METHOD_CASH_REFUND,
        ]);
        $probe->setRelation('detail', $detail->withoutRelations());
        $probe->setRelation('saleReturn', $this->saleReturn->withoutRelations());

        try {
            return [app(\App\Support\SalesReturn\StandardFinancialService::class)->resolve($probe)['value'], null];
        } catch (\App\Support\SalesReturn\SalesReturnFinancialBlockedException $e) {
            return [0.0, $e->getMessage()];
        }
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
                    $statusLabel = ' (' . PaymentStatus::label(PaymentStatus::PARTIAL) . ')';
                    if ((float) $sale->due_amount <= 0) {
                        $statusLabel = ' (' . PaymentStatus::label(PaymentStatus::PAID) . ')';
                    } elseif ((float) $sale->paid_amount <= 0) {
                        $statusLabel = ' (' . PaymentStatus::label(PaymentStatus::UNPAID) . ')';
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

    /** Server-side ownership guard for every settlement mutation (authoritative state, not hydrated). */
    protected function guardStandardMutation(): void
    {
        // Every settlement mutation (field edit, submit, reset) needs the settlement-submit permission.
        abort_if(\Illuminate\Support\Facades\Gate::denies('saleReturnSettlements.submit'), 403);

        $fresh = SaleReturn::withArchived()->findOrFail($this->saleReturnId);

        // The hydrated model must still be the document this component was mounted for.
        if ((int) $this->saleReturn->id !== (int) $fresh->id) {
            throw new \App\Support\SalesReturn\SalesReturnWorkflowException('Identitas dokumen retur tidak valid.', (int) $fresh->sale_id);
        }

        app(\App\Support\SalesReturn\StandardReturnGuard::class)->assertBusinessAccess($fresh);
        app(SalesReturnWorkflowResolver::class)->assertStandardMutable($fresh);
    }

    /**
     * Resolve a settlement row through its authoritative parent. A hydrated line id that
     * does not belong to THIS return is rejected, never trusted.
     */
    protected function settlementOfThisReturn(?int $settlementId): ?SaleReturnItemSettlement
    {
        if (! $settlementId) {
            return null;
        }

        $settlement = SaleReturnItemSettlement::query()
            ->whereKey($settlementId)
            ->where('sale_return_id', $this->saleReturnId)
            ->lockForUpdate()
            ->first();

        if (! $settlement) {
            throw new \App\Support\SalesReturn\SalesReturnWorkflowException('Baris penyelesaian tidak termasuk dalam retur ini.', (int) $this->saleReturn->sale_id);
        }

        return $settlement;
    }

    public function updatedSettlementLines($value, $key)
    {
        $this->guardStandardMutation();
        if (Str::endsWith($key, '.method')) {
            $index = explode('.', $key)[0];
            $method = $this->settlementLines[$index]['method'] ?? '';
            // Keep the displayed nominal stable (use the document price).
            // For cash refund, default to max nominal only if it's not set or empty.
            if (in_array($method, [SaleReturnDetail::METHOD_CASH_REFUND])) {
                $current = $this->settlementLines[$index]['nominal'] ?? null;
                if ($current === null || $current === '' || (float)$current <= 0) {
                    $this->settlementLines[$index]['nominal'] = $this->settlementLines[$index]['max_nominal'];
                }
            }
            // If switched to a non-cash method and the line is still editable,
            // revert any modified nominal back to the document price (max_nominal).
            // Do not change if the line has been submitted/locked for approval.
            if (!in_array($method, [SaleReturnDetail::METHOD_CASH_REFUND])) {
                $status = $this->settlementLines[$index]['status'] ?? SaleReturnItemSettlement::STATUS_DRAFT;
                if (in_array($status, [SaleReturnItemSettlement::STATUS_DRAFT, SaleReturnItemSettlement::STATUS_REJECTED])) {
                    $this->settlementLines[$index]['nominal'] = $this->settlementLines[$index]['max_nominal'];
                    // Dispatch browser event to ensure Alpine-formatted input is updated immediately
                }
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
        $rules = [
            // New submissions may only use the three current choices; historical methods stay readable.
            "settlementLines.{$index}.method" => 'required|string|in:' . implode(',', array_keys(SaleReturnDetail::selectableSettlementMethods())),
        ];

        // For cash refund, require nominal (amount) input only
        if ($line['method'] === SaleReturnDetail::METHOD_CASH_REFUND) {
            $rules["settlementLines.{$index}.nominal"] = 'required|numeric|min:0';
        }

        // For 'Tidak dapat diproses' require notes (reason)
        if ($line['method'] === SaleReturnDetail::METHOD_UNPROCESSED) {
            $rules["settlementLines.{$index}.notes"] = 'required|string|max:500';
        }

        // No serial/location/proof validation at settlement stage; deferred to dispatch
        return $rules;
    }

    public function submitLine(int $index)
    {
        $this->guardStandardMutation();
        $rules = $this->rulesForLineSubmit($index);
        if (!empty($rules)) {
            $this->validate($rules);
        }

        try {
            DB::transaction(function () use ($index) {
                // Source sale -> header locks, then every decision is made from persisted state.
                $guard = app(\App\Support\SalesReturn\StandardReturnGuard::class);
                $locked = $guard->lockForAction($this->saleReturn);
                $guard->assertCanSettle($locked);

                $line = $this->settlementLines[$index] ?? [];

                // Lineage: the detail must belong to this return; serial (if any) must be one of
                // the serials claimed on that detail. Hydrated ids are only selectors.
                $detail = SaleReturnDetail::query()
                    ->whereKey($line['detail_id'] ?? 0)
                    ->where('sale_return_id', $this->saleReturnId)
                    ->lockForUpdate()
                    ->first();
                if (! $detail) {
                    throw new \App\Support\SalesReturn\SalesReturnWorkflowException('Rincian retur tidak termasuk dalam retur ini.', (int) $this->saleReturn->sale_id);
                }

                $serialId = ! empty($line['serial_number_id']) ? (int) $line['serial_number_id'] : null;
                $claimedSerialIds = collect($detail->serial_number_ids ?? [])->map(fn ($id) => (int) $id)->all();
                $requiresSerial = (bool) optional($detail->product)->serial_number_required;
                if ($requiresSerial && ($serialId === null || ! in_array($serialId, $claimedSerialIds, true))) {
                    throw new \App\Support\SalesReturn\SalesReturnWorkflowException('Nomor seri tidak termasuk dalam rincian retur ini.', (int) $this->saleReturn->sale_id);
                }
                if (! $requiresSerial && $serialId !== null) {
                    throw new \App\Support\SalesReturn\SalesReturnWorkflowException('Produk tanpa nomor seri tidak boleh memiliki nomor seri.', (int) $this->saleReturn->sale_id);
                }

                // The persisted row for this (return, detail, serial) is the only authority on
                // editability: approved/dispatched rows can never be resubmitted, even with a
                // blank or forged hydrated id.
                $existing = SaleReturnItemSettlement::query()
                    ->where('sale_return_id', $this->saleReturnId)
                    ->where('sale_return_detail_id', $detail->id)
                    ->when($serialId, fn ($q) => $q->where('product_serial_number_id', $serialId), fn ($q) => $q->whereNull('product_serial_number_id'))
                    ->lockForUpdate()
                    ->first();
                if (! empty($line['id']) && (! $existing || (int) $existing->id !== (int) $line['id'])) {
                    throw new \App\Support\SalesReturn\SalesReturnWorkflowException('Baris penyelesaian tidak termasuk dalam retur ini.', (int) $this->saleReturn->sale_id);
                }
                if ($existing) {
                    $guard->assertSettlementEditable($existing);
                }

                // Server-side value rules (authoritative amounts, never the hydrated max).
                $method = strtoupper((string) ($line['method'] ?? ''));
                if (! array_key_exists($method, SaleReturnDetail::selectableSettlementMethods())) {
                    throw ValidationException::withMessages(["settlementLines.{$index}.method" => 'Metode penyelesaian tidak valid.']);
                }
                $maxNominal = $requiresSerial ? (float) $detail->unit_price : (float) $detail->sub_total;
                $reviewedValue = null;
                $reviewedFingerprint = null;
                if ($method === SaleReturnDetail::METHOD_CASH_REFUND) {
                    // The attributable value comes from the persisted source sale (discount/tax/prior returns),
                    // never from the form. Ambiguous or contradictory evidence blocks here with guidance.
                    $probe = new SaleReturnItemSettlement([
                        'sale_return_id' => $this->saleReturnId, 'sale_return_detail_id' => $detail->id,
                        'product_serial_number_id' => $serialId, 'method' => $method,
                    ]);
                    $probe->setRelation('detail', $detail->withoutRelations());
                    $probe->setRelation('saleReturn', $locked->withoutRelations());
                    try {
                        $review = app(\App\Support\SalesReturn\StandardFinancialService::class)
                            ->review($probe, (float) ($line['nominal'] ?? 0));
                    } catch (\App\Support\SalesReturn\SalesReturnFinancialBlockedException $e) {
                        throw ValidationException::withMessages(["settlementLines.{$index}.nominal" => $e->getMessage()]);
                    }
                    $nominal = $review['committed'];
                    $reviewedValue = $review['committed'];
                    $reviewedFingerprint = $review['fingerprint'];
                } else {
                    $nominal = $maxNominal;
                }
                if ($nominal < 0) {
                    throw ValidationException::withMessages(["settlementLines.{$index}.nominal" => 'Nominal tidak valid.']);
                }
                if ($method === SaleReturnDetail::METHOD_UNPROCESSED && trim((string) ($line['notes'] ?? '')) === '') {
                    throw ValidationException::withMessages(["settlementLines.{$index}.notes" => 'Alasan wajib diisi untuk Tidak dapat diproses.']);
                }

                $attributes = [
                    'method' => $method,
                    'nominal' => $nominal,
                    'reviewed_value' => $reviewedValue,
                    'reviewed_fingerprint' => $reviewedFingerprint,
                    'target_sale_id' => null,
                    // location and new_serial_number are deferred to dispatch stage
                    'location_id' => $line['location_id'] ?? null,
                    'new_serial_number' => $line['new_serial_number'] ?? null,
                    'notes' => $line['notes'] ?? null,
                    'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'submitted_by' => Auth::id(),
                    'rejected_at' => null,
                    'rejected_by' => null,
                    'rejection_reason' => null,
                ];

                $settlement = $existing
                    ? tap($existing)->update($attributes)
                    : SaleReturnItemSettlement::create(array_merge($attributes, [
                        'sale_return_id' => $this->saleReturnId,
                        'sale_return_detail_id' => $detail->id,
                        'product_serial_number_id' => $serialId,
                    ]));

                $this->settlementLines[$index]['id'] = $settlement->id;
                $this->settlementLines[$index]['status'] = $settlement->status;
            });

            // Committed action only: notifications are never emitted from inside the transaction.
            $this->saleReturn->refresh();
            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->notifyApprovalNeeded($this->saleReturn, $this->saleReturn->reference ?? 'Penyelesaian Retur Penjualan', $this->saleReturn->setting_id, null, 'settlement'));

            session()->flash('success', 'Baris penyelesaian dikirim untuk persetujuan.');
        } catch (\App\Support\SalesReturn\SalesReturnWorkflowException|\App\Support\SalesReturn\SalesReturnStateException|\App\Support\SalesReturn\SalesReturnAccessException|ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Failed to submit sale return settlement line', [
                'sale_return_id' => $this->saleReturnId,
                'index' => $index,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat mengirim baris penyelesaian.');
        }
    }

    public function resetLine(int $index)
    {
        $this->guardStandardMutation();
        $line = $this->settlementLines[$index] ?? null;
        if (! $line || empty($line['id'])) {
            return;
        }

        try {
            DB::transaction(function () use ($index, $line) {
                $guard = app(\App\Support\SalesReturn\StandardReturnGuard::class);
                $guard->assertCanSettle($guard->lockForAction($this->saleReturn));
                // Status and ownership come from the authoritative row, not hydrated state.
                $settlement = $this->settlementOfThisReturn((int) $line['id']);
                if ($settlement->status !== SaleReturnItemSettlement::STATUS_REJECTED) {
                    return;
                }

                $settlement->update([
                    'status' => SaleReturnItemSettlement::STATUS_DRAFT,
                    'rejection_reason' => null,
                ]);

                $this->settlementLines[$index]['status'] = SaleReturnItemSettlement::STATUS_DRAFT;
                $this->settlementLines[$index]['rejection_reason'] = null;
            });
        } catch (\App\Support\SalesReturn\SalesReturnWorkflowException|\App\Support\SalesReturn\SalesReturnStateException|\App\Support\SalesReturn\SalesReturnAccessException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Failed to reset sale return settlement line', [
                'sale_return_id' => $this->saleReturnId,
                'index' => $index,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat mereset baris.');
        }
    }

    public function submit()
    {
        $this->guardStandardMutation();

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

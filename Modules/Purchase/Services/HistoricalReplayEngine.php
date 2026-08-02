<?php

namespace Modules\Purchase\Services;

use App\Support\ImportDocumentAdjustmentAllocator;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Support\BackfillCostCalculator;
use Modules\Setting\Entities\Setting;

/**
 * Historical replay engine for purchase cost calculations.
 * Shared by PurchaseCostReplayEngine and BackfillSalesCostSnapshotsCommand.
 *
 * Collects timeline events (purchase receipts, purchase returns, sales),
 * establishes per-bucket running averages, and provides historical cost snapshots.
 */
class HistoricalReplayEngine
{
    private ?Purchase $correctionPurchase = null;
    private Collection $settingsCache;

    public function __construct()
    {
        $this->settingsCache = collect();
    }

    public function withCorrectionPurchase(Purchase $purchase): self
    {
        $this->correctionPurchase = $purchase;
        return $this;
    }

    /**
     * Collect all timeline events (purchases, returns, sales) for a product.
     * Events are sorted by date, then by type order (purchase before return before sale),
     * then by ID for determinism.
     */
    public function collectTimelineEvents(
        int $productId,
        ?Carbon $untilDate = null,
        ?int $settingId = null,
    ): Collection {
        $events = collect();

        // 1. Purchase receipt events
        $purchases = PurchaseDetail::query()
            ->with([
                'purchase' => function ($q) {
                    $q->select('id', 'date', 'status', 'setting_id', 'discount_amount', 'shipping_amount');
                },
                'receivedNoteDetails' => function ($q) {
                    $q->select('id', 'po_detail_id', 'received_note_id', 'quantity_received');
                },
                'receivedNoteDetails.receivedNote' => function ($q) {
                    $q->select('id', 'po_id', 'date', 'status', 'approved_at');
                },
            ])
            ->select('id', 'purchase_id', 'product_id', 'quantity', 'unit_price',
                     'sub_total', 'product_tax_amount', 'product_discount_amount',
                     'product_discount_type')
            ->where('product_id', $productId)
            ->whereHas('purchase', function ($q) use ($untilDate) {
                $q->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY, 'COMPLETED']);
                if ($untilDate) {
                    $q->where('date', '<=', $untilDate);
                }
            })
            ->get();

        foreach ($purchases as $detail) {
            $purchaseSettings = $detail->purchase->setting_id;
            $companyName = $this->getSettingCompanyName($detail->purchase->setting_id);
            $bucket = BackfillCostCalculator::classifyBucket($companyName);

            $receivedNotes = $detail->receivedNoteDetails ?? collect();
            $hasApprovedReceipts = false;

            // First, process approved received-note details
            foreach ($receivedNotes as $rnd) {
                $rn = $rnd->receivedNote;
                if (!$rn || $rn->status !== ReceivedNote::STATUS_APPROVED || !$rn->approved_at) {
                    continue;
                }

                $hasApprovedReceipts = true;
                $eventDate = Carbon::parse($rn->approved_at)->format('Y-m-d H:i:s');
                $receiptQty = (float) $rnd->quantity_received;

                // For the corrected purchase, use corrected detail values with allocation
                if ($this->correctionPurchase && $detail->purchase_id === $this->correctionPurchase->id) {
                    $lineCost = $this->calculateCorrectedLineReceiptCost(
                        $detail,
                        $receiptQty,
                        $this->correctionPurchase,
                    );
                } else {
                    // For non-corrected purchases, calculate from stored values
                    $lineCost = $this->calculateLineReceiptCost(
                        unitPrice: $detail->unit_price,
                        qty: (float) $detail->quantity,
                        receiptQty: $receiptQty,
                        subTotal: $detail->sub_total,
                        taxAmount: $detail->product_tax_amount,
                        discountAmount: $detail->product_discount_amount,
                        discountType: $detail->product_discount_type,
                    );
                }

                $events->push([
                    'type' => 'purchase',
                    'order' => 1,
                    'id' => $detail->id,
                    'date' => $eventDate,
                    'quantity' => $receiptQty,
                    'cost' => $lineCost,
                    'bucket' => $bucket,
                    'setting_id' => $purchaseSettings,
                    'model' => $detail,
                ]);
            }

            // Fallback: if no approved received notes exist, emit event using purchase document date/cost
            // Skip "RECEIVED PARTIALLY" without actual receipts as invalid
            if (!$hasApprovedReceipts) {
                if ($detail->purchase->status === Purchase::STATUS_RECEIVED_PARTIALLY) {
                    continue; // Skip RECEIVED PARTIALLY without actual receipts
                }

                $eventDate = Carbon::parse($detail->purchase->date)->format('Y-m-d H:i:s');
                $qty = (float) $detail->quantity;

                if ($qty > 0) {
                    // Calculate tax-exclusive DPP: sub_total - tax - discount
                    $lineDpp = BackfillCostCalculator::calculatePurchaseDpp(
                        subTotal: $detail->sub_total,
                        taxAmount: $detail->product_tax_amount,
                        discountAmount: $detail->product_discount_amount,
                    );

                    // For the corrected purchase, allocate global discount/shipping
                    if ($this->correctionPurchase && $detail->purchase_id === $this->correctionPurchase->id) {
                        $allocatedAdjustment = $this->calculateLineAllocationAdjustment(
                            detail: $detail,
                            lineDpp: $lineDpp,
                            purchase: $this->correctionPurchase,
                        );
                        $lineCost = max(0, round($lineDpp + $allocatedAdjustment, 2));
                    } else {
                        $lineCost = max(0, round($lineDpp, 2));
                    }

                    $events->push([
                        'type' => 'purchase',
                        'order' => 1,
                        'id' => $detail->id,
                        'date' => $eventDate,
                        'quantity' => $qty,
                        'cost' => $lineCost,
                        'bucket' => $bucket,
                        'setting_id' => $purchaseSettings,
                        'model' => $detail,
                    ]);
                }
            }
        }

        // 2. Purchase return events
        $returns = PurchaseReturnDetail::query()
            ->with([
                'purchaseReturn' => function ($q) {
                    $q->select('id', 'date', 'status', 'setting_id');
                },
            ])
            ->select('id', 'purchase_return_id', 'product_id', 'quantity')
            ->where('product_id', $productId)
            ->whereHas('purchaseReturn', function ($q) use ($untilDate) {
                $q->where('status', PurchaseReturn::STATUS_COMPLETED);
                if ($untilDate) {
                    $q->where('date', '<=', $untilDate);
                }
            })
            ->get();

        foreach ($returns as $prd) {
            $companyName = $this->getSettingCompanyName($prd->purchaseReturn->setting_id);
            $bucket = BackfillCostCalculator::classifyBucket($companyName);

            $eventDate = Carbon::parse($prd->purchaseReturn->date)->format('Y-m-d H:i:s');
            $events->push([
                'type' => 'purchase_return',
                'order' => 2,
                'id' => $prd->id,
                'date' => $eventDate,
                'quantity' => (float) $prd->quantity,
                'bucket' => $bucket,
                'setting_id' => $prd->purchaseReturn->setting_id,
                'model' => $prd,
            ]);
        }

        // 3. Sale events (if requested)
        // Note: sales are collected for full timeline, but may be filtered later
        $salesQuery = SaleDetails::query()
            ->with(['sale' => function ($q) {
                $q->select('id', 'date', 'status', 'setting_id');
            }])
            ->select('id', 'sale_id', 'product_id', 'quantity', 'cost_snapshot_source')
            ->where('product_id', $productId)
            ->whereHas('sale', function ($q) use ($untilDate) {
                $q->whereIn('status', ['Completed', 'COMPLETED', 'DISPATCHED', 'RETURNED PARTIALLY', 'RETURNED']);
                if ($untilDate) {
                    $q->where('date', '<=', $untilDate);
                }
            });

        foreach ($salesQuery->get() as $sd) {
            $companyName = $this->getSettingCompanyName($sd->sale->setting_id);
            $bucket = BackfillCostCalculator::classifyBucket($companyName);

            $eventDate = Carbon::parse($sd->sale->date)->format('Y-m-d H:i:s');
            $events->push([
                'type' => 'sale',
                'order' => 3,
                'id' => $sd->id,
                'date' => $eventDate,
                'quantity' => (float) $sd->quantity,
                'bucket' => $bucket,
                'setting_id' => $sd->sale->setting_id,
                'model' => $sd,
            ]);
        }

        // Sort by date, then order, then ID for determinism
        return $events->sort(function ($a, $b) {
            if ($a['date'] === $b['date']) {
                if ($a['order'] === $b['order']) {
                    return $a['id'] <=> $b['id'];
                }
                return $a['order'] <=> $b['order'];
            }
            return $a['date'] <=> $b['date'];
        })->values();
    }

    /**
     * Replay events to calculate average purchase price for a product.
     * Returns the final moving average at the end of the timeline.
     */
    public function replayAverageCost(Collection $events, ?Carbon $fromDate = null): float
    {
        $runningQty = 0;
        $runningValue = 0;
        $currentAverage = 0;

        foreach ($events as $event) {
            if ($fromDate && $event['date'] < $fromDate->format('Y-m-d H:i:s')) {
                // Build opening state from pre-fromDate events
                if ($event['type'] === 'purchase') {
                    $state = BackfillCostCalculator::applyPurchase(
                        $event['quantity'],
                        $event['cost'],
                        $runningQty,
                        $runningValue,
                        $currentAverage,
                    );
                } else {
                    $state = BackfillCostCalculator::applyConsumption(
                        $event['quantity'],
                        $runningQty,
                        $runningValue,
                        $currentAverage,
                    );
                }
                $runningQty = $state['runningQty'];
                $runningValue = $state['runningValue'];
                $currentAverage = $state['currentAverage'];
                continue;
            }

            // Apply in-scope events
            if ($event['type'] === 'purchase') {
                $state = BackfillCostCalculator::applyPurchase(
                    $event['quantity'],
                    $event['cost'],
                    $runningQty,
                    $runningValue,
                    $currentAverage,
                );
            } else {
                $state = BackfillCostCalculator::applyConsumption(
                    $event['quantity'],
                    $runningQty,
                    $runningValue,
                    $currentAverage,
                );
            }
            $runningQty = $state['runningQty'];
            $runningValue = $state['runningValue'];
            $currentAverage = $state['currentAverage'];
        }

        return $currentAverage > 0 ? round($currentAverage, 2) : 0;
    }

    /**
     * Pre-scan future purchases to establish fallback averages for buckets without in-scope purchases.
     * Used by backfill commands to handle the "no purchase history" case.
     */
    public function buildFallbackAverages(
        int $productId,
        ?Carbon $afterDate = null,
    ): array {
        $fallbackAverages = [
            'tiga_nusa' => null,
            'top_it' => null,
            'rest' => null,
        ];

        if (!$afterDate) {
            return $fallbackAverages;
        }

        $futurePurchases = PurchaseDetail::query()
            ->with([
                'purchase' => function ($q) {
                    $q->select('id', 'date', 'status', 'setting_id');
                },
                'receivedNoteDetails' => function ($q) {
                    $q->select('id', 'po_detail_id', 'received_note_id', 'quantity_received');
                },
                'receivedNoteDetails.receivedNote' => function ($q) {
                    $q->select('id', 'po_id', 'date', 'status', 'approved_at');
                },
            ])
            ->select('id', 'purchase_id', 'product_id', 'quantity', 'unit_price',
                     'sub_total', 'product_tax_amount', 'product_discount_amount',
                     'product_discount_type')
            ->where('product_id', $productId)
            ->whereHas('purchase', function ($q) use ($afterDate) {
                $q->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY, 'COMPLETED']);
                $q->where('date', '>', $afterDate);
            })
            ->orderBy('id', 'asc')
            ->get();

        foreach ($futurePurchases as $detail) {
            $companyName = $this->getSettingCompanyName($detail->purchase->setting_id);
            $bucket = BackfillCostCalculator::classifyBucket($companyName);

            if ($fallbackAverages[$bucket] !== null) {
                continue; // Already have fallback for this bucket
            }

            $receivedNotes = $detail->receivedNoteDetails ?? collect();
            $hasApprovedReceipts = false;
            $lineCost = 0;
            $receiptQty = 0;

            // Try approved received-note details first
            foreach ($receivedNotes as $rnd) {
                $rn = $rnd->receivedNote;
                if (!$rn || $rn->status !== ReceivedNote::STATUS_APPROVED || !$rn->approved_at) {
                    continue;
                }

                $hasApprovedReceipts = true;
                $receiptQty = (float) $rnd->quantity_received;
                if ($receiptQty <= 0) {
                    continue;
                }

                $lineCost = $this->calculateLineReceiptCost(
                    unitPrice: $detail->unit_price,
                    qty: (float) $detail->quantity,
                    receiptQty: $receiptQty,
                    subTotal: $detail->sub_total,
                    taxAmount: $detail->product_tax_amount,
                    discountAmount: $detail->product_discount_amount,
                    discountType: $detail->product_discount_type,
                );

                break; // Use first receipt only
            }

            // Fallback: if no approved received notes, use purchase document only for "Completed" status
            // Skip "RECEIVED PARTIALLY" without actual receipts as invalid
            if (!$hasApprovedReceipts) {
                if ($detail->purchase->status === Purchase::STATUS_RECEIVED_PARTIALLY) {
                    continue; // Skip RECEIVED PARTIALLY without actual receipts
                }

                $qty = (float) $detail->quantity;
                if ($qty > 0) {
                    $lineDpp = BackfillCostCalculator::calculatePurchaseDpp(
                        subTotal: $detail->sub_total,
                        taxAmount: $detail->product_tax_amount,
                        discountAmount: $detail->product_discount_amount,
                    );
                    $lineCost = max(0, round($lineDpp, 2));
                    $receiptQty = $qty;
                }
            }

            if ($receiptQty > 0 && $lineCost >= 0) {
                $fallbackAverages[$bucket] = $lineCost / $receiptQty;
            }

            if (collect($fallbackAverages)->filter(fn($v) => $v !== null)->count() === 3) {
                break; // All buckets have fallbacks
            }
        }

        return $fallbackAverages;
    }

    /**
     * Replay events with bucket isolation and fallback logic.
     * Returns an array of moving averages at each sale event for historical HPP snapshot.
     *
     * @return array{
     *     final_average: float,
     *     sale_snapshots: array<int, float>,
     *     warnings: array<string, mixed>
     * }
     */
    public function replayWithBucketIsolation(Collection $events, ?Carbon $fromDate = null): array
    {
        $states = [
            'tiga_nusa' => ['runningQty' => 0, 'runningValue' => 0, 'currentAverage' => 0, 'hasPurchases' => false],
            'top_it'    => ['runningQty' => 0, 'runningValue' => 0, 'currentAverage' => 0, 'hasPurchases' => false],
            'rest'      => ['runningQty' => 0, 'runningValue' => 0, 'currentAverage' => 0, 'hasPurchases' => false],
        ];

        $saleSnapshots = [];
        $warnings = [];

        // Pre-scan to mark which buckets have purchases
        foreach ($events as $event) {
            if ($event['type'] === 'purchase' && $event['quantity'] > 0) {
                $states[$event['bucket']]['hasPurchases'] = true;
            }
        }

        // Process events
        foreach ($events as $event) {
            $bucket = $event['bucket'];
            $inScope = !$fromDate || $event['date'] >= $fromDate->format('Y-m-d H:i:s');

            if ($event['type'] === 'purchase') {
                $state = BackfillCostCalculator::applyPurchase(
                    $event['quantity'],
                    $event['cost'],
                    $states[$bucket]['runningQty'],
                    $states[$bucket]['runningValue'],
                    $states[$bucket]['currentAverage'],
                );
                $states[$bucket]['runningQty'] = $state['runningQty'];
                $states[$bucket]['runningValue'] = $state['runningValue'];
                $states[$bucket]['currentAverage'] = $state['currentAverage'];
            } elseif ($event['type'] === 'purchase_return') {
                $state = BackfillCostCalculator::applyConsumption(
                    $event['quantity'],
                    $states[$bucket]['runningQty'],
                    $states[$bucket]['runningValue'],
                    $states[$bucket]['currentAverage'],
                );
                $states[$bucket]['runningQty'] = $state['runningQty'];
                $states[$bucket]['runningValue'] = $state['runningValue'];
                $states[$bucket]['currentAverage'] = $state['currentAverage'];

                if ($states[$bucket]['runningQty'] < 0) {
                    $warnings['negative_stock'][] = [
                        'type' => 'purchase_return',
                        'id' => $event['id'],
                        'date' => $event['date'],
                        'bucket' => $bucket,
                    ];
                }
            } elseif ($event['type'] === 'sale' && $inScope) {
                // Determine evaluation bucket with fallback
                $evalBucket = $bucket;
                if ($bucket !== 'rest' && !$states[$bucket]['hasPurchases']) {
                    $evalBucket = 'rest';
                }

                // Capture moving average before consumption
                $unitCost = $states[$evalBucket]['currentAverage'];
                $saleSnapshots[$event['id']] = $unitCost;

                // Consume stock
                $state = BackfillCostCalculator::applyConsumption(
                    $event['quantity'],
                    $states[$bucket]['runningQty'],
                    $states[$bucket]['runningValue'],
                    $states[$bucket]['currentAverage'],
                );
                $states[$bucket]['runningQty'] = $state['runningQty'];
                $states[$bucket]['runningValue'] = $state['runningValue'];
                $states[$bucket]['currentAverage'] = $state['currentAverage'];

                if ($states[$bucket]['runningQty'] < 0) {
                    $warnings['negative_stock'][] = [
                        'type' => 'sale',
                        'id' => $event['id'],
                        'date' => $event['date'],
                        'bucket' => $bucket,
                    ];
                }
            } elseif ($event['type'] === 'sale' && !$inScope) {
                // Still consume but don't snapshot if out of scope
                $state = BackfillCostCalculator::applyConsumption(
                    $event['quantity'],
                    $states[$bucket]['runningQty'],
                    $states[$bucket]['runningValue'],
                    $states[$bucket]['currentAverage'],
                );
                $states[$bucket]['runningQty'] = $state['runningQty'];
                $states[$bucket]['runningValue'] = $state['runningValue'];
                $states[$bucket]['currentAverage'] = $state['currentAverage'];
            }
        }

        return [
            'final_average' => $states['rest']['currentAverage'] > 0 ? round($states['rest']['currentAverage'], 2) : 0,
            'sale_snapshots' => $saleSnapshots,
            'warnings' => $warnings,
        ];
    }

    /**
     * Calculate the line receipt cost from stored values (non-corrected purchases).
     * DPP = sub_total - tax - discount.
     */
    private function calculateLineReceiptCost(
        float $unitPrice,
        float $qty,
        float $receiptQty,
        float $subTotal,
        float $taxAmount,
        float $discountAmount,
        string $discountType,
    ): float {
        // DPP: tax-exclusive, discount-aware line amount
        $lineDpp = BackfillCostCalculator::calculatePurchaseDpp(
            subTotal: $subTotal,
            taxAmount: $taxAmount,
            discountAmount: $discountAmount,
        );

        // Prorate across actual receipt
        if ($qty > 0) {
            $proratedDpp = $lineDpp * ($receiptQty / $qty);
        } else {
            $proratedDpp = 0;
        }

        return max(0, round($proratedDpp, 2));
    }

    /**
     * Calculate the corrected line receipt cost using corrected purchase data.
     * Allocates global discount and shipping proportionally across lines.
     */
    private function calculateCorrectedLineReceiptCost(
        PurchaseDetail $detail,
        float $receiptQty,
        Purchase $purchase,
    ): float {
        // Line DPP: sub_total already has discount applied
        $lineDpp = $detail->sub_total - $detail->product_tax_amount;

        // Allocate global discount and shipping proportionally
        $allocatedAdjustment = $this->calculateLineAllocationAdjustment(
            detail: $detail,
            lineDpp: $lineDpp,
            purchase: $purchase,
        );

        // Prorate across actual receipt
        $orderedQty = (float) $detail->quantity;
        if ($orderedQty > 0) {
            $proratedDpp = ($lineDpp + $allocatedAdjustment) * ($receiptQty / $orderedQty);
        } else {
            $proratedDpp = $lineDpp + $allocatedAdjustment;
        }

        return max(0, round($proratedDpp, 2));
    }

    /**
     * Calculate allocated global discount and shipping for a line detail.
     * Uses document-level proportional allocation.
     */
    private function calculateLineAllocationAdjustment(
        PurchaseDetail $detail,
        float $lineDpp,
        Purchase $purchase,
    ): float {
        if ($lineDpp <= 0) {
            return 0;
        }

        // Build group totals for allocation
        $groupTotals = [];
        foreach ($purchase->purchaseDetails as $pd) {
            $dpp = $pd->sub_total - $pd->product_tax_amount;
            if ($dpp > 0) {
                $groupTotals[$pd->id] = $dpp;
            }
        }

        if (empty($groupTotals)) {
            return 0;
        }

        // Use allocator to distribute adjustments
        $allocator = new ImportDocumentAdjustmentAllocator();
        $discountAllocations = $allocator->allocate($groupTotals, -(float) ($purchase->discount_amount ?? 0));
        $shippingAllocations = $allocator->allocate($groupTotals, (float) ($purchase->shipping_amount ?? 0));

        return ($discountAllocations[$detail->id] ?? 0) + ($shippingAllocations[$detail->id] ?? 0);
    }

    private function getSettingCompanyName(int $settingId): ?string
    {
        if (!$this->settingsCache->has($settingId)) {
            $setting = Setting::find($settingId);
            $this->settingsCache[$settingId] = $setting?->company_name;
        }
        return $this->settingsCache[$settingId];
    }
}

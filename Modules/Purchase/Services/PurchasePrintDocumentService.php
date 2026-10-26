<?php

namespace Modules\Purchase\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchasePrintDocumentService
{
    public const VARIANT_PURCHASE = 'purchase';
    public const VARIANT_ORDER = 'order';

    /**
     * Map of supported variant aliases.
     *
     * @var array<string, string>
     */
    protected array $variantAliases = [
        'purchase' => self::VARIANT_PURCHASE,
        'pembelian' => self::VARIANT_PURCHASE,
        'invoice' => self::VARIANT_PURCHASE,
        'order' => self::VARIANT_ORDER,
        'pesanan' => self::VARIANT_ORDER,
        'po' => self::VARIANT_ORDER,
    ];

    /**
     * Normalize and validate variant parameter.
     */
    public function resolveVariant(?string $variant): string
    {
        if (empty($variant)) {
            return self::VARIANT_PURCHASE;
        }

        $normalized = strtolower(trim($variant));

        if (!array_key_exists($normalized, $this->variantAliases)) {
            throw new HttpException(400, 'Varian dokumen pembelian tidak valid.');
        }

        return $this->variantAliases[$normalized];
    }

    /**
     * Authorize and load purchase with all bounded relations.
     */
    public function authorizeAndLoad(int|string|Purchase $purchaseId): Purchase
    {
        $id = $purchaseId instanceof Purchase ? $purchaseId->id : $purchaseId;

        $purchase = Purchase::withArchived()
            ->with([
                'tenantSetting',
                'supplier',
                'purchaseDetails.product.baseUnit',
                'purchaseDetails.productUnitConversion',
                'purchaseDetails.purchaseUnit',
                'purchaseDetails.tax',
                'purchasePayments',
            ])
            ->findOrFail($id);

        $user = auth()->user();
        if (!$user) {
            abort(401);
        }

        abort_if(Gate::denies('purchases.show'), 403, 'Anda tidak memiliki izin untuk melihat dokumen pembelian.');

        $settingId = (int) $purchase->setting_id;
        $sessionSettingId = session('setting_id');
        $isCrossBusiness = !is_null($sessionSettingId) && (int) $sessionSettingId !== $settingId;

        $hasBusinessAccess = $user->hasRole('Super Admin')
            || ($user->settings()->exists()
                ? $user->settings()->where('settings.id', $settingId)->exists()
                : ((int) $sessionSettingId === $settingId));

        if (!$hasBusinessAccess) {
            abort(403, 'Anda tidak memiliki akses ke bisnis pemilik dokumen ini.');
        }

        if ($isCrossBusiness) {
            abort_if(Gate::denies('purchasePayments.global.access'), 403, 'Akses global pembayaran diperlukan untuk mencetak dokumen lintas bisnis.');
        }

        return $purchase;
    }

    /**
     * Build presentation payload for printing.
     *
     * @return array<string, mixed>
     */
    public function buildPresentationPayload(Purchase $purchase, string $variant): array
    {
        $variant = $this->resolveVariant($variant);

        $tenantSetting = $purchase->tenantSetting;
        if (!$tenantSetting) {
            throw new HttpException(422, 'Pengaturan bisnis pemilik dokumen tidak ditemukan.');
        }

        $isPkp = (bool) $tenantSetting->is_pkp;
        $isTaxIncluded = (bool) $purchase->is_tax_included;
        $supplier = $purchase->supplier;

        $preparedRows = [];
        $subtotalSum = 0.0;
        $taxByRate = [];
        $totalRecordedTax = 0.0;

        foreach ($purchase->purchaseDetails as $detail) {
            $rowSubtotal = (float) $detail->sub_total;
            $subtotalSum += $rowSubtotal;

            $taxAmount = (float) $detail->product_tax_amount;
            $totalRecordedTax += $taxAmount;

            $rateValue = $this->resolveHistoricalRowTaxRate(
                $taxAmount,
                $rowSubtotal,
                (float) ($purchase->tax_percentage ?? 0)
            );

            if ($rateValue !== null) {
                $rateKey = number_format($rateValue, 2, '.', '');
                if (!isset($taxByRate[$rateKey])) {
                    $taxByRate[$rateKey] = [
                        'rate' => $rateValue,
                        'tax_amount' => 0.0,
                    ];
                }
                $taxByRate[$rateKey]['tax_amount'] += $taxAmount;
            }

            $enteredQty = $detail->effective_entered_quantity;
            $enteredUnitPrice = $detail->effective_entered_unit_price;
            $unitName = $detail->effective_unit_name;

            $isPct = $detail->isPercentageDiscount();
            $discountPct = $isPct ? (float) $detail->resolveEnteredDiscountInput() : null;
            $discountVal = (float) $detail->resolveEnteredCurrencyDiscount();

            $displayUnitPrice = $enteredUnitPrice;
            $displayDiscountVal = $discountVal;
            if (!$isPkp && !$isTaxIncluded && $taxAmount > 0) {
                $taxMultiplier = 1.0 + (($rateValue ?? 0) / 100);
                if ($taxMultiplier <= 1.0 && ($rowSubtotal - $taxAmount) > 0) {
                    $taxMultiplier = $rowSubtotal / ($rowSubtotal - $taxAmount);
                }
                $displayUnitPrice = round($enteredUnitPrice * $taxMultiplier, 2);
                if ($discountVal > 0) {
                    $displayDiscountVal = round($discountVal * $taxMultiplier, 2);
                }
            }

            $preparedRows[] = [
                'detail' => $detail,
                'product_name' => $detail->display_product_name ?? $detail->product_name ?? '-',
                'product_code' => $detail->product_code ?? optional($detail->product)->product_code,
                'entered_quantity' => $enteredQty,
                'entered_unit_price' => $displayUnitPrice,
                'unit_name' => $unitName,
                'is_percentage_discount' => $isPct,
                'discount_percentage' => $discountPct,
                'discount_amount' => $displayDiscountVal,
                'sub_total' => $rowSubtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $rateValue,
            ];
        }

        $discountPercentage = (float) ($purchase->discount_percentage ?? 0);
        $discountAmount = (float) ($purchase->discount_amount ?? 0);

        if ($discountPercentage > 0) {
            $overallDiscount = round($subtotalSum * ($discountPercentage / 100), 2);
            $overallDiscountPercentage = $discountPercentage;
        } else {
            $overallDiscount = $discountAmount;
            $overallDiscountPercentage = 0.0;
        }

        $shipping = (float) ($purchase->shipping_amount ?? 0);
        $savedGrandTotal = (float) $purchase->total_amount;

        $isTaxIncluded = (bool) $purchase->is_tax_included;

        if ($isPkp) {
            $dpp = max(0.0, round($subtotalSum - $overallDiscount - $totalRecordedTax, 2));
        } else {
            $dpp = 0.0;
            $totalRecordedTax = 0.0;
            $taxByRate = [];
        }

        $expectedTotal = round($subtotalSum - $overallDiscount + $shipping, 2);
        $reconciliationDiff = round($savedGrandTotal - $expectedTotal, 2);

        $paidAmount = $purchase->getEffectivePaidAmount();
        $dueAmount = $purchase->live_due_amount;

        $statusLabel = match ($purchase->status) {
            Purchase::STATUS_DRAFTED, 'Draft', 'DRAFT' => 'Draft',
            Purchase::STATUS_WAITING_APPROVAL, 'Pending', 'PENDING' => 'Menunggu Persetujuan',
            Purchase::STATUS_APPROVED => 'Disetujui',
            Purchase::STATUS_RECEIVED_PARTIALLY, 'Partially Received' => 'Diterima Sebagian',
            Purchase::STATUS_RECEIVED => 'Diterima',
            Purchase::STATUS_REJECTED => 'Ditolak',
            Purchase::STATUS_RETURNED, Purchase::STATUS_RETURNED_PARTIALLY => 'Retur',
            default => (string) $purchase->status,
        };

        $documentTitle = $variant === self::VARIANT_ORDER ? 'PESANAN PEMBELIAN' : 'PEMBELIAN';
        $filename = ($variant === self::VARIANT_ORDER ? 'pesanan-pembelian-' : 'pembelian-') . ($purchase->reference ?: $purchase->id) . '.pdf';

        return [
            'purchase' => $purchase,
            'supplier' => $supplier,
            'details' => $preparedRows,
            'company' => [
                'name' => $tenantSetting->company_name,
                'address' => $tenantSetting->company_address,
                'phone' => $tenantSetting->company_phone,
                'email' => $tenantSetting->company_email,
                'is_pkp' => $isPkp,
            ],
            'variant' => $variant,
            'documentTitle' => $documentTitle,
            'documentNumber' => $purchase->reference ?: (string) $purchase->id,
            'filename' => $filename,
            'dateFormatted' => $purchase->date ? Carbon::parse($purchase->date)->format('d/m/Y') : '-',
            'dueDateFormatted' => $purchase->due_date ? Carbon::parse($purchase->due_date)->format('d/m/Y') : '-',
            'statusLabel' => $statusLabel,
            'isDraft' => in_array($purchase->status, [Purchase::STATUS_DRAFTED, 'Draft', 'DRAFT'], true),
            'isPending' => in_array($purchase->status, [Purchase::STATUS_WAITING_APPROVAL, 'Pending', 'PENDING'], true),
            'isRejected' => in_array($purchase->status, [Purchase::STATUS_REJECTED, 'Rejected', 'REJECTED'], true),
            'isArchived' => $purchase->isArchived(),
            'isPkp' => $isPkp,
            'isTaxIncluded' => $isTaxIncluded,
            'showSettlement' => $variant === self::VARIANT_PURCHASE,
            'totals' => [
                'subtotal' => $subtotalSum,
                'overall_discount' => $overallDiscount,
                'overall_discount_percentage' => $overallDiscountPercentage,
                'shipping' => $shipping,
                'dpp' => $dpp,
                'tax_amount' => $totalRecordedTax,
                'tax_by_rate' => array_values($taxByRate),
                'reconciliation_diff' => $reconciliationDiff,
                'grand_total' => $savedGrandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'terbilang' => function_exists('terbilang') ? strtoupper(terbilang($savedGrandTotal)) . ' RUPIAH' : '',
            ],
        ];
    }

    /**
     * Stream or return the generated PDF response.
     */
    public function renderPdf(int|string|Purchase $purchaseId, string $variant = self::VARIANT_PURCHASE): Response
    {
        $purchase = $this->authorizeAndLoad($purchaseId);
        $payload = $this->buildPresentationPayload($purchase, $variant);

        $pdf = Pdf::loadView('purchase::print', $payload);

        return $pdf->stream($payload['filename']);
    }

    /**
     * Resolve reliable historical row tax rate from recorded amounts and transaction snapshots,
     * avoiding false rates from subsequent tax master modifications.
     */
    private function resolveHistoricalRowTaxRate(float $taxAmount, float $rowSubtotal, float $headerTaxPercentage): ?float
    {
        $rowDpp = $rowSubtotal - $taxAmount;

        if ($taxAmount > 0 && $rowDpp > 0) {
            $derivedRate = round(($taxAmount / $rowDpp) * 100, 2);

            // If header recorded a positive tax_percentage that closely matches the derived rate, prefer it
            if ($headerTaxPercentage > 0 && abs($derivedRate - $headerTaxPercentage) < 0.25) {
                return $headerTaxPercentage;
            }

            // Derivation from recorded immutable amounts prevents modified tax masters from altering reprints
            return $derivedRate;
        }

        if ($headerTaxPercentage > 0) {
            return $headerTaxPercentage;
        }

        return null;
    }
}

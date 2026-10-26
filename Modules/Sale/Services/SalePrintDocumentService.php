<?php

namespace Modules\Sale\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalePrintDocumentService
{
    public const VARIANT_INVOICE = 'invoice';
    public const VARIANT_OFFER = 'offer';

    /**
     * Map of supported variant aliases.
     *
     * @var array<string, string>
     */
    protected array $variantAliases = [
        'invoice' => self::VARIANT_INVOICE,
        'faktur' => self::VARIANT_INVOICE,
        'offer' => self::VARIANT_OFFER,
        'penawaran' => self::VARIANT_OFFER,
        'quotation' => self::VARIANT_OFFER,
    ];

    /**
     * Normalize and validate variant parameter.
     */
    public function resolveVariant(?string $variant): string
    {
        if (empty($variant)) {
            return self::VARIANT_INVOICE;
        }

        $normalized = strtolower(trim($variant));

        if (!array_key_exists($normalized, $this->variantAliases)) {
            throw new HttpException(400, 'Varian dokumen penjualan tidak valid.');
        }

        return $this->variantAliases[$normalized];
    }

    /**
     * Authorize and load sale with bounded relations.
     */
    public function authorizeAndLoad(int|string|Sale $saleId): Sale
    {
        $id = $saleId instanceof Sale ? $saleId->id : $saleId;

        $sale = Sale::withArchived()
            ->with([
                'tenantSetting',
                'customer',
                'saleDetails.product.baseUnit',
                'saleDetails.tax',
                'bundleItems.product.baseUnit',
                'bundleItems.tax',
                'salePayments.paymentMethod',
            ])
            ->findOrFail($id);

        $user = auth()->user();
        if (!$user) {
            abort(401);
        }

        abort_if(Gate::denies('sales.show'), 403, 'Anda tidak memiliki izin untuk melihat dokumen penjualan.');

        $settingId = (int) $sale->setting_id;
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
            abort_if(
                Gate::denies('salePayments.global.access') && Gate::denies('globalSalesSearch.access'),
                403,
                'Akses global pembayaran diperlukan untuk mencetak dokumen lintas bisnis.'
            );
        }

        return $sale;
    }

    /**
     * Build presentation payload for printing.
     *
     * @return array<string, mixed>
     */
    public function buildPresentationPayload(Sale $sale, string $variant): array
    {
        $variant = $this->resolveVariant($variant);

        $sale->loadMissing([
            'tenantSetting',
            'customer',
            'saleDetails.product.baseUnit',
            'saleDetails.tax',
            'bundleItems.product.baseUnit',
            'bundleItems.tax',
            'salePayments.paymentMethod',
        ]);

        $tenantSetting = $sale->tenantSetting;
        if (!$tenantSetting) {
            throw new HttpException(422, 'Pengaturan bisnis pemilik dokumen tidak ditemukan.');
        }

        $isPkp = (bool) $tenantSetting->is_pkp;
        $isTaxIncluded = (bool) $sale->is_tax_included;
        $customer = $sale->customer;

        $preparedRows = [];
        $subtotalSum = 0.0;
        $taxByRate = [];
        $totalRecordedTax = 0.0;

        // 1. Ordinary sale details
        foreach ($sale->saleDetails as $detail) {
            $rowSubtotal = (float) $detail->sub_total;
            $subtotalSum += $rowSubtotal;

            $taxAmount = (float) $detail->product_tax_amount;
            $totalRecordedTax += $taxAmount;

            $rateValue = $this->resolveHistoricalRowTaxRate(
                $taxAmount,
                $rowSubtotal,
                (float) ($sale->tax_percentage ?? 0)
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

            $rawUnitPrice = (float) ($detail->unit_price ?? $detail->price);
            $displayUnitPrice = $rawUnitPrice;
            if (!$isPkp && !$isTaxIncluded && $taxAmount > 0) {
                $taxMultiplier = 1.0 + (($rateValue ?? 0) / 100);
                if ($taxMultiplier <= 1.0 && ($rowSubtotal - $taxAmount) > 0) {
                    $taxMultiplier = $rowSubtotal / ($rowSubtotal - $taxAmount);
                }
                $displayUnitPrice = round($rawUnitPrice * $taxMultiplier, 2);
            }

            $preparedRows[] = [
                'is_bundle' => false,
                'product_name' => $detail->display_product_name ?? $detail->product_name ?? '-',
                'product_code' => $detail->product_code ?? optional($detail->product)->product_code,
                'quantity' => $detail->quantity,
                'unit_name' => $detail->product->baseUnit->name ?? '-',
                'unit_price' => $displayUnitPrice,
                'sub_total' => $rowSubtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $rateValue,
            ];
        }

        // 2. Standalone bundle commercial items (nonduplicated)
        $standaloneBundles = $sale->bundleItems->filter(fn ($item) => is_null($item->sale_detail_id));
        foreach ($standaloneBundles as $bundleItem) {
            $rowSubtotal = (float) $bundleItem->sub_total;
            $subtotalSum += $rowSubtotal;

            $taxAmount = (float) $bundleItem->resolved_tax_amount;
            $totalRecordedTax += $taxAmount;

            $rateValue = $this->resolveHistoricalRowTaxRate(
                $taxAmount,
                $rowSubtotal,
                (float) ($sale->tax_percentage ?? 0)
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

            $rawBundlePrice = (float) ($bundleItem->price ?? $bundleItem->informational_item_price);
            $displayBundlePrice = $rawBundlePrice;
            if (!$isPkp && !$isTaxIncluded && $taxAmount > 0) {
                $taxMultiplier = 1.0 + (($rateValue ?? 0) / 100);
                if ($taxMultiplier <= 1.0 && ($rowSubtotal - $taxAmount) > 0) {
                    $taxMultiplier = $rowSubtotal / ($rowSubtotal - $taxAmount);
                }
                $displayBundlePrice = round($rawBundlePrice * $taxMultiplier, 2);
            }

            $preparedRows[] = [
                'is_bundle' => true,
                'product_name' => $bundleItem->display_product_name ?? $bundleItem->name ?? '-',
                'product_code' => optional($bundleItem->product)->product_code ?? $bundleItem->line_group_key,
                'quantity' => $bundleItem->quantity,
                'unit_name' => optional($bundleItem->product?->baseUnit)->name ?? '-',
                'unit_price' => $displayBundlePrice,
                'sub_total' => $rowSubtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $rateValue,
            ];
        }

        $discountPercentage = (float) ($sale->discount_percentage ?? 0);
        $discountAmount = (float) ($sale->discount_amount ?? 0);

        if ($discountPercentage > 0) {
            $overallDiscount = round($subtotalSum * ($discountPercentage / 100), 2);
            $overallDiscountPercentage = $discountPercentage;
        } else {
            $overallDiscount = $discountAmount;
            $overallDiscountPercentage = 0.0;
        }

        $shipping = (float) ($sale->shipping_amount ?? 0);
        $savedGrandTotal = (float) $sale->total_amount;
        $isTaxIncluded = (bool) $sale->is_tax_included;

        if ($isPkp) {
            $dpp = max(0.0, round($subtotalSum - $overallDiscount - $totalRecordedTax, 2));
        } else {
            $dpp = 0.0;
            $totalRecordedTax = 0.0;
            $taxByRate = [];
        }

        $expectedTotal = round($subtotalSum - $overallDiscount + $shipping, 2);
        $reconciliationDiff = round($savedGrandTotal - $expectedTotal, 2);

        // Canonical active settlements
        $activeMonetaryPayments = (float) $sale->salePayments()
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->sum('amount');

        $activeCreditsApplied = (float) DB::table('sale_payment_credit_applications')
            ->join('sale_payments', 'sale_payment_credit_applications.sale_payment_id', '=', 'sale_payments.id')
            ->where('sale_payments.sale_id', $sale->id)
            ->where('sale_payments.status', SalePayment::STATUS_ACTIVE)
            ->sum('sale_payment_credit_applications.amount');

        $totalEffectivePaid = round($activeMonetaryPayments + $activeCreditsApplied, 2);
        $dueAmount = $sale->live_due_amount;

        $statusLabel = match ($sale->status) {
            Sale::STATUS_DRAFTED, 'Draft', 'DRAFT' => 'Draft',
            Sale::STATUS_WAITING_APPROVAL, 'Pending', 'PENDING' => 'Menunggu Persetujuan',
            Sale::STATUS_APPROVED => 'Disetujui',
            Sale::STATUS_DISPATCHED_PARTIALLY => 'Terkirim Sebagian',
            Sale::STATUS_DISPATCHED => 'Terkirim',
            Sale::STATUS_RETURNED_PARTIALLY => 'Retur Sebagian',
            Sale::STATUS_RETURNED => 'Retur',
            Sale::STATUS_REJECTED => 'Ditolak',
            default => (string) $sale->status,
        };

        $documentTitle = $variant === self::VARIANT_OFFER ? 'NOTA PENAWARAN' : 'FAKTUR PENJUALAN';
        $filename = ($variant === self::VARIANT_OFFER ? 'nota-penawaran-' : 'faktur-penjualan-') . ($sale->reference ?: $sale->id) . '.pdf';

        return [
            'sale' => $sale,
            'customer' => $customer,
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
            'documentNumber' => $sale->reference ?: (string) $sale->id,
            'filename' => $filename,
            'dateFormatted' => $sale->date ? Carbon::parse($sale->date)->format('d/m/Y') : '-',
            'dueDateFormatted' => $sale->due_date ? Carbon::parse($sale->due_date)->format('d/m/Y') : '-',
            'statusLabel' => $statusLabel,
            'isDraft' => in_array($sale->status, [Sale::STATUS_DRAFTED, 'Draft', 'DRAFT'], true),
            'isPending' => in_array($sale->status, [Sale::STATUS_WAITING_APPROVAL, 'Pending', 'PENDING'], true),
            'isRejected' => in_array($sale->status, [Sale::STATUS_REJECTED, 'Rejected', 'REJECTED'], true),
            'isArchived' => $sale->isArchived(),
            'isPkp' => $isPkp,
            'isTaxIncluded' => $isTaxIncluded,
            'showSettlement' => $variant === self::VARIANT_INVOICE,
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
                'monetary_paid' => $activeMonetaryPayments,
                'credits_applied' => $activeCreditsApplied,
                'total_paid' => $totalEffectivePaid,
                'due_amount' => $dueAmount,
                'terbilang' => function_exists('terbilang') ? strtoupper(terbilang($savedGrandTotal)) . ' RUPIAH' : '',
            ],
        ];
    }

    /**
     * Stream or return the generated PDF response.
     */
    public function renderPdf(int|string|Sale $saleId, string $variant = self::VARIANT_INVOICE): Response
    {
        $sale = $this->authorizeAndLoad($saleId);
        $payload = $this->buildPresentationPayload($sale, $variant);

        $pdf = Pdf::loadView('sale::print.invoice', $payload);

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

<?php

namespace Modules\Purchase\Services;

use App\Services\MonetaryEdit\AbstractMonetaryEditService;
use App\Services\MonetaryEdit\MonetaryEditException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;

/**
 * In-place monetary edit for a received (or partially received) Purchase.
 *
 * Never deletes or recreates purchase_details, because received_note_details
 * cascade off those primary keys. Never touches stock, receiving records,
 * ProductPrice, purchase-cost recalculation, or historical replay: a corrected
 * supplier invoice changes the document's commercial value, not the executed
 * inventory facts or the current purchase-price indicators.
 */
class PurchaseMonetaryEditService extends AbstractMonetaryEditService
{
    /** Cart metadata key carrying the persisted purchase_details.id. */
    public const DETAIL_ID_OPTION = 'purchase_detail_id';

    protected function lockDetails(Model $document): Collection
    {
        return PurchaseDetail::query()
            ->where('purchase_id', $document->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    protected function assertMonetaryOnlyMode(Model $document): void
    {
        if ($document->resolveEditMode() !== Purchase::EDIT_MODE_MONETARY_ONLY) {
            throw new MonetaryEditException(
                'Pembelian ini tidak dapat diubah secara moneter pada status saat ini.'
            );
        }
    }

    protected function assertBelongsToActiveSetting(Model $document): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $document->setting_id !== (int) $currentSettingId) {
            throw new MonetaryEditException('Pembelian ini bukan milik bisnis yang sedang aktif.');
        }
    }

    protected function resolveSubmittedDetailId(mixed $cartItem): ?int
    {
        $detailId = data_get($cartItem, 'options.' . self::DETAIL_ID_OPTION);

        return is_numeric($detailId) ? (int) $detailId : null;
    }

    protected function assertProtectedRowValues(object $detail, mixed $cartItem): void
    {
        $submittedProductId = data_get($cartItem, 'options.product_id') ?? data_get($cartItem, 'id');

        if ((int) $submittedProductId !== (int) $detail->product_id) {
            throw new MonetaryEditException(
                "Produk pada baris '{$detail->product_name}' tidak boleh diubah setelah barang diterima."
            );
        }

        if (round((float) $detail->quantity, 3) !== round((float) data_get($cartItem, 'qty'), 3)) {
            throw new MonetaryEditException(
                "Kuantitas produk '{$detail->product_name}' tidak boleh diubah setelah barang diterima."
            );
        }
    }

    protected function normalize(Model $document, array $rows, array $input): array
    {
        $globalDiscount = is_numeric($input['global_discount'] ?? null) ? (float) $input['global_discount'] : 0.0;
        $discountType = $input['global_discount_type'] ?? 'percentage';

        return app(PurchaseNormalizer::class)->normalize([
            'discount_percentage' => $discountType === 'percentage' ? $globalDiscount : 0,
            'discount_amount' => $discountType === 'fixed' ? $globalDiscount : 0,
            'shipping_amount' => $input['shipping'] ?? 0,
            // Paid amount comes from persisted state; this workflow never edits payments.
            'paid_amount' => $document->paid_amount,
            // Header tax identity is protected, so it is carried through unchanged.
            'tax_id' => $document->tax_id,
            'tax_percentage' => $document->tax_percentage,
        ], collect($rows)->map(fn ($row) => $row->cartItem), $this->resolveIsPkp($document));
    }

    protected function persistHeader(Model $document, array $header, array $input): void
    {
        $isPkp = $this->resolveIsPkp($document);
        $total = (float) $header['total_amount'];

        // Reconcile the payment summary from the existing active payment rows,
        // mirroring the correction workflow's arithmetic — but without creating,
        // editing, invalidating, or deleting any payment row. apply() has already
        // rejected a total below this figure.
        $paid = round($this->effectivePaidAmount($document), 2);

        $document->forceFill([
            'discount_percentage' => $header['discount_percentage'],
            'discount_amount' => $header['discount_amount'],
            'shipping_amount' => $header['shipping_amount'],
            'tax_amount' => $header['tax_amount'],
            'total_amount' => $total,
            'paid_amount' => $paid,
            'due_amount' => max(0, round($total - $paid, 2)),
            'payment_status' => $this->resolvePaymentStatus($paid, $total),
            'is_tax_included' => $this->resolveIsTaxIncluded($document, $isPkp),
        ])->save();
    }

    /**
     * Payment status in the application's existing Purchase vocabulary
     * (uppercase PAID / PARTIAL / UNPAID), derived from persisted active
     * payments and the corrected total.
     */
    private function resolvePaymentStatus(float $paid, float $totalAmount): string
    {
        if ($paid <= 0.0) {
            return 'UNPAID';
        }

        return $paid >= round($totalAmount, 2) ? 'PAID' : 'PARTIAL';
    }

    protected function effectivePaidAmount(Model $document): float
    {
        return $document->getEffectivePaidAmount();
    }
}

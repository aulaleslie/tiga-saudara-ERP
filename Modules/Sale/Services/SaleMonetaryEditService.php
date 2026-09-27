<?php

namespace Modules\Sale\Services;

use App\Services\MonetaryEdit\AbstractMonetaryEditService;
use App\Services\MonetaryEdit\MonetaryEditException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;

/**
 * In-place monetary edit for a dispatched (or partially dispatched) Sale.
 *
 * Never deletes or recreates sale_details, because dispatch_details,
 * sale_bundle_items, and serial assignments all hang off those primary keys —
 * and because the normal update path regenerates HPP cost snapshots even when
 * quantities are unchanged. Cost snapshots, bundle rows, and stock are left
 * exactly as the dispatch executed them.
 */
class SaleMonetaryEditService extends AbstractMonetaryEditService
{
    protected function lockDetails(Model $document): Collection
    {
        return SaleDetails::query()
            ->where('sale_id', $document->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    protected function assertMonetaryOnlyMode(Model $document): void
    {
        if ($document->resolveEditMode() !== Sale::EDIT_MODE_MONETARY_ONLY) {
            throw new MonetaryEditException(
                'Penjualan ini tidak dapat diubah secara moneter pada status saat ini.'
            );
        }
    }

    protected function assertBelongsToActiveSetting(Model $document): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $document->setting_id !== (int) $currentSettingId) {
            throw new MonetaryEditException('Penjualan ini bukan milik bisnis yang sedang aktif.');
        }
    }

    protected function assertGlobalAuthorizationAndEligibility(Model $document, mixed $user): void
    {
        if (! $user->can('salePayments.global.access')
            || ! $user->can('sales.edit')
            || ! $user->can('sales.dispatched.monetary.edit')) {
            throw new MonetaryEditException('Anda tidak memiliki hak akses untuk mengubah penjualan ini secara global.');
        }

        $eligible = Sale::globalPaymentEligible()
            ->whereNull('archived_at')
            ->whereKey($document->getKey())
            ->exists();

        if (! $eligible) {
            throw new MonetaryEditException('Penjualan ini tidak memenuhi syarat untuk penyesuaian pembayaran global.');
        }
    }

    protected function assertNormalAuthorization(Model $document, mixed $user): void
    {
        if (! $user->can('sales.edit') || ! $user->can('sales.dispatched.monetary.edit')) {
            throw new MonetaryEditException('Anda tidak memiliki hak akses untuk mengubah nilai moneter penjualan ini.');
        }
    }

    protected function resolveSubmittedDetailId(mixed $cartItem): ?int
    {
        // Sale cart hydration keys each row by its persisted sale_details.id.
        $detailId = data_get($cartItem, 'id');

        return is_numeric($detailId) ? (int) $detailId : null;
    }

    protected function assertProtectedRowValues(object $detail, mixed $cartItem): void
    {
        $submittedProductId = data_get($cartItem, 'options.product_id');

        if ((int) $submittedProductId !== (int) $detail->product_id) {
            throw new MonetaryEditException(
                "Produk pada baris '{$detail->product_name}' tidak boleh diubah setelah barang dikirim."
            );
        }

        if (round((float) $detail->quantity, 3) !== round((float) data_get($cartItem, 'qty'), 3)) {
            throw new MonetaryEditException(
                "Kuantitas produk '{$detail->product_name}' tidak boleh diubah setelah barang dikirim."
            );
        }
    }

    protected function normalize(Model $document, array $rows, array $input): array
    {
        // Global discount and shipping are editable header monetary values. A
        // submitted value is authoritative — including an explicit zero, which
        // is how a user clears a discount; only an absent key falls back to the
        // persisted figure.
        [$discountPercentage, $discountAmount] = $this->resolveGlobalDiscount($document, $input);

        return app(SaleNormalizer::class)->normalize([
            // Header tax identity is protected and carried through unchanged.
            'tax_id' => $document->tax_id,
            'tax_percentage' => $document->tax_percentage,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'shipping_amount' => array_key_exists('shipping', $input) && is_numeric($input['shipping'])
                ? (float) $input['shipping']
                : $document->shipping_amount,
            // Paid amount comes from persisted state; this workflow never edits payments.
            'paid_amount' => $document->paid_amount,
        ], collect($rows)->map(fn ($row) => $row->cartItem), $this->resolveIsPkp($document));
    }

    /**
     * Split the submitted global discount into the normalizer's
     * percentage/amount pair, falling back to persisted values when the form
     * supplied nothing.
     *
     * @return array{0: float, 1: float}
     */
    private function resolveGlobalDiscount(Model $document, array $input): array
    {
        if (! array_key_exists('global_discount', $input) || ! is_numeric($input['global_discount'])) {
            return [(float) $document->discount_percentage, (float) $document->discount_amount];
        }

        $value = (float) $input['global_discount'];

        return ($input['global_discount_type'] ?? 'percentage') === 'fixed'
            ? [0.0, $value]
            : [$value, 0.0];
    }

    protected function persistHeader(Model $document, array $header, array $input): void
    {
        $isPkp = $this->resolveIsPkp($document);
        $total = (float) $header['total_amount'];

        // Due is derived from the persisted active-payment state (which counts
        // credit applications), matching getLiveDueAmountAttribute() — not from
        // the normalizer's paid_amount, which ignores credits.
        $paid = round($document->getEffectivePaidAmount(), 2);

        $document->forceFill([
            'discount_percentage' => $header['discount_percentage'],
            'discount_amount' => $header['discount_amount'],
            'shipping_amount' => $header['shipping_amount'],
            'tax_amount' => $header['tax_amount'],
            'total_amount' => $total,
            'due_amount' => max(0, round($total - $paid, 2)),
            'payment_status' => $this->resolvePaymentStatus($paid, $total),
            'is_tax_included' => $this->resolveIsTaxIncluded($document, $isPkp),
        ])->save();
    }

    /**
     * Derive the payment status from the persisted active-payment state and the
     * new total, without creating, updating, or deleting any payment row.
     */
    private function resolvePaymentStatus(float $paid, float $totalAmount): string
    {
        if ($paid <= 0.0) {
            return 'Unpaid';
        }

        return $paid >= round($totalAmount, 2) ? 'Paid' : 'Partial';
    }

    protected function effectivePaidAmount(Model $document): float
    {
        return $document->getEffectivePaidAmount();
    }
}

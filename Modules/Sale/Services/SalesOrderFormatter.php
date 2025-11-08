<?php

namespace Modules\Sale\Services;

use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;

class SalesOrderFormatter
{
    /**
     * Format a sale for list view display.
     *
     * @param Sale $sale
     * @return array
     */
    public function formatForList(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'status' => $sale->status,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
            ] : null,
            'tenant' => $this->getTenantInfo($sale),
            'seller' => $this->getSellerInfo($sale),
            'total_amount' => $sale->total_amount,
            'paid_amount' => $sale->paid_amount,
            'due_amount' => $sale->due_amount,
            'created_at' => $sale->created_at?->format('Y-m-d H:i:s'),
            'serial_numbers_count' => $this->getSerialNumbersCount($sale),
        ];
    }

    /**
     * Format a sale for detail view display.
     *
     * @param Sale $sale
     * @return array
     */
    public function formatForDetail(Sale $sale): array
    {
        $sale->load(['saleDetails.product', 'customer', 'seller', 'tenantSetting', 'location', 'salePayments']);

        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'status' => $sale->status,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
                'phone' => $sale->customer->phone ?? null,
                'email' => $sale->customer->email ?? null,
            ] : null,
            'tenant' => $this->getTenantInfo($sale),
            'seller' => $this->getSellerInfo($sale),
            'location' => $sale->location ? [
                'id' => $sale->location->id,
                'name' => $sale->location->name,
            ] : null,
            'total_amount' => $sale->total_amount,
            'tax_amount' => $sale->tax_amount,
            'discount_amount' => $sale->discount_amount,
            'shipping_amount' => $sale->shipping_amount,
            'paid_amount' => $sale->paid_amount,
            'due_amount' => $sale->due_amount,
            'created_at' => $sale->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $sale->updated_at?->format('Y-m-d H:i:s'),
            'sale_details' => $this->formatSaleDetails($sale->saleDetails),
            'payment_history' => $this->formatPaymentHistory($sale->salePayments),
        ];
    }

    /**
     * Format sale details with serial numbers.
     *
     * @param $saleDetails
     * @return array
     */
    public function formatSaleDetails($saleDetails): array
    {
        return $saleDetails->map(function (SaleDetails $detail) {
            return [
                'id' => $detail->id,
                'product' => $detail->product ? [
                    'id' => $detail->product->id,
                    'name' => $detail->product->product_name,
                    'code' => $detail->product->product_code,
                ] : null,
                'quantity' => $detail->quantity,
                'unit_price' => $detail->unit_price,
                'price' => $detail->price,
                'sub_total' => $detail->sub_total,
                'product_tax_amount' => $detail->product_tax_amount,
                'product_discount_amount' => $detail->product_discount_amount,
                'serial_numbers' => $this->formatSerialNumbers($detail),
            ];
        })->toArray();
    }

    /**
     * Format serial numbers for a sale detail.
     *
     * @param SaleDetails $detail
     * @return array
     */
    public function formatSerialNumbers(SaleDetails $detail): array
    {
        if (!$detail->serial_number_ids || !is_array($detail->serial_number_ids)) {
            return [];
        }

        // Fetch the serial number records
        $serialNumbers = \Modules\Product\Entities\ProductSerialNumber::query()
            ->whereIn('id', $detail->serial_number_ids)
            ->with(['product', 'location', 'tax'])
            ->get();

        return $serialNumbers->map(function ($serial) {
            return [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'location' => $serial->location ? [
                    'id' => $serial->location->id,
                    'name' => $serial->location->name,
                ] : null,
                'tax' => $serial->tax ? [
                    'id' => $serial->tax->id,
                    'name' => $serial->tax->name,
                    'value' => $serial->tax->value,
                ] : null,
            ];
        })->toArray();
    }

    /**
     * Get tenant (setting) information for a sale.
     *
     * @param Sale $sale
     * @return array|null
     */
    public function getTenantInfo(Sale $sale): ?array
    {
        $sale->load('tenantSetting');

        if (!$sale->tenantSetting) {
            return null;
        }

        return [
            'id' => $sale->tenantSetting->id,
            'name' => $sale->tenantSetting->company_name,
            'email' => $sale->tenantSetting->company_email,
            'phone' => $sale->tenantSetting->company_phone,
            'address' => $sale->tenantSetting->company_address,
        ];
    }

    /**
     * Get seller (user) information for a sale.
     *
     * @param Sale $sale
     * @return array|null
     */
    public function getSellerInfo(Sale $sale): ?array
    {
        $sale->load('seller');

        if (!$sale->seller) {
            return null;
        }

        return [
            'id' => $sale->seller->id,
            'name' => $sale->seller->name,
            'email' => $sale->seller->email,
            'roles' => $sale->seller->getRoleNames()->toArray(),
        ];
    }

    /**
     * Get count of serial numbers in a sale.
     *
     * @param Sale $sale
     * @return int
     */
    public function getSerialNumbersCount(Sale $sale): int
    {
        $count = 0;
        foreach ($sale->saleDetails as $detail) {
            if ($detail->serial_number_ids && is_array($detail->serial_number_ids)) {
                $count += count($detail->serial_number_ids);
            }
        }
        return $count;
    }

    /**
     * Format payment history.
     *
     * @param $payments
     * @return array
     */
    public function formatPaymentHistory($payments): array
    {
        return $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method ?? 'N/A',
                'payment_date' => $payment->created_at?->format('Y-m-d H:i:s'),
                'reference' => $payment->reference ?? null,
            ];
        })->toArray();
    }

    /**
     * Format multiple sales for list display.
     *
     * @param iterable $sales
     * @return array
     */
    public function formatMultiple(iterable $sales): array
    {
        $formatted = [];
        foreach ($sales as $sale) {
            $formatted[] = $this->formatForList($sale);
        }
        return $formatted;
    }
}

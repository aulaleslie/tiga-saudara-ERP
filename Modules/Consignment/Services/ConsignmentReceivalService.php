<?php

namespace Modules\Consignment\Services;

use InvalidArgumentException;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

class ConsignmentReceivalService
{
    /**
     * Normalize and validate lines for Consignment Receival.
     *
     * @param Setting $setting
     * @param array $linesInput
     * @return array
     */
    public function normalizeLines(Setting $setting, array $linesInput): array
    {
        if (empty($linesInput)) {
            throw new InvalidArgumentException('Dokumen konsinyasi harus memiliki minimal satu baris produk.');
        }

        $isPkp = (bool) ($setting->is_pkp ?? false);
        $normalizedLines = [];

        foreach ($linesInput as $index => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $product = Product::with(['unit', 'baseUnit'])->find($productId);

            if (!$product) {
                throw new InvalidArgumentException("Produk pada baris #" . ($index + 1) . " tidak ditemukan.");
            }

            // Guard: active and stock-managed non-bundle product using the
            // canonical Product schema and setting-scoped bundle relation.
            if ($product->merged_into_id !== null) {
                throw new InvalidArgumentException("Produk '{$product->product_name}' tidak aktif.");
            }

            if ($product->bundles()->where('setting_id', $setting->id)->exists()) {
                throw new InvalidArgumentException("Produk bundle '{$product->product_name}' tidak dapat diterima sebagai konsinyasi.");
            }

            if (!$product->stock_managed) {
                throw new InvalidArgumentException("Produk '{$product->product_name}' bukan produk yang dikelola stok.");
            }

            $quantity = (float) ($line['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new InvalidArgumentException("Jumlah produk '{$product->product_name}' harus lebih besar dari 0.");
            }

            $isSerialized = (bool) ($product->serial_number_required ?? $product->is_serial_number_required ?? false);
            if ($isSerialized && floor($quantity) != $quantity) {
                throw new InvalidArgumentException("Produk dengan nomor seri '{$product->product_name}' harus bernilai bilangan bulat (whole number).");
            }

            $unitCost = (float) ($line['unit_cost'] ?? 0);
            if ($unitCost <= 0) {
                throw new InvalidArgumentException("Biaya satuan produk '{$product->product_name}' harus lebih besar dari 0.");
            }

            $taxId = null;
            $taxName = null;
            $taxRate = 0.0;
            $taxAmount = 0.0;
            $unitDpp = $unitCost;

            if ($isPkp) {
                $rawTaxId = $line['tax_id'] ?? null;
                if (!$rawTaxId) {
                    throw new InvalidArgumentException("Bisnis PKP mewajibkan pemilihan pajak pada produk '{$product->product_name}'.");
                }

                $tax = Tax::find($rawTaxId);
                if (!$tax) {
                    throw new InvalidArgumentException("Pajak yang dipilih pada produk '{$product->product_name}' tidak valid.");
                }

                $taxId = $tax->id;
                $taxName = $tax->name;
                $taxRate = (float) ($tax->rate ?? $tax->value ?? 0);

                // Unit DPP equals unit_cost for consignment (DPP base)
                $unitDpp = $unitCost;
                $subtotalCost = round($unitDpp * $quantity, 2);
                $taxAmount = round($subtotalCost * ($taxRate / 100), 2);
                $totalCost = round($subtotalCost + $taxAmount, 2);
            } else {
                $taxId = null;
                $taxName = null;
                $taxRate = 0.0;
                $taxAmount = 0.0;
                $unitDpp = $unitCost;
                $subtotalCost = round($unitDpp * $quantity, 2);
                $totalCost = $subtotalCost;
            }

            $unit = $product->unit ?? $product->baseUnit;

            $normalizedLines[] = [
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'unit_id' => $unit?->id,
                'unit_code' => $unit?->short_name ?? $unit?->operator ?? 'PCS',
                'tax_id' => $taxId,
                'tax_name' => $taxName,
                'tax_rate' => $taxRate,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'unit_dpp' => $unitDpp,
                'subtotal_cost' => $subtotalCost,
                'tax_amount' => $taxAmount,
                'total_cost' => $totalCost,
                'is_serialized' => $isSerialized,
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $normalizedLines;
    }
}

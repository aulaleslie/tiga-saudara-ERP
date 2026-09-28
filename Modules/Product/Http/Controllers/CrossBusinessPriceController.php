<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Product\Http\Requests\CrossBusinessPriceUpdateRequest;
use Modules\Product\Http\Requests\GlobalHppUpdateRequest;
use Modules\Product\Entities\Product;
use Modules\Product\Services\CrossBusinessPriceService;

class CrossBusinessPriceController extends Controller
{
    protected CrossBusinessPriceService $priceService;

    public function __construct(CrossBusinessPriceService $priceService)
    {
        $this->priceService = $priceService;
    }

    public function edit(Product $product)
    {
        $prices = $this->priceService->loadPricesForProduct($product);
        $conversionsData = $this->priceService->loadConversionPricesForProduct($product);
        $conversionSnapshot = $this->priceService->generateConversionSnapshot($product);
        $bundlesData = $this->priceService->loadBundlePricesForProduct($product);
        $bundleSnapshot = $this->priceService->generateBundleSnapshot($product);
        $globalHppSnapshot = $this->priceService->generateGlobalHppSnapshot($product);

        $activeSettingId = session('setting_id');
        $activeRow = collect($prices)->firstWhere('setting_id', $activeSettingId);
        $firstExistingRow = collect($prices)->firstWhere('is_existing', true);
        $activeExistingRow = ($activeRow && !empty($activeRow['is_existing'])) ? $activeRow : null;
        $currentGlobalHpp = $activeExistingRow['average_purchase_price'] ?? ($firstExistingRow['average_purchase_price'] ?? 0);

        // Check if existing averages diverge
        $existingAverages = collect($prices)
            ->filter(fn($p) => $p['is_existing'])
            ->map(fn($p) => number_format((float) $p['average_purchase_price'], 2, '.', ''))
            ->unique();
        $hasDivergentAverages = $existingAverages->count() > 1;

        return view('product::products.cross-business-prices', compact(
            'product',
            'prices',
            'conversionsData',
            'conversionSnapshot',
            'bundlesData',
            'bundleSnapshot',
            'globalHppSnapshot',
            'currentGlobalHpp',
            'hasDivergentAverages'
        ));
    }

    public function update(CrossBusinessPriceUpdateRequest $request, Product $product)
    {
        $validated = $request->validated();

        try {
            $this->priceService->savePricesForProduct(
                $product,
                $validated['prices'],
                $validated['conversions'] ?? null,
                $validated['conversion_snapshot'] ?? null,
                $validated['conversion_snapshot_signature'] ?? null,
                $validated['bundles'] ?? null,
                $validated['bundle_snapshot'] ?? null,
                $validated['bundle_snapshot_signature'] ?? null
            );
            return redirect()
                ->route('products.cross-business-prices.edit', $product)
                ->with('success', 'Prices updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage())
                ->withInput();
        }
    }

    public function updateGlobalHpp(GlobalHppUpdateRequest $request, Product $product)
    {
        if (!$product->stock_managed) {
            return redirect()->back()
                ->with('error', 'Produk bukan produk yang dikelola stoknya.')
                ->withInput();
        }

        $validated = $request->validated();

        try {
            $this->priceService->saveGlobalHppForProduct(
                $product,
                (float) $validated['average_purchase_price'],
                $validated['loaded_state_evidence'],
                $validated['loaded_state_signature']
            );

            return redirect()
                ->route('products.cross-business-prices.edit', $product)
                ->with('success', 'Harga Beli Rata-rata berhasil diperbarui untuk seluruh bisnis.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage())
                ->withInput();
        }
    }
}



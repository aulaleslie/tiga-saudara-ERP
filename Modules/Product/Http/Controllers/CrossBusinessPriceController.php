<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Product\Http\Requests\CrossBusinessPriceUpdateRequest;
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
        return view('product::products.cross-business-prices', compact('product', 'prices'));
    }

    public function update(CrossBusinessPriceUpdateRequest $request, Product $product)
    {
        $validated = $request->validated();

        try {
            $this->priceService->savePricesForProduct($product, $validated['prices']);
            return redirect()->route('products.index')
                ->with('success', 'Prices updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage())
                ->withInput();
        }
    }
}

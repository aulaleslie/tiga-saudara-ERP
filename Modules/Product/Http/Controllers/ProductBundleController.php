<?php

namespace Modules\Product\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;

class ProductBundleController extends Controller
{
    /**
     * Display a listing of bundles for a given parent product.
     *
     * @param int $productId
     * @return View
     */
    public function index(int $productId): View
    {
        $product = Product::findOrFail($productId);
        // Load bundles along with their items and the bundled products
        $bundles = $product->bundles()->with('items.product')->get();

        return view('product::bundles.index', compact('product', 'bundles'));
    }

    /**
     * Show the form for creating a new bundle for the parent product.
     *
     * @param int $productId
     * @return View
     */
    public function create(int $productId): View
    {
        $product = Product::findOrFail($productId);
        // Retrieve a list of products that can be bundled.
        // You might want to exclude the parent product itself.
        $products = Product::where('id', '!=', $productId)->get();

        return view('product::bundles.create', compact('product', 'products'));
    }

    /**
     * Store a newly created bundle in storage.
     *
     * @param Request $request
     * @param int $productId Parent product ID
     * @return RedirectResponse
     */
    public function store(Request $request, int $productId): RedirectResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'active_from' => 'nullable|date',
            'active_to'   => 'nullable|date|after_or_equal:active_from',
            'items'       => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.price'      => 'nullable|numeric|min:0',
            'items.*.quantity'   => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            // Create the bundle header record including active period
            $bundle = ProductBundle::create([
                'parent_product_id' => $productId,
                'name'              => $request->input('name'),
                'description'       => $request->input('description'),
                'active_from'       => $request->input('active_from'),
                'active_to'         => $request->input('active_to'),
            ]);

            // Create each bundle item
            foreach ($request->input('items') as $item) {
                $bundle->items()->create([
                    'product_id' => $item['product_id'],
                    'price'      => $item['price'] ?? null,
                    'quantity'   => $item['quantity'],
                ]);
            }

            DB::commit();
            return redirect()->route('products.show', $productId)
                ->with('success', 'Bundle created successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create bundle', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to create bundle.');
        }
    }

    /**
     * Show the form for editing the specified bundle.
     *
     * @param int $bundleId
     * @return View
     */
    public function edit(int $bundleId): View
    {
        $bundle = ProductBundle::with('items')->findOrFail($bundleId);
        $parentProduct = $bundle->parentProduct;
        // Get a list of potential products to include in the bundle (excluding the parent product)
        $products = Product::where('id', '!=', $parentProduct->id)->get();

        return view('product::bundles.edit', compact('bundle', 'parentProduct', 'products'));
    }

    /**
     * Update the specified bundle in storage.
     *
     * @param Request $request
     * @param int $bundleId
     * @return RedirectResponse
     */
    public function update(Request $request, int $bundleId): RedirectResponse
    {
        $bundle = ProductBundle::with('items')->findOrFail($bundleId);
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'active_from' => 'nullable|date',
            'active_to'   => 'nullable|date|after_or_equal:active_from',
            'items'       => 'required|array',
            'items.*.id'         => 'sometimes|exists:product_bundle_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.price'      => 'nullable|numeric|min:0',
            'items.*.quantity'   => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            // Update bundle header including active period
            $bundle->update([
                'name'        => $request->input('name'),
                'description' => $request->input('description'),
                'active_from' => $request->input('active_from'),
                'active_to'   => $request->input('active_to'),
            ]);

            // Option 1: Delete all existing items and re-create them.
            $bundle->items()->delete();
            foreach ($request->input('items') as $item) {
                $bundle->items()->create([
                    'product_id' => $item['product_id'],
                    'price'      => $item['price'] ?? null,
                    'quantity'   => $item['quantity'],
                ]);
            }

            DB::commit();
            return redirect()->route('products.bundle.index', $bundle->parent_product_id)
                ->with('success', 'Bundle updated successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update bundle', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to update bundle.');
        }
    }

    /**
     * Remove the specified bundle from storage.
     *
     * @param int $bundleId
     * @return RedirectResponse
     */
    public function destroy(int $bundleId): RedirectResponse
    {
        $bundle = ProductBundle::findOrFail($bundleId);
        $parentProductId = $bundle->parent_product_id;
        try {
            $bundle->delete();
            return redirect()->route('products.show', $parentProductId)
                ->with('success', 'Bundle deleted successfully.');
        } catch (Exception $e) {
            Log::error('Failed to delete bundle', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete bundle.');
        }
    }
}

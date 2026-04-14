<?php

namespace Modules\Product\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
    public function index(int $productId): RedirectResponse
    {
        abort_if(Gate::denies('products.bundle.access'), 403);

        return redirect()->route('products.show', $productId);
    }

    /**
     * Show the form for creating a new bundle for the parent product.
     *
     * @param int $productId
     * @return View
     */
    public function create(int $productId): View
    {
        abort_if(Gate::denies('products.bundle.create'), 403);
        $product = Product::findOrFail($productId);
        // Retrieve a list of products that can be bundled.
        // You might want to exclude the parent product itself.
        $products = Product::where('id', '!=', $productId)->get();
        // 5.2 Pass settingId to view for display context
        $settingId = session('setting_id');

        return view('product::bundles.create', compact('product', 'products', 'settingId'));
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
        abort_if(Gate::denies('products.bundle.create'), 403);
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'active_from' => 'nullable|date',
            'active_to' => 'nullable|date|after_or_equal:active_from',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'name.required' => 'Nama harus diisi.',
            'price.numeric' => 'Harga bundle harus berupa angka.',
            'active_to.after_or_equal' => 'Periode Selesai harus sama atau lebih dari Periode Mulai',
            'items.required' => 'Item harus diisi.',
            'items.*.product_id.required' => 'Produk harus dipilih disetiap item.',
            'items.*.product_id.exists' => 'Produk yang dipilih tidak ada.',
            'items.*.quantity.required' => 'Setiap item harus punya jumlah.',
            'items.*.quantity.integer' => 'Jumlah harus berupa angka.',
        ]);

        DB::beginTransaction();
        try {
            // 5.3 Include setting_id from session('setting_id')
            $bundle = ProductBundle::create([
                'setting_id' => session('setting_id'),
                'parent_product_id' => $productId,
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'price' => $request->input('price'),
                'active_from' => $request->input('active_from'),
                'active_to' => $request->input('active_to'),
            ]);

            // Create each bundle item (without price)
            foreach ($request->input('items') as $item) {
                $bundle->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
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

    public function edit(Product $product, ProductBundle $bundle): View
    {
        abort_if(Gate::denies('products.bundle.edit'), 403);
        // 5.4 Ensure that the bundle actually belongs to the product and the active setting.
        if ($bundle->parent_product_id !== $product->id || $bundle->setting_id != session('setting_id')) {
            abort(404);
        }

        // Retrieve potential products for editing if needed.
        $products = Product::where('id', '!=', $product->id)->get();

        return view('product::bundles.edit', [
            'bundle' => $bundle,
            'parentProduct' => $product,
            'products' => $products,
        ]);
    }

    public function update(Request $request, Product $product, ProductBundle $bundle): RedirectResponse
    {
        abort_if(Gate::denies('products.bundle.edit'), 403);
        // 5.5 Verify bundle's setting_id matches active session
        if ($bundle->setting_id != session('setting_id')) {
            abort(404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'active_from' => 'nullable|date',
            'active_to' => 'nullable|date|after_or_equal:active_from',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'name.required' => 'Nama harus diisi.',
            'price.numeric' => 'Harga bundle harus berupa angka.',
            'active_to.after_or_equal' => 'Periode Selesai harus sama atau lebih dari Periode Mulai',
            'items.required' => 'Item harus diisi.',
            'items.*.product_id.required' => 'Produk harus dipilih disetiap item.',
            'items.*.product_id.exists' => 'Produk yang dipilih tidak ada.',
            'items.*.quantity.required' => 'Setiap item harus punya jumlah.',
            'items.*.quantity.integer' => 'Jumlah harus berupa angka.',
        ]);

        DB::beginTransaction();
        try {
            // Update bundle header
            $bundle->update([
                'name'        => $request->input('name'),
                'description' => $request->input('description'),
                'price'       => $request->input('price'),
                'active_from' => $request->input('active_from'),
                'active_to'   => $request->input('active_to'),
            ]);

            // Reset and re-create bundle items
            $bundle->items()->delete();
            foreach ($request->input('items') as $item) {
                $bundle->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                ]);
            }

            DB::commit();
            return redirect()->route('products.show', $product->id)
                ->with('success', 'Bundle updated successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update bundle', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to update bundle.');
        }
    }

    public function destroy(Product $product, ProductBundle $bundle): RedirectResponse
    {
        abort_if(Gate::denies('products.bundle.delete'), 403);
        // 5.6 Verify bundle's setting_id matches active session
        if ($bundle->parent_product_id !== $product->id || $bundle->setting_id != session('setting_id')) {
            abort(404);
        }

        try {
            $bundle->delete();
            return redirect()->route('products.show', $product->id)
                ->with('success', 'Bundle deleted successfully.');
        } catch (Exception $e) {
            Log::error('Failed to delete bundle', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete bundle.');
        }
    }
}

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
        // 5.2 Pass settingId to view for display context
        $settingId = session('setting_id');

        return view('product::bundles.create', compact('product', 'settingId'));
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
            'bundle_sale_price' => 'required|numeric|min:0',
            'active_from' => 'nullable|date',
            'active_to' => 'nullable|date|after_or_equal:active_from',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.informational_item_price' => 'required|numeric|min:0',
        ], [
            'name.required' => 'Nama harus diisi.',
            'bundle_sale_price.required' => 'Harga Jual Paket harus diisi.',
            'bundle_sale_price.numeric' => 'Harga Jual Paket harus berupa angka.',
            'active_to.after_or_equal' => 'Periode Selesai harus sama atau lebih dari Periode Mulai',
            'items.required' => 'Item harus diisi.',
            'items.*.product_id.required' => 'Produk harus dipilih disetiap item.',
            'items.*.product_id.distinct' => 'Produk komponen tidak boleh duplikat.',
            'items.*.product_id.exists' => 'Produk yang dipilih tidak ada.',
            'items.*.quantity.required' => 'Setiap item harus punya jumlah.',
            'items.*.quantity.integer' => 'Jumlah harus berupa angka.',
            'items.*.informational_item_price.required' => 'Setiap item harus punya Harga Informasi Item.',
            'items.*.informational_item_price.numeric' => 'Harga Informasi Item harus berupa angka.',
        ]);

        $settings = \Modules\Setting\Entities\Setting::all();
        if ($settings->isEmpty()) {
            $settings = collect([
                (object) ['id' => session('setting_id', 1)]
            ]);
        }

        DB::beginTransaction();
        try {
            foreach ($settings as $setting) {
                $bundle = ProductBundle::create([
                    'setting_id' => $setting->id,
                    'parent_product_id' => $productId,
                    'name' => $request->input('name'),
                    'description' => $request->input('description'),
                    'bundle_sale_price' => $request->input('bundle_sale_price'),
                    'active_from' => $request->input('active_from'),
                    'active_to' => $request->input('active_to'),
                    'is_active' => true,
                ]);

                foreach ($request->input('items') as $item) {
                    $bundle->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'informational_item_price' => $item['informational_item_price'],
                    ]);
                }
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
        if ($bundle->parent_product_id !== $product->id || (int) $bundle->setting_id !== (int) session('setting_id')) {
            abort(404);
        }

        return view('product::bundles.edit', [
            'bundle' => $bundle,
            'parentProduct' => $product,
        ]);
    }

    public function update(Request $request, Product $product, ProductBundle $bundle): RedirectResponse
    {
        abort_if(Gate::denies('products.bundle.edit'), 403);
        if ($bundle->parent_product_id !== $product->id || (int) $bundle->setting_id !== (int) session('setting_id')) {
            abort(404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'bundle_sale_price' => 'required|numeric|min:0',
            'active_from' => 'nullable|date',
            'active_to' => 'nullable|date|after_or_equal:active_from',
            'is_active' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.informational_item_price' => 'required|numeric|min:0',
        ], [
            'name.required' => 'Nama harus diisi.',
            'bundle_sale_price.required' => 'Harga Jual Paket harus diisi.',
            'bundle_sale_price.numeric' => 'Harga Jual Paket harus berupa angka.',
            'active_to.after_or_equal' => 'Periode Selesai harus sama atau lebih dari Periode Mulai',
            'items.required' => 'Item harus diisi.',
            'items.*.product_id.required' => 'Produk harus dipilih disetiap item.',
            'items.*.product_id.distinct' => 'Produk komponen tidak boleh duplikat.',
            'items.*.product_id.exists' => 'Produk yang dipilih tidak ada.',
            'items.*.quantity.required' => 'Setiap item harus punya jumlah.',
            'items.*.quantity.integer' => 'Jumlah harus berupa angka.',
            'items.*.informational_item_price.required' => 'Setiap item harus punya Harga Informasi Item.',
            'items.*.informational_item_price.numeric' => 'Harga Informasi Item harus berupa angka.',
        ]);

        DB::beginTransaction();
        try {
            // Update bundle header (scoped strictly to this copy)
            $bundle->update([
                'name'        => $request->input('name'),
                'description' => $request->input('description'),
                'bundle_sale_price' => $request->input('bundle_sale_price'),
                'active_from' => $request->input('active_from'),
                'active_to'   => $request->input('active_to'),
                'is_active'   => $request->boolean('is_active', true),
            ]);

            // Reset and re-create bundle items
            $bundle->items()->delete();
            foreach ($request->input('items') as $item) {
                $bundle->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'informational_item_price' => $item['informational_item_price'],
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
        if ($bundle->parent_product_id !== $product->id || (int) $bundle->setting_id !== (int) session('setting_id')) {
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

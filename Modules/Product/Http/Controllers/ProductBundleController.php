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

use Modules\Product\Support\ProductBundlePriceResolver;

class ProductBundleController extends Controller
{
    public function __construct(
        private readonly ProductBundlePriceResolver $priceResolver = new ProductBundlePriceResolver()
    ) {
    }

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
            'items.*.informational_item_price' => 'nullable|numeric|min:0',
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
            'items.*.informational_item_price.numeric' => 'Harga Informasi Item harus berupa angka.',
        ]);

        $settings = \Modules\Setting\Entities\Setting::all();
        if ($settings->isEmpty()) {
            $settings = collect([
                (object) ['id' => session('setting_id', 1)]
            ]);
        }

        $activeSettingId = session('setting_id') ? (int) session('setting_id') : null;
        $itemsInput = $request->input('items', []);

        // Pre-resolve and validate component price matrix for all settings
        $resolvedPricesBySetting = [];
        foreach ($settings as $setting) {
            $settingId = (int) $setting->id;
            $resolvedPricesBySetting[$settingId] = [];

            foreach ($itemsInput as $item) {
                $productIdKey = (int) $item['product_id'];
                $compPrice = $this->priceResolver->resolveComponentSalePrice(
                    $productIdKey,
                    $settingId,
                    $activeSettingId
                );

                if ($compPrice === null) {
                    return redirect()->back()
                        ->withInput()
                        ->withErrors([
                            'items' => "Harga jual produk komponen #{$productIdKey} tidak ditemukan untuk setting #{$settingId} maupun setting aktif.",
                        ]);
                }

                $resolvedPricesBySetting[$settingId][$productIdKey] = $compPrice;
            }
        }

        $replicaGroupUuid = \Illuminate\Support\Str::uuid()->toString();

        DB::beginTransaction();
        try {
            foreach ($settings as $setting) {
                $settingId = (int) $setting->id;
                $bundle = ProductBundle::create([
                    'setting_id' => $settingId,
                    'parent_product_id' => $productId,
                    'replica_group_uuid' => $replicaGroupUuid,
                    'name' => $request->input('name'),
                    'description' => $request->input('description'),
                    'bundle_sale_price' => $request->input('bundle_sale_price'),
                    'active_from' => $request->input('active_from'),
                    'active_to' => $request->input('active_to'),
                    'is_active' => true,
                ]);

                foreach ($itemsInput as $item) {
                    $productIdKey = (int) $item['product_id'];
                    $resolvedInfoPrice = $resolvedPricesBySetting[$settingId][$productIdKey];

                    $bundle->items()->create([
                        'product_id' => $productIdKey,
                        'quantity' => $item['quantity'],
                        'informational_item_price' => $resolvedInfoPrice,
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
            'apply_price_to_all_businesses' => 'nullable|boolean',
            'active_from' => 'nullable|date',
            'active_to' => 'nullable|date|after_or_equal:active_from',
            'is_active' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.informational_item_price' => 'nullable|numeric|min:0',
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
            'items.*.informational_item_price.numeric' => 'Harga Informasi Item harus berupa angka.',
        ]);

        $activeSettingId = session('setting_id') ? (int) session('setting_id') : null;
        $itemsInput = $request->input('items', []);
        $bundleSettingId = (int) $bundle->setting_id;

        // Pre-resolve and validate prices for this copy
        $resolvedPrices = [];
        foreach ($itemsInput as $item) {
            $productIdKey = (int) $item['product_id'];
            $compPrice = $this->priceResolver->resolveComponentSalePrice(
                $productIdKey,
                $bundleSettingId,
                $activeSettingId
            );

            if ($compPrice === null) {
                return redirect()->back()
                    ->withInput()
                    ->withErrors([
                        'items' => "Harga jual produk komponen #{$productIdKey} tidak ditemukan untuk setting ini.",
                    ]);
            }

            $resolvedPrices[$productIdKey] = $compPrice;
        }

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

            // Reset and re-create bundle items with pre-resolved prices
            $bundle->items()->delete();
            foreach ($itemsInput as $item) {
                $productIdKey = (int) $item['product_id'];
                $resolvedInfoPrice = $resolvedPrices[$productIdKey];

                $bundle->items()->create([
                    'product_id' => $productIdKey,
                    'quantity'   => $item['quantity'],
                    'informational_item_price' => $resolvedInfoPrice,
                ]);
            }

            if ($request->boolean('apply_price_to_all_businesses') && !empty($bundle->replica_group_uuid)) {
                ProductBundle::where('replica_group_uuid', $bundle->replica_group_uuid)
                    ->update([
                        'bundle_sale_price' => $request->input('bundle_sale_price'),
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

    public function destroy(Request $request, Product $product, ProductBundle $bundle): RedirectResponse
    {
        abort_if(Gate::denies('products.bundle.delete'), 403);
        if ($bundle->parent_product_id !== $product->id || (int) $bundle->setting_id !== (int) session('setting_id')) {
            abort(404);
        }

        $request->validate([
            'delete_from_all_businesses' => 'nullable|boolean',
        ]);

        $deleteAll = $request->boolean('delete_from_all_businesses');

        DB::beginTransaction();
        try {
            if ($deleteAll && !empty($bundle->replica_group_uuid)) {
                $targets = ProductBundle::where('replica_group_uuid', $bundle->replica_group_uuid)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($targets as $targetBundle) {
                    $targetBundle->delete();
                }
            } else {
                $bundle->delete();
            }

            DB::commit();
            return redirect()->route('products.show', $product->id)
                ->with('success', 'Bundle deleted successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete bundle', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete bundle.');
        }
    }
}

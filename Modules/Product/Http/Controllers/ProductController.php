<?php

namespace Modules\Product\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Product\DataTables\ProductDataTable;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Http\Requests\StoreProductRequest;
use Modules\Product\Http\Requests\UpdateProductRequest;
use Modules\Setting\Entities\Unit;
use Modules\Upload\Entities\Upload;

class ProductController extends Controller
{

    public function index(ProductDataTable $dataTable) {
        abort_if(Gate::denies('access_products'), 403);

        return $dataTable->render('product::products.index');
    }


    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('create_products'), 403);

        $currentSettingId = session('setting_id');

        // Filter units, brands, and categories by setting_id
        $units = Unit::where('setting_id', $currentSettingId)->get();
        $brands = Brand::where('setting_id', $currentSettingId)->get();
        $categories = Category::where('setting_id', $currentSettingId)->with('parent')->get();

        // Format categories with parent category
        $formattedCategories = $categories->mapWithKeys(function($category) {
            $formattedName = $category->parent ? "{$category->parent->category_name} | $category->category_name" : $category->category_name;
            return [$category->id => $formattedName];
        })->sortBy('name')->toArray();

        return view('product::products.create', compact('units', 'brands', 'formattedCategories'));
    }


    public function store(StoreProductRequest $request): RedirectResponse
    {
        Log::info('Starting product creation.');

        $validatedData = $request->validated();

        Log::info('Validated data.', $validatedData);

        // Set default values for nullable fields
        $fieldsWithDefaults = [
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
        ];

        foreach ($fieldsWithDefaults as $field => $defaultValue) {
            if (empty($validatedData[$field])) {
                $validatedData[$field] = $defaultValue;
            }
        }

        $fieldsConvertedToNulls = ['brand_id', 'category_id', 'base_unit_id'];
        foreach ($fieldsConvertedToNulls as $field) {
            if (empty($validatedData[$field])) {
                $validatedData[$field] = null;
            }
        }

        $validatedData['setting_id'] = session('setting_id');

        // Handle documents separately
        $documents = $validatedData['document'] ?? [];
        unset($validatedData['document']);

        // Handle conversions separately
        $conversions = $validatedData['conversions'] ?? [];
        unset($validatedData['conversions']);

        DB::beginTransaction();

        try {
            $product = Product::create($validatedData);
            Log::info('Product created successfully', ['product_id' => $product->id]);

            // Handle document uploads
            if (!empty($documents)) {
                foreach ($documents as $file) {
                    $tempFile = Upload::where('folder', $file)->first();
                    if ($tempFile) {
                        $product->addMedia(Storage::path('temp/dropzone/' . $file))->toMediaCollection('images');
                        Storage::deleteDirectory('temp/dropzone/' . $file);
                        $tempFile->delete();
                    }
                }
            }

            // Handle unit conversions
            if (!empty($conversions)) {
                foreach ($conversions as $conversion) {
                    $conversion['base_unit_id'] = $validatedData['base_unit_id'];
                    $product->conversions()->create($conversion);
                }
            }

            DB::commit();
            Log::info('Product creation successful, transaction committed.');

            toast('Produk Ditambahkan!', 'success');
            return redirect()->route('products.index');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product creation failed', ['error' => $e->getMessage()]);

            toast('Failed to create product. Please try again.', 'error');
            return redirect()->back()->withInput();
        }
    }


    public function show(Product $product): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('show_products'), 403);

        return view('product::products.show', compact('product'));
    }


    public function edit(Product $product): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('edit_products'), 403);

        return view('product::products.edit', compact('product'));
    }


    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->except('document'));

        if ($request->has('document')) {
            if (count($product->getMedia('images')) > 0) {
                foreach ($product->getMedia('images') as $media) {
                    if (!in_array($media->file_name, $request->input('document', []))) {
                        $media->delete();
                    }
                }
            }

            $media = $product->getMedia('images')->pluck('file_name')->toArray();

            foreach ($request->input('document', []) as $file) {
                if (count($media) === 0 || !in_array($file, $media)) {
                    $product->addMedia(Storage::path('temp/dropzone/' . $file))->toMediaCollection('images');
                }
            }
        }

        toast('Produk Diperbaharui!', 'info');

        return redirect()->route('products.index');
    }


    public function destroy(Product $product): RedirectResponse
    {
        abort_if(Gate::denies('delete_products'), 403);

        $product->delete();

        toast('Produk Dihapus!', 'warning');

        return redirect()->route('products.index');
    }
}

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
use Modules\Product\Entities\Transaction;
use Modules\Product\Http\Requests\StoreProductRequest;
use Modules\Product\Http\Requests\UpdateProductRequest;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Unit;
use Modules\Upload\Entities\Upload;

class ProductController extends Controller
{

    public function index(ProductDataTable $dataTable)
    {
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
        $locations = Location::where('setting_id', $currentSettingId)->get();

        // Format categories with parent category
        $formattedCategories = $categories->mapWithKeys(function ($category) {
            $formattedName = $category->parent ? "{$category->parent->category_name} | $category->category_name" : $category->category_name;
            return [$category->id => $formattedName];
        })->sortBy('name')->toArray();

        return view('product::products.create', compact('units', 'brands', 'formattedCategories', 'locations'));
    }


    public function store(StoreProductRequest $request): RedirectResponse
    {
        Log::info('Starting product creation.');

        $validatedData = $request->validated();

        Log::info('Validated data.', $validatedData);

        // Extract location_id before unsetting it from the validated data
        $locationId = $validatedData['location_id'] ?? null;

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

        // Remove location_id from the validated data to prevent it from being saved to the products table
        unset($validatedData['location_id']);

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

            // Add a transaction if product_quantity is greater than 0
            if ($validatedData['product_quantity'] > 0) {
                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => $validatedData['setting_id'],
                    'type' => 'INIT', // Assuming 'INIT' is used for initial stock setup
                    'quantity' => $validatedData['product_quantity'],
                    'current_quantity' => $validatedData['product_quantity'], // Assuming initial quantity is the current quantity
                    'broken_quantity' => 0, // Assuming no broken quantity initially
                    'location_id' => $locationId, // Use the extracted location_id
                    'user_id' => auth()->id(), // Assuming the user is authenticated
                    'reason' => 'Initial stock setup', // Provide a reason for the transaction
                ]);
                Log::info('Transaction created successfully for the product.', ['product_id' => $product->id]);
            }

            DB::commit();
            Log::info('Product creation successful, transaction committed.');

            toast('Product Created!', 'success');
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

        $baseUnit = $product->baseUnit; // Assuming this relation exists
        $conversions = $product->conversions; // Assuming this relation exists

        $convertedQuantity = null;
        $remainder = null;

        if ($baseUnit && $conversions->isNotEmpty()) {
            $biggestConversion = $conversions->sortByDesc('conversion_factor')->first();
            $convertedQuantity = floor($product->product_quantity / $biggestConversion->conversion_factor);
            $remainder = $product->product_quantity % $biggestConversion->conversion_factor;

            $displayQuantity = "{$convertedQuantity} {$biggestConversion->unit->short_name} {$remainder} {$baseUnit->short_name}";
        } else {
            $displayQuantity = $product->product_quantity . ' ' . ($product->product_unit ?? '');
        }

        $transactions = $product->transactions()->orderBy('created_at', 'desc')->get(); // Fetch transactions

        return view('product::products.show', compact('product', 'displayQuantity', 'transactions'));
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

        toast('Product Updated!', 'info');

        return redirect()->route('products.index');
    }


    public function destroy(Product $product): RedirectResponse
    {
        abort_if(Gate::denies('delete_products'), 403);

        $product->delete();

        toast('Product Deleted!', 'warning');

        return redirect()->route('products.index');
    }
}

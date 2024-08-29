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
                    $product->addMedia(Storage::path('temp/dropzone/' . $file))->toMediaCollection('images');
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

        $baseUnit = $product->baseUnit; // Assuming this relation exists
        $conversions = $product->conversions; // Assuming this relation exists

        if ($baseUnit && $conversions->isNotEmpty()) {
            $biggestConversion = $conversions->sortByDesc('conversion_factor')->first();
            $convertedQuantity = floor($product->product_quantity / $biggestConversion->conversion_factor);
            $remainder = $product->product_quantity % $biggestConversion->conversion_factor;

            $displayQuantity = "{$convertedQuantity} {$biggestConversion->unit->short_name} {$remainder} {$baseUnit->short_name}";
        } else {
            $displayQuantity = $product->product_quantity . ' ' . ($product->product_unit ?? '');
        }

        // Eager load the location relationship on transactions
        $transactions = Transaction::where('product_id', $product->id)
            ->with('location') // Eager load the location
            ->orderBy('created_at', 'desc')
            ->get();// Fetch transactions

        return view('product::products.show', compact('product', 'displayQuantity', 'transactions'));
    }


    public function edit(Product $product): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('edit_products'), 403);

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

        return view('product::products.edit', compact('product', 'units', 'brands', 'formattedCategories', 'locations'));
    }


    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $validatedData = $request->validated();

        // Update the product fields
        if ($request->filled('product_name')) {
            $product->product_name = $validatedData['product_name'];
        }

        if ($request->filled('product_code')) {
            $product->product_code = $validatedData['product_code'];
        }

        if ($request->filled('category_id')) {
            $product->category_id = $validatedData['category_id'];
        }

        if ($request->filled('brand_id')) {
            $product->brand_id = $validatedData['brand_id'];
        }

        if ($request->filled('product_stock_alert')) {
            $product->product_stock_alert = $validatedData['product_stock_alert'];
        }

        if ($request->filled('product_order_tax')) {
            $product->product_order_tax = $validatedData['product_order_tax'];
        }

        if ($request->filled('product_tax_type')) {
            $product->product_tax_type = $validatedData['product_tax_type'];
        }

        if ($request->filled('product_cost')) {
            $product->product_cost = $validatedData['product_cost'];
        }

        if ($request->filled('profit_percentage')) {
            $product->profit_percentage = $validatedData['profit_percentage'];
        }

        if ($request->filled('product_price')) {
            $product->product_price = $validatedData['product_price'];
        }

        if ($request->filled('primary_unit_barcode')) {
            $product->primary_unit_barcode = $validatedData['primary_unit_barcode'];
        }

        if ($request->filled('base_unit_id')) {
            $product->base_unit_id = $validatedData['base_unit_id'];
        }

        if ($request->filled('product_note')) {
            $product->product_note = $validatedData['product_note'];
        }

        // Check if the product has been modified and save changes
        if ($product->isDirty()) {
            $product->save();
            toast('Produk Diperbaharui!', 'info');
        }

        // Handle document uploads if new files are provided
        if ($request->hasFile('document')) {
            foreach ($request->file('document') as $file) {
                $product->addMedia($file)->toMediaCollection('images');
            }
        }

        // Handle unit conversions
        if (isset($validatedData['conversions']) && is_array($validatedData['conversions'])) {
            // Remove existing conversions and replace with the new ones
            $product->conversions()->delete();
            foreach ($validatedData['conversions'] as $conversion) {
                $conversion['base_unit_id'] = $validatedData['base_unit_id'];
                $product->conversions()->create($conversion);
            }
        }

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

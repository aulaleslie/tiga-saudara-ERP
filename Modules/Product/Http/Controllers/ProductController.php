<?php

namespace Modules\Product\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Product\DataTables\ProductDataTable;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;
use Modules\Product\Http\Requests\InitializeProductStockRequest;
use Modules\Product\Http\Requests\InputSerialNumbersRequest;
use Modules\Product\Http\Requests\StoreProductInfoRequest;
use Modules\Product\Http\Requests\UpdateProductRequest;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use League\Csv\Reader;
use League\Csv\Statement;

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
        $taxes = Tax::where('setting_id', $currentSettingId)->get();

        // Format categories with parent category
        $formattedCategories = $categories->mapWithKeys(function ($category) {
            $formattedName = $category->parent ? "{$category->parent->category_name} | $category->category_name" : $category->category_name;
            return [$category->id => $formattedName];
        })->sortBy('name')->toArray();

        return view('product::products.create', compact('units', 'brands', 'formattedCategories', 'locations', 'taxes'));
    }


    /**
     * Common logic to handle product creation.
     *
     * @param array $validatedData
     * @return Product
     * @throws Exception
     */
    private function handleProductCreation(array $validatedData): Product
    {
        Log::info('Starting product creation.');

        // Set default values for nullable fields
        $fieldsWithDefaults = [
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
            'purchase_price' => 0,
            'purchase_tax' => 0,
            'sale_price' => 0,
            'sale_tax' => 0,
            'product_price' => 0
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

        // Handle documents and conversions separately
        $documents = $validatedData['document'] ?? [];
        $conversions = $validatedData['conversions'] ?? [];
        unset($validatedData['document'], $validatedData['conversions'], $validatedData['location_id']);

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

            DB::commit();
            Log::info('Product creation successful, transaction committed.');

            return $product; // Return the created product
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create product. Please try again.', ['error' => $e->getMessage()]);

            throw new \Exception('Product creation failed');
        }
    }

    /**
     * Store the product and redirect to product index.
     *
     * @param StoreProductInfoRequest $request
     * @return RedirectResponse
     * @throws Exception
     */
    public function store(StoreProductInfoRequest $request): RedirectResponse
    {
        $validatedData = $request->validated();

        // Use the handleProductCreation method to create the product and get the product object
        $this->handleProductCreation($validatedData);

        return redirect()->route('products.index');
    }

    /**
     * Store the product and redirect to initialize product stock.
     *
     * @param StoreProductInfoRequest $request
     * @return RedirectResponse
     * @throws Exception
     */
    public function storeProductAndRedirectToInitializeProductStock(StoreProductInfoRequest $request): RedirectResponse
    {
        $validatedData = $request->validated();

        $product = $this->handleProductCreation($validatedData); // Retrieve the created product

        // Pass the created product's ID when redirecting
        return redirect()->route('products.initializeProductStock', ['product_id' => $product->id]);
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

        // Ensure brand_id and category_id are either NULL or valid
        $validatedData['brand_id'] = $validatedData['brand_id'] ?: null;
        $validatedData['category_id'] = $validatedData['category_id'] ?: null;

        // Handle location_id, conversions, and documents separately
        $locationId = $validatedData['location_id'] ?? null;
        $conversions = $validatedData['conversions'] ?? [];
        $documents = $validatedData['document'] ?? [];

        // Unset fields that should not be saved directly to the products table
        unset($validatedData['location_id'], $validatedData['conversions'], $validatedData['document']);

        DB::beginTransaction();

        try {
            // Update the product fields
            $product->update($validatedData);

            // Handle document uploads if new files are provided
            if ($request->hasFile('document')) {
                foreach ($request->file('document') as $file) {
                    $product->addMedia($file)->toMediaCollection('images');
                }
            }

            // Handle unit conversions
            if (!empty($conversions)) {
                $product->conversions()->delete(); // Remove existing conversions
                foreach ($conversions as $conversion) {
                    $conversion['base_unit_id'] = $product->base_unit_id;
                    $product->conversions()->create($conversion);
                }
            }

            DB::commit();

            toast('Produk Diperbaharui!', 'info');
            return redirect()->route('products.index');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Product update failed', ['error' => $e->getMessage()]);

            toast('Gagal Perbaharui Produk. Silahkan Coba Lagi !.', 'error');
            return redirect()->back()->withInput();
        }
    }


    public function destroy(Product $product): RedirectResponse
    {
        abort_if(Gate::denies('delete_products'), 403);

        $product->delete();

        toast('Produk Dihapus!', 'warning');

        return redirect()->route('products.index');
    }

    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        // Get the current setting ID from the session
        $currentSettingId = session('setting_id');

        // Query the locations for the current setting ID
        $locations = Location::where('setting_id', $currentSettingId)->get();

        // Return the upload view with the locations data
        return view('product::products.upload', compact('locations'));
    }

    public function upload(Request $request): RedirectResponse
    {
        // Validate the request
        $request->validate([
            'file' => 'required|mimes:csv,txt',
            'location_id' => 'required|exists:locations,id',
        ]);

        // Handle the uploaded file
        $file = $request->file('file');
        $csv = Reader::createFromPath($file->getPathname(), 'r');
        $csv->setHeaderOffset(0); // The CSV has headers
        $records = (new Statement())->process($csv);

        // Get the selected location ID
        $locationId = $request->input('location_id');

        // Initialize counters for logging
        $rowsProcessed = 0;
        $rowsRead = 0;

        // Process each row
        DB::beginTransaction();
        try {
            foreach ($records as $record) {
                $rowsRead++;
                // Normalize and validate each value
                $name = trim($record['Name*']);
                $productCode = trim($record['ProductCode']);
                $stock = (int)trim($record['Stock']);
                $unitName = trim($record['*Unit']);
                $buyPrice = $this->normalizePrice($record['BuyPrice']);
                $buyTaxName = trim($record['DefaultBuyTaxName']);
                $sellPrice = $this->normalizePrice($record['SellPrice']);
                $sellTaxName = trim($record['DefaultSellTaxName']);
                $minimumStock = (int)trim($record['MinimumStock']);

                // Validate required fields
                if (!$name || !$unitName || !$buyPrice || !$sellPrice) {
                    Log::error("Row $rowsRead: Required fields are missing");
                    continue; // Skip this row
                }

                // Check for duplicate product name
                $existingProductWithName = Product::where('product_name', $name)->first();
                if ($existingProductWithName) {
                    Log::error("Row $rowsRead: A product with the name '{$name}' already exists.");
                    continue; // Skip this row
                }

                // Find or create the unit
                $unit = Unit::firstOrCreate(['name' => $unitName]);

                // Set tax values based on the tax name
                $purchaseTax = $buyTaxName === 'PPN 11%' ? 1 : 0;
                $saleTax = $sellTaxName === 'PPN 11%' ? 1 : 0;

                // Determine if the product is sold or purchased
                $isPurchased = $buyPrice > 0;
                $isSold = $sellPrice > 0;

                // Create or update the product using Eloquent
                $product = Product::updateOrCreate(
                    ['product_code' => $productCode],
                    [
                        'product_name' => $name,
                        'product_quantity' => $stock,
                        'base_unit_id' => $unit->id,
                        'purchase_price' => $buyPrice,
                        'purchase_tax' => $purchaseTax,
                        'sale_price' => $sellPrice,
                        'sale_tax' => $saleTax,
                        'stock_managed' => true,
                        'product_stock_alert' => $minimumStock,
                        'is_purchased' => $isPurchased,
                        'is_sold' => $isSold,
                        'setting_id' => session('setting_id'),

                        // set to default
                        'product_cost' => 0,
                        'product_order_tax' => 0,
                        'product_tax_type' => 0,
                        'profit_percentage' => 0,
                        'product_price' => 0
                    ]
                );

                // If stock is more than 0, record a transaction
                if ($stock > 0) {
                    Transaction::create([
                        'product_id' => $product->id,
                        'setting_id' => session('setting_id'),
                        'type' => 'INIT', // Assuming 'INIT' is used for initial stock setup
                        'quantity' => $stock,
                        'current_quantity' => $stock,
                        'broken_quantity' => 0, // Assuming no broken quantity initially
                        'location_id' => $locationId,
                        'user_id' => auth()->id(), // Assuming the user is authenticated
                        'reason' => 'Initial stock setup from upload', // Provide a reason for the transaction
                    ]);
                }

                // Log each successfully processed row
                $rowsProcessed++;
                Log::info("Row $rowsRead: Product '{$name}' processed successfully.");
            }

            DB::commit();
            Log::info("Upload completed: $rowsProcessed rows processed out of $rowsRead rows read.");
            toast('Upload Berhasil!', 'success');
            return redirect()->route('products.index')->with('success', 'Products uploaded successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Upload failed: " . $e->getMessage());
            toast('Failed to upload product. Please try again.', 'error');
            return redirect()->back()->withErrors(['error' => 'Failed to upload products: ' . $e->getMessage()]);
        }
    }

    private function normalizePrice($price): int
    {
        // Remove any commas or currency symbols and convert to float
        return (int)str_replace([','], '', trim($price));
    }

    public function initializeProductStock(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $product = Product::findOrFail($request->product_id);

        // Fetch the locations from the database
        $locations = Location::where('setting_id', session('setting_id'))->get();

        // Get prices from product
        $last_purchase_price = $product->purchase_price;
        $average_purchase_price = $product->purchase_price;
        $sale_price = $product->sale_price;

        // Pass the product, prices, and locations to the view
        return view('product::products.initialize-product-stock', compact('product', 'last_purchase_price', 'average_purchase_price', 'sale_price', 'locations'));
    }

    public function storeInitialProductStock(InitializeProductStockRequest $request): RedirectResponse
    {
        return $this->handleStockInitialization($request, 'products.index');
    }

    public function storeInitialProductStockAndRedirectToInputSerialNumbers(InitializeProductStockRequest $request): RedirectResponse
    {
        return $this->handleStockInitialization($request, 'products.inputSerialNumbers', [
            'product_id' => $request->route('product_id'),
            'location_id' => $request->input('location_id'),
        ]);
    }

    private function handleStockInitialization(InitializeProductStockRequest $request, string $redirectRoute, array $routeParams = []): RedirectResponse
    {
        $validatedData = $request->validated();

        DB::beginTransaction();

        try {
            // Assuming the product ID is passed in the request or retrieved from session
            $product = Product::findOrFail($request->route('product_id'));

            // Create a transaction for product stock initialization
            Transaction::create([
                'product_id' => $product->id,
                'setting_id' => session('setting_id'),
                'type' => 'INIT', // Assuming 'INIT' is used for initial stock setup
                'quantity' => $validatedData['quantity'],
                'previous_quantity' => 0,
                'previous_quantity_at_location' => 0,
                'after_quantity' => $validatedData['quantity'],
                'after_quantity_at_location' => 0,
                'quantity_tax' => $validatedData['quantity_tax'],
                'quantity_non_tax' => $validatedData['quantity_non_tax'],
                'current_quantity' => $validatedData['quantity'],
                'broken_quantity' => $validatedData['broken_quantity_tax'] + $validatedData['broken_quantity_non_tax'],
                'broken_quantity_tax' => $validatedData['broken_quantity_tax'],
                'broken_quantity_non_tax' => $validatedData['broken_quantity_non_tax'],
                'location_id' => $validatedData['location_id'],
                'user_id' => auth()->id(), // Assuming the user is authenticated
                'reason' => 'Initial stock setup',
            ]);

            DB::commit();

            toast('Stock initialized successfully!', 'success');

            // Redirect to the appropriate route (index or serial number input)
            return redirect()->route($redirectRoute, $routeParams);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to initialize stock.', ['error' => $e->getMessage()]);

            toast('Failed to initialize stock. Please try again.', 'error');
            return redirect()->back()->withInput();
        }
    }

    public function inputSerialNumbers(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $product = Product::findOrFail($request->route('product_id'));
        $location = Location::findOrFail($request->route('location_id'));
        $taxes = Tax::all(); // Retrieve all available taxes (assuming they are global, adjust if needed)
        $transaction = Transaction::where('location_id', $request->route('location_id'))
            ->where('product_id', $request->route('product_id'))
            ->firstOrFail();

        return view('product::products.input-serial-number', compact(
            'product', 'location', 'taxes', 'transaction'
        ));
    }

    public function storeSerialNumbers(InputSerialNumbersRequest $request): RedirectResponse
    {
        DB::beginTransaction();

        try {
            $serialNumbers = $request->input('serial_numbers');
            $taxIds = $request->input('tax_ids');

            foreach ($serialNumbers as $index => $serialNumberData) {
                ProductSerialNumber::create([
                    'product_id' => $request->route('product_id'),
                    'location_id' => $request->route('location_id'),
                    'serial_number' => $serialNumberData,
                    'tax_id' => $taxIds[$index] ?? null, // Tax ID is optional
                ]);
            }

            DB::commit();

            toast('Serial numbers saved successfully!', 'success');
            return redirect()->route('products.index');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to save serial numbers.', ['error' => $e->getMessage()]);

            toast('Failed to save serial numbers. Please try again.', 'error');
            return redirect()->back()->withInput();
        }
    }
}

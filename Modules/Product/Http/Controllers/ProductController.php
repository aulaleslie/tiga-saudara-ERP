<?php

namespace Modules\Product\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Csv\InvalidArgument;
use League\Csv\SyntaxError;
use League\Csv\UnavailableStream;
use Modules\Product\DataTables\ProductDataTable;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
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
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;

class ProductController extends Controller
{

    public function index(ProductDataTable $dataTable)
    {
        abort_if(Gate::denies('products.access'), 403);

        return $dataTable->render('product::products.index');
    }


    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);

        // Filter units, brands, and categories by setting_id
        $units = Unit::all();
        $brands = Brand::all();
        $categories = Category::with('parent')->get();
        $locations = Location::all();
        $taxes = Tax::all();

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
     * - Legacy price columns on `products` are kept at defaults (0 / null).
     * - The submitted prices are written to `product_prices` for ALL settings.
     *
     * @param  array  $validatedData
     * @return Product
     * @throws Throwable
     */
    private function handleProductCreation(array $validatedData): Product
    {
        // Capture the incoming price values before we zero-out legacy columns
        $incomingPrices = [
            'sale_price'             => data_get($validatedData, 'sale_price', 0),
            'tier_1_price'           => data_get($validatedData, 'tier_1_price', 0),
            'tier_2_price'           => data_get($validatedData, 'tier_2_price', 0),
            // For purchase snapshots we mirror your previous behavior (using purchase_price),
            // but we DO NOT store them on products—only on product_prices.
            'last_purchase_price'    => data_get($validatedData, 'purchase_price', 0),
            'average_purchase_price' => data_get($validatedData, 'purchase_price', 0),
            // Accept either *_id or legacy *_tax keys
            'purchase_tax_id'        => data_get($validatedData, 'purchase_tax_id', data_get($validatedData, 'purchase_tax')),
            'sale_tax_id'            => data_get($validatedData, 'sale_tax_id', data_get($validatedData, 'sale_tax')),
        ];

        // ========= keep product legacy columns at defaults =========
        // (We do NOT persist incoming prices to products table.)
        $fieldsWithDefaults = [
            'product_quantity'        => 0,
            'product_cost'            => 0,
            'product_stock_alert'     => 0,
            'product_order_tax'       => 0,
            'product_tax_type'        => 0,
            'profit_percentage'       => 0,
            'purchase_price'          => 0,
            'purchase_tax_id'         => null,   // or 'purchase_tax' => 0 if your column is boolean/legacy
            'sale_price'              => 0,
            'sale_tax_id'             => null,   // or 'sale_tax' => 0 if your column is boolean/legacy
            'product_price'           => 0,
            'last_purchase_price'     => 0,
            'average_purchase_price'  => 0,
        ];
        foreach ($fieldsWithDefaults as $field => $defaultValue) {
            $validatedData[$field] = $defaultValue;
        }

        // Normalize nullable FKs on products
        foreach (['brand_id', 'category_id', 'base_unit_id'] as $field) {
            if (empty($validatedData[$field])) {
                $validatedData[$field] = null;
            }
        }

        // Tie product to the current session setting (kept as before)
        $validatedData['setting_id'] = session('setting_id');

        // Handle documents/conversions separately (unchanged)
        $documents   = $validatedData['document']   ?? [];
        $conversions = $validatedData['conversions'] ?? [];
        unset($validatedData['document'], $validatedData['conversions'], $validatedData['location_id']);

        DB::beginTransaction();

        try {
            // 1) Create product with legacy price columns left at defaults
            $product = Product::create($validatedData);

            // 2) Mirror the submitted prices across ALL settings into product_prices
            $settingIds = Setting::query()->pluck('id'); // filter if you have an "active" flag
            foreach ($settingIds as $sid) {
                ProductPrice::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'setting_id' => (int) $sid,
                    ],
                    [
                        'sale_price'             => $incomingPrices['sale_price'] ?: 0,
                        'tier_1_price'           => $incomingPrices['tier_1_price'] ?: 0,
                        'tier_2_price'           => $incomingPrices['tier_2_price'] ?: 0,
                        'last_purchase_price'    => $incomingPrices['last_purchase_price'] ?: 0,
                        'average_purchase_price' => $incomingPrices['average_purchase_price'] ?: 0,
                        'purchase_tax_id'        => $incomingPrices['purchase_tax_id'] ?: null,
                        'sale_tax_id'            => $incomingPrices['sale_tax_id'] ?: null,
                    ]
                );
            }

            // 3) Documents
            if (!empty($documents)) {
                foreach ($documents as $file) {
                    $product->addMedia(Storage::path('temp/dropzone/' . $file))->toMediaCollection('images');
                }
            }

            // 4) Unit conversions
            if (!empty($conversions)) {
                foreach ($conversions as $conversion) {
                    $conversion['base_unit_id'] = $validatedData['base_unit_id'];
                    $product->conversions()->create($conversion);
                }
            }

            DB::commit();
            Log::info('Product created with prices replicated to all settings.', [
                'product_id' => $product->id,
                'settings'   => $settingIds->values(),
            ]);

            return $product;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Gagal membuat Produk (replicate prices).', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Store the product and redirect to product index.
     *
     * @param StoreProductInfoRequest $request
     * @return RedirectResponse
     * @throws Exception|Throwable
     */
    public function store(StoreProductInfoRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);
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
     * @throws Exception|Throwable
     */
    public function storeProductAndRedirectToInitializeProductStock(StoreProductInfoRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);
        $validatedData = $request->validated();

        $product = $this->handleProductCreation($validatedData); // Retrieve the created product

        // Pass the created product's ID when redirecting
        return redirect()->route('products.initializeProductStock', ['product_id' => $product->id]);
    }


    public function show(Product $product): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.show'), 403);

        $baseUnit = $product->baseUnit;
        $conversions = $product->conversions()->with('unit')->get();

        if ($baseUnit && $conversions->isNotEmpty()) {
            $biggestConversion = $conversions->sortByDesc('conversion_factor')->first();
            $convertedQuantity = floor($product->product_quantity / $biggestConversion->conversion_factor);
            $remainder = $product->product_quantity % $biggestConversion->conversion_factor;
            $displayQuantity = "$convertedQuantity {$biggestConversion->unit->short_name} $remainder $baseUnit->short_name";
        } else {
            $displayQuantity = $product->product_quantity . ' ' . ($product->product_unit ?? '');
        }

        $transactions = Transaction::where('product_id', $product->id)
            ->with('location')
            ->orderBy('created_at', 'desc')
            ->get();

        $productStocks = ProductStock::where('product_id', $product->id)
            ->with('location')
            ->get();

        $serialNumbers = ProductSerialNumber::where('product_id', $product->id)
            ->whereNull('dispatch_detail_id')
            ->with('location')
            ->with('tax')
            ->get();

        // Eager load bundles with their items and the bundled products
        $bundles = $product->bundles()->with('items.product')->get();

        return view('product::products.show', compact(
            'product',
            'displayQuantity',
            'transactions',
            'productStocks',
            'serialNumbers',
            'bundles'
        ));
    }


    public function edit(Product $product)
    {
        abort_if(Gate::denies('products.edit'), 403);

        $units = Unit::all();
        $brands = Brand::all();
        $categories = Category::with('parent')->get();
        $locations = Location::all();
        $taxes = Tax::all();

        $formattedCategories = $categories->mapWithKeys(function ($category) {
            $formattedName = $category->parent ? "{$category->parent->category_name} | $category->category_name" : $category->category_name;
            return [$category->id => $formattedName];
        })->sortBy('name')->toArray();

        // ✅ Send existing media to view
        $existingMedia = $product->getMedia('images')->map(function ($m) {
            return [
                'id'   => $m->id,
                'name' => $m->file_name,          // we use file_name everywhere
                'url'  => $m->getUrl(),           // or getUrl('thumb') if you have a conversion
                'size' => $m->size,
            ];
        })->values();

        return view('product::products.edit', compact(
            'product', 'units', 'taxes', 'brands', 'formattedCategories', 'locations', 'existingMedia'
        ));
    }


    /**
     * @throws Throwable
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        abort_if(Gate::denies('products.edit'), 403);

        Log::info('Update product request', [
            'request' => $request,
            'product' => $product,
        ]);
        $validatedData = $request->validated();

        // Ensure brand_id and category_id are either NULL or valid
        $validatedData['brand_id'] = $validatedData['brand_id'] ?: null;
        $validatedData['category_id'] = $validatedData['category_id'] ?: null;

        $isPurchased = (bool)($validatedData['is_purchased'] ?? false);
        $isSold      = (bool)($validatedData['is_sold'] ?? false);

        // When NOT purchased: wipe purchase-related fields
        if (!$isPurchased) {
            $validatedData['purchase_price']  = 0;
            // Use the actual column name you store: purchase_tax_id OR purchase_tax
            $validatedData['purchase_tax_id'] = null; // or unset() if column doesn’t exist
        }

        // When NOT sold: wipe sale-related fields (incl. tiers)
        if (!$isSold) {
            $validatedData['sale_price']      = 0;
            $validatedData['sale_tax_id']     = null; // or correct column
            $validatedData['tier_1_price']    = 0;
            $validatedData['tier_2_price']    = 0;
        }

        // Handle location_id, conversions, and documents separately
        $conversions = $validatedData['conversions'] ?? [];

        // Unset fields that should not be saved directly to the products table
        unset($validatedData['location_id'], $validatedData['conversions'], $validatedData['document']);

        DB::beginTransaction();

        try {
            // Update the product fields
            $product->update($validatedData);

            // Handle document uploads if new files are provided
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
            Log::error('Pembaruan Produk Gagal', ['error' => $e->getMessage()]);

            toast('Gagal Perbaharui Produk. Silahkan Coba Lagi !.', 'error');
            return redirect()->back()->withInput();
        }
    }


    public function destroy(Product $product): RedirectResponse
    {
        abort_if(Gate::denies('products.delete'), 403);

        $product->delete();

        toast('Produk Dihapus!', 'warning');

        return redirect()->route('products.index');
    }

    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);

        // Query the locations for the current setting ID
        $locations = Location::all();

        // Return the upload view with the locations data
        return view('product::products.upload', compact('locations'));
    }

    /**
     * @throws UnavailableStream
     * @throws InvalidArgument
     * @throws Throwable
     * @throws SyntaxError
     * @throws \League\Csv\Exception
     */
    public function upload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);
        // Validate the request
        $request->validate([
            'file' => 'required|mimes:csv,txt',
            'location_id' => 'required|exists:locations,id',
        ]);

        // Handle the uploaded file
        $file = $request->file('file');
        $csv = Reader::createFromPath($file->getPathname());
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
                    Log::error("Row $rowsRead: A product with the name '$name' already exists.");
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
                Log::info("Row $rowsRead: Product '$name' processed successfully.");
            }

            DB::commit();
            Log::info("Upload completed: $rowsProcessed rows processed out of $rowsRead rows read.");
            toast('Upload Berhasil!', 'success');
            return redirect()->route('products.index')->with('Sukses', 'Produk berhasil diunggah.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Upload failed: " . $e->getMessage());
            toast('Gagal mengunggah produk. Silakan coba lagi.', 'error');
            return redirect()->back()->withErrors(['error' => 'Gagal mengunggah produk : ' . $e->getMessage()]);
        }
    }

    private function normalizePrice($price): int
    {
        // Remove any commas or currency symbols and convert to float
        return (int)str_replace([','], '', trim($price));
    }

    public function initializeProductStock(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);
        $product = Product::findOrFail($request->product_id);

        // Fetch the locations from the database
        $locations = Location::with('setting')->get();

        // Get prices from product
        $last_purchase_price = $product->purchase_price;
        $average_purchase_price = $product->purchase_price;
        $sale_price = $product->sale_price;

        // Pass the product, prices, and locations to the view
        return view('product::products.initialize-product-stock', compact('product', 'last_purchase_price', 'average_purchase_price', 'sale_price', 'locations'));
    }

    /**
     * @throws Throwable
     */
    public function storeInitialProductStock(InitializeProductStockRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);
        return $this->handleStockInitialization($request, 'products.index');
    }

    /**
     * @throws Throwable
     */
    public function storeInitialProductStockAndRedirectToInputSerialNumbers(InitializeProductStockRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);
        return $this->handleStockInitialization($request, 'products.inputSerialNumbers', [
            'product_id' => $request->route('product_id'),
            'location_id' => $request->input('location_id'),
        ]);
    }

    /**
     * @throws Throwable
     */
    private function handleStockInitialization(InitializeProductStockRequest $request, string $redirectRoute, array $routeParams = []): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);
        $validatedData = $request->validated();

        DB::beginTransaction();

        try {
            // Assuming the product ID is passed in the request or retrieved from session
            $product = Product::findOrFail($request->route('product_id'));

            $product->update([
                'product_quantity' => $validatedData['quantity'],
                'broken_quantity' => $validatedData['broken_quantity_tax'] + $validatedData['broken_quantity_non_tax'],
            ]);

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

            ProductStock::create([
                'product_id' => $product->id,  // Assuming $product is available
                'location_id' => $validatedData['location_id'],  // Assuming location_id comes from the request
                'quantity' => $validatedData['quantity'],  // Quantity
                'quantity_non_tax' => $validatedData['quantity_non_tax'],  // Quantity without tax
                'quantity_tax' => $validatedData['quantity_tax'],  // Quantity with tax
                'broken_quantity_non_tax' => $validatedData['broken_quantity_non_tax'],  // Broken quantity without tax
                'broken_quantity_tax' => $validatedData['broken_quantity_tax'],  // Broken quantity with tax
                'sale_price' => $product->sale_price,  // Sale price
                'broken_quantity' => 0,
            ]);

            DB::commit();

            toast('Stok berhasil diinisialisasi!', 'success');

            return redirect()->route($redirectRoute, $routeParams);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Gagal menginisialisasi stok.', ['error' => $e->getMessage()]);

            toast('Gagal menginisialisasi stok. Silakan coba lagi.', 'error');
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

    /**
     * @throws Throwable
     */
    public function storeSerialNumbers(InputSerialNumbersRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);
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

            toast('Nomor seri berhasil disimpan!', 'success');
            return redirect()->route('products.index');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Gagal menyimpan nomor seri.', ['error' => $e->getMessage()]);

            toast('Failed to save serial numbers. Please try again.', 'error');
            return redirect()->back()->withInput();
        }
    }

    public function search(Request $request): JsonResponse
    {
        abort_if(Gate::denies('products.access'), 403);
        $search = $request->input('q');
        $products = Product::where('product_name', 'LIKE', "%$search%")
            ->select('id', 'product_name as text')
            ->limit(10)
            ->get();
        return response()->json($products);
    }

    public function destroyMedia(Product $product, Media $media): Response
    {
        abort_if(Gate::denies('products.edit'), 403);

        // Safety: ensure media belongs to this product
        if ($media->model_id !== $product->id || $media->model_type !== Product::class) {
            abort(404);
        }

        $media->delete();
        return response()->noContent();
    }

    public function downloadCsvTemplate(): StreamedResponse
    {
        abort_if(Gate::denies('products.create'), 403);

        $filename = 'template_upload_produk.csv';
        $maxConversions = 5;

        // Header berbahasa Indonesia (semua by NAMA, tidak ada kolom ID)
        $headers = [
            // Identitas produk
            'Nama Produk',          // wajib
            'Kode Produk',          // wajib & unik
            'Barcode',              // opsional
            'Nama Kategori',        // opsional (akan dibuat jika belum ada)
            'Nama Merek',           // opsional (akan dibuat jika belum ada)

            // Stok & unit
            'Kelola Stok',          // 0|1
            'Wajib Nomor Seri',     // 0|1
            'Nama Unit Dasar',      // wajib jika Kelola Stok = 1 (akan dibuat jika belum ada)
            'Stok',                 // integer
            'Stok Minimum',         // integer

            // Pembelian (pajak opsional)
            'Dibeli',               // 0|1
            'Harga Beli',           // wajib jika Dibeli = 1
            'Nama Pajak Beli',      // opsional (cari by name; biarkan kosong jika tidak ada)

            // Penjualan (pajak opsional)
            'Dijual',               // 0|1
            'Harga Jual',           // wajib jika Dijual = 1
            'Harga Tier 1',         // wajib jika Dijual = 1
            'Harga Tier 2',         // wajib jika Dijual = 1
            'Nama Pajak Jual',      // opsional (cari by name; biarkan kosong jika tidak ada)
        ];

        // Kolom konversi (maks 5 set) — semua by NAMA, tanpa ID
        for ($i = 1; $i <= $maxConversions; $i++) {
            $headers[] = "Konv{$i}_NamaUnit";   // prioritas nama (akan dibuat jika belum ada)
            $headers[] = "Konv{$i}_Faktor";     // wajib jika unit diisi
            $headers[] = "Konv{$i}_Barcode";    // opsional
            $headers[] = "Konv{$i}_Harga";      // wajib jika unit diisi
        }

        // Contoh 1 baris (boleh dihapus oleh user)
        $example = [
            // Identitas produk
            'Produk Contoh A',    // Nama Produk
            'SKU-001',            // Kode Produk
            '8991234567890',      // Barcode
            'Sembako',            // Nama Kategori
            'Merek Umum',         // Nama Merek

            // Stok & unit
            1,                    // Kelola Stok
            0,                    // Wajib Nomor Seri
            'Pcs',                // Nama Unit Dasar
            100,                  // Stok
            10,                   // Stok Minimum

            // Pembelian
            1,                    // Dibeli
            15000,                // Harga Beli
            'PPN 11%',            // Nama Pajak Beli (opsional)

            // Penjualan
            1,                    // Dijual
            20000,                // Harga Jual
            19500,                // Harga Tier 1
            19000,                // Harga Tier 2
            'PPN 11%',            // Nama Pajak Jual (opsional)
        ];

        // Contoh konversi
        for ($i = 1; $i <= $maxConversions; $i++) {
            if ($i === 1) {
                // 1 Box = 12 Pcs
                $example[] = 'Box';     // Konv1_NamaUnit
                $example[] = 12;        // Konv1_Faktor
                $example[] = '8991234567891';
                $example[] = 220000;    // Konv1_Harga
            } elseif ($i === 2) {
                // 1 Pack = 6 Pcs
                $example[] = 'Pack';    // Konv2_NamaUnit
                $example[] = 6;         // Konv2_Faktor
                $example[] = '';        // barcode kosong
                $example[] = 110000;    // Konv2_Harga
            } else {
                // sisanya kosong
                $example[] = ''; $example[] = ''; $example[] = ''; $example[] = '';
            }
        }

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            // Jika perlu kompatibilitas Excel Windows: tulis BOM UTF-8
            // fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $headers);
            fputcsv($out, $example);
            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-store, no-cache',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}

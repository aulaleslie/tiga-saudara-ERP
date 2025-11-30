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
use Illuminate\Support\Str;
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
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Setting;

class ProductController extends Controller
{

    public function index(Request $request, ProductDataTable $dataTable)
    {
        abort_if(Gate::denies('products.access'), 403);

        if ($request->ajax()) {
            return $dataTable->ajax();
        }

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

        $idempotencyToken = (string) Str::uuid();

        return view('product::products.create', compact('units', 'brands', 'formattedCategories', 'locations', 'taxes', 'idempotencyToken'));
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
        // Resolve the active setting BEFORE opening the transaction so we can
        // persist prices only for that context.
        $settingId = $this->getActiveSettingId();

        // Capture all setting IDs up-front (needed for multi-setting price rows).
        $settingIds = Setting::query()->pluck('id');
        if ($settingIds->isEmpty()) {
            $settingIds = collect([$settingId]);
        }

        $isPurchased = (bool) data_get($validatedData, 'is_purchased', false);
        $isSold      = (bool) data_get($validatedData, 'is_sold', false);

        // Capture the incoming price values before we zero-out legacy columns
        $incomingPrices = [
            'sale_price'             => $isSold ? data_get($validatedData, 'sale_price', 0) : 0,
            'tier_1_price'           => $isSold ? data_get($validatedData, 'tier_1_price', 0) : 0,
            'tier_2_price'           => $isSold ? data_get($validatedData, 'tier_2_price', 0) : 0,
            // For purchase snapshots we mirror your previous behavior (using purchase_price),
            // but we DO NOT store them on products—only on product_prices.
            'last_purchase_price'    => $isPurchased ? data_get($validatedData, 'purchase_price', 0) : 0,
            'average_purchase_price' => $isPurchased ? data_get($validatedData, 'purchase_price', 0) : 0,
            // Accept either *_id or legacy *_tax keys
            'purchase_tax_id'        => $isPurchased
                ? data_get($validatedData, 'purchase_tax_id', data_get($validatedData, 'purchase_tax'))
                : null,
            'sale_tax_id'            => $isSold
                ? data_get($validatedData, 'sale_tax_id', data_get($validatedData, 'sale_tax'))
                : null,
        ];

        // ========= keep product legacy columns at defaults =========
        // (We do NOT persist incoming prices to product table.)
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
        $validatedData['setting_id'] = $settingId;

        // Handle documents/conversions separately (unchanged)
        $documents   = $validatedData['document']   ?? [];
        $conversions = $validatedData['conversions'] ?? [];
        unset($validatedData['document'], $validatedData['conversions'], $validatedData['location_id']);

        DB::beginTransaction();

        try {
            // 1) Create product with legacy price columns left at defaults
            $product = Product::create($validatedData);

            // 2) Mirror the submitted prices for every setting
            ProductPrice::seedForSettings(
                $product->id,
                [
                    'sale_price'             => $incomingPrices['sale_price'] ?: 0,
                    'tier_1_price'           => $incomingPrices['tier_1_price'] ?: 0,
                    'tier_2_price'           => $incomingPrices['tier_2_price'] ?: 0,
                    'last_purchase_price'    => $incomingPrices['last_purchase_price'] ?: 0,
                    'average_purchase_price' => $incomingPrices['average_purchase_price'] ?: 0,
                    'purchase_tax_id'        => $incomingPrices['purchase_tax_id'] ?: null,
                    'sale_tax_id'            => $incomingPrices['sale_tax_id'] ?: null,
                ],
                $settingIds
            );

            // 3) Documents
            if (!empty($documents)) {
                foreach ($documents as $file) {
                    $product->addMedia(Storage::path('temp/dropzone/' . $file))->toMediaCollection('images');
                }
            }

            // 4) Unit conversions
            if (!empty($conversions)) {
                foreach ($conversions as $conversion) {
                    if (empty($conversion['unit_id'])) {
                        continue;
                    }

                    $price = (float) ($conversion['price'] ?? 0);

                    $conversionPayload = [
                        'unit_id'           => $conversion['unit_id'] ?? null,
                        'base_unit_id'      => $validatedData['base_unit_id'],
                        'conversion_factor' => $conversion['conversion_factor'] ?? 0,
                        'barcode'           => $conversion['barcode'] ?? null,
                    ];

                    $createdConversion = $product->conversions()->create($conversionPayload);

                    ProductUnitConversionPrice::seedForSettings(
                        $createdConversion->id,
                        $price,
                        $settingIds
                    );
                }
            }

            DB::commit();
            Log::info('Product created with prices stored for all settings.', [
                'product_id'  => $product->id,
                'setting_ids' => $settingIds->all(),
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

        // --- per-setting price lookup ---
        $settingId = $this->getActiveSettingId();
        $pp = ProductPrice::select(
            'sale_price','tier_1_price','tier_2_price',
            'last_purchase_price','average_purchase_price',
            'purchase_tax_id','sale_tax_id'
        )
            ->where('product_id', $product->id)
            ->where('setting_id', $settingId)
            ->first();

        // Prefer product_prices exclusively (default to zero/blank when row missing)
        $price = (object) [
            'sale_price'             => data_get($pp, 'sale_price', 0),
            'tier_1_price'           => data_get($pp, 'tier_1_price', 0),
            'tier_2_price'           => data_get($pp, 'tier_2_price', 0),
            'last_purchase_price'    => data_get($pp, 'last_purchase_price', 0),
            'average_purchase_price' => data_get($pp, 'average_purchase_price', 0),
            'purchase_tax_id'        => data_get($pp, 'purchase_tax_id'),
            'sale_tax_id'            => data_get($pp, 'sale_tax_id'),
        ];

        // --- quantity display (unchanged) ---
        $product->load(['conversions.unit', 'conversions.prices']);
        $baseUnit    = $product->baseUnit;
        $conversions = $product->conversions;

        if ($baseUnit && $conversions->isNotEmpty()) {
            $biggestConversion = $conversions->sortByDesc('conversion_factor')->first();
            $convertedQuantity = floor($product->product_quantity / $biggestConversion->conversion_factor);
            $remainder         = $product->product_quantity % $biggestConversion->conversion_factor;
            $displayQuantity   = "$convertedQuantity {$biggestConversion->unit->short_name} $remainder $baseUnit->short_name";
        } else {
            $displayQuantity = $product->product_quantity . ' ' . ($product->product_unit ?? '');
        }

        // ✅ ONLY locations that belong to the current setting
        $transactions = Transaction::where('product_id', $product->id)
            ->whereHas('location', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId);
            })
            ->with(['location' => function ($q) {
                $q->select('id', 'name', 'setting_id');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        $productStocks = ProductStock::where('product_id', $product->id)
            ->whereHas('location', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId);
            })
            ->with(['location' => function ($q) {
                $q->select('id', 'name', 'setting_id');
            }])
            ->get();

        // (Leave serial numbers as-is unless you also want to scope them by setting via location)
        $serialNumbers = ProductSerialNumber::where('product_id', $product->id)
            ->whereNull('dispatch_detail_id')
            ->with('location')
            ->with('tax')
            ->get();

        $bundles = $product->bundles()->with('items.product')->get();

        return view('product::products.show', compact(
            'product',
            'displayQuantity',
            'transactions',
            'productStocks',
            'serialNumbers',
            'bundles',
            'price',
            'settingId'
        ));
    }

    private function getActiveSettingId(): int
    {
        $user = auth()->user();

        return (int) (
            session('setting_id')
            ?? optional($user?->settings()->select('settings.id')->first())->id
            ?? Setting::query()->min('id')
        );
    }


    public function edit(Product $product)
    {
        abort_if(Gate::denies('products.edit'), 403);

        $idempotencyToken = (string) Str::uuid();

        $units      = Unit::all();
        $brands     = Brand::all();
        $categories = Category::with('parent')->get();
        $locations  = Location::all();
        $taxes      = Tax::all();

        $formattedCategories = $categories->mapWithKeys(function ($category) {
            $formattedName = $category->parent
                ? "{$category->parent->category_name} | $category->category_name"
                : $category->category_name;
            return [$category->id => $formattedName];
        })->sortBy('name')->toArray();

        // ✅ Per-setting prices for the current setting
        $settingId = $this->getActiveSettingId();
        $pp = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $settingId)
            ->first();

        // Always prefer the per-setting price row. If it is missing, default to zero/blank values.
        $price = (object) [
            // For editing “Harga Beli” we’ll show last_purchase_price only
            'purchase_price'  => data_get($pp, 'last_purchase_price', 0),
            'sale_price'      => data_get($pp, 'sale_price', 0),
            'tier_1_price'    => data_get($pp, 'tier_1_price', 0),
            'tier_2_price'    => data_get($pp, 'tier_2_price', 0),
            'purchase_tax_id' => data_get($pp, 'purchase_tax_id'),
            'sale_tax_id'     => data_get($pp, 'sale_tax_id'),
        ];

        $product->load(['conversions.prices', 'conversions.unit']);

        $conversionFormData = $product->conversions->map(function ($conversion) use ($settingId) {
            $payload = $conversion->toArray();
            $payload['price'] = $conversion->priceValueForSetting($settingId);

            return $payload;
        })->toArray();

        // existing media for the dropzone
        $existingMedia = $product->getMedia('images')->map(function ($m) {
            return [
                'id'   => $m->id,
                'name' => $m->file_name,
                'url'  => $m->getUrl(),
                'size' => $m->size,
            ];
        })->values();

        return view('product::products.edit', compact(
            'product', 'units', 'taxes', 'brands', 'formattedCategories',
            'locations', 'existingMedia', 'price', 'settingId', 'conversionFormData', 'idempotencyToken'
        ));
    }


    /**
     * @throws Throwable
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        abort_if(Gate::denies('products.edit'), 403);

        Log::info('Update product request', [
            'request' => $request->all(),
            'product' => $product->id,
        ]);

        $validatedData = $request->validated();

        // Normalize nullable FKs on products
        $validatedData['brand_id']    = $validatedData['brand_id']    ?: null;
        $validatedData['category_id'] = $validatedData['category_id'] ?: null;

        $isPurchased = (bool)($validatedData['is_purchased'] ?? false);
        $isSold      = (bool)($validatedData['is_sold'] ?? false);

        // Respect the toggles (these affect *per-setting* prices below)
        if (!$isPurchased) {
            $validatedData['purchase_price']  = 0;
            $validatedData['purchase_tax_id'] = $validatedData['purchase_tax_id'] ?? null; // leave taxes as-is if you want global; or null if desired
        }
        if (!$isSold) {
            $validatedData['sale_price']   = 0;
            $validatedData['tier_1_price'] = 0;
            $validatedData['tier_2_price'] = 0;
            $validatedData['sale_tax_id']  = $validatedData['sale_tax_id'] ?? null; // same note as above
        }

        // Pull price inputs (to write into product_prices), then remove them from the product update
        $pricePayload = [
            // purchase_price is the source for both snapshots
            'purchase_price' => (float) ($validatedData['purchase_price'] ?? 0),
            'sale_price'     => (float) ($validatedData['sale_price']     ?? 0),
            'tier_1_price'   => (float) ($validatedData['tier_1_price']   ?? 0),
            'tier_2_price'   => (float) ($validatedData['tier_2_price']   ?? 0),
            // if you still store per-setting taxes, uncomment the lines in the upsert below
            'purchase_tax_id'=> $validatedData['purchase_tax_id'] ?? null,
            'sale_tax_id'    => $validatedData['sale_tax_id']     ?? null,
        ];
        unset(
            $validatedData['purchase_price'],
            $validatedData['sale_price'],
            $validatedData['tier_1_price'],
            $validatedData['tier_2_price'],
            $validatedData['purchase_tax_id'],
            $validatedData['sale_tax_id']
        );

        // Reset legacy price columns on `products` so we no longer persist stale values there.
        foreach ([
            'purchase_price'         => 0,
            'sale_price'             => 0,
            'tier_1_price'           => 0,
            'tier_2_price'           => 0,
            'last_purchase_price'    => 0,
            'average_purchase_price' => 0,
            'purchase_tax_id'        => null,
            'sale_tax_id'            => null,
        ] as $column => $default) {
            $validatedData[$column] = $default;
        }

        // Handle location_id, conversions, and documents separately
        $conversions = $validatedData['conversions'] ?? [];
        unset($validatedData['location_id'], $validatedData['conversions'], $validatedData['document']);

        DB::beginTransaction();

        try {
            // 1) Update non-price product fields on `products`
            $product->update($validatedData);

            // 2) Upsert prices only for the current setting on `product_prices`
            $settingId = $this->getActiveSettingId();
            $allSettingIds = Setting::query()->pluck('id');
            if ($allSettingIds->isEmpty()) {
                $allSettingIds = collect([$settingId]);
            }

            ProductPrice::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'setting_id' => $settingId,
                ],
                [
                    // purchase snapshots come from purchase_price
                    'last_purchase_price'    => $isPurchased ? $pricePayload['purchase_price'] : 0,
                    'average_purchase_price' => $isPurchased ? $pricePayload['purchase_price'] : 0,

                    // selling prices
                    'sale_price'   => $isSold ? $pricePayload['sale_price']   : 0,
                    'tier_1_price' => $isSold ? $pricePayload['tier_1_price'] : 0,
                    'tier_2_price' => $isSold ? $pricePayload['tier_2_price'] : 0,

                    // ❗ If you still keep taxes per-setting, uncomment:
                     'purchase_tax_id' => $pricePayload['purchase_tax_id'],
                     'sale_tax_id'     => $pricePayload['sale_tax_id'],
                ]
            );

            // 3) Documents (unchanged)
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

            if (!empty($conversions)) {
                $existingConversions = $product->conversions()->with('prices')->get()->keyBy('id');
                $processedIds = [];

                foreach ($conversions as $conversion) {
                    $unitId = $conversion['unit_id'] ?? null;
                    if (!$unitId) {
                        continue;
                    }

                    $price = (float) ($conversion['price'] ?? 0);
                    $payload = [
                        'unit_id'           => $unitId,
                        'base_unit_id'      => $product->base_unit_id,
                        'conversion_factor' => $conversion['conversion_factor'] ?? 0,
                        'barcode'           => $conversion['barcode'] ?? null,
                    ];

                    if (!empty($conversion['id']) && $existingConversions->has((int) $conversion['id'])) {
                        $model = $existingConversions[(int) $conversion['id']];
                        $model->update($payload);

                        ProductUnitConversionPrice::upsertFor([
                            'product_unit_conversion_id' => $model->id,
                            'setting_id'                 => $settingId,
                            'price'                      => $price,
                        ]);
                    } else {
                        $model = $product->conversions()->create($payload);

                        ProductUnitConversionPrice::seedForSettings(
                            $model->id,
                            $price,
                            $allSettingIds
                        );
                    }

                    $processedIds[] = $model->id;
                }

                $idsToDelete = $existingConversions->keys()->diff($processedIds);
                if ($idsToDelete->isNotEmpty()) {
                    $product->conversions()->whereIn('id', $idsToDelete->all())->delete();
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

    public function initializeProductStock(Request $request)
    {
        abort_if(Gate::denies('products.create'), 403);

        $product   = Product::findOrFail($request->product_id);
        $settingId = $this->getActiveSettingId(); // already defined in your controller

        // per-setting prices (fallback to legacy columns if missing)
        $pp = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $settingId)
            ->first();

        $last_purchase_price    = data_get($pp, 'last_purchase_price',    $product->last_purchase_price ?? $product->purchase_price);
        $average_purchase_price = data_get($pp, 'average_purchase_price', $product->average_purchase_price ?? $product->purchase_price);
        $sale_price             = data_get($pp, 'sale_price',             $product->sale_price);

        // locations with their company (⚠️ no `name` column on settings)
        $locations = Location::with(['setting:id,company_name'])->get();

        // build "Location — Company" options for the <x-select>
        $locationOptions = $locations->mapWithKeys(function ($loc) {
            $company = optional($loc->setting)->company_name ?? '—';
            return [$loc->id => "{$loc->name} — {$company}"];
        });

        return view('product::products.initialize-product-stock', compact(
            'product',
            'last_purchase_price',
            'average_purchase_price',
            'sale_price',
            'locations',
            'locationOptions',   // <-- pass to blade
            'settingId'
        ));
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

        $data   = $request->validated();
        $locId  = (int) $data['location_id'];

        DB::beginTransaction();
        try {
            // Lock to keep totals consistent
            $product = Product::lockForUpdate()->findOrFail($request->route('product_id'));

            // Parts
            $qtyNonTax = (int) $data['quantity_non_tax'];
            $qtyTax    = (int) $data['quantity_tax'];
            $bqNonTax  = (int) $data['broken_quantity_non_tax'];
            $bqTax     = (int) $data['broken_quantity_tax'];

            $total         = $qtyNonTax + $qtyTax + $bqNonTax + $bqTax;     // equals $data['quantity'] (validator ensures this)
            $brokenTotal   = $bqNonTax + $bqTax;

            $prevProduct   = (int) $product->product_quantity;
            $prevAtLoc     = (int) (ProductStock::where('product_id', $product->id)
                ->where('location_id', $locId)->value('quantity') ?? 0);

            // Update product aggregate counters
            $product->product_quantity = $total;
            $product->broken_quantity  = $brokenTotal; // products.broken_quantity exists with default 0. :contentReference[oaicite:1]{index=1}
            $product->save();

            // Upsert product stock at location (avoid mass-assignment; set fields explicitly)
            $stock = ProductStock::firstOrNew([
                'product_id'  => $product->id,
                'location_id' => $locId,
            ]);
            $stock->quantity                 = $total;
            $stock->quantity_non_tax         = $qtyNonTax;
            $stock->quantity_tax             = $qtyTax;
            $stock->broken_quantity_non_tax  = $bqNonTax;
            $stock->broken_quantity_tax      = $bqTax;
            $stock->broken_quantity          = $brokenTotal; // REQUIRED by schema. :contentReference[oaicite:2]{index=2}
            $stock->save();

            $afterProduct = $product->product_quantity; // $total
            $afterAtLoc   = $stock->quantity;           // $total

            // Record transaction (columns exist in schema) :contentReference[oaicite:3]{index=3}
            Transaction::create([
                'product_id'                 => $product->id,
                'setting_id'                 => $this->getActiveSettingId(),
                'type'                       => 'INIT',
                'reason'                     => 'Initial stock setup',
                'user_id'                    => auth()->id(),
                'location_id'                => $locId,

                'quantity'                   => $total,        // involved quantity
                'current_quantity'           => $afterProduct, // product qty after txn
                'broken_quantity'            => $brokenTotal,
                'quantity_non_tax'           => $qtyNonTax,
                'quantity_tax'               => $qtyTax,
                'broken_quantity_non_tax'    => $bqNonTax,
                'broken_quantity_tax'        => $bqTax,

                'previous_quantity'          => $prevProduct,
                'after_quantity'             => $afterProduct,
                'previous_quantity_at_location' => $prevAtLoc,
                'after_quantity_at_location' => $afterAtLoc,
            ]);

            DB::commit();
            toast('Stok berhasil diinisialisasi!', 'success');
            return redirect()->route($redirectRoute, $routeParams);

        } catch (\Exception $e) {
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

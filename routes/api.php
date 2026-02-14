<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/login', [LoginController::class, 'apiLogin']);
Route::middleware('auth:sanctum')->post('/logout', [LoginController::class, 'apiLogout']);

// Supplier search API
Route::middleware('web')->get('/suppliers/search', function (Request $request) {
    $query = $request->get('query', '');
    $limit = $request->get('limit', 10);

    if (strlen($query) < 2) {
        return response()->json([]);
    }

    $suppliers = \Modules\People\Entities\Supplier::where('supplier_name', 'like', '%' . $query . '%')
        ->orWhere('contact_name', 'like', '%' . $query . '%')
        ->limit($limit)
        ->get(['id', 'supplier_name', 'contact_name', 'payment_term_id']);

    // Format the suppliers with combined name
    $formattedSuppliers = $suppliers->map(function ($supplier) {
        return [
            'id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'contact_name' => $supplier->contact_name,
            'display_name' => $supplier->contact_name ? $supplier->contact_name . ' - ' . $supplier->supplier_name : $supplier->supplier_name,
            'payment_term_id' => $supplier->payment_term_id,
        ];
    });

    return response()->json($formattedSuppliers);
});

// Product search API
Route::middleware('web')->get('/products/search', function (Request $request) {
    $search = $request->get('query', '');
    $limit = $request->get('limit', 10);
    $settingId = session('setting_id');

    if (strlen($search) < 2) {
        return response()->json([]);
    }

    $productQuery = \Modules\Product\Entities\Product::query()
        ->from('products as p')
        ->where('p.stock_managed', true)
        ->where(function ($q) use ($search) {
            $q->where('p.product_name', 'like', '%' . $search . '%')
                ->orWhere('p.product_code', 'like', '%' . $search . '%');
        });

    $priceSelect = [
        DB::raw('COALESCE(p.last_purchase_price, p.purchase_price) as last_purchase_price'),
        DB::raw('p.average_purchase_price as average_purchase_price'),
        DB::raw('p.purchase_tax_id as purchase_tax_id'),
    ];

    if ($settingId) {
        $productQuery->leftJoin('product_prices as pp', function ($join) use ($settingId) {
            $join->on('pp.product_id', '=', 'p.id');
            $join->where('pp.setting_id', '=', $settingId);
        });

        $priceSelect = [
            DB::raw('COALESCE(pp.last_purchase_price, p.last_purchase_price, p.purchase_price) as last_purchase_price'),
            DB::raw('COALESCE(pp.average_purchase_price, p.average_purchase_price) as average_purchase_price'),
            DB::raw('COALESCE(pp.purchase_tax_id, p.purchase_tax_id) as purchase_tax_id'),
        ];
    }

    $products = $productQuery
        ->limit($limit)
        ->get(array_merge([
            'p.id',
            'p.product_name',
            'p.product_code',
            'p.product_quantity',
            'p.product_unit',
        ], $priceSelect));

    $productIds = $products->pluck('id')->all();
    $conversions = \Modules\Product\Entities\ProductUnitConversion::with(['unit:id,name', 'baseUnit:id,name'])
        ->whereIn('product_id', $productIds)
        ->orderByDesc('conversion_factor')
        ->get()
        ->groupBy('product_id');

    $formattedProducts = $products->map(function ($product) use ($conversions) {
        $productConversions = $conversions[$product->id] ?? collect();
        $conversionPayload = $productConversions->map(function ($conv) {
            return [
                'factor' => (float) $conv->conversion_factor,
                'unit_name' => optional($conv->unit)->name,
                'base_unit_name' => optional($conv->baseUnit)->name,
                'base_unit_id' => $conv->base_unit_id,
            ];
        })->values();

        $baseUnitName = $conversionPayload->first()['base_unit_name'] ?? null;

        return [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_quantity' => $product->product_quantity ?? 0,
            'product_unit' => $product->product_unit ?? 'pc',
            'display_name' => $product->product_name . ' | ' . $product->product_code,
            'last_purchase_price' => (float) ($product->last_purchase_price ?? 0),
            'average_purchase_price' => (float) ($product->average_purchase_price ?? 0),
            'purchase_tax_id' => $product->purchase_tax_id,
            'conversions' => $conversionPayload,
            'base_unit_name' => $baseUnitName,
        ];
    });

    return response()->json($formattedProducts);
});

// Payment terms API
Route::middleware('web')->get('/payment-terms', function (Request $request) {
    $paymentTerms = \Modules\Purchase\Entities\PaymentTerm::all(['id', 'name', 'longevity']);
    return response()->json($paymentTerms);
});

// Get single payment term by ID
Route::middleware('web')->get('/payment-terms/{id}', function (Request $request, $id) {
    $paymentTerm = \Modules\Purchase\Entities\PaymentTerm::find($id, ['id', 'name', 'longevity']);
    if (!$paymentTerm) {
        return response()->json(['error' => 'Payment term not found'], 404);
    }
    return response()->json($paymentTerm);
});

// Taxes API
Route::middleware('web')->get('/taxes', function (Request $request) {
    $taxes = \Modules\Setting\Entities\Tax::all(['id', 'name', 'value', 'is_default']);
    return response()->json($taxes);
});

// Create supplier API
Route::middleware('web')->post('/suppliers', function (Request $request) {
    $request->validate([
        'supplier_name' => 'required|string|max:255',
        'contact_name' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:20',
        'address' => 'nullable|string',
        'payment_term_id' => 'nullable|exists:payment_terms,id',
    ]);

    $setting_id = session('setting_id');
    if (!$setting_id) {
        return response()->json(['error' => 'Setting ID not found'], 400);
    }

    // Generate unique identifiers if email/phone are not provided
    $uniqueId = uniqid();
    $email = $request->email ?: "noemail-{$uniqueId}@placeholder.local";
    $phone = $request->phone ?: "nophone-{$uniqueId}";

    $supplier = \Modules\People\Entities\Supplier::create([
        'supplier_name' => $request->supplier_name,
        'contact_name' => $request->contact_name,
        'supplier_email' => $email,
        'supplier_phone' => $phone,
        'address' => $request->address,
        'city' => '',
        'country' => '',
        'setting_id' => $setting_id,
        'payment_term_id' => $request->payment_term_id ?: null,
    ]);

    return response()->json([
        'id' => $supplier->id,
        'supplier_name' => $supplier->supplier_name,
        'contact_name' => $supplier->contact_name,
        'display_name' => $supplier->contact_name ? $supplier->contact_name . ' - ' . $supplier->supplier_name : $supplier->supplier_name,
        'payment_term_id' => $supplier->payment_term_id,
    ]);
});

// Create product API
Route::middleware('web')->post('/products', function (Request $request) {
    $request->validate([
        'product_name' => 'required|string|max:255',
        'product_code' => 'nullable|string|max:255|unique:products',
        'category_id' => 'nullable|exists:categories,id',
        'brand_id' => 'nullable|exists:brands,id',
        'unit_id' => 'required|exists:units,id',
        'description' => 'nullable|string',
        'is_sold' => 'boolean',
        'is_purchased' => 'boolean',
    ]);

    $product = \Modules\Product\Entities\Product::create([
        'product_name' => $request->product_name,
        'product_code' => $request->product_code ?: 'AUTO-' . strtoupper(uniqid()),
        'category_id' => $request->category_id,
        'brand_id' => $request->brand_id,
        'unit_id' => $request->unit_id,
        'description' => $request->description,
        'is_sold' => $request->is_sold ?? false,
        'is_purchased' => $request->is_purchased ?? true,
        'stock_managed' => true,
    ]);

    return response()->json([
        'id' => $product->id,
        'product_name' => $product->product_name,
        'product_code' => $product->product_code,
        'display_name' => $product->product_name . ' | ' . $product->product_code,
    ]);
});

// Create payment term API
Route::middleware('web')->post('/payment-terms', function (Request $request) {
    $request->validate([
        'name' => 'required|string|max:255|unique:payment_terms,name',
        'longevity' => 'required|integer|min:0|max:365'
    ]);

    $paymentTerm = \Modules\Purchase\Entities\PaymentTerm::create([
        'name' => $request->name,
        'longevity' => $request->longevity
    ]);

    return response()->json([
        'id' => $paymentTerm->id,
        'name' => $paymentTerm->name,
        'display_name' => $paymentTerm->name,
    ]);
});

// Create tax API
Route::middleware('web')->post('/taxes', function (Request $request) {
    $request->validate([
        'name' => 'required|string|max:255|unique:taxes,name',
        'value' => 'required|numeric|min:0|max:100',
        'is_default' => 'nullable|boolean',
    ]);

    $tax = \Modules\Setting\Entities\Tax::create([
        'name' => $request->name,
        'value' => $request->value,
        'is_default' => $request->boolean('is_default'),
    ]);

    return response()->json([
        'id' => $tax->id,
        'name' => $tax->name,
        'value' => $tax->value,
        'is_default' => (bool) $tax->is_default,
        'display_name' => $tax->name . ' (' . $tax->value . '%)',
    ]);
});

// Categories API
Route::middleware('web')->get('/categories/search', function (Request $request) {
    $query = $request->get('query', '');
    $limit = $request->get('limit', 10);

    if (strlen($query) < 2) {
        return response()->json([]);
    }

    $categories = \Modules\Product\Entities\Category::where('category_name', 'like', '%' . $query . '%')
        ->limit($limit)
        ->get(['id', 'category_name']);

    $formattedCategories = $categories->map(function ($category) {
        return [
            'id' => $category->id,
            'category_name' => $category->category_name,
            'display_name' => $category->category_name,
        ];
    });

    return response()->json($formattedCategories);
});

Route::middleware('web')->post('/categories', function (Request $request) {
    $settingId = session('setting_id');

    $request->validate([
        'category_name'  => 'required|string|max:255|unique:categories,category_name,NULL,id,setting_id,' . $settingId,
        'category_code'  => 'nullable|string|max:255|unique:categories,category_code',
        'parent_id'      => 'nullable|exists:categories,id',
    ]);

    $code = $request->category_code;
    if (!$code) {
        $categoryMaxId = \Modules\Product\Entities\Category::max('id') + 1;
        $code = 'CA_' . str_pad($categoryMaxId, 2, '0', STR_PAD_LEFT);
    }

    $parent = null;
    if ($request->parent_id) {
        $parent = \Modules\Product\Entities\Category::find($request->parent_id);
    }

    $category = \Modules\Product\Entities\Category::create([
        'category_code' => $code,
        'category_name' => $request->category_name,
        'parent_id'     => $request->parent_id,
        'created_by'    => auth()->id(),
        'setting_id'    => $settingId,
    ]);

    $displayName = $parent
        ? $parent->category_name . ' | ' . $category->category_name
        : $category->category_name;

    return response()->json([
        'id'           => $category->id,
        'category_name'=> $category->category_name,
        'category_code'=> $category->category_code,
        'parent_id'    => $category->parent_id,
        'display_name' => $displayName,
        'name'         => $displayName,
    ]);
});

// Brands API
Route::middleware('web')->get('/brands/search', function (Request $request) {
    $query = $request->get('query', '');
    $limit = $request->get('limit', 10);

    if (strlen($query) < 2) {
        return response()->json([]);
    }

    $brands = \Modules\Product\Entities\Brand::where('name', 'like', '%' . $query . '%')
        ->limit($limit)
        ->get(['id', 'name']);

    $formattedBrands = $brands->map(function ($brand) {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'display_name' => $brand->name,
        ];
    });

    return response()->json($formattedBrands);
});

Route::middleware('web')->post('/brands', function (Request $request) {
    $request->validate([
        'name' => 'required|string|max:255|unique:brands,name',
    ]);

    $brand = \Modules\Product\Entities\Brand::create([
        'name' => $request->name,
    ]);

    return response()->json([
        'id' => $brand->id,
        'name' => $brand->name,
        'display_name' => $brand->name,
    ]);
});

// Units API
Route::middleware('web')->get('/units/search', function (Request $request) {
    $query = $request->get('query', '');
    $limit = $request->get('limit', 10);

    if (strlen($query) < 2) {
        return response()->json([]);
    }

    $units = \Modules\Setting\Entities\Unit::where('name', 'like', '%' . $query . '%')
        ->limit($limit)
        ->get(['id', 'name']);

    $formattedUnits = $units->map(function ($unit) {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'display_name' => $unit->name,
        ];
    });

    return response()->json($formattedUnits);
});

Route::middleware('web')->post('/units', function (Request $request) {
    $request->validate([
        'name' => 'required|string|max:255|unique:units,name',
    ]);

    $unit = \Modules\Setting\Entities\Unit::create([
        'name' => $request->name,
    ]);

    return response()->json([
        'id' => $unit->id,
        'name' => $unit->name,
        'display_name' => $unit->name,
    ]);
});

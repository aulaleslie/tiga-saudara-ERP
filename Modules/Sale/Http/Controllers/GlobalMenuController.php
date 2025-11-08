<?php

namespace Modules\Sale\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Http\Requests\GlobalMenuSearchRequest;
use Modules\Sale\Http\Resources\SaleSearchResource;
use Modules\Sale\Http\Resources\SerialNumberResource;
use Modules\Sale\Services\SerialNumberSearchService;
use Modules\Sale\Services\SalesOrderFormatter;
use Modules\Product\Entities\ProductSerialNumber;
use App\Models\GlobalMenuSearch;

class GlobalMenuController extends Controller
{
    protected SerialNumberSearchService $searchService;
    protected SalesOrderFormatter $formatter;

    public function __construct(
        SerialNumberSearchService $searchService,
        SalesOrderFormatter $formatter
    ) {
        $this->searchService = $searchService;
        $this->formatter = $formatter;
    }

    /**
     * Search for sales orders by various criteria
     *
     * @param GlobalMenuSearchRequest $request
     * @return JsonResponse
     */
    public function search(GlobalMenuSearchRequest $request): JsonResponse
    {
        abort_if(Gate::denies('sales.search.global'), 403);

        try {
            $settingId = session('setting_id');
            
            if (!$settingId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant context not set. Please select a business unit.'
                ], 400);
            }

            $startTime = microtime(true);

            // Prepare filters from request
            $filters = $request->validated();
            $perPage = $filters['per_page'] ?? 20;
            $page = $filters['page'] ?? 1;

            // Execute search
            $results = $this->searchService->buildQuery($filters)
                ->where('sales.setting_id', $settingId)
                ->paginate($perPage, ['*'], 'page', $page);

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log search for audit trail
            GlobalMenuSearch::create([
                'user_id' => auth()->id(),
                'setting_id' => $settingId,
                'search_query' => json_encode([
                    'serial_number' => $filters['serial_number'] ?? null,
                    'sale_reference' => $filters['sale_reference'] ?? null,
                    'customer_id' => $filters['customer_id'] ?? null,
                    'customer_name' => $filters['customer_name'] ?? null,
                ]),
                'filters_applied' => json_encode($filters),
                'results_count' => $results->total(),
                'response_time_ms' => $responseTime,
            ]);

            return response()->json([
                'success' => true,
                'data' => SaleSearchResource::collection($results->items()),
                'pagination' => [
                    'total' => $results->total(),
                    'per_page' => $results->perPage(),
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'from' => $results->firstItem(),
                    'to' => $results->lastItem(),
                ],
                'response_time_ms' => $responseTime,
            ]);

        } catch (\Exception $e) {
            Log::error('Global Menu Search Error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get sales order by reference number
     *
     * @param string $reference
     * @return JsonResponse
     */
    public function searchByReference(string $reference): JsonResponse
    {
        abort_if(Gate::denies('sales.search.global'), 403);

        try {
            $settingId = session('setting_id');

            if (!$settingId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant context not set.'
                ], 400);
            }

            $sale = Sale::query()
                ->where('reference', $reference)
                ->where('setting_id', $settingId)
                ->with(['customer', 'details.product', 'details.serialNumbers', 'user'])
                ->first();

            if (!$sale) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sales order not found.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new SaleSearchResource($sale),
            ]);

        } catch (\Exception $e) {
            Log::error('Get Sale by Reference Error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sales order.'
            ], 500);
        }
    }

    /**
     * Get serial number details
     *
     * @param int $serialId
     * @return JsonResponse
     */
    public function getSerialDetails(int $serialId): JsonResponse
    {
        abort_if(Gate::denies('sales.search.global'), 403);

        try {
            $settingId = session('setting_id');

            if (!$settingId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant context not set.'
                ], 400);
            }

            $serial = ProductSerialNumber::query()
                ->where('id', $serialId)
                ->with(['product', 'location'])
                ->first();

            if (!$serial) {
                return response()->json([
                    'success' => false,
                    'message' => 'Serial number not found.'
                ], 404);
            }

            // Verify tenant access via location
            if ($serial->location->setting_id !== $settingId) {
                abort(403, 'Unauthorized access to this serial number.');
            }

            // Get associated sales orders
            $sales = Sale::query()
                ->join('sale_details', 'sales.id', '=', 'sale_details.sale_id')
                ->where('sale_details.serial_number_ids', 'LIKE', "%\"$serialId\"%")
                ->where('sales.setting_id', $settingId)
                ->select('sales.*')
                ->distinct()
                ->with(['customer', 'user'])
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'serial' => new SerialNumberResource($serial),
                    'associated_sales' => SaleSearchResource::collection($sales),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Get Serial Details Error', [
                'serial_id' => $serialId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve serial number details.'
            ], 500);
        }
    }

    /**
     * Autocomplete suggestions for search
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function suggest(Request $request): JsonResponse
    {
        abort_if(Gate::denies('sales.search.global'), 403);

        try {
            $query = $request->input('q', '');
            $type = $request->input('type', 'serial'); // serial, reference, customer
            $settingId = session('setting_id');

            if (!$settingId || empty($query)) {
                return response()->json([
                    'success' => true,
                    'suggestions' => []
                ]);
            }

            $suggestions = [];

            if ($type === 'serial' || $type === 'all') {
                $serials = ProductSerialNumber::query()
                    ->where('serial_number', 'LIKE', "%$query%")
                    ->limit(10)
                    ->pluck('serial_number')
                    ->toArray();

                $suggestions = array_merge($suggestions, array_map(fn($s) => [
                    'label' => $s,
                    'type' => 'serial'
                ], $serials));
            }

            if ($type === 'reference' || $type === 'all') {
                $references = Sale::query()
                    ->where('reference', 'LIKE', "%$query%")
                    ->where('setting_id', $settingId)
                    ->limit(10)
                    ->pluck('reference')
                    ->toArray();

                $suggestions = array_merge($suggestions, array_map(fn($r) => [
                    'label' => $r,
                    'type' => 'reference'
                ], $references));
            }

            if ($type === 'customer' || $type === 'all') {
                $customers = \Modules\People\Entities\Customer::query()
                    ->where('name', 'LIKE', "%$query%")
                    ->limit(10)
                    ->pluck('name')
                    ->toArray();

                $suggestions = array_merge($suggestions, array_map(fn($c) => [
                    'label' => $c,
                    'type' => 'customer'
                ], $customers));
            }

            return response()->json([
                'success' => true,
                'suggestions' => array_slice($suggestions, 0, 20)
            ]);

        } catch (\Exception $e) {
            Log::error('Autocomplete Suggestion Error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'suggestions' => []
            ]);
        }
    }

    /**
     * Display the Global Menu search interface
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        abort_if(Gate::denies('globalMenu.access'), 403);

        return view('sale::global-menu.index');
    }

    /**
     * Handle web search requests (for AJAX/DataTables)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSearch(Request $request)
    {
        abort_if(Gate::denies('globalMenu.access'), 403);

        try {
            $settingId = session('setting_id');

            if (!$settingId) {
                return response()->json([
                    'error' => 'Tenant context not set. Please select a business unit.'
                ], 400);
            }

            // Get filters from request
            $filters = $request->only([
                'serial_number', 'sale_reference', 'customer_id', 'customer_name',
                'status', 'date_from', 'date_to', 'location_id', 'product_id',
                'product_category_id', 'serial_number_status', 'seller_id'
            ]);

            // Remove empty filters
            $filters = array_filter($filters, function($value) {
                return $value !== '' && $value !== null;
            });

            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);

            // Execute search
            $query = $this->searchService->buildQuery($filters);
            $query->where('sales.setting_id', $settingId);

            $results = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $results->items(),
                'recordsTotal' => $results->total(),
                'recordsFiltered' => $results->total(),
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
            ]);

        } catch (\Exception $e) {
            Log::error('Global Menu Web Search Error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Search failed. Please try again.'
            ], 500);
        }
    }
}

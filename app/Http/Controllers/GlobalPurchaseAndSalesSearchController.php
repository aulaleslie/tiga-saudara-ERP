<?php

namespace App\Http\Controllers;

use App\Services\GlobalPurchaseAndSalesSearchService;
use App\Models\GlobalPurchaseAndSalesSearch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Global Purchase and Sales Search Controller
 *
 * Handles unified search across purchase orders and sales orders with
 * support for serial numbers, references, supplier/customer names,
 * and combined multi-criteria searches.
 */
class GlobalPurchaseAndSalesSearchController extends Controller
{
    private GlobalPurchaseAndSalesSearchService $searchService;

    public function __construct(GlobalPurchaseAndSalesSearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Display the main search page
     *
     * @return View
     */
    public function index(): View
    {
        // Check permission
        Gate::authorize('globalPurchaseAndSalesSearch.access');

        return view('global-purchase-and-sales-search.index', [
            'title' => 'Pencarian Pembelian dan Penjualan Global',
            'searchTypes' => [
                'serial' => 'Nomor Seri',
                'reference' => 'Nomor Referensi',
                'party' => 'Supplier/Pelanggan',
                'combined' => 'Pencarian Gabungan'
            ]
        ]);
    }

    /**
     * Perform search operation
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        // Check permission
        Gate::authorize('globalPurchaseAndSalesSearch.access');

        // Validate request
        $request->validate([
            'query' => 'required|string|min:1|max:255',
            'type' => 'required|in:serial,reference,party,combined',
            'limit' => 'nullable|integer|min:1|max:100',
            'include_global' => 'nullable|boolean'
        ]);

        try {
            $user = Auth::user();
            $settingId = $user->setting_id ?? null;
            $includeGlobal = $request->boolean('include_global', false);

            // Determine effective setting ID (null for global search)
            $effectiveSettingId = $includeGlobal ? null : $settingId;

            $results = match($request->type) {
                'serial' => $this->searchService->searchBySerialNumber(
                    $request->input('query'),
                    $effectiveSettingId,
                    $request->integer('limit', 50)
                ),
                'reference' => $this->searchReferences(
                    $request->input('query'),
                    $effectiveSettingId,
                    $request->integer('limit', 50)
                ),
                'party' => $this->searchParties(
                    $request->input('query'),
                    $effectiveSettingId,
                    $request->integer('limit', 50)
                ),
                'combined' => $this->searchService->searchCombined(
                    $request->input('query'),
                    $effectiveSettingId,
                    $request->integer('limit', 50)
                ),
            };

            // Log the search operation
            GlobalPurchaseAndSalesSearch::create([
                'user_id' => $user->id,
                'setting_id' => $settingId,
                'search_type' => $request->type,
                'search_query' => $request->input('query'),
                'results_count' => count($results['results'] ?? []),
                'response_time_ms' => $results['response_time_ms'] ?? null,
                'include_global' => $includeGlobal,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            // Log error search
            GlobalPurchaseAndSalesSearch::create([
                'user_id' => Auth::id(),
                'setting_id' => Auth::user()->setting_id ?? null,
                'search_type' => $request->type ?? 'unknown',
                'search_query' => $request->input('query') ?? '',
                'results_count' => 0,
                'response_time_ms' => null,
                'include_global' => $request->boolean('include_global', false),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'error_message' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search references (combines purchase and sales references)
     */
    private function searchReferences(string $query, ?int $settingId, int $limit): array
    {
        $purchaseResults = $this->searchService->searchByPurchaseReference($query, $settingId, $limit, 1);
        $salesResults = $this->searchService->searchBySalesReference($query, $settingId, $limit, 1);

        // Combine results
        $combinedResults = array_merge($purchaseResults['results'], $salesResults['results']);
        usort($combinedResults, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        return [
            'results' => array_slice($combinedResults, 0, $limit),
            'total' => count($combinedResults),
            'page' => 1,
            'limit' => $limit,
            'response_time_ms' => ($purchaseResults['response_time_ms'] ?? 0) + ($salesResults['response_time_ms'] ?? 0)
        ];
    }

    /**
     * Search parties (combines suppliers and customers)
     */
    private function searchParties(string $query, ?int $settingId, int $limit): array
    {
        $supplierResults = $this->searchService->searchBySupplier($query, $settingId, $limit, 1);
        $customerResults = $this->searchService->searchByCustomer($query, $settingId, $limit, 1);

        // Combine results
        $combinedResults = array_merge($supplierResults['results'], $customerResults['results']);
        usort($combinedResults, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        return [
            'results' => array_slice($combinedResults, 0, $limit),
            'total' => count($combinedResults),
            'page' => 1,
            'limit' => $limit,
            'response_time_ms' => ($supplierResults['response_time_ms'] ?? 0) + ($customerResults['response_time_ms'] ?? 0)
        ];
    }

    /**
     * Get autocomplete suggestions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function suggestions(Request $request): JsonResponse
    {
        // Check permission
        Gate::authorize('globalPurchaseAndSalesSearch.access');

        // Validate request
        $request->validate([
            'query' => 'required|string|min:1|max:255',
            'type' => 'required|in:serial,reference,party',
            'limit' => 'nullable|integer|min:1|max:20',
            'include_global' => 'nullable|boolean'
        ]);

        try {
            $user = Auth::user();
            $settingId = $user->setting_id ?? null;
            $includeGlobal = $request->boolean('include_global', false);

            // Determine effective setting ID (null for global search)
            $effectiveSettingId = $includeGlobal ? null : $settingId;

            $suggestions = $this->searchService->getSuggestions(
                $request->input('query'),
                $request->type,
                $effectiveSettingId,
                $request->integer('limit', 10)
            );

            return response()->json([
                'success' => true,
                'data' => $suggestions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get suggestions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get search statistics for the current user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        // Check permission
        Gate::authorize('globalPurchaseAndSalesSearch.access');

        try {
            $user = Auth::user();

            // Get search statistics for the last 30 days
            $stats = GlobalPurchaseAndSalesSearch::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('
                    COUNT(*) as total_searches,
                    AVG(response_time_ms) as avg_response_time,
                    SUM(results_count) as total_results_found,
                    COUNT(CASE WHEN error_message IS NOT NULL THEN 1 END) as failed_searches
                ')
                ->first();

            // Get search type breakdown
            $typeBreakdown = GlobalPurchaseAndSalesSearch::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('search_type, COUNT(*) as count')
                ->groupBy('search_type')
                ->pluck('count', 'search_type');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_searches' => $stats->total_searches ?? 0,
                    'avg_response_time' => round($stats->avg_response_time ?? 0, 2),
                    'total_results_found' => $stats->total_results_found ?? 0,
                    'failed_searches' => $stats->failed_searches ?? 0,
                    'type_breakdown' => $typeBreakdown
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get statistics: ' . $e->getMessage()
            ], 500);
        }
    }
}
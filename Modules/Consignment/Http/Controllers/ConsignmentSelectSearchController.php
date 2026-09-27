<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;

/**
 * Shared AJAX Select2 endpoints for Consignment filter dropdowns.
 *
 * Boundary rules enforced here:
 * - Supplier and Product are shared master data: searched globally, never filtered
 *   by setting_id. Only the active/eligible boundary applies.
 * - Consignment documents and evidence (sold sources) stay scoped to the active
 *   setting, as do the Locations they reference.
 */
class ConsignmentSelectSearchController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Suppliers for filter dropdowns. Shared master data: not setting-scoped.
     *
     * Filter selectors list every supplier that may appear on a historical
     * document, so inactive suppliers stay selectable here; the create/edit
     * write paths enforce the active boundary separately.
     */
    public function suppliers(Request $request): JsonResponse
    {
        abort_if($this->deniesAnyConsignmentRead(), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'selected_id' => ['nullable', 'integer'],
            'active_only' => ['nullable', 'boolean'],
        ]);

        if ($resolved = $this->resolveSelected($request, Supplier::query(), fn (Supplier $s) => $this->supplierLabel($s))) {
            return $resolved;
        }

        $term = trim($validated['q'] ?? '');

        $suppliers = Supplier::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($sub) use ($term) {
                    $sub->where('supplier_name', 'like', "%{$term}%")
                        ->orWhere('contact_name', 'like', "%{$term}%");
                });
            })
            ->orderBy('supplier_name')
            ->simplePaginate(self::PER_PAGE, ['id', 'supplier_name', 'contact_name']);

        return $this->paginatedJson($suppliers, fn (Supplier $s) => $this->supplierLabel($s));
    }

    /**
     * Products for filter dropdowns. Shared master data: not setting-scoped.
     */
    public function products(Request $request): JsonResponse
    {
        abort_if($this->deniesAnyConsignmentRead(), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'selected_id' => ['nullable', 'integer'],
        ]);

        if ($resolved = $this->resolveSelected($request, Product::query(), fn (Product $p) => $this->productLabel($p))) {
            return $resolved;
        }

        $term = trim($validated['q'] ?? '');

        $products = Product::query()
            ->active()
            ->where('stock_managed', true)
            ->when($term !== '', fn ($query) => $query->globalSearch($term))
            ->orderBy('product_name')
            ->simplePaginate(self::PER_PAGE, ['products.id', 'products.product_name', 'products.product_code']);

        return $this->paginatedJson($products, fn (Product $p) => $this->productLabel($p));
    }

    /**
     * Eligible sold sources for Confirmation creation, filtered and paginated.
     *
     * Sold sources are Consignment evidence and stay scoped to the active setting.
     */
    public function soldSources(Request $request): JsonResponse
    {
        abort_if(Gate::denies('consignments.allocations.create'), 403);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $settingId = (int) session('setting_id');
        $term = trim($request->input('q', ''));

        $sources = ConsignmentSoldSource::forSetting($settingId)
            ->where('has_reconstruction_blocker', false)
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($term !== '', fn ($q) => $q->searchTerm($term))
            // Eager loads keep label rendering free of N+1 queries.
            ->with(['sale:id,reference', 'product:id,product_name,product_code', 'location:id,name'])
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE);

        return $this->paginatedJson($sources, function (ConsignmentSoldSource $src) {
            return trim(sprintf(
                '%s | %s | %s',
                $src->product->product_name ?? '-',
                $src->location->name ?? '-',
                $src->sale->reference ?? '-'
            ));
        });
    }

    /**
     * Consignment locations for filter dropdowns. Setting-scoped infrastructure.
     */
    public function locations(Request $request): JsonResponse
    {
        abort_if($this->deniesAnyConsignmentRead(), 403);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $settingId = (int) session('setting_id');
        $term = trim($request->input('q', ''));

        $locations = \Modules\Setting\Entities\Location::query()
            ->where('setting_id', $settingId)
            ->consignment()
            ->when($term !== '', fn ($query) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->simplePaginate(self::PER_PAGE, ['id', 'name']);

        return $this->paginatedJson($locations, fn ($loc) => $loc->name);
    }

    /**
     * Resolve a preselected id so Select2 can restore a label after a reload or
     * validation failure without loading the whole collection.
     */
    private function resolveSelected(Request $request, $query, callable $label): ?JsonResponse
    {
        if (!$request->filled('selected_id')) {
            return null;
        }

        $record = $query->whereKey($request->integer('selected_id'))->first();

        return response()->json([
            'results' => $record ? [['id' => $record->id, 'text' => $label($record)]] : [],
            'pagination' => ['more' => false],
        ]);
    }

    private function paginatedJson($paginator, callable $label): JsonResponse
    {
        return response()->json([
            'results' => $paginator->getCollection()
                ->map(fn ($record) => ['id' => $record->id, 'text' => $label($record)])
                ->values(),
            'pagination' => ['more' => $paginator->hasMorePages()],
        ]);
    }

    private function supplierLabel(Supplier $supplier): string
    {
        return $supplier->contact_name
            ? "{$supplier->supplier_name} - {$supplier->contact_name}"
            : $supplier->supplier_name;
    }

    private function productLabel(Product $product): string
    {
        return trim($product->product_name . ($product->product_code ? " ({$product->product_code})" : ''));
    }

    /**
     * Filter endpoints back several Consignment screens; any read permission on
     * those screens is sufficient to populate their dropdowns.
     */
    private function deniesAnyConsignmentRead(): bool
    {
        return Gate::denies('consignments.access')
            && Gate::denies('consignments.allocations.access')
            && Gate::denies('consignments.billing.access');
    }
}

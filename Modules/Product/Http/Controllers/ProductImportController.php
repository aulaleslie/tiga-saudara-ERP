<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductStock;

class ProductImportController extends Controller
{
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.access'), 403);

        $batches = ProductImportBatch::with('user','location')
            ->orderByDesc('id')
            ->paginate(20);

        return view('product::products.imports.index', compact('batches'));
    }

    public function show(ProductImportBatch $batch): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.access'), 403);

        $rows = ProductImportRow::where('batch_id', $batch->id)
            ->orderBy('row_number')
            ->paginate(25);

        return view('product::products.imports.show', compact('batch','rows'));
    }

    public function undo(Request $request, ProductImportBatch $batch): RedirectResponse
    {
        abort_if(Gate::denies('products.edit'), 403);
        if (!$batch->canUndo()) {
            toast('Undo window expired atau batch sudah di-undo.', 'warning');
            return back();
        }

        // Minimal undo sample: only revert created stocks.
        // Extend as needed (e.g., flag created_by_batch_id on rows you create).
        ProductImportRow::where('batch_id', $batch->id)
            ->whereNotNull('created_stock_id')
            ->get()
            ->each(function ($r) {
                // If you add relation: $r->createdStock?->delete();
                ProductStock::whereKey($r->created_stock_id)->delete();
            });

        $batch->update(['undone_at' => now()]);
        toast('Batch berhasil di-undo.', 'info');
        return back();
    }
}

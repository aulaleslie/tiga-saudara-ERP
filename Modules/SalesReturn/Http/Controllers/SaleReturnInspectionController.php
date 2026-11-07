<?php

namespace Modules\SalesReturn\Http\Controllers;

use App\Support\SalesReturn\SalesReturnStateException;
use App\Support\SalesReturn\StandardReturnCustodyService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\SalesReturn\Entities\SaleReturn;

/** Dedicated, permission-controlled inspection and release of held returned goods. */
class SaleReturnInspectionController extends Controller
{
    public function show(SaleReturn $sale_return, StandardReturnCustodyService $custody)
    {
        abort_if(Gate::denies('saleReturns.inspect'), 403);

        return view('salesreturn::inspect', [
            'sale_return' => $sale_return,
            'custody' => $custody->summary($sale_return),
            'request_key' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, SaleReturn $sale_return, StandardReturnCustodyService $custody)
    {
        abort_if(Gate::denies('saleReturns.inspect'), 403);

        $data = $request->validate([
            'notes' => ['required', 'string', 'min:3', 'max:1000'],
            'request_key' => ['nullable', 'string', 'max:64'],
            'release' => ['required', 'array', 'min:1'],
            'release.*.selected' => ['nullable'],
            'release.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
        ], [
            'notes.required' => 'Catatan inspeksi wajib diisi.',
            'release.required' => 'Pilih barang yang akan dilepas.',
        ]);

        $selections = collect($data['release'])
            ->filter(fn ($row) => ! empty($row['selected']))
            ->map(fn ($row, $custodyId) => ['custody_id' => (int) $custodyId, 'quantity' => round((float) ($row['quantity'] ?? 1), 3)])
            ->values()
            ->all();

        try {
            $inspection = $custody->release($sale_return, $selections, $data['notes'], $data['request_key'] ?? null);
        } catch (SalesReturnStateException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('sale-returns.show', $sale_return)
            ->with('success', "Inspeksi dicatat: {$inspection->released_quantity} unit dilepas ke stok.");
    }
}

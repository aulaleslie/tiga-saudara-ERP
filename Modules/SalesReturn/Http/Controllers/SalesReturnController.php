<?php

namespace Modules\SalesReturn\Http\Controllers;

use Modules\SalesReturn\DataTables\SaleReturnsDataTable;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\SalesReturn\Entities\SaleReturn;

class SalesReturnController extends Controller
{

    public function index(SaleReturnsDataTable $dataTable) {
        abort_if(Gate::denies('saleReturns.access'), 403);

        return $dataTable->render('salesreturn::index');
    }


    public function create() {
        abort_if(Gate::denies('saleReturns.create'), 403);

        return view('salesreturn::create');
    }


    public function store() {
        abort(404, 'Gunakan formulir Livewire untuk membuat retur penjualan.');
    }


    public function show(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturns.show'), 403);

        return view('salesreturn::show', compact('sale_return'));
    }


    public function edit(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturns.edit'), 403);

        return view('salesreturn::edit', compact('sale_return'));
    }


    public function update(SaleReturn $sale_return) {
        abort(404, 'Gunakan formulir Livewire untuk memperbarui retur penjualan.');
    }


    public function destroy(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturns.delete'), 403);

        $sale_return->delete();

        toast('Retur Penjualan Dihapus!', 'warning');

        return redirect()->route('sale-returns.index');
    }
}

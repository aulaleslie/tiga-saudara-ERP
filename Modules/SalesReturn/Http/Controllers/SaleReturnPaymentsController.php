<?php

namespace Modules\SalesReturn\Http\Controllers;

use Modules\SalesReturn\DataTables\SaleReturnPaymentsDataTable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;

class SaleReturnPaymentsController extends Controller
{

    public function index($sale_return_id, SaleReturnPaymentsDataTable $dataTable) {
        abort_if(Gate::denies('saleReturnPayments.access'), 403);

        $sale_return = SaleReturn::findOrFail($sale_return_id);

        return $dataTable->render('salesreturn::payments.index', compact('sale_return'));
    }


    public function create($sale_return_id) {
        abort_if(Gate::denies('saleReturnPayments.create'), 403);

        $sale_return = SaleReturn::findOrFail($sale_return_id);

        toast('Pembayaran retur kini dikelola melalui penyelesaian retur.', 'info');

        return redirect()->route('sale-returns.settlement', $sale_return);
    }


    public function store(Request $request) {
        abort_if(Gate::denies('saleReturnPayments.create'), 403);

        $sale_return = SaleReturn::findOrFail($request->sale_return_id);

        toast('Gunakan penyelesaian retur untuk mencatat pembayaran.', 'info');

        return redirect()->route('sale-returns.settlement', $sale_return);
    }


    public function edit($sale_return_id, SaleReturnPayment $saleReturnPayment) {
        abort_if(Gate::denies('saleReturnPayments.edit'), 403);

        $sale_return = SaleReturn::findOrFail($sale_return_id);

        toast('Pembayaran retur kini dikelola melalui penyelesaian retur.', 'info');

        return redirect()->route('sale-returns.settlement', $sale_return);
    }


    public function update(Request $request, SaleReturnPayment $saleReturnPayment) {
        abort_if(Gate::denies('saleReturnPayments.edit'), 403);

        $sale_return = $saleReturnPayment->saleReturn;

        toast('Gunakan penyelesaian retur untuk memperbarui pembayaran.', 'info');

        return redirect()->route('sale-returns.settlement', $sale_return);
    }


    public function destroy(SaleReturnPayment $saleReturnPayment) {
        abort_if(Gate::denies('saleReturnPayments.delete'), 403);

        $sale_return = $saleReturnPayment->saleReturn;

        toast('Hapus atau ubah pembayaran melalui penyelesaian retur.', 'info');

        return redirect()->route('sale-returns.settlement', $sale_return);
    }
}

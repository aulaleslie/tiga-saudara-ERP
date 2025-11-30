<?php

namespace Modules\Expense\Http\Controllers;

use App\Services\IdempotencyService;
use Modules\Expense\DataTables\ExpensesDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Expense\Entities\Expense;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Exp;

class ExpenseController extends Controller
{

    public function __construct()
    {
        $this->middleware('idempotency')->only('store');
    }

    public function index(ExpensesDataTable $dataTable) {
        abort_if(Gate::denies('expenses.access'), 403);

        return $dataTable->render('expense::expenses.index');
    }


    public function create(Request $request) {
        abort_if(Gate::denies('expenses.create'), 403);

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('expense::expenses.create', compact('idempotencyToken'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('expenses.create'), 403);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'category_id' => 'required',
            'amount' => 'required|numeric|max:2147483647',
            'details' => 'nullable|string|max:1000'
        ]);

        $currentSettingId = session('setting_id');

        Expense::create([
            'setting_id' => $currentSettingId,
            'date' => $request->date,
            'category_id' => $request->category_id,
            'amount' => $request->amount,
            'details' => $request->details
        ]);

        toast('Pengeluaran telah dibuat!', 'success');

        return redirect()->route('expenses.index');
    }


    public function edit(Expense $expense) {
        abort_if(Gate::denies('expenses.edit'), 403);

        return view('expense::expenses.edit', compact('expense'));
    }


    public function update(Request $request, Expense $expense) {
        abort_if(Gate::denies('expenses.edit'), 403);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'category_id' => 'required',
            'amount' => 'required|numeric|max:2147483647',
            'details' => 'nullable|string|max:1000'
        ]);

        $expense->update([
            'date' => $request->date,
            'reference' => $request->reference,
            'category_id' => $request->category_id,
            'amount' => $request->amount,
            'details' => $request->details
        ]);

        toast('Expense Updated!', 'info');

        return redirect()->route('expenses.index');
    }


    public function destroy(Expense $expense) {
        abort_if(Gate::denies('expenses.delete'), 403);

        $expense->delete();

        toast('Expense Deleted!', 'warning');

        return redirect()->route('expenses.index');
    }
}

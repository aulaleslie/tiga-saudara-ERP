<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\DataTables\ChartOfAccountsDataTable;
use Modules\Setting\Entities\ChartOfAccount;

class ChartofAccountController extends Controller
{
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $coa = ChartOfAccount::with('parentAccount')->get();
        return view('setting::coa.index', [
            'coa' => $coa
        ]);
    }

    public function create()
    {
        abort_if(Gate::denies('create_account'), 403);
        return view('setting::coa.create', [
            'parent_accounts' => ChartOfAccount::whereNull('parent_account_id')->get(),
            'taxes' => \Modules\Setting\Entities\Tax::all(),
        ]);    
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('create_account'), 403);

        $request->validate([
            'name' => 'required|string|unique:chart_of_accounts,name',
            'account_number' => 'required|string|unique:chart_of_accounts,account_number',
            'category' => 'required|in:Akun Piutang,Aktiva Lancar Lainnya,Kas & Bank,Persediaan,Aktiva Tetap,Aktiva Lainnya,Depresiasi & Amortisasi,Akun Hutang,Kartu Kredit,Kewajiban Lancar Lainnya,Kewajiban Jangka Panjang,Ekuitas,Pendapatan,Pendapatan Lainnya,Harga Pokok Penjualan,Beban,Beban Lainnya',
            'parent_account_id' => 'nullable|exists:chart_of_accounts,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'description' => 'nullable|string',
        ]);

        ChartOfAccount::create($request->all()); // Store the account
        toast('Akun Berhasil Ditambahkan!', 'success');

        return redirect()->route('chart-of-account.index'); // Redirect to index
    }

    public function show($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('show_account'), 403);

        $account = ChartOfAccount::findOrFail($id); // Fetch the account
        return view('setting::coa.show', compact('account'));
    }

    public function edit($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('edit_account'), 403);

        $account = ChartOfAccount::findOrFail($id); // Fetch the account
        return view('setting::coa.edit', [
            'parent_accounts' => ChartOfAccount::whereNull('parent_account_id')->whereNot('id',$id)->get(),
            'taxes' => \Modules\Setting\Entities\Tax::all(),
            'chartOfAccount' => $account,
        ]);
    }

    public function update(Request $request, $id): RedirectResponse
    {
        abort_if(Gate::denies('edit_account'), 403);

        $account = ChartOfAccount::findOrFail($id); // Fetch the account

        $request->validate([
            'name' => 'required|string|unique:chart_of_accounts,name,' . $account->id,
            'account_number' => 'required|string|unique:chart_of_accounts,account_number,' . $account->id,
            'category' => 'required|in:Akun Piutang,Aktiva Lancar Lainnya,Kas & Bank,Persediaan,Aktiva Tetap,Aktiva Lainnya,Depresiasi & Amortisasi,Akun Hutang,Kartu Kredit,Kewajiban Lancar Lainnya,Kewajiban Jangka Panjang,Ekuitas,Pendapatan,Pendapatan Lainnya,Harga Pokok Penjualan,Beban,Beban Lainnya',
            'parent_account_id' => 'nullable|exists:chart_of_accounts,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'description' => 'nullable|string',
        ]);

        $account->update($request->all()); // Update the account
        toast('Akun Berhasil Diperbaharui!', 'info');

        return redirect()->route('chart-of-account.index'); // Redirect to index
    }

    public function destroy($id): RedirectResponse
    {
        abort_if(Gate::denies('delete_account'), 403);

        $account = ChartOfAccount::findOrFail($id); // Fetch the account
        $account->delete(); // Delete the account
        toast('Akun Berhasil Dihapus!', 'warning');

        return redirect()->route('chart-of-account.index'); // Redirect to index
    }
}

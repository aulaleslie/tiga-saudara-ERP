<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Tax;

class ChartofAccountController extends Controller
{
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('chartOfAccounts.access'), 403);
        $query = ChartOfAccount::with('parentAccount');

        if (request()->filled('status')) {
            $status = request('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $coa = $query->get();
        return view('setting::coa.index', [
            'coa' => $coa
        ]);
    }

    public function create()
    {
        abort_if(Gate::denies('chartOfAccounts.create'), 403);
        return view('setting::coa.create', [
            'parent_accounts' => ChartOfAccount::whereNull('parent_account_id')->where('is_active', true)->get(),
            'taxes' => Tax::where('is_active', true)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('chartOfAccounts.create'), 403);

        $request->validate([
            'name' => 'required|string|unique:chart_of_accounts,name',
            'account_number' => 'required|string|unique:chart_of_accounts,account_number',
            'category' => 'required|in:Akun Piutang,Aktiva Lancar Lainnya,Kas & Bank,Persediaan,Aktiva Tetap,Aktiva Lainnya,Depresiasi & Amortisasi,Akun Hutang,Kartu Kredit,Kewajiban Lancar Lainnya,Kewajiban Jangka Panjang,Ekuitas,Pendapatan,Pendapatan Lainnya,Harga Pokok Penjualan,Beban,Beban Lainnya',
            'parent_account_id' => 'nullable|exists:chart_of_accounts,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'description' => 'nullable|string',
        ]);

        $data = $request->all();
        $data['setting_id'] = session('setting_id');
        ChartOfAccount::create($data); // Store the account
        toast('Akun Berhasil Ditambahkan!', 'success');

        return redirect()->route('chart-of-account.index'); // Redirect to index
    }

    public function show($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('chartOfAccounts.show'), 403);

        $account = ChartOfAccount::findOrFail($id); // Fetch the account
        return view('setting::coa.show', compact('account'));
    }

    public function edit($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('chartOfAccounts.edit'), 403);

        $account = ChartOfAccount::findOrFail($id); // Fetch the account
        return view('setting::coa.edit', [
            'parent_accounts' => ChartOfAccount::whereNull('parent_account_id')
                ->where('id', '!=', $id)
                ->where(function ($q) use ($account) {
                    $q->where('is_active', true)
                        ->orWhere('id', $account->parent_account_id);
                })
                ->get(),
            'taxes' => Tax::where('is_active', true)
                ->orWhere('id', $account->tax_id)
                ->get(),
            'chartOfAccount' => $account,
        ]);
    }

    public function update(Request $request, $id): RedirectResponse
    {
        abort_if(Gate::denies('chartOfAccounts.edit'), 403);

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

    public function toggleStatus($id, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('chartOfAccounts.edit') && ! Gate::allows('chartOfAccounts.delete'), 403);

        $account = ChartOfAccount::findOrFail($id);

        try {
            if ($account->is_active) {
                $lifecycleService->deactivate($account);
                toast('Akun berhasil dinonaktifkan!', 'info');
            } else {
                $lifecycleService->reactivate($account);
                toast('Akun berhasil diaktifkan kembali!', 'success');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->back();
    }

    public function destroy($id, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('chartOfAccounts.edit') && ! Gate::allows('chartOfAccounts.delete'), 403);

        $account = ChartOfAccount::findOrFail($id);

        try {
            $lifecycleService->deactivate($account);
            toast('Akun berhasil dinonaktifkan!', 'info');
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('chart-of-account.index');
    }
}

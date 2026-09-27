<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Journal;

class JournalController extends Controller
{
    // Display a list of journals
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('journals.access'), 403);
        $journals = Journal::with('items')->latest()->paginate(10);
        return view('setting::journals.index', compact('journals'));
    }

    // Show form for creating a new journal
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('journals.create'), 403);
        // Get Chart of Accounts to populate the dropdowns in the form
        $accounts = ChartOfAccount::all();
        return view('setting::journals.create', compact('accounts'));
    }

    // Store a new journal with nested journal items
    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('journals.create'), 403);
        $rules = [
            'transaction_date'                    => 'required|date',
            'description'                         => 'nullable|string',
            'items'                               => 'required|array|min:1',
            'items.*.chart_of_account_id'         => 'required|exists:chart_of_accounts,id',
            'items.*.amount_debit'                => 'nullable|numeric',
            'items.*.amount_credit'               => 'nullable|numeric',
        ];

        $validator = Validator::make($request->all(), $rules);

        // Custom validation: Each item must have either a debit or credit amount greater than 0,
        // and the sum of all debit amounts must equal the sum of all credit amounts.
        $validator->after(function ($validator) use ($request) {
            $totalDebit = 0;
            $totalCredit = 0;
            foreach ($request->items as $index => $item) {
                $debit  = isset($item['amount_debit']) ? (float)$item['amount_debit'] : 0;
                $credit = isset($item['amount_credit']) ? (float)$item['amount_credit'] : 0;

                // Check that at least one of the amounts is greater than 0.
                if ($debit <= 0 && $credit <= 0) {
                    $validator->errors()->add("items.$index", 'Either debit or credit amount must be greater than 0.');
                }

                $totalDebit  += $debit;
                $totalCredit += $credit;
            }

            // Check that total debits equal total credits.
            if ($totalDebit !== $totalCredit) {
                $validator->errors()->add('items', 'The total debit must equal the total credit.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $validated = $validator->validated();

        // Create the Journal header
        $journal = Journal::create([
            'transaction_date' => $validated['transaction_date'],
            'description'      => $validated['description'] ?? null,
        ]);

        // Process each item and create JournalItem records.
        foreach ($validated['items'] as $itemData) {
            $debit  = isset($itemData['amount_debit']) ? (float)$itemData['amount_debit'] : 0;
            $credit = isset($itemData['amount_credit']) ? (float)$itemData['amount_credit'] : 0;

            // Determine which amount and type to assign:
            if ($debit > 0) {
                $amount = $debit;
                $type   = 'debit';
            } else {
                $amount = $credit;
                $type   = 'credit';
            }

            $journal->items()->create([
                'chart_of_account_id' => $itemData['chart_of_account_id'],
                'amount'              => $amount,
                'type'                => $type,
            ]);
        }

        return redirect()->route('journals.index')
            ->with('success', 'Journal created successfully.');
    }

    // Display a single journal with its items
    public function show($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('journals.show'), 403);
        $journal = Journal::with('items.chartOfAccount')->findOrFail($id);
        return view('setting::journals.show', compact('journal'));
    }

    // Show form for editing a journal and its items
    public function edit($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('journals.edit'), 403);
        $journal = Journal::with('items')->findOrFail($id);
        $accounts = ChartOfAccount::all();
        return view('setting::journals.edit', compact('journal', 'accounts'));
    }

    // Update a journal and its items
    public function update(Request $request, $id): RedirectResponse
    {
        abort_if(Gate::denies('journals.edit'), 403);
        // Similar validation as store
        $rules = [
            'transaction_date'                    => 'required|date',
            'description'                         => 'nullable|string',
            'items'                               => 'required|array|min:1',
            'items.*.chart_of_account_id'         => 'required|exists:chart_of_accounts,id',
            'items.*.amount_debit'                => 'nullable|numeric',
            'items.*.amount_credit'               => 'nullable|numeric',
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            $totalDebit = 0;
            $totalCredit = 0;
            foreach ($request->items as $index => $item) {
                $debit  = isset($item['amount_debit']) ? (float)$item['amount_debit'] : 0;
                $credit = isset($item['amount_credit']) ? (float)$item['amount_credit'] : 0;

                if ($debit <= 0 && $credit <= 0) {
                    $validator->errors()->add("items.$index", 'Either debit or credit amount must be greater than 0.');
                }

                $totalDebit  += $debit;
                $totalCredit += $credit;
            }

            if ($totalDebit !== $totalCredit) {
                $validator->errors()->add('items', 'The total debit must equal the total credit.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $validated = $validator->validated();

        // Update Journal header
        $journal = Journal::findOrFail($id);
        $journal->update([
            'transaction_date' => $validated['transaction_date'],
            'description'      => $validated['description'] ?? null,
        ]);

        // Option: Replace all items with new ones.
        $journal->items()->delete();

        foreach ($validated['items'] as $itemData) {
            $debit  = isset($itemData['amount_debit']) ? (float)$itemData['amount_debit'] : 0;
            $credit = isset($itemData['amount_credit']) ? (float)$itemData['amount_credit'] : 0;

            if ($debit > 0) {
                $amount = $debit;
                $type   = 'debit';
            } else {
                $amount = $credit;
                $type   = 'credit';
            }

            $journal->items()->create([
                'chart_of_account_id' => $itemData['chart_of_account_id'],
                'amount'              => $amount,
                'type'                => $type,
            ]);
        }

        return redirect()->route('journals.index')
            ->with('success', 'Journal updated successfully.');
    }

    // Delete a journal along with its items
    public function destroy($id): RedirectResponse
    {
        abort_if(Gate::denies('journals.delete'), 403);
        $journal = Journal::findOrFail($id);
        $journal->delete();
        return redirect()->route('journals.index')
            ->with('success', 'Journal deleted successfully.');
    }
}

<?php

namespace Modules\Expense\Http\Controllers;

use App\Services\IdempotencyService;
use Modules\Expense\DataTables\ExpensesDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Expense\Entities\Expense;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Exp;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ExpenseController extends Controller
{

    public function __construct(protected \Modules\Expense\Services\ExpenseService $expenseService)
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
            'category_id' => 'required|exists:expense_categories,id',
            'details' => 'required|array|min:1',
            'details.*.name' => 'required|string|max:255',
            'details.*.amount' => 'required|numeric|min:0.01',
            'details.*.tax_id' => 'nullable|exists:taxes,id',
            'files.*' => 'nullable|file|max:10240',
            'status' => 'nullable|string|in:' . Expense::STATUS_DRAFT . ',' . Expense::STATUS_SUBMITTED,
            'is_tax_included' => 'nullable|boolean',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        $data = $request->only(['date', 'category_id', 'details', 'status', 'is_tax_included', 'supplier_id', 'tag_ids']);
        $data['files'] = $request->file('files', []);
        $data['setting_id'] = session('setting_id');

        $this->expenseService->saveExpense($data);

        toast('Pengeluaran telah dibuat!', 'success');

        return redirect()->route('expenses.index');
    }

    public function show(Expense $expense) {
        abort_if(Gate::denies('expenses.access'), 403);
        $this->expenseService->verifySettingOwnership($expense);

        $expense->load('detailRows.tax', 'media', 'category', 'archivedBy', 'supplier', 'tags');

        return view('expense::expenses.show', compact('expense'));
    }


    public function edit(Expense $expense) {
        abort_if(Gate::denies('expenses.edit'), 403);
        $this->expenseService->verifySettingOwnership($expense);

        return view('expense::expenses.edit', compact('expense'));
    }


    public function update(Request $request, Expense $expense) {
        abort_if(Gate::denies('expenses.edit'), 403);
        $this->expenseService->verifySettingOwnership($expense);

        $request->validate([
            'date' => 'required|date',
            'category_id' => 'required|exists:expense_categories,id',
            'details' => 'required|array|min:1',
            'details.*.name' => 'required|string|max:255',
            'details.*.amount' => 'required|numeric|min:0.01',
            'details.*.tax_id' => 'nullable|exists:taxes,id',
            'files.*' => 'nullable|file|max:10240',
            'removed_attachment_ids' => 'nullable|array',
            'status' => 'nullable|string|in:' . Expense::STATUS_DRAFT . ',' . Expense::STATUS_SUBMITTED,
            'is_tax_included' => 'nullable|boolean',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        $data = $request->only(['date', 'category_id', 'details', 'removed_attachment_ids', 'status', 'is_tax_included', 'supplier_id', 'tag_ids']);
        $data['files'] = $request->file('files', []);
        $data['setting_id'] = session('setting_id');

        $this->expenseService->saveExpense($data, $expense);

        toast('Expense Updated!', 'info');

        return redirect()->route('expenses.index');
    }


    public function destroy(Expense $expense) {
        abort_if(Gate::denies('expenses.delete'), 403);
        
        $this->expenseService->delete($expense);

        toast('Expense Deleted!', 'warning');

        return redirect()->route('expenses.index');
    }

    public function submit(Expense $expense) {
        abort_if(Gate::denies('expenses.create') && Gate::denies('expenses.edit'), 403);
        $this->expenseService->submit($expense);
        toast('Pengeluaran telah diajukan!', 'success');
        return redirect()->back();
    }

    public function approve(Expense $expense) {
        abort_if(Gate::denies('expenses.approval'), 403);
        $this->expenseService->approve($expense);
        toast('Pengeluaran telah disetujui!', 'success');
        return redirect()->back();
    }

    public function reject(Request $request, Expense $expense) {
        abort_if(Gate::denies('expenses.approval'), 403);
        $request->validate(['rejection_reason' => 'required|string|max:1000']);
        $this->expenseService->reject($expense, $request->rejection_reason);
        toast('Pengeluaran telah ditolak!', 'warning');
        return redirect()->back();
    }

    public function archive(Request $request, Expense $expense) {
        abort_if(Gate::denies('expenses.archive'), 403);
        $this->expenseService->archive($expense, $request->archive_reason);
        toast('Pengeluaran telah diarsipkan!', 'info');
        return redirect()->back();
    }

    public function storeAttachment(Request $request, Expense $expense): RedirectResponse
    {
        abort_if(Gate::denies('expenses.edit'), 403);
        $this->expenseService->verifySettingOwnership($expense);

        if ($expense->archived_at) {
            abort(403, 'Tidak dapat menambah lampiran pada pengeluaran yang diarsipkan.');
        }

        $request->validate([
            'file' => 'required|file|max:10240', // 10MB
        ]);

        try {
            $expense->addMedia($request->file('file'))->toMediaCollection('attachments');
            toast('Lampiran berhasil diunggah', 'success');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Expense Attachment Upload Failed:', ['error' => $e->getMessage()]);
            toast('Gagal mengunggah lampiran', 'error');
        }

        return redirect()->back();
    }

    public function destroyAttachment(Expense $expense, Media $media): RedirectResponse
    {
        abort_if(Gate::denies('expenses.edit'), 403);
        $this->expenseService->verifySettingOwnership($expense);

        if ($expense->archived_at) {
            abort(403, 'Tidak dapat menghapus lampiran pada pengeluaran yang diarsipkan.');
        }

        if ($media->model_type !== get_class($expense) || $media->model_id !== $expense->id || $media->collection_name !== 'attachments') {
            abort(404);
        }

        try {
            $media->delete();
            toast('Lampiran berhasil dihapus', 'success');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Expense Attachment Deletion Failed:', ['error' => $e->getMessage()]);
            toast('Gagal menghapus lampiran', 'error');
        }

        return redirect()->back();
    }
}

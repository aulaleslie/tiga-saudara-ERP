<?php

namespace Modules\Expense\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Modules\Expense\Entities\ExpenseImportBatch;
use Modules\Expense\Entities\ExpenseImportRow;

class ExpenseUploadController extends Controller
{
    /**
     * Show list of all import batches.
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('expenses.import'), 403);

        $batches = ExpenseImportBatch::with('user')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('expense::expenses.imports.index', compact('batches'));
    }

    /**
     * Show the upload form.
     */
    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('expenses.import'), 403);

        return view('expense::expenses.imports.upload');
    }

    /**
     * Handle CSV upload.
     */
    public function upload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('expenses.import'), 403);

        $request->validate([
            'file' => 'required|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        Log::info('[ExpenseImport] Upload request received', [
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_size_kb' => round($file->getSize() / 1024, 2),
        ]);

        // Save CSV
        $path = $file->store('imports/expenses');
        $fullPath = Storage::path($path);

        // Create batch
        $batch = ExpenseImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => $path,
            'file_sha256' => hash_file('sha256', $fullPath),
            'status' => 'queued',
        ]);

        Log::info('[ExpenseImport] Batch created', ['batch_id' => $batch->id]);

        // Validate CSV headers only (don't stage rows here)
        try {
            $csv = Reader::createFromPath($fullPath);

            // Auto-detect delimiter
            $sample = @file_get_contents($fullPath, false, null, 0, 4096) ?: '';
            $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
            $csv->setDelimiter($delimiter);

            $csv->setHeaderOffset(0);
            $rawHeaders = $csv->getHeader();

            // Normalize headers
            $normalizedHeaders = $this->normalizeHeaders($rawHeaders);

            // Validate required columns
            $required = ['tanggal', 'transaksi', 'nomor', 'kategori', 'deskripsi', 'supplier', 'jumlah', 'tax', 'status', 'sisa_tagihan'];
            $missing = array_diff($required, array_keys($normalizedHeaders));

            if (!empty($missing)) {
                $batch->update(['status' => 'failed']);
                return back()->withErrors([
                    'file' => 'Missing required columns: ' . implode(', ', $missing)
                        . '. Required: Tanggal, Transaksi, Nomor, Kategori, Deskripsi, Supplier, Jumlah, Tax, Status, Sisa Tagihan',
                ]);
            }

            // Dispatch job to stage rows asynchronously
            \Modules\Expense\Jobs\StageExpenseImportRows::dispatch(
                $batch->id,
                $normalizedHeaders,
                $rawHeaders,
                $delimiter
            );

            Log::info('[ExpenseImport] StageExpenseImportRows job dispatched', [
                'batch_id' => $batch->id,
            ]);

            toast("Upload berhasil. Batch #{$batch->id} sedang diproses di background.", 'success');
            return redirect()->route('expenses.imports.show', $batch);

        } catch (\Exception $e) {
            Log::error('[ExpenseImport] Upload failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            $batch->update(['status' => 'failed']);
            return back()->withErrors(['file' => 'Error processing file: ' . $e->getMessage()]);
        }
    }

    /**
     * Show batch status with paginated rows.
     */
    public function show(Request $request, ExpenseImportBatch $batch): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('expenses.import'), 403);

        $query = ExpenseImportRow::where('batch_id', $batch->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function($q) use ($term) {
                $q->where('error_message', 'like', $term)
                  ->orWhere('raw_json', 'like', $term);
            });
        }

        $rows = $query->orderBy('row_number')
            ->paginate(25)
            ->withQueryString();

        return view('expense::expenses.imports.show', compact('batch', 'rows'));
    }

    /**
     * Normalize header names.
     */
    protected function normalizeHeaders(array $rawHeaders): array
    {
        $aliases = [
            'tanggal' => 'tanggal',
            'date' => 'tanggal',
            
            'transaksi' => 'transaksi',
            'transaction' => 'transaksi',
            
            'nomor' => 'nomor',
            'number' => 'nomor',
            'no' => 'nomor',
            
            'kategori' => 'kategori',
            'category' => 'kategori',
            
            'deskripsi' => 'deskripsi',
            'description' => 'deskripsi',
            
            'supplier' => 'supplier',
            
            'jumlah' => 'jumlah',
            'amount' => 'jumlah',
            
            'tax' => 'tax',
            'pajak' => 'tax',
            
            'status' => 'status',
            
            'sisa tagihan' => 'sisa_tagihan',
            'sisa_tagihan' => 'sisa_tagihan',
            'sisa tagihan hari ini' => 'sisa_tagihan',
            'remaining bill' => 'sisa_tagihan',
        ];

        $map = [];
        foreach ($rawHeaders as $header) {
            $norm = strtolower(trim(preg_replace('/\s+/', ' ', $header)));
            if (isset($aliases[$norm])) {
                $map[$aliases[$norm]] = $header;
            }
        }

        return $map;
    }
}

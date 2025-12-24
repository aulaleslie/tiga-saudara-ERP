<?php

namespace Modules\Purchase\Http\Controllers;

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
use League\Csv\Statement;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;

class PurchaseUploadController extends Controller
{
    protected PurchaseImportService $importService;

    public function __construct(PurchaseImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * Show list of all import batches.
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.create'), 403);

        $batches = PurchaseImportBatch::with('user')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('purchase::purchases.imports.index', compact('batches'));
    }

    /**
     * Show the upload form.
     */
    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.create'), 403);

        return view('purchase::purchases.upload');
    }

    /**
     * Handle CSV upload.
     */
    public function upload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('purchases.create'), 403);

        $request->validate([
            'file' => 'required|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        Log::info('[PurchaseImport] Upload request received', [
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_size_kb' => round($file->getSize() / 1024, 2),
        ]);

        // Save CSV
        $path = $file->store('imports/purchases');
        $fullPath = Storage::path($path);

        // Create batch
        $batch = PurchaseImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => $path,
            'file_sha256' => hash_file('sha256', $fullPath),
            'status' => 'queued',
        ]);

        Log::info('[PurchaseImport] Batch created', ['batch_id' => $batch->id]);

        // Parse CSV and stage rows
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
            $required = ['tanggal', 'supplier', 'no_faktur', 'produk', 'kuantitas', 'satuan', 'harga_satuan'];
            $missing = array_diff($required, array_keys($normalizedHeaders));

            if (!empty($missing)) {
                $batch->update(['status' => 'failed']);
                return back()->withErrors([
                    'file' => 'Missing required columns: ' . implode(', ', $missing)
                        . '. Required: Tanggal, Supplier, No Faktur, Produk, Kuantitas, Satuan, Harga Satuan',
                ]);
            }

            // Stage rows
            $records = (new Statement())->process($csv);
            $rowNo = 0;

            foreach ($records as $record) {
                $mapped = $this->mapCsvRow((array) $record, $normalizedHeaders, $rawHeaders);

                // Skip empty rows
                if (empty($mapped['produk']) || empty($mapped['tanggal'])) {
                    continue;
                }

                PurchaseImportRow::create([
                    'batch_id' => $batch->id,
                    'row_number' => ++$rowNo,
                    'raw_json' => $mapped,
                ]);
            }

            $batch->update(['total_rows' => $rowNo, 'status' => 'validating']);

            Log::info('[PurchaseImport] Rows staged', [
                'batch_id' => $batch->id,
                'total_rows' => $rowNo,
            ]);

            // Process synchronously
            $this->importService->processBatch($batch);

            toast("Upload selesai. Batch #{$batch->id} telah diproses.", 'success');
            return redirect()->route('purchases.imports.show', $batch);

        } catch (\Exception $e) {
            Log::error('[PurchaseImport] Upload failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            $batch->update(['status' => 'failed']);
            return back()->withErrors(['file' => 'Error processing file: ' . $e->getMessage()]);
        }
    }

    /**
     * Show batch status.
     */
    public function show(PurchaseImportBatch $batch): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.create'), 403);

        $batch->load(['rows' => function ($q) {
            $q->orderBy('row_number');
        }]);

        return view('purchase::purchases.import-show', compact('batch'));
    }

    /**
     * Download template CSV.
     */
    public function downloadTemplate()
    {
        $headers = ['Tanggal', 'Supplier', 'No Faktur', 'Produk', 'Kuantitas', 'Satuan', 'Harga Satuan', 'Pajak'];
        $example = ['19/03/2020', 'PT BALI SATU COMPUTER', 'JL.2003.02096', '* PC SERVER DELL Power Edge', '1', 'UNIT', '9009090.91', '900909.09'];

        $content = implode(',', $headers) . "\n" . implode(',', $example);

        return response($content)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="purchase_import_template.csv"');
    }

    /**
     * Normalize header names.
     */
    protected function normalizeHeaders(array $rawHeaders): array
    {
        $aliases = [
            'tanggal' => 'tanggal',
            'date' => 'tanggal',
            'supplier' => 'supplier',
            'supplier name' => 'supplier',
            'no faktur' => 'no_faktur',
            'no. faktur' => 'no_faktur',
            'invoice' => 'no_faktur',
            'invoice no' => 'no_faktur',
            'produk' => 'produk',
            'product' => 'produk',
            'product name' => 'produk',
            'kuantitas' => 'kuantitas',
            'quantity' => 'kuantitas',
            'qty' => 'kuantitas',
            'satuan' => 'satuan',
            'unit' => 'satuan',
            'harga satuan' => 'harga_satuan',
            'unit price' => 'harga_satuan',
            'price' => 'harga_satuan',
            'pajak' => 'pajak',
            'tax' => 'pajak',
            'tax amount' => 'pajak',
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

    /**
     * Map CSV row to normalized structure.
     */
    protected function mapCsvRow(array $record, array $normalizedHeaders, array $rawHeaders): array
    {
        $get = function (string $canonical) use ($record, $normalizedHeaders) {
            if (!isset($normalizedHeaders[$canonical])) {
                return null;
            }
            $actual = $normalizedHeaders[$canonical];
            return array_key_exists($actual, $record) ? trim((string) $record[$actual]) : null;
        };

        return [
            'tanggal' => $get('tanggal'),
            'supplier' => $get('supplier'),
            'no_faktur' => $get('no_faktur'),
            'produk' => $get('produk'),
            'kuantitas' => $get('kuantitas'),
            'satuan' => $get('satuan'),
            'harga_satuan' => $get('harga_satuan'),
            'pajak' => $get('pajak') ?: '0',
        ];
    }
}

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
            $required = ['tanggal', 'supplier', 'no_faktur', 'produk', 'kuantitas', 'satuan', 'harga_satuan'];
            $missing = array_diff($required, array_keys($normalizedHeaders));

            if (!empty($missing)) {
                $batch->update(['status' => 'failed']);
                return back()->withErrors([
                    'file' => 'Missing required columns: ' . implode(', ', $missing)
                        . '. Required: Tanggal, Supplier, No Faktur, Produk, Kuantitas, Satuan, Harga Satuan',
                ]);
            }

            // Dispatch job to stage rows asynchronously (with bulk insert)
            \Modules\Purchase\Jobs\StagePurchaseImportRows::dispatch(
                $batch->id,
                $normalizedHeaders,
                $rawHeaders,
                $delimiter
            );

            Log::info('[PurchaseImport] StagePurchaseImportRows job dispatched', [
                'batch_id' => $batch->id,
            ]);

            toast("Upload berhasil. Batch #{$batch->id} sedang diproses di background.", 'success');
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
     * Show batch status with paginated rows, filtering, and search.
     */
    public function show(Request $request, PurchaseImportBatch $batch): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.create'), 403);

        $query = PurchaseImportRow::where('batch_id', $batch->id);

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

        return view('purchase::purchases.import-show', compact('batch', 'rows'));
    }

    /**
     * Download template CSV.
     */
    public function downloadTemplate()
    {
        abort_if(Gate::denies('purchases.access'), 403);

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
            // Date
            'tanggal' => 'tanggal',
            'date' => 'tanggal',
            // Supplier
            'supplier' => 'supplier',
            'supplier name' => 'supplier',
            'nama panggilan' => 'supplier',
            // Invoice number
            'no faktur' => 'no_faktur',
            'no. faktur' => 'no_faktur',
            'invoice' => 'no_faktur',
            'invoice no' => 'no_faktur',
            'nomor transaksi' => 'no_faktur',
            // Product
            'produk' => 'produk',
            'product' => 'produk',
            'product name' => 'produk',
            'nama produk' => 'produk',
            // Quantity
            'kuantitas' => 'kuantitas',
            'quantity' => 'kuantitas',
            'qty' => 'kuantitas',
            // Unit
            'satuan' => 'satuan',
            'unit' => 'satuan',
            // Unit price
            'harga satuan' => 'harga_satuan',
            'harga per unit' => 'harga_satuan',
            'unit price' => 'harga_satuan',
            'price' => 'harga_satuan',
            // Tax amount per line
            'pajak' => 'pajak',
            'tax' => 'pajak',
            'tax amount' => 'pajak',
            'jumlah pajak' => 'pajak',
            // Tax rate
            'tarif pajak' => 'tarif_pajak',
            'tax rate' => 'tarif_pajak',
            // Product description
            'deskripsi' => 'deskripsi',
            'description' => 'deskripsi',
            // Tag (for tenant selection)
            'tag' => 'tag',
            // Memo/notes
            'memo' => 'memo',
            // Due date
            'tanggal jatuh tempo' => 'tanggal_jatuh_tempo',
            'due date' => 'tanggal_jatuh_tempo',
            // Outstanding balance
            'sisa tagihan hari ini' => 'sisa_tagihan_hari_ini',
            'sisa tagihan' => 'sisa_tagihan',
            // Payment amount
            'pembayaran' => 'pembayaran',
            'payment' => 'pembayaran',
            // Source document total
            'total' => 'source_total',
            // Shipping
            'biaya pengiriman' => 'biaya_pengiriman',
            'shipping' => 'biaya_pengiriman',
            // Supplier company name
            'nama perusahaan' => 'nama_perusahaan',
            'company name' => 'nama_perusahaan',
            // Tax reference number
            'nomor pajak' => 'nomor_pajak',
            // Phone
            'nomor telepon' => 'nomor_telepon',
            'phone' => 'nomor_telepon',
            // Discount
            'diskon' => 'diskon',
            'diskon %' => 'diskon_document_persen',
            'diskon per baris %' => 'diskon_persen',
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
            // New fields for updated template
            'tag' => $get('tag'),
            'tarif_pajak' => $get('tarif_pajak'),
            'deskripsi' => $get('deskripsi'),
            'memo' => $get('memo'),
            'tanggal_jatuh_tempo' => $get('tanggal_jatuh_tempo'),
            'sisa_tagihan_hari_ini' => $get('sisa_tagihan_hari_ini'),
            'sisa_tagihan' => $get('sisa_tagihan'),
            'pembayaran' => $get('pembayaran'),
            'source_total' => $get('source_total'),
            'biaya_pengiriman' => $get('biaya_pengiriman'),
            'nama_perusahaan' => $get('nama_perusahaan'),
            'nomor_pajak' => $get('nomor_pajak'),
            'nomor_telepon' => $get('nomor_telepon'),
            'diskon' => $get('diskon'),
            'diskon_document_persen' => $get('diskon_document_persen') ?: '0',
            'diskon_persen' => $get('diskon_persen') ?: '0',
        ];
    }
}

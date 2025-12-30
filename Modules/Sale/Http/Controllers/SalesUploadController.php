<?php

namespace Modules\Sale\Http\Controllers;

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
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;

class SalesUploadController extends Controller
{
    protected SalesImportService $importService;

    public function __construct(SalesImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * Show list of all import batches.
     */
    public function index(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('sales.create'), 403);

        $batches = SalesImportBatch::with('user')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('sale::sales.imports.index', compact('batches'));
    }

    /**
     * Show the upload form.
     */
    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('sales.create'), 403);

        return view('sale::sales.upload');
    }

    /**
     * Handle CSV upload.
     */
    public function upload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('sales.create'), 403);

        $request->validate([
            'file' => 'required|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        Log::info('[SalesImport] Upload request received', [
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_size_kb' => round($file->getSize() / 1024, 2),
        ]);

        // Save CSV
        $path = $file->store('imports/sales');
        $fullPath = Storage::path($path);

        // Create batch
        $batch = SalesImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => $path,
            'file_sha256' => hash_file('sha256', $fullPath),
            'status' => 'queued',
        ]);

        Log::info('[SalesImport] Batch created', ['batch_id' => $batch->id]);

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
            $required = ['tanggal', 'customer', 'no_faktur', 'produk', 'kuantitas', 'satuan', 'harga_satuan'];
            $missing = array_diff($required, array_keys($normalizedHeaders));

            if (!empty($missing)) {
                $batch->update(['status' => 'failed']);
                return back()->withErrors([
                    'file' => 'Missing required columns: ' . implode(', ', $missing)
                        . '. Required: Tanggal, Customer (Nama Panggilan), Nomor Transaksi, Produk, Kuantitas, Satuan, Harga per Unit',
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

                SalesImportRow::create([
                    'batch_id' => $batch->id,
                    'row_number' => ++$rowNo,
                    'raw_json' => $mapped,
                    'status' => SalesImportRow::STATUS_PENDING,
                ]);
            }

            $batch->update(['total_rows' => $rowNo, 'status' => 'validating']);

            Log::info('[SalesImport] Rows staged', [
                'batch_id' => $batch->id,
                'total_rows' => $rowNo,
            ]);

            // Dispatch job for async processing
            \Modules\Sale\Jobs\ProcessSalesImportBatch::dispatch($batch->id);

            toast("Upload berhasil. Batch #{$batch->id} sedang diproses di background.", 'success');
            return redirect()->route('sales.imports.show', $batch);

        } catch (\Exception $e) {
            Log::error('[SalesImport] Upload failed', [
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
    public function show(Request $request, SalesImportBatch $batch): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('sales.create'), 403);

        $query = SalesImportRow::where('batch_id', $batch->id);

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

        return view('sale::sales.import-show', compact('batch', 'rows'));
    }

    /**
     * Download template CSV.
     */
    public function downloadTemplate()
    {
        $headers = ['Tanggal', 'Nama Panggilan', 'Nomor Transaksi', 'Nama Produk', 'Kuantitas', 'Satuan', 'Harga per Unit', 'Jumlah Pajak', 'Tag', 'Gudang'];
        $example = ['09/02/2020', 'customer umum', 'CA-1', '* Epson L3110', '1', 'UNIT', '1890909.09', '189090.91', 'CV TIGA NUSA', ''];

        $content = implode(',', $headers) . "\n" . implode(',', $example);

        return response($content)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="sales_import_template.csv"');
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
            // Customer
            'customer' => 'customer',
            'customer name' => 'customer',
            'nama panggilan' => 'customer',
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
            'sisa tagihan hari ini' => 'sisa_tagihan',
            'sisa tagihan' => 'sisa_tagihan',
            // Payment amount
            'pembayaran' => 'pembayaran',
            'payment' => 'pembayaran',
            // Shipping
            'biaya pengiriman' => 'biaya_pengiriman',
            'shipping' => 'biaya_pengiriman',
            // Customer company name
            'nama perusahaan' => 'nama_perusahaan',
            'company name' => 'nama_perusahaan',
            // Phone
            'nomor telepon' => 'nomor_telepon',
            'phone' => 'nomor_telepon',
            // Discount
            'diskon per baris %' => 'diskon_persen',
            // Location/warehouse
            'gudang' => 'gudang',
            'warehouse' => 'gudang',
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
            'customer' => $get('customer'),
            'no_faktur' => $get('no_faktur'),
            'produk' => $get('produk'),
            'kuantitas' => $get('kuantitas'),
            'satuan' => $get('satuan'),
            'harga_satuan' => $get('harga_satuan'),
            'pajak' => $get('pajak') ?: '0',
            // Additional fields
            'tag' => $get('tag'),
            'tarif_pajak' => $get('tarif_pajak'),
            'deskripsi' => $get('deskripsi'),
            'memo' => $get('memo'),
            'tanggal_jatuh_tempo' => $get('tanggal_jatuh_tempo'),
            'sisa_tagihan' => $get('sisa_tagihan') ?: '0',
            'pembayaran' => $get('pembayaran') ?: '0',
            'biaya_pengiriman' => $get('biaya_pengiriman') ?: '0',
            'nama_perusahaan' => $get('nama_perusahaan'),
            'nomor_telepon' => $get('nomor_telepon'),
            'diskon_persen' => $get('diskon_persen') ?: '0',
            'gudang' => $get('gudang'),
        ];
    }
}

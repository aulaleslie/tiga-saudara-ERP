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
    public function upload(Request $request)
    {
        abort_if(Gate::denies('sales.create'), 403);

        if ($request->has('is_chunked')) {
            return $this->handleChunkedUpload($request);
        }

        $request->validate([
            'file' => 'required|mimes:csv,txt,zip',
        ]);

        $file = $request->file('file');
        return $this->processUploadedFile($file);
    }

    /**
     * Handle chunked upload request.
     */
    protected function handleChunkedUpload(Request $request)
    {
        $fileId = $request->input('file_id'); // Unique ID for the file upload session
        $chunkIndex = $request->input('chunk_index');
        $totalChunks = $request->input('total_chunks');
        $file = $request->file('chunk');

        $fileName = $request->input('file_name');
        $tempPath = 'imports/sales/temp/' . $fileId . '_' . $fileName;
        $finalPath = 'imports/sales/' . $fileName;

        // Append chunk to temp file
        Storage::append($tempPath, $file->get());

        // logging
        // Log::info("Processed chunk {$chunkIndex}/{$totalChunks} for {$fileName}");

        if ($chunkIndex == $totalChunks - 1) {
            // Last chunk received, Move temp file to final location
            if (Storage::exists($finalPath)) {
                Storage::delete($finalPath);
            }
            Storage::move($tempPath, $finalPath);

            $fullPath = Storage::path($finalPath);
            
            // Create a fake file object to reuse existing logic if possible, 
            // or just call processing logic directly.
            // Since processUploadedFile expects a generic file or UploadedFile, 
            // Let's adapt processUploadedFile to take a path or handle it.
            // Actually, let's just duplicate the processing logic for now to ensure safety or refactor processUploadedFile.
            
            return $this->processLocalFile($finalPath, $fullPath);
        }

        return response()->json(['status' => 'chunk_uploaded']);
    }

    /**
     * Process a file that is already in storage (from chunked upload).
     */
    protected function processLocalFile($relativePath, $absolutePath) 
    {
        Log::info('[SalesImport] Processing local file', [
            'user_id' => auth()->id(),
            'path' => $relativePath
        ]);

        // Handle ZIP files (reuse logic)
        $isZip = str_ends_with(strtolower($absolutePath), '.zip');
        $csvPath = $relativePath;
        $processingPath = $absolutePath; // Path to the actual CSV file to read

        if ($isZip) {
            $zip = new \ZipArchive;
            if ($zip->open($absolutePath) === TRUE) {
                $extractedCsv = null;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($filename), '.csv') || str_ends_with(strtolower($filename), '.txt')) {
                        $extractedCsv = $filename;
                        break;
                    }
                }

                if ($extractedCsv) {
                    $extractDir = dirname($absolutePath) . '/extracted_' . time();
                    $zip->extractTo($extractDir, $extractedCsv);
                    $zip->close();
                    
                    $newRelativePath = 'imports/sales/' . time() . '_' . basename($extractedCsv);
                    Storage::put($newRelativePath, file_get_contents($extractDir . '/' . $extractedCsv));
                    
                    $csvPath = $newRelativePath;
                    $processingPath = Storage::path($csvPath);
                } else {
                    $zip->close();
                    return response()->json(['error' => 'No CSV file found inside ZIP.'], 422);
                }
            } else {
                 return response()->json(['error' => 'Failed to open ZIP archive.'], 422);
            }
        }

        // Create batch
        $batch = SalesImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => $csvPath, // Store relative path for job
            'file_sha256' => hash_file('sha256', $processingPath),
            'status' => 'queued',
        ]);

        try {
            $csv = Reader::createFromPath($processingPath);
            $sample = @file_get_contents($processingPath, false, null, 0, 4096) ?: '';
            $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
            $csv->setDelimiter($delimiter);
            $csv->setHeaderOffset(0);
            $rawHeaders = $csv->getHeader();
            
            $normalizedHeaders = $this->normalizeHeaders($rawHeaders);
            $required = ['tanggal', 'customer', 'no_faktur', 'produk', 'kuantitas', 'satuan', 'harga_satuan'];
            $missing = array_diff($required, array_keys($normalizedHeaders));

            if (!empty($missing)) {
                $batch->update(['status' => 'failed']);
                return response()->json([
                    'error' => 'Missing required columns: ' . implode(', ', $missing)
                ], 422);
            }

            \Modules\Sale\Jobs\StageSalesImportRows::dispatch(
                $batch->id,
                $normalizedHeaders,
                $rawHeaders,
                $delimiter
            );

            // Return JSON redirect URL for frontend to follow
            return response()->json([
                'status' => 'completed', 
                'redirect_url' => route('sales.imports.show', $batch)
            ]);

        } catch (\Exception $e) {
             $batch->update(['status' => 'failed']);
             return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Legacy/Standard File Upload Handler
     */
    protected function processUploadedFile($file)
    {
        // Save uploaded file
        $uploadPath = $file->store('imports/sales');
        $fullPath = Storage::path($uploadPath);
        
        // Use the common logic, assuming processLocalFile returns JSON, 
        // we need to adapt it to return RedirectResponse for standard valid form processing.
        // But since I'm implementing chunked upload, the frontend will primarily use that.
        // For fallback standard upload, I'll essentially replicate the logic or just wrap the response.
        
        // Actually, let's keep the standard upload logic simple as before for fallback
        // Re-implementing simplified version of previous logic here for standard requests
        
        // Reuse processLocalFile but handle response
        $response = $this->processLocalFile($uploadPath, $fullPath);
        
        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getContent(), true);
            if (isset($data['redirect_url'])) {
                 toast("Upload berhasil.", 'success');
                 return redirect($data['redirect_url']);
            }
        }
        
        $data = json_decode($response->getContent(), true);
        return back()->withErrors(['file' => $data['error'] ?? 'Upload failed']);
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
        abort_if(Gate::denies('sales.access'), 403);

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
            // Customer company name
            'nama perusahaan' => 'nama_perusahaan',
            'company name' => 'nama_perusahaan',
            // Phone
            'nomor telepon' => 'nomor_telepon',
            'phone' => 'nomor_telepon',
            // Discount
            'diskon' => 'diskon',
            'diskon %' => 'diskon_document_persen',
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
            'sisa_tagihan_hari_ini' => $get('sisa_tagihan_hari_ini'),
            'sisa_tagihan' => $get('sisa_tagihan'),
            'pembayaran' => $get('pembayaran'),
            'source_total' => $get('source_total'),
            'biaya_pengiriman' => $get('biaya_pengiriman'),
            'nama_perusahaan' => $get('nama_perusahaan'),
            'nomor_telepon' => $get('nomor_telepon'),
            'diskon' => $get('diskon'),
            'diskon_document_persen' => $get('diskon_document_persen') ?: '0',
            'diskon_persen' => $get('diskon_persen') ?: '0',
            'gudang' => $get('gudang'),
        ];
    }
}

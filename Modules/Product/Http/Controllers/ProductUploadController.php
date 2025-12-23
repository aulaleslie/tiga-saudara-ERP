<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Exception;
use League\Csv\InvalidArgument;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\SyntaxError;
use League\Csv\UnavailableStream;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class ProductUploadController extends Controller
{
    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);
        $locations = Location::all();
        return view('product::products.upload', compact('locations'));
    }

    /**
     * Robust CSV upload with header normalization and alias mapping.
     *
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws Exception
     */
    public function upload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);

        $request->validate([
            'file' => 'required|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        Log::info('[ProductImport] Upload request received', [
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_size_kb' => round($file->getSize() / 1024, 2),
        ]);

        // 1) Save CSV
        $path = $request->file('file')->store('imports/products');
        $fullPath = Storage::path($path);

        // Resolve setting & location automatically (location kept for schema compatibility only)
        $settingId = $this->resolveSettingId();
        if (!$settingId) {
            return back()->withErrors(['file' => 'Setting belum dikonfigurasi. Tambahkan setting terlebih dahulu.']);
        }
        $locationId = $this->resolveLocationId($settingId);
        if ($locationId === null) {
            Log::warning('[ProductImport] Location resolution failed', ['setting_id' => $settingId]);
            return back()->withErrors(['file' => 'Lokasi belum dikonfigurasi. Tambahkan lokasi terlebih dahulu.']);
        }

        Log::info('[ProductImport] Resolved context', [
            'setting_id' => $settingId,
            'location_id' => $locationId
        ]);

        // 2) Create a batch
        $batch = ProductImportBatch::create([
            'user_id'         => auth()->id(),
            'location_id'     => $locationId,
            'source_csv_path' => $path,
            'file_sha256'     => hash_file('sha256', $fullPath),
            'status'          => 'queued',
            'undo_token'      => Str::random(40),
        ]);

        Log::info('[ProductImport] Batch created', ['batch_id' => $batch->id]);

        // 3) Read & normalize headers (BOM/whitespace/case) + auto-detect delimiter
        $csv = Reader::createFromPath($fullPath);

        $sample = @file_get_contents($fullPath, false, null, 0, 4096) ?: '';
        $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
        $csv->setDelimiter($delimiter);

        $csv->setHeaderOffset(0);
        $rawHeaders = $csv->getHeader();

        $normalize = function (string $h): string {
            // strip UTF-8 BOM if present
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            // trim & collapse multiple spaces
            $h = trim(preg_replace('/\s+/', ' ', $h));
            return mb_strtolower($h);
        };

        $normHeaders = array_map($normalize, $rawHeaders);

        // Aliases: left = normalized incoming header, right = our canonical key
        $aliases = [
            'nama produk'        => 'Nama Produk',
            'product name'       => 'Nama Produk',

            'kode produk'        => 'Kode Produk',
            'product code'       => 'Kode Produk',
            'sku'                => 'Kode Produk',

            'stok di tangan'     => 'Stok di tangan',
            'stok'               => 'Stok di tangan',

            'batas minimum'      => 'Batas Minimum',
            'stok minimum'       => 'Batas Minimum',

            'satuan'             => 'Satuan',
            'unit'               => 'Satuan',

            'harga rata-rata'    => 'Harga Rata-rata',
            'harga rata rata'    => 'Harga Rata-rata',
            'average price'      => 'Harga Rata-rata',

            'nilai'              => 'Nilai',
        ];

        // Build canonical => actual header map
        $headerMap = [];
        foreach ($normHeaders as $i => $norm) {
            if (isset($aliases[$norm])) {
                $headerMap[$aliases[$norm]] = $rawHeaders[$i];
            }
        }

        // Required columns (adjust if you need more/less strict)
        $required = ['Nama Produk', 'Satuan', 'Harga Rata-rata'];
        $missing = array_values(array_diff($required, array_keys($headerMap)));
        if (!empty($missing)) {
            return back()->withErrors([
                'file' => 'CSV header mismatch. Missing columns: ' . implode(', ', $missing)
                    . '. Pastikan header sesuai template product.csv.',
            ]);
        }

        // 4) Stage rows
        $records = (new Statement())->process($csv);

        $rowNo = 0;
        foreach ($records as $record) {
            ProductImportRow::create([
                'batch_id'   => $batch->id,
                'row_number' => ++$rowNo,
                'raw_json'   => $this->mapCsvRowToPayload((array) $record, $headerMap),
            ]);
        }

        Log::info('[ProductImport] Rows staged', ['batch_id' => $batch->id, 'total_rows' => $rowNo]);

        $batch->update(['total_rows' => $rowNo, 'status' => 'validating']);

        // 5) Queue processing
        dispatch(new ProcessProductImportBatch($batch->id));

        Log::info('[ProductImport] Batch processing job dispatched', ['batch_id' => $batch->id]);

        toast("Upload diterima. Batch #{$batch->id} sedang diproses.", 'success');
        return redirect()->route('products.imports.show', $batch);
    }

    /**
     * Map one CSV row into your normalized payload using the header map.
     */
    private function mapCsvRowToPayload(array $record, array $headerMap): array
    {
        // Safe getter via canonical key
        $get = function (string $canonical) use ($record, $headerMap) {
            if (!isset($headerMap[$canonical])) {
                return null;
            }
            $actual = $headerMap[$canonical];
            return array_key_exists($actual, $record) ? trim((string) $record[$actual]) : null;
        };

        $payload = [
            'product_name'      => $get('Nama Produk'),
            'product_code'      => $get('Kode Produk'),
            'unit_name'         => $get('Satuan'),
            'average_price'     => $get('Harga Rata-rata'),
            'stock_on_hand'     => $get('Stok di tangan'),
            'minimum_stock'     => $get('Batas Minimum'),
            'nilai'             => $get('Nilai'),
        ];

        return $payload;
    }

    private function resolveSettingId(): ?int
    {
        $id = session('setting_id') ?? Setting::query()->min('id');
        return $id ? (int) $id : null;
    }

    private function resolveLocationId(int $settingId): ?int
    {
        $locationId = Location::where('setting_id', $settingId)->value('id');
        if ($locationId) {
            return (int) $locationId;
        }

        $fallback = Location::query()->value('id');
        return $fallback ? (int) $fallback : null;
    }
}

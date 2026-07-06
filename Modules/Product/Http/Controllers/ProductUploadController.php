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
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductUploadController extends Controller
{
    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);
        $locations = Location::all();
        return view('product::products.upload', compact('locations'));
    }

    /**
     * Handle CSV upload - file is saved and processing is dispatched to queue.
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

        // Auto-detect import type based on headers
        $importType = 'product';
        try {
            $csv = \League\Csv\Reader::createFromPath($fullPath);
            $sample = @file_get_contents($fullPath, false, null, 0, 4096) ?: '';
            $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
            $csv->setDelimiter($delimiter);
            $csv->setHeaderOffset(0);
            $rawHeaders = $csv->getHeader();
            
            $normalize = function (string $h): string {
                $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
                return mb_strtolower(trim(preg_replace('/\s+/', ' ', $h)));
            };
            $normHeaders = array_map($normalize, $rawHeaders);
            
            if (in_array('total quantity', $normHeaders) && in_array('unassigned', $normHeaders)) {
                $importType = ProductImportBatch::TYPE_STOCK_SNAPSHOT;
            } elseif (in_array('tipe transaksi', $normHeaders) && in_array('barang', $normHeaders) && in_array('mutasi', $normHeaders) && in_array('harga rata-rata', $normHeaders)) {
                $importType = ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT;
            } else {
                $importType = ProductImportBatch::TYPE_PRODUCT;
            }
        } catch (\Throwable $e) {
            Log::warning('[ProductImport] Failed to detect import type', ['error' => $e->getMessage()]);
        }

        // 2) Create a batch
        $batch = ProductImportBatch::create([
            'user_id'         => auth()->id(),
            'location_id'     => $locationId,
            'source_csv_path' => $path,
            'file_sha256'     => hash_file('sha256', $fullPath),
            'status'          => 'queued',
            'import_type'     => $importType,
            'undo_token'      => Str::random(40),
        ]);

        Log::info('[ProductImport] Batch created', ['batch_id' => $batch->id, 'import_type' => $importType]);

        // 3) Queue processing - CSV parsing and row staging happens in background
        Log::info('[ProductImport] Dispatching background job for CSV processing', ['batch_id' => $batch->id]);

        dispatch(new ProcessProductImportBatch($batch->id));

        Log::info('[ProductImport] Batch processing job dispatched', ['batch_id' => $batch->id]);

        toast("Upload diterima. Batch #{$batch->id} sedang diproses.", 'success');
        return redirect()->route('products.imports.show', $batch);
    }

    /**
     * Stock snapshot dedicated upload page.
     */
    public function stockSnapshotUploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);
        return view('product::products.stock-snapshot-upload');
    }

    /**
     * Handle stock snapshot CSV upload — forces import_type = stock_snapshot.
     */
    public function stockSnapshotUpload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);

        $request->validate([
            'file' => 'required|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        Log::info('[StockSnapshotImport] Upload request received', [
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_size_kb' => round($file->getSize() / 1024, 2),
        ]);

        $path = $file->store('imports/products');
        $fullPath = Storage::path($path);

        // Resolve any setting/location for schema compatibility
        $settingId = $this->resolveSettingId();
        if (!$settingId) {
            return back()->withErrors(['file' => 'Setting belum dikonfigurasi. Tambahkan setting terlebih dahulu.']);
        }
        $locationId = $this->resolveLocationId($settingId);
        if ($locationId === null) {
            return back()->withErrors(['file' => 'Lokasi belum dikonfigurasi. Tambahkan lokasi terlebih dahulu.']);
        }

        $batch = ProductImportBatch::create([
            'user_id'         => auth()->id(),
            'location_id'     => $locationId,
            'source_csv_path' => $path,
            'file_sha256'     => hash_file('sha256', $fullPath),
            'status'          => 'queued',
            'import_type'     => 'stock_snapshot',
            'undo_token'      => Str::random(40),
        ]);

        Log::info('[StockSnapshotImport] Batch created', ['batch_id' => $batch->id]);
        dispatch(new ProcessProductImportBatch($batch->id));

        toast("Upload stok diterima. Batch #{$batch->id} sedang diproses.", 'success');
        return redirect()->route('products.imports.show', $batch);
    }

    /**
     * Download stock snapshot CSV template.
     */
    public function downloadStockSnapshotTemplate(): StreamedResponse
    {
        abort_if(Gate::denies('products.create'), 403);

        $filename = 'template_stock_snapshot.csv';
        $headers = ['Product Code', 'Product Name', 'Unassigned', 'Total Quantity', 'Product Unit'];
        $example = ['SKU-001', 'Produk Contoh', 0, 100, 'PCS'];

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fputcsv($out, $example);
            fclose($out);
        }, $filename, [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Sales HPP snapshot dedicated upload page.
     */
    public function salesHppSnapshotUploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);
        return view('product::products.sales-hpp-snapshot-upload');
    }

    /**
     * Handle sales HPP snapshot CSV upload.
     */
    public function salesHppSnapshotUpload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);

        $request->validate([
            'file' => 'required|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        Log::info('[SalesHppSnapshotImport] Upload request received', [
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_size_kb' => round($file->getSize() / 1024, 2),
        ]);

        $path = $file->store('imports/products');
        $fullPath = Storage::path($path);

        // Resolve any setting/location for schema compatibility
        $settingId = $this->resolveSettingId();
        if (!$settingId) {
            return back()->withErrors(['file' => 'Setting belum dikonfigurasi. Tambahkan setting terlebih dahulu.']);
        }
        $locationId = $this->resolveLocationId($settingId);
        if ($locationId === null) {
            return back()->withErrors(['file' => 'Lokasi belum dikonfigurasi. Tambahkan lokasi terlebih dahulu.']);
        }

        // Validate required headers
        try {
            $csv = \League\Csv\Reader::createFromPath($fullPath);
            $sample = @file_get_contents($fullPath, false, null, 0, 4096) ?: '';
            $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
            $csv->setDelimiter($delimiter);
            $csv->setHeaderOffset(0);
            $rawHeaders = $csv->getHeader();
            
            $normalize = function (string $h): string {
                $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
                return mb_strtolower(trim(preg_replace('/\s+/', ' ', $h)));
            };
            $normHeaders = array_map($normalize, $rawHeaders);
            
            $required = ['tipe transaksi', 'barang', 'mutasi', 'harga rata-rata', 'no. transaksi'];
            $missing = array_diff($required, $normHeaders);
            if (!empty($missing)) {
                return back()->withErrors(['file' => 'File CSV tidak memiliki kolom wajib: ' . implode(', ', $missing)]);
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Gagal membaca CSV: ' . $e->getMessage()]);
        }

        $batch = ProductImportBatch::create([
            'user_id'         => auth()->id(),
            'location_id'     => $locationId,
            'source_csv_path' => $path,
            'file_sha256'     => hash_file('sha256', $fullPath),
            'status'          => 'queued',
            'import_type'     => ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT,
            'undo_token'      => Str::random(40),
        ]);

        Log::info('[SalesHppSnapshotImport] Batch created', ['batch_id' => $batch->id]);
        dispatch(new ProcessProductImportBatch($batch->id));

        toast("Upload HPP diterima. Batch #{$batch->id} sedang diproses.", 'success');
        return redirect()->route('products.imports.show', $batch);
    }

    /**
     * Download sales HPP snapshot CSV template.
     */
    public function downloadSalesHppSnapshotTemplate(): StreamedResponse
    {
        abort_if(Gate::denies('products.create'), 403);

        $filename = 'template_sales_hpp_snapshot.csv';
        $headers = ['Tipe Transaksi', 'No. Transaksi', 'Barang', 'Mutasi', 'Harga Rata-Rata'];
        $example = ['Sales Invoice', 'INV-001', 'Produk Contoh', '-10', '1500000'];

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fputcsv($out, $example);
            fclose($out);
        }, $filename, [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
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

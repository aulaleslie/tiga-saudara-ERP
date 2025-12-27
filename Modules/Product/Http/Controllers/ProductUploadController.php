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

        // 3) Queue processing - CSV parsing and row staging happens in background
        Log::info('[ProductImport] Dispatching background job for CSV processing', ['batch_id' => $batch->id]);

        dispatch(new ProcessProductImportBatch($batch->id));

        Log::info('[ProductImport] Batch processing job dispatched', ['batch_id' => $batch->id]);

        toast("Upload diterima. Batch #{$batch->id} sedang diproses.", 'success');
        return redirect()->route('products.imports.show', $batch);
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

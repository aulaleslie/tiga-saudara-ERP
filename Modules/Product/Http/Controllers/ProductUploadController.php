<?php

namespace Modules\Product\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Statement;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;

class ProductUploadController extends Controller
{
    public function uploadPage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('products.create'), 403);
        $locations = Location::all();
        // Reuse your existing Blade: product::products.upload
        return view('product::products.upload', compact('locations'));
    }

    public function upload(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('products.create'), 403);

        $request->validate([
            'file'        => 'required|mimes:csv,txt',
            'location_id' => 'required|exists:locations,id',
        ]);

        // 1) Save CSV
        $path = $request->file('file')->store('imports/products');

        // 2) Create batch (queued)
        $batch = ProductImportBatch::create([
            'user_id'         => auth()->id(),
            'location_id'     => (int)$request->input('location_id'),
            'source_csv_path' => $path,
            'file_sha256'     => hash_file('sha256', Storage::path($path)),
            'status'          => 'queued',
            'undo_token'      => Str::random(40),
        ]);

        // 3) Stage rows
        $csv = Reader::createFromPath(Storage::path($path));
        $csv->setHeaderOffset(0);
        $records = (new Statement())->process($csv);

        $rowNo = 0;
        foreach ($records as $record) {
            ProductImportRow::create([
                'batch_id'   => $batch->id,
                'row_number' => ++$rowNo,
                'raw_json'   => $this->mapCsvRowToPayload((array)$record),
            ]);
        }

        $batch->update(['total_rows' => $rowNo, 'status' => 'validating']);

        // 4) Process (queue or sync depending on QUEUE_CONNECTION)
        dispatch(new ProcessProductImportBatch($batch->id));

        toast("Upload diterima. Batch #{$batch->id} sedang diproses.", 'success');

        // Optional: send user to monitor page instead of index
        return redirect()->route('products.imports.show', $batch);
        // Or: return redirect()->route('products.index');
    }

    /** Keep aligned with your downloadCsvTemplate() headers */
    private function mapCsvRowToPayload(array $r): array
    {
        $g = fn($k) => array_key_exists($k, $r) ? trim((string)$r[$k]) : null;
        $toBool = fn($v) => in_array(strtolower((string)$v), ['1','true','ya'], true) ? 1 : 0;
        $toInt  = fn($v) => (int)preg_replace('/[^\d-]/', '', (string)$v);

        $payload = [
            'product_name'      => $g('Nama Produk'),
            'product_code'      => $g('Kode Produk'),
            'barcode'           => $g('Barcode'),
            'category_name'     => $g('Nama Kategori'),
            'brand_name'        => $g('Nama Merek'),

            'stock_managed'     => $toBool($g('Kelola Stok')),
            'serial_required'   => $toBool($g('Wajib Nomor Seri')),
            'base_unit_name'    => $g('Nama Unit Dasar'),
            'stock_qty'         => $toInt($g('Stok')),
            'min_stock'         => $toInt($g('Stok Minimum')),

            'is_purchased'      => $toBool($g('Dibeli')),
            'purchase_price'    => $toInt($g('Harga Beli')),
            'purchase_tax_name' => $g('Nama Pajak Beli'),

            'is_sold'           => $toBool($g('Dijual')),
            'sale_price'        => $toInt($g('Harga Jual')),
            'tier_1_price'      => $toInt($g('Harga Tier 1')),
            'tier_2_price'      => $toInt($g('Harga Tier 2')),
            'sale_tax_name'     => $g('Nama Pajak Jual'),
        ];

        for ($i = 1; $i <= 5; $i++) {
            $payload["conv{$i}"] = [
                'unit_name' => $g("Konv{$i}_NamaUnit"),
                'factor'    => $toInt($g("Konv{$i}_Faktor")),
                'barcode'   => $g("Konv{$i}_Barcode"),
                'price'     => $toInt($g("Konv{$i}_Harga")),
            ];
        }

        return $payload;
    }
}

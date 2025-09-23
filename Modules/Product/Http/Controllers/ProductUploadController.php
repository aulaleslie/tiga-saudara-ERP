<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
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
            'file'        => 'required|mimes:csv,txt',
            'location_id' => 'required|exists:locations,id',
        ]);

        // 1) Save CSV
        $path = $request->file('file')->store('imports/products');
        $fullPath = Storage::path($path);

        // 2) Create a batch
        $batch = ProductImportBatch::create([
            'user_id'         => auth()->id(),
            'location_id'     => (int) $request->input('location_id'),
            'source_csv_path' => $path,
            'file_sha256'     => hash_file('sha256', $fullPath),
            'status'          => 'queued',
            'undo_token'      => Str::random(40),
        ]);

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
            'sku'                => 'Kode Produk',

            'barcode'            => 'Barcode',

            'nama kategori'      => 'Nama Kategori',
            'kategori'           => 'Nama Kategori',

            'nama merek'         => 'Nama Merek',
            'merek'              => 'Nama Merek',
            'brand'              => 'Nama Merek',

            'kelola stok'        => 'Kelola Stok',

            'wajib nomor seri'   => 'Wajib Nomor Seri',
            'wajib no seri'      => 'Wajib Nomor Seri',

            'nama unit dasar'    => 'Nama Unit Dasar',
            'unit'               => 'Nama Unit Dasar',

            'stok'               => 'Stok',
            'stok minimum'       => 'Stok Minimum',

            'dibeli'             => 'Dibeli',
            'harga beli'         => 'Harga Beli',
            'nama pajak beli'    => 'Nama Pajak Beli',

            'dijual'             => 'Dijual',
            'harga jual'         => 'Harga Jual',
            'harga tier 1'       => 'Harga Tier 1',
            'harga tier 2'       => 'Harga Tier 2',
            'nama pajak jual'    => 'Nama Pajak Jual',

            // optional conversion blocks
            'konv1_namaunit'     => 'Konv1_NamaUnit',
            'konv1_faktor'       => 'Konv1_Faktor',
            'konv1_barcode'      => 'Konv1_Barcode',
            'konv1_harga'        => 'Konv1_Harga',
            'konv2_namaunit'     => 'Konv2_NamaUnit',
            'konv2_faktor'       => 'Konv2_Faktor',
            'konv2_barcode'      => 'Konv2_Barcode',
            'konv2_harga'        => 'Konv2_Harga',
            'konv3_namaunit'     => 'Konv3_NamaUnit',
            'konv3_faktor'       => 'Konv3_Faktor',
            'konv3_barcode'      => 'Konv3_Barcode',
            'konv3_harga'        => 'Konv3_Harga',
            'konv4_namaunit'     => 'Konv4_NamaUnit',
            'konv4_faktor'       => 'Konv4_Faktor',
            'konv4_barcode'      => 'Konv4_Barcode',
            'konv4_harga'        => 'Konv4_Harga',
            'konv5_namaunit'     => 'Konv5_NamaUnit',
            'konv5_faktor'       => 'Konv5_Faktor',
            'konv5_barcode'      => 'Konv5_Barcode',
            'konv5_harga'        => 'Konv5_Harga',
        ];

        // Build canonical => actual header map
        $headerMap = [];
        foreach ($normHeaders as $i => $norm) {
            if (isset($aliases[$norm])) {
                $headerMap[$aliases[$norm]] = $rawHeaders[$i];
            }
        }

        // Required columns (adjust if you need more/less strict)
        $required = ['Nama Produk', 'Nama Kategori', 'Nama Unit Dasar', 'Stok', 'Harga Jual', 'Harga Beli'];
        $missing = array_values(array_diff($required, array_keys($headerMap)));
        if (!empty($missing)) {
            return back()->withErrors([
                'file' => 'CSV header mismatch. Missing columns: ' . implode(', ', $missing)
                    . '. Make sure your header matches the template (aliases are accepted).',
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

        $batch->update(['total_rows' => $rowNo, 'status' => 'validating']);

        // 5) Queue processing
        dispatch(new ProcessProductImportBatch($batch->id));

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

        // Helpers
        $toBool = function ($v): int {
            return in_array(strtolower((string) $v), ['1','true','ya','yes','y'], true) ? 1 : 0;
        };
        $toInt = function ($v): int {
            // keep digits, dot, minus; parse float; round to int (IDR)
            $s = preg_replace('/[^\d.\-]/', '', (string) $v);
            if ($s === '' || $s === '.' || $s === '-' || $s === '-.' ) {
                return 0;
            }
            return (int) $s;
        };

        $payload = [
            'product_name'      => $get('Nama Produk'),
            'product_code'      => $get('Kode Produk'),
            'barcode'           => $get('Barcode'),
            'category_name'     => $get('Nama Kategori'),
            'brand_name'        => $get('Nama Merek'),

            'stock_managed'     => $toBool($get('Kelola Stok')),        // if header absent → 0
            'serial_required'   => $toBool($get('Wajib Nomor Seri')),
            'base_unit_name'    => $get('Nama Unit Dasar'),
            'stock_qty'         => $toInt($get('Stok')),
            'min_stock'         => $toInt($get('Stok Minimum')),

            'is_purchased'      => $toBool($get('Dibeli')),
            'purchase_price'    => $toInt($get('Harga Beli')),
            'purchase_tax_name' => $get('Nama Pajak Beli'),

            'is_sold'           => $toBool($get('Dijual')),
            'sale_price'        => $toInt($get('Harga Jual')),
            'tier_1_price'      => $toInt($get('Harga Tier 1')),
            'tier_2_price'      => $toInt($get('Harga Tier 2')),
            'sale_tax_name'     => $get('Nama Pajak Jual'),
        ];

        // Optional conversion blocks conv1..conv5
        for ($i = 1; $i <= 5; $i++) {
            $payload["conv{$i}"] = [
                'unit_name' => $get("Konv{$i}_NamaUnit"),
                'factor'    => $toInt($get("Konv{$i}_Faktor")),
                'barcode'   => $get("Konv{$i}_Barcode"),
                'price'     => $toInt($get("Konv{$i}_Harga")),
            ];
        }

        // Sensible defaults if some optional columns are missing
        if (!isset($headerMap['Kelola Stok'])) {
            $payload['stock_managed'] = 1; // default manage stock if column not provided
        }

        return $payload;
    }
}

<?php

namespace Modules\Product\Jobs;

use App\Support\AccurateDecimalParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Services\TigaNusaPriceExportService;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Throwable;

/**
 * Round-trip processor for the `product:export-tiga-nusa-prices` workbook.
 *
 * Updates only the selling tiers (sale/tier 1/tier 2) of existing company-scoped
 * `product_prices` rows. It never creates products or price rows and never touches
 * stock, purchase costs, taxes, bundles, or conversion prices.
 */
class ProcessDualCompanyTierPriceBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public const HEADER_ROW = 4;

    public const REQUIRED_SHEETS = [
        TigaNusaPriceExportService::TIGA_NUSA_COMPANY_NAME,
        TigaNusaPriceExportService::TOP_IT_COMPANY_NAME,
    ];

    /** Header label => raw_json key. */
    private const REQUIRED_HEADERS = [
        'nama produk'  => 'nama_produk',
        'harga jual'   => 'harga_jual',
        'harga tier 1' => 'harga_tier_1',
        'harga tier 2' => 'harga_tier_2',
    ];

    /** raw_json key => product_prices column. */
    public const TIER_COLUMNS = [
        'harga_jual'   => 'sale_price',
        'harga_tier_1' => 'tier_1_price',
        'harga_tier_2' => 'tier_2_price',
    ];

    private const MAX_PRICE = 99999999.99;

    public function __construct(public int $batchId) {}

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        Log::info('[DualCompanyTierPriceImport] Job started', ['batch_id' => $this->batchId]);

        $batch = ProductImportBatch::findOrFail($this->batchId);
        $batch->update(['status' => 'processing']);

        try {
            if (ProductImportRow::where('batch_id', $batch->id)->count() === 0) {
                if ($this->stageRowsFromXlsx($batch) === null) {
                    return; // Batch failed during structure validation; nothing was mutated.
                }
            }

            $this->processRows($batch);
        } catch (Throwable $e) {
            Log::error('[DualCompanyTierPriceImport] Job failed', ['batch_id' => $this->batchId, 'error' => $e->getMessage()]);
            $batch->update(['status' => 'failed', 'error_message' => Str::limit($e->getMessage(), 1000)]);
            throw $e;
        }

        $batch->update(['status' => 'completed', 'completed_at' => now()]);

        Log::info('[DualCompanyTierPriceImport] Job completed', ['batch_id' => $this->batchId]);
    }

    /**
     * Validate the whole workbook structure, then stage every data row of both
     * worksheets with its worksheet-derived company target.
     */
    private function stageRowsFromXlsx(ProductImportBatch $batch): ?int
    {
        $fullPath = storage_path('app/' . $batch->source_csv_path);

        if (!file_exists($fullPath)) {
            return $this->failBatch($batch, 'XLSX file not found.');
        }

        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($fullPath);
        } catch (Throwable $e) {
            return $this->failBatch($batch, 'Failed to read XLSX file: ' . $e->getMessage());
        }

        $sheetNames = $spreadsheet->getSheetNames();

        foreach (self::REQUIRED_SHEETS as $required) {
            $occurrences = count(array_filter($sheetNames, fn ($name) => trim((string) $name) === $required));
            if ($occurrences === 0) {
                return $this->failBatch($batch, "Missing required worksheet: {$required}.");
            }
            if ($occurrences > 1) {
                return $this->failBatch($batch, "Duplicated required worksheet: {$required}.");
            }
        }

        $unexpected = array_values(array_filter(
            $sheetNames,
            fn ($name) => !in_array(trim((string) $name), self::REQUIRED_SHEETS, true)
        ));
        if (!empty($unexpected)) {
            return $this->failBatch($batch, 'Unexpected worksheet(s) in workbook: ' . implode(', ', $unexpected) . '.');
        }

        // Validate every header row before staging anything, so a bad second sheet
        // cannot leave the first sheet's rows staged.
        $headerMaps = [];
        foreach (self::REQUIRED_SHEETS as $sheetName) {
            $worksheet = $spreadsheet->getSheetByName($sheetName);
            $headerMap = $this->buildHeaderMap($worksheet);

            $missing = array_diff(array_values(self::REQUIRED_HEADERS), array_keys($headerMap));
            if (!empty($missing)) {
                $labels = array_map(
                    fn (string $label) => ucwords($label),
                    array_keys(array_intersect(self::REQUIRED_HEADERS, $missing))
                );
                return $this->failBatch(
                    $batch,
                    "Worksheet \"{$sheetName}\" is missing required header(s) on row " . self::HEADER_ROW . ': ' . implode(', ', $labels) . '.'
                );
            }

            $headerMaps[$sheetName] = $headerMap;
        }

        $insertBuffer = [];
        $bufferSize = 500;
        $staged = 0;
        $rowNumber = 0;

        foreach (self::REQUIRED_SHEETS as $sheetName) {
            $worksheet = $spreadsheet->getSheetByName($sheetName);
            $headerMap = $headerMaps[$sheetName];
            $highestRow = $worksheet->getHighestDataRow();

            for ($excelRow = self::HEADER_ROW + 1; $excelRow <= $highestRow; $excelRow++) {
                $payload = ['worksheet' => $sheetName, 'source_row' => $excelRow];
                foreach ($headerMap as $key => $columnIndex) {
                    $payload[$key] = trim((string) $worksheet->getCellByColumnAndRow($columnIndex, $excelRow)->getValue());
                }

                // Entirely empty spreadsheet rows are structural padding, not data.
                if ($payload['nama_produk'] === ''
                    && $payload['harga_jual'] === ''
                    && $payload['harga_tier_1'] === ''
                    && $payload['harga_tier_2'] === '') {
                    continue;
                }

                $rowNumber++;
                $insertBuffer[] = [
                    'batch_id'   => $batch->id,
                    'row_number' => $rowNumber,
                    'raw_json'   => json_encode($payload),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $staged++;

                if (count($insertBuffer) >= $bufferSize) {
                    DB::table('product_import_rows')->insert($insertBuffer);
                    $insertBuffer = [];
                }
            }
        }

        if (!empty($insertBuffer)) {
            DB::table('product_import_rows')->insert($insertBuffer);
        }

        $batch->update(['total_rows' => $staged]);

        Log::info('[DualCompanyTierPriceImport] Rows staged', ['batch_id' => $batch->id, 'total_rows' => $staged]);

        return $staged;
    }

    /**
     * Map required header labels on the header row to their 1-based column index.
     *
     * @return array<string, int> raw_json key => column index
     */
    private function buildHeaderMap($worksheet): array
    {
        $map = [];
        $highestColumn = $worksheet->getHighestDataColumn(self::HEADER_ROW);
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = (string) $worksheet->getCellByColumnAndRow($col, self::HEADER_ROW)->getValue();
            $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/^\xEF\xBB\xBF/', '', $value))));

            if (isset(self::REQUIRED_HEADERS[$normalized]) && !isset($map[self::REQUIRED_HEADERS[$normalized]])) {
                $map[self::REQUIRED_HEADERS[$normalized]] = $col;
            }
        }

        return $map;
    }

    /**
     * Resolve worksheet settings and product matches, parse tiers, then apply mutations.
     */
    private function processRows(ProductImportBatch $batch): void
    {
        $exportService = new TigaNusaPriceExportService();

        $settings = [];
        foreach (self::REQUIRED_SHEETS as $sheetName) {
            try {
                $settings[$sheetName] = $exportService->resolveSettingByCompanyName($sheetName);
            } catch (Throwable $e) {
                $settings[$sheetName] = null;
                Log::warning('[DualCompanyTierPriceImport] Setting resolution failed', [
                    'batch_id' => $batch->id,
                    'worksheet' => $sheetName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $resolver = app(\Modules\Product\Services\ProductResolver::class);
        $resolvedCache = [];

        $validRows = [];

        ProductImportRow::where('batch_id', $batch->id)
            ->whereNull('status')
            ->chunkById(500, function ($rows) use ($batch, $settings, $resolver, &$resolvedCache, &$validRows) {
                foreach ($rows as $row) {
                    $payload = is_array($row->raw_json) ? $row->raw_json : json_decode($row->raw_json, true);

                    $sheetName = $payload['worksheet'] ?? '';
                    $rawName = (string) ($payload['nama_produk'] ?? '');

                    $meta = [
                        'worksheet' => $sheetName,
                        'company_name' => $sheetName,
                        'source_row' => $payload['source_row'] ?? null,
                        'raw_product_name' => $rawName,
                        'raw_tiers' => [
                            'harga_jual' => $payload['harga_jual'] ?? null,
                            'harga_tier_1' => $payload['harga_tier_1'] ?? null,
                            'harga_tier_2' => $payload['harga_tier_2'] ?? null,
                        ],
                    ];

                    $setting = $settings[$sheetName] ?? null;
                    if (!$setting) {
                        $this->recordFailure($batch, $row, "No unique setting found for worksheet company \"{$sheetName}\".", 'error', $meta);
                        continue;
                    }
                    $meta['setting_id'] = $setting->id;

                    // Resolve the product by canonical identity; exactly one match is required.
                    if (trim($rawName) === '') {
                        $this->recordFailure($batch, $row, 'Product name is blank.', 'error', $meta);
                        continue;
                    }

                    if (!array_key_exists($rawName, $resolvedCache)) {
                        try {
                            $resolvedCache[$rawName] = $resolver->resolveExisting($rawName);
                        } catch (\Modules\Product\Exceptions\AmbiguousProductResolutionException $e) {
                            $resolvedCache[$rawName] = $e;
                        }
                    }

                    $product = $resolvedCache[$rawName];

                    if ($product instanceof \Exception) {
                        $this->recordFailure(
                            $batch,
                            $row,
                            'Ambiguous product name: ' . $product->getMessage(),
                            'error',
                            $meta
                        );
                        continue;
                    }

                    if (!$product) {
                        $this->recordFailure($batch, $row, "Product not found for name \"{$rawName}\".", 'skipped', $meta);
                        continue;
                    }

                    $meta['match_strategy'] = 'canonical_identity';
                    $meta['matched_product_id'] = $product->id;
                    $meta['matched_product_name'] = $product->product_name;

                    // Parse each selling tier independently: blank = no change, zero = explicit.
                    $supplied = [];
                    $invalid = null;
                    foreach (self::TIER_COLUMNS as $key => $column) {
                        $raw = $payload[$key] ?? null;
                        if ($raw === null || trim((string) $raw) === '') {
                            continue;
                        }

                        $parsed = AccurateDecimalParser::parse($raw);
                        if ($parsed === null) {
                            $invalid = "Invalid value for {$this->headerLabel($key)}: \"{$raw}\".";
                            break;
                        }
                        if ($parsed < 0) {
                            $invalid = "Negative value for {$this->headerLabel($key)}: \"{$raw}\".";
                            break;
                        }
                        if ($parsed > self::MAX_PRICE) {
                            $invalid = "{$this->headerLabel($key)} exceeds maximum limit (99,999,999.99).";
                            break;
                        }

                        $supplied[$column] = $parsed;
                    }

                    if ($invalid !== null) {
                        $this->recordFailure($batch, $row, $invalid, 'error', $meta);
                        continue;
                    }

                    if (empty($supplied)) {
                        $this->recordFailure($batch, $row, 'No selling tier supplied; all price cells are blank.', 'skipped', $meta);
                        continue;
                    }

                    $meta['supplied_tiers'] = $supplied;

                    $row->product_id = $product->id;
                    $row->result_metadata = $meta;
                    $validRows[] = $row;
                }
            });

        $this->applyMutations($batch, $validRows);
    }

    /**
     * Group rows by (product, setting) target, reject conflicting duplicates, then
     * apply each target's supplied tiers in a single transaction.
     */
    private function applyMutations(ProductImportBatch $batch, array $validRows): void
    {
        $groups = [];
        foreach ($validRows as $row) {
            $key = $row->product_id . '_' . $row->result_metadata['setting_id'];
            $groups[$key][] = $row;
        }

        foreach ($groups as $groupRows) {
            $firstRow = $groupRows[0];
            $productId = $firstRow->product_id;
            $settingId = $firstRow->result_metadata['setting_id'];

            // Duplicates conflict when they supply different values for a commonly supplied tier.
            $conflict = false;
            $merged = $firstRow->result_metadata['supplied_tiers'];
            foreach (array_slice($groupRows, 1) as $row) {
                foreach ($row->result_metadata['supplied_tiers'] as $column => $value) {
                    if (array_key_exists($column, $merged) && (float) $merged[$column] !== (float) $value) {
                        $conflict = true;
                        break 2;
                    }
                    $merged[$column] = $value;
                }
            }

            if ($conflict) {
                foreach ($groupRows as $row) {
                    $this->recordFailure(
                        $batch,
                        $row,
                        'Conflicting duplicate selling-tier values for the same product and company.',
                        'error',
                        $row->result_metadata ?? []
                    );
                }
                continue;
            }

            $productPrice = ProductPrice::where('product_id', $productId)
                ->where('setting_id', $settingId)
                ->first();

            if (!$productPrice) {
                foreach ($groupRows as $row) {
                    $this->recordFailure(
                        $batch,
                        $row,
                        'No existing price row for this product and company; import does not create price rows.',
                        'skipped',
                        $row->result_metadata ?? []
                    );
                }
                continue;
            }

            $previousTiers = [
                'sale_price'   => $productPrice->sale_price,
                'tier_1_price' => $productPrice->tier_1_price,
                'tier_2_price' => $productPrice->tier_2_price,
            ];

            try {
                DB::transaction(function () use ($productPrice, $merged) {
                    foreach ($merged as $column => $value) {
                        $productPrice->{$column} = $value;
                    }
                    $productPrice->save();
                });
            } catch (Throwable $e) {
                Log::error('[DualCompanyTierPriceImport] Price mutation failed', [
                    'batch_id' => $batch->id,
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'error' => $e->getMessage(),
                ]);

                foreach ($groupRows as $row) {
                    $this->recordFailure(
                        $batch,
                        $row,
                        'Database error during price update: ' . Str::limit($e->getMessage(), 500),
                        'error',
                        $row->result_metadata ?? []
                    );
                }
                continue;
            }

            $resultingTiers = [
                'sale_price'   => $productPrice->sale_price,
                'tier_1_price' => $productPrice->tier_1_price,
                'tier_2_price' => $productPrice->tier_2_price,
            ];

            $changed = false;
            foreach ($resultingTiers as $column => $value) {
                if ((float) $value !== (float) $previousTiers[$column]) {
                    $changed = true;
                    break;
                }
            }

            $isFirst = true;
            foreach ($groupRows as $row) {
                $meta = $row->result_metadata ?? [];
                $meta['applied_tiers'] = $merged;
                $meta['previous_tiers'] = $previousTiers;
                $meta['resulting_tiers'] = $resultingTiers;
                $meta['price_changed'] = $changed;

                $status = $isFirst ? 'imported' : 'skipped';
                if (!$isFirst) {
                    $meta['outcome'] = 'duplicate';
                }

                $row->forceFill([
                    'status' => $status,
                    'result_metadata' => $meta,
                    'error_message' => $isFirst ? null : 'Equivalent duplicate row.',
                ])->save();

                $batch->increment('processed_rows');
                if ($isFirst) {
                    $batch->increment('success_rows');
                }
                $isFirst = false;
            }
        }
    }

    private function headerLabel(string $key): string
    {
        $labels = array_flip(self::REQUIRED_HEADERS);
        return ucwords($labels[$key] ?? $key);
    }

    private function normalizeName(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
    }

    private function failBatch(ProductImportBatch $batch, string $message): ?int
    {
        Log::error('[DualCompanyTierPriceImport] ' . $message, ['batch_id' => $batch->id]);
        $batch->update(['status' => 'failed', 'error_message' => $message]);
        return null;
    }

    private function recordFailure(
        ProductImportBatch $batch,
        ProductImportRow $row,
        string $message,
        string $status = 'error',
        array $meta = []
    ): void {
        $row->forceFill([
            'status' => $status,
            'error_message' => $message,
            'result_metadata' => $meta,
        ])->save();

        $batch->increment('processed_rows');
        if ($status === 'error') {
            $batch->increment('error_rows');
        }
    }
}

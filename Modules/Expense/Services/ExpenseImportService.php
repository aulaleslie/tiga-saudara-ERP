<?php

namespace Modules\Expense\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Expense\Entities\ExpenseDetail;
use Modules\Expense\Entities\ExpenseImportBatch;
use Modules\Expense\Entities\ExpenseImportRow;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;

class ExpenseImportService
{
    protected array $suppliersCache = [];
    protected array $categoriesCache = [];

    /**
     * Get the target setting for Expense Import.
     * Must be exactly one CV Tiga Nusa Computer setting.
     */
    protected function getTargetSetting(): Setting
    {
        $settings = Setting::where('company_name', 'LIKE', '%CV Tiga Nusa Computer%')->get();
        if ($settings->count() !== 1) {
            throw new \Exception('Could not resolve exactly one CV Tiga Nusa Computer setting. Found: ' . $settings->count());
        }
        return $settings->first();
    }

        /**
     * Process the entire batch of imported rows.
     */
    public function processBatch(ExpenseImportBatch $batch): void
    {
        $batch->update(['status' => ExpenseImportBatch::STATUS_PROCESSING]);
        
        try {
            $setting = $this->getTargetSetting();
            
            $rows = $batch->pendingRows()->orderBy('row_number')->get();
            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;

            $groups = [];
            foreach ($rows as $row) {
                $nomor = trim($row->raw_json['nomor'] ?? '');
                $groups[$nomor][] = $row;
            }

            foreach ($groups as $nomor => $groupRows) {
                try {
                    DB::transaction(function () use ($groupRows, $setting, &$successCount, $nomor) {
                        $this->processGroup($groupRows, $setting, $nomor);
                        $successCount += count($groupRows);
                    });
                } catch (\Exception $e) {
                    // A duplicate Nomor is a skip, not an error.
                    if ($e->getMessage() === 'Duplicate imported_expense_number') {
                        foreach ($groupRows as $row) {
                            $row->update([
                                'status' => ExpenseImportRow::STATUS_SKIPPED,
                                'error_message' => 'Duplicate Nomor',
                            ]);
                        }
                        $skippedCount += count($groupRows);
                    } else {
                        foreach ($groupRows as $row) {
                            $row->update([
                                'status' => ExpenseImportRow::STATUS_INVALID,
                                'error_message' => $e->getMessage(),
                            ]);
                        }
                        $errorCount += count($groupRows);
                    }
                }
            }

            $batch->update([
                'status' => ExpenseImportBatch::STATUS_COMPLETED,
                'processed_rows' => $rows->count(),
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'skipped_count' => $skippedCount,
            ]);

        } catch (\Exception $e) {
            $batch->update([
                'status' => ExpenseImportBatch::STATUS_FAILED,
                'error_count' => $batch->total_rows,
            ]);
            Log::error('[ExpenseImport] Batch failed', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    protected function processGroup(array $groupRows, Setting $setting, string $nomor): void
    {
        if (empty($nomor)) {
            throw new \Exception('Missing nomor');
        }

        $parsedRows = [];
        $totalJumlah = 0.0;

        foreach ($groupRows as $row) {
            $data = $row->raw_json;

            $transaksi = trim($data['transaksi'] ?? '');
            $status = strtolower(trim($data['status'] ?? ''));
            $sisaTagihan = $this->parseAmount($data['sisa_tagihan'] ?? '0', 'Sisa Tagihan');
            $jumlah = $this->parseAmount($data['jumlah'] ?? '0', 'Jumlah');
            $tax = $this->parseAmount($data['tax'] ?? '0', 'Tax');
            $tanggalStr = trim($data['tanggal'] ?? '');
            
            if ($transaksi !== 'Expense') {
                throw new \Exception('Invalid transaction type: ' . $transaksi);
            }
            if ($status !== 'paid') {
                throw new \Exception('Expense must be Paid');
            }
            if ($sisaTagihan != 0) {
                throw new \Exception('Sisa Tagihan must be zero');
            }
            if ($jumlah <= 0) {
                throw new \Exception('Jumlah must be greater than zero');
            }
            if (empty($tanggalStr)) {
                throw new \Exception('Missing tanggal');
            }
            
            try {
                $tanggal = Carbon::createFromFormat('d/m/Y', $tanggalStr);
            } catch (\Exception $e) {
                $tanggal = null;
            }
            $errors = Carbon::getLastErrors();
            if (
                $tanggal === null
                || $errors['error_count'] > 0
                || $errors['warning_count'] > 0
                || $tanggal->format('d/m/Y') !== $tanggalStr
            ) {
                throw new \Exception('Invalid date format: ' . $tanggalStr);
            }

            $parsedRows[] = [
                'row' => $row,
                'data' => $data,
                'tanggal' => $tanggal,
                'jumlah' => $jumlah,
            ];
            
            $totalJumlah += $jumlah;
        }

        $firstRowData = $parsedRows[0];
        $firstData = $firstRowData['data'];
        $kategori = trim($firstData['kategori'] ?? '');
        $supplierName = trim($firstData['supplier'] ?? '');

        if (empty($kategori)) {
            throw new \Exception('Missing kategori');
        }

        if (empty($supplierName)) {
            $supplierName = $kategori;
        }

        $existing = Expense::where('setting_id', $setting->id)
            ->where('imported_expense_number', $nomor)
            ->first();

        if ($existing) {
            throw new \Exception('Duplicate imported_expense_number');
        }

        $category = $this->findOrCreateCategory($kategori, $setting->id);
        $supplier = $this->findOrCreateSupplier($supplierName, $setting->id);

        $deskripsi = trim((string) ($firstData['deskripsi'] ?? ''));
        $expenseDetails = filled($deskripsi) ? $deskripsi : "Imported Expense {$nomor}";

        $expense = Expense::create([
            'date' => $firstRowData['tanggal']->format('Y-m-d'),
            'reference' => '', // Will be auto-generated
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'amount' => $totalJumlah,
            'details' => $expenseDetails,
            'status' => Expense::STATUS_APPROVED,
            'setting_id' => $setting->id,
            'imported_expense_number' => $nomor,
            'is_tax_included' => false,
        ]);

        foreach ($parsedRows as $parsed) {
            $rowDesc = trim((string) ($parsed['data']['deskripsi'] ?? ''));
            $rowKategori = trim($parsed['data']['kategori'] ?? '');
            
            $detailName = filled($rowDesc) ? $rowDesc : $rowKategori;
            if (empty($detailName)) {
                $detailName = $kategori; // fallback to header category
            }

            ExpenseDetail::create([
                'expense_id' => $expense->id,
                'amount' => $parsed['jumlah'],
                'name' => $detailName,
            ]);

            $parsed['row']->update([
                'status' => ExpenseImportRow::STATUS_PROCESSED,
                'expense_id' => $expense->id,
            ]);
        }
    }

    protected function findOrCreateCategory(string $name, int $settingId): ExpenseCategory
    {
        $normalizedName = strtolower(trim($name));
        $cacheKey = "{$settingId}_{$normalizedName}";

        if (isset($this->categoriesCache[$cacheKey])) {
            return $this->categoriesCache[$cacheKey];
        }

        $category = ExpenseCategory::where('setting_id', $settingId)
            ->whereRaw('LOWER(category_name) = ?', [$normalizedName])
            ->first();

        if (!$category) {
            $category = ExpenseCategory::create([
                'category_name' => trim($name),
                'category_description' => 'Imported category',
                'setting_id' => $settingId,
            ]);
        }

        $this->categoriesCache[$cacheKey] = $category;
        return $category;
    }

    protected function findOrCreateSupplier(string $name, int $settingId): Supplier
    {
        $normalizedName = strtolower(trim($name));
        $cacheKey = "{$settingId}_{$normalizedName}";

        if (isset($this->suppliersCache[$cacheKey])) {
            return $this->suppliersCache[$cacheKey];
        }

        $supplier = Supplier::where('setting_id', $settingId)
            ->whereRaw('LOWER(supplier_name) = ?', [$normalizedName])
            ->first();

        if (!$supplier) {
            $supplier = Supplier::create([
                'supplier_name' => trim($name),
                'supplier_email' => 'imported@example.com',
                'supplier_phone' => '000000000',
                'contact_name' => 'Imported Supplier',
                'address' => 'Imported Address',
                'city' => 'Imported City',
                'country' => 'Indonesia',
                'setting_id' => $settingId,
            ]);
        }

        $this->suppliersCache[$cacheKey] = $supplier;
        return $supplier;
    }

    protected function parseAmount(mixed $value, string $field = 'amount'): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }
        $normalized = str_replace(',', '', trim((string) $value));
        if (!is_numeric($normalized)) {
            throw new \Exception("Unparseable {$field} value: " . $value);
        }
        return (float) $normalized;
    }
}

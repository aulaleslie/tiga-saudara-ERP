<?php

namespace Modules\Expense\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Expense\Entities\ExpenseDetail;
use Modules\Expense\Entities\ExpenseImportBatch;
use Modules\Expense\Entities\ExpenseImportRow;
use Modules\Expense\Jobs\StageExpenseImportRows;
use Modules\Expense\Services\ExpenseImportService;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Tests\TestCase;

class ExpenseImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Permission::create(['name' => 'expenses.import']);
        \Spatie\Permission\Models\Permission::create(['name' => 'expenses.access']);
        
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['expenses.import', 'expenses.access']);

        // Clear existing settings to avoid conflicts
        Setting::query()->delete();

        $this->setting = Setting::create([
            'company_name' => 'CV Tiga Nusa Computer',
            'company_email' => 'tiganusa@example.com',
            'company_phone' => '123456789',
            'site_logo' => 'logo.png',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);
    }

    public function test_upload_controller_dispatches_job_and_creates_batch()
    {
        Queue::fake();

        $csvContent = "tanggal,transaksi,nomor,kategori,deskripsi,supplier,jumlah,tax,status,sisa_tagihan\n";
        $csvContent .= "19/03/2020,Expense,EXP-001,Biaya Listrik,Pembayaran Listrik,PLN,100000,0,Paid,0";

        $file = UploadedFile::fake()->createWithContent('pengeluaran.csv', $csvContent);

        $response = $this->actingAs($this->admin)->post(route('expenses.imports.upload'), [
            'file' => $file
        ]);

        $batch = ExpenseImportBatch::first();
        $response->assertRedirect(route('expenses.imports.show', $batch));
        $this->assertEquals($this->admin->id, $batch->user_id);

        Queue::assertPushed(StageExpenseImportRows::class, function ($job) use ($batch) {
            return $job->batchId === $batch->id;
        });
    }

    public function test_expense_import_service_processes_valid_row()
    {
        $batch = ExpenseImportBatch::create([
            'status' => ExpenseImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'user_id' => $this->admin->id,
        ]);

        $row = ExpenseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'tanggal' => '19/03/2020',
                'transaksi' => 'Expense',
                'nomor' => 'EXP-001',
                'kategori' => 'Biaya Listrik',
                'deskripsi' => 'Pembayaran Listrik',
                'supplier' => 'PLN',
                'jumlah' => '100000',
                'tax' => '0',
                'status' => 'Paid',
                'sisa_tagihan' => '0',
            ],
            'status' => ExpenseImportRow::STATUS_PENDING,
        ]);

        $service = new ExpenseImportService();
        $service->processBatch($batch);

        $row->refresh();
        $batch->refresh();

        if ($row->status === 'invalid') {
            dump($row->error_message);
        }

        $this->assertEquals(ExpenseImportRow::STATUS_PROCESSED, $row->status);
        $this->assertNotNull($row->expense_id);
        $this->assertEquals(1, $batch->success_count);
        $this->assertEquals(0, $batch->error_count);

        $expense = Expense::find($row->expense_id);
        // $expense->date is already cast to "d M, Y" due to accessor, let's check native value using getAttributes()
        $this->assertEquals('2020-03-19 00:00:00', $expense->getAttributes()['date']);
        
        // Let's just check the DB value natively.
        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'amount' => 10000000,
            'imported_expense_number' => 'EXP-001',
            'setting_id' => $this->setting->id,
            'status' => Expense::STATUS_APPROVED,
        ]);

        $this->assertDatabaseHas('expense_details', [
            'expense_id' => $expense->id,
            'amount' => 100000,
            'name' => 'PEMBAYARAN LISTRIK',
        ]);

        $this->assertDatabaseHas('expense_categories', [
            'category_name' => 'BIAYA LISTRIK',
            'setting_id' => $this->setting->id,
        ]);

        $this->assertDatabaseHas('suppliers', [
            'supplier_name' => 'PLN',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_expense_import_service_skips_duplicate_nomor()
    {
        $cat = ExpenseCategory::create([
            'category_name' => 'Test',
            'setting_id' => $this->setting->id,
        ]);
        
        $sup = Supplier::create([
            'supplier_name' => 'Test',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '123456',
            'contact_name' => 'Test',
            'address' => 'Test',
            'city' => 'Test',
            'country' => 'Test',
            'setting_id' => $this->setting->id,
        ]);

        Expense::create([
            'date' => '2020-03-19',
            'reference' => 'REF-001',
            'category_id' => $cat->id,
            'supplier_id' => $sup->id,
            'amount' => 100000,
            'details' => 'Test',
            'status' => Expense::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'imported_expense_number' => 'EXP-001',
            'is_tax_included' => false,
        ]);

        $batch = ExpenseImportBatch::create([
            'status' => ExpenseImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'user_id' => $this->admin->id,
        ]);

        $row = ExpenseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'tanggal' => '19/03/2020',
                'transaksi' => 'Expense',
                'nomor' => 'EXP-001',
                'kategori' => 'Biaya Listrik',
                'deskripsi' => 'Pembayaran Listrik',
                'supplier' => 'PLN',
                'jumlah' => '100000',
                'tax' => '0',
                'status' => 'Paid',
                'sisa_tagihan' => '0',
            ],
            'status' => ExpenseImportRow::STATUS_PENDING,
        ]);

        $service = new ExpenseImportService();
        $service->processBatch($batch);

        $row->refresh();
        $batch->refresh();

        $this->assertEquals(ExpenseImportRow::STATUS_SKIPPED, $row->status);
        $this->assertEquals('Duplicate Nomor', $row->error_message);
        $this->assertEquals(0, $batch->success_count);
        $this->assertEquals(0, $batch->error_count); // Skipped duplicates are not errors.
        $this->assertEquals(1, $batch->skipped_count); // They are counted as skipped at batch level.
    }

        /**
     * Stage multiple rows from raw_json payloads and process the batch.
     */
    protected function processMultipleRows(array $rowsRawJson): array
    {
        $batch = ExpenseImportBatch::create([
            'status' => ExpenseImportBatch::STATUS_QUEUED,
            'total_rows' => count($rowsRawJson),
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'user_id' => $this->admin->id,
        ]);

        $rows = [];
        $rowNumber = 2;
        foreach ($rowsRawJson as $rawJson) {
            $rows[] = ExpenseImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $rowNumber++,
                'raw_json' => $rawJson,
                'status' => ExpenseImportRow::STATUS_PENDING,
            ]);
        }

        (new ExpenseImportService())->processBatch($batch);

        $freshRows = [];
        foreach ($rows as $row) {
            $freshRows[] = $row->fresh();
        }

        return [$batch->fresh(), $freshRows];
    }

    /**
     * Helper to process a single row backward compatibility.
     */
    protected function processSingleRow(array $rawJson): array
    {
        [$batch, $rows] = $this->processMultipleRows([$rawJson]);
        return [$batch, $rows[0]];
    }

    protected function validRawJson(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '19/03/2020',
            'transaksi' => 'Expense',
            'nomor' => 'EXP-001',
            'kategori' => 'Biaya Listrik',
            'deskripsi' => 'Pembayaran Listrik',
            'supplier' => 'PLN',
            'jumlah' => '100000',
            'tax' => '0',
            'status' => 'Paid',
            'sisa_tagihan' => '0',
        ], $overrides);
    }

    public function test_group_expense_import_creates_one_expense_with_multiple_details()
    {
        [$batch, $rows] = $this->processMultipleRows([
            $this->validRawJson([
                'nomor' => 'EXP-002',
                'deskripsi' => 'Row 1',
                'jumlah' => '10000',
            ]),
            $this->validRawJson([
                'nomor' => 'EXP-002',
                'deskripsi' => 'Row 2',
                'jumlah' => '20000',
            ])
        ]);

        $this->assertEquals(ExpenseImportRow::STATUS_PROCESSED, $rows[0]->status);
        $this->assertEquals(ExpenseImportRow::STATUS_PROCESSED, $rows[1]->status);
        
        $this->assertEquals($rows[0]->expense_id, $rows[1]->expense_id);
        
        $expense = Expense::find($rows[0]->expense_id);
        $this->assertEquals(30000, $expense->amount); // 10k + 20k
        $this->assertEquals(2, $expense->detailRows()->count());
        
        $this->assertEquals(2, $batch->success_count);
        $this->assertEquals(0, $batch->error_count);
    }

    public function test_first_row_determines_header_fields()
    {
        [$batch, $rows] = $this->processMultipleRows([
            $this->validRawJson([
                'nomor' => 'EXP-003',
                'tanggal' => '10/01/2026',
                'kategori' => 'Cat 1',
                'supplier' => 'Sup 1',
                'deskripsi' => 'Row 1',
                'jumlah' => '10000',
            ]),
            $this->validRawJson([
                'nomor' => 'EXP-003',
                'tanggal' => '11/01/2026',
                'kategori' => 'Cat 2',
                'supplier' => 'Sup 2',
                'deskripsi' => 'Row 2',
                'jumlah' => '20000',
            ])
        ]);

        $expense = Expense::find($rows[0]->expense_id);
        $this->assertEquals('2026-01-10 00:00:00', $expense->getAttributes()['date']); // First row date
        
        $cat1 = ExpenseCategory::where('category_name', 'CAT 1')->first();
        $sup1 = Supplier::where('supplier_name', 'SUP 1')->first();
        
        $this->assertEquals($cat1->id, $expense->category_id);
        $this->assertEquals($sup1->id, $expense->supplier_id);
    }

    public function test_parseable_nonzero_tax_is_ignored()
    {
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'tax' => '5000',
            'jumlah' => '100000',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_PROCESSED, $row->status);
        $this->assertEquals(1, $batch->success_count);
        
        $expense = Expense::find($row->expense_id);
        $this->assertEquals(100000, $expense->amount); // Excludes tax
        $this->assertFalse((bool)$expense->is_tax_included);
        
        $detail = $expense->detailRows()->first();
        $this->assertNull($detail->tax_id);
    }

    public function test_empty_deskripsi_falls_back_to_category_and_nomor()
    {
        // Mirrors the sample file shape where Deskripsi is "".
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'deskripsi' => '',
            'kategori' => 'PLN',
            'nomor' => '10476',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_PROCESSED, $row->status);

        $expense = Expense::find($row->expense_id);
        // The model uppercases text attributes on save.
        $this->assertEquals('IMPORTED EXPENSE 10476', $expense->getAttributes()['details']);

        $this->assertDatabaseHas('expense_details', [
            'expense_id' => $expense->id,
            'name' => 'PLN', // falls back to category name (uppercased by model)
        ]);
    }

    public function test_stray_tab_characters_are_trimmed_during_staging()
    {
        Queue::fake();

        $csvContent = "Tanggal,Transaksi,Nomor,Kategori,Deskripsi,Supplier,Jumlah,Tax,Status,Sisa Tagihan\n";
        $csvContent .= "06/01/2026\t,Expense,10476\t,PLN,\"\",PLN,321012.0,0.0,Paid,0.0";

        $file = UploadedFile::fake()->createWithContent('pengeluaran.csv', $csvContent);

        $this->actingAs($this->admin)->post(route('expenses.imports.upload'), ['file' => $file]);

        $batch = ExpenseImportBatch::first();

        // Drive the staging job synchronously so we can inspect the persisted row.
        (new StageExpenseImportRows(
            $batch->id,
            [
                'tanggal' => 'Tanggal',
                'transaksi' => 'Transaksi',
                'nomor' => 'Nomor',
                'kategori' => 'Kategori',
                'deskripsi' => 'Deskripsi',
                'supplier' => 'Supplier',
                'jumlah' => 'Jumlah',
                'tax' => 'Tax',
                'status' => 'Status',
                'sisa_tagihan' => 'Sisa Tagihan',
            ],
            ['Tanggal', 'Transaksi', 'Nomor', 'Kategori', 'Deskripsi', 'Supplier', 'Jumlah', 'Tax', 'Status', 'Sisa Tagihan'],
        ))->handle();

        $row = ExpenseImportRow::where('batch_id', $batch->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals('06/01/2026', $row->raw_json['tanggal']);
        $this->assertEquals('10476', $row->raw_json['nomor']);
        $this->assertEquals('', $row->raw_json['deskripsi']);
    }

    public function test_invalid_calendar_date_is_rejected()
    {
        // 31/02 is not a real date; Carbon would silently roll it forward.
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'tanggal' => '31/02/2026',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Invalid date format', $row->error_message);
        $this->assertEquals(1, $batch->error_count);
        $this->assertEquals(0, $batch->success_count);
    }

    public function test_nonzero_sisa_tagihan_row_is_rejected()
    {
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'sisa_tagihan' => '1000',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Sisa Tagihan must be zero', $row->error_message);
        $this->assertEquals(1, $batch->error_count);
    }

    public function test_unparseable_tax_marks_row_invalid()
    {
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'tax' => 'abc',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Unparseable Tax value', $row->error_message);
        $this->assertEquals(1, $batch->error_count);
        $this->assertEquals(0, $batch->success_count);
    }

    public function test_unparseable_sisa_tagihan_marks_row_invalid()
    {
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'sisa_tagihan' => 'abc',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Unparseable Sisa Tagihan value', $row->error_message);
        $this->assertEquals(1, $batch->error_count);
    }

    public function test_unparseable_jumlah_marks_row_invalid()
    {
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'jumlah' => 'abc',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Unparseable Jumlah value', $row->error_message);
        $this->assertEquals(1, $batch->error_count);
    }

    public function test_blank_supplier_falls_back_to_kategori()
    {
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'supplier' => '',
            'kategori' => 'PLN', // Valid category
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_PROCESSED, $row->status);
        $this->assertEquals(1, $batch->success_count);

        $expense = Expense::find($row->expense_id);
        $this->assertNotNull($expense);

        // Check if a supplier named PLN was created/reused and linked
        $this->assertDatabaseHas('suppliers', [
            'id' => $expense->supplier_id,
            'supplier_name' => 'PLN',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_missing_kategori_marks_row_invalid()
    {
        [$batch, $row] = $this->processSingleRow($this->validRawJson([
            'kategori' => '',
        ]));

        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Missing kategori', $row->error_message);
        $this->assertEquals(1, $batch->error_count);
    }

    public function test_existing_supplier_and_category_are_reused()
    {
        $cat = ExpenseCategory::create([
            'category_name' => 'Biaya Listrik',
            'setting_id' => $this->setting->id,
        ]);

        $sup = Supplier::create([
            'supplier_name' => 'PLN',
            'supplier_email' => 'pln@example.com',
            'supplier_phone' => '123456',
            'contact_name' => 'PLN',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Indonesia',
            'setting_id' => $this->setting->id,
        ]);

        [$batch, $row] = $this->processSingleRow($this->validRawJson());

        $this->assertEquals(ExpenseImportRow::STATUS_PROCESSED, $row->status);

        $expense = Expense::find($row->expense_id);
        $this->assertEquals($cat->id, $expense->category_id);
        $this->assertEquals($sup->id, $expense->supplier_id);

        // No duplicate category/supplier rows were created.
        $this->assertEquals(1, ExpenseCategory::where('setting_id', $this->setting->id)->count());
        $this->assertEquals(1, Supplier::where('setting_id', $this->setting->id)->count());
    }

        public function test_expense_import_service_skips_multi_row_duplicate_nomor()
    {
        $cat = ExpenseCategory::create([
            'category_name' => 'Test',
            'setting_id' => $this->setting->id,
        ]);
        
        $sup = Supplier::create([
            'supplier_name' => 'Test',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '123456',
            'contact_name' => 'Test',
            'address' => 'Test',
            'city' => 'Test',
            'country' => 'Test',
            'setting_id' => $this->setting->id,
        ]);

        Expense::create([
            'date' => '2020-03-19',
            'reference' => 'REF-001',
            'category_id' => $cat->id,
            'supplier_id' => $sup->id,
            'amount' => 100000,
            'details' => 'Test',
            'status' => Expense::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'imported_expense_number' => 'EXP-DUP',
            'is_tax_included' => false,
        ]);

        [$batch, $rows] = $this->processMultipleRows([
            $this->validRawJson([
                'nomor' => 'EXP-DUP',
                'deskripsi' => 'Row 1',
            ]),
            $this->validRawJson([
                'nomor' => 'EXP-DUP',
                'deskripsi' => 'Row 2',
            ])
        ]);

        $this->assertEquals(ExpenseImportRow::STATUS_SKIPPED, $rows[0]->status);
        $this->assertEquals('Duplicate Nomor', $rows[0]->error_message);
        
        $this->assertEquals(ExpenseImportRow::STATUS_SKIPPED, $rows[1]->status);
        $this->assertEquals('Duplicate Nomor', $rows[1]->error_message);
        
        $this->assertEquals(0, $batch->success_count);
        $this->assertEquals(0, $batch->error_count); // Skipped duplicates are not errors.
        $this->assertEquals(2, $batch->skipped_count); // They are counted as skipped at batch level (by row count).
    }

    public function test_invalid_multi_row_group_creates_no_records_and_marks_all_invalid()
    {
        $initialExpenseCount = Expense::count();
        $initialDetailCount = ExpenseDetail::count();

        // The second row has an invalid Sisa Tagihan.
        // The first row is completely valid.
        [$batch, $rows] = $this->processMultipleRows([
            $this->validRawJson([
                'nomor' => 'EXP-INV',
                'deskripsi' => 'Valid Row 1',
                'sisa_tagihan' => '0',
            ]),
            $this->validRawJson([
                'nomor' => 'EXP-INV',
                'deskripsi' => 'Invalid Row 2',
                'sisa_tagihan' => '100', // invalid
            ])
        ]);

        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $rows[0]->status);
        $this->assertStringContainsString('Sisa Tagihan must be zero', $rows[0]->error_message);
        
        $this->assertEquals(ExpenseImportRow::STATUS_INVALID, $rows[1]->status);
        $this->assertStringContainsString('Sisa Tagihan must be zero', $rows[1]->error_message);
        
        $this->assertEquals(0, $batch->success_count);
        $this->assertEquals(2, $batch->error_count);
        
        // No partial Expense or Details should have been created.
        $this->assertEquals($initialExpenseCount, Expense::count());
        $this->assertEquals($initialDetailCount, ExpenseDetail::count());
    }

    public function test_ambiguous_target_setting_fails_batch()
    {
        // A second matching setting makes the target ambiguous.
        Setting::create([
            'company_name' => 'CV Tiga Nusa Computer (Cabang)',
            'company_email' => 'tiganusa2@example.com',
            'company_phone' => '123456789',
            'site_logo' => 'logo.png',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $batch = ExpenseImportBatch::create([
            'status' => ExpenseImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'user_id' => $this->admin->id,
        ]);

        ExpenseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => $this->validRawJson(),
            'status' => ExpenseImportRow::STATUS_PENDING,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Could not resolve exactly one CV Tiga Nusa Computer setting');

        try {
            (new ExpenseImportService())->processBatch($batch);
        } finally {
            // No expenses should have been created before the batch failed.
            $this->assertEquals(0, Expense::count());
        }
    }

    public function test_expense_import_fails_without_setting()
    {
        Setting::query()->delete();

        $batch = ExpenseImportBatch::create([
            'status' => ExpenseImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'user_id' => $this->admin->id,
        ]);

        $service = new ExpenseImportService();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Could not resolve exactly one CV Tiga Nusa Computer setting');

        $service->processBatch($batch);
    }
}

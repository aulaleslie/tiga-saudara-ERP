<?php

namespace Tests\Feature\Services\Sequence;

use App\Services\Sequence\DocumentReferenceFormatter;
use App\Services\Sequence\DocumentSequence;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceNamespace;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class DocumentSequenceAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private DocumentSequenceAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Business A',
            'company_email' => 'a@example.com',
            'company_phone' => '111',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'a@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr A',
            'document_prefix' => 'PD-BL',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Business B',
            'company_email' => 'b@example.com',
            'company_phone' => '222',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'b@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr B',
            'document_prefix' => 'PD-JK',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);

        $this->allocator = new DocumentSequenceAllocator();
    }

    public function test_allocation_increments_and_formats_monotonically(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $alloc1 = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(1, $alloc1->number);
        $this->assertEquals('PD-BL-PR-2026-08-00001', $alloc1->reference);

        $alloc2 = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(2, $alloc2->number);
        $this->assertEquals('PD-BL-PR-2026-08-00002', $alloc2->reference);

        // Sequence row check
        $seqRow = DocumentSequence::where('setting_id', $this->settingA->id)
            ->where('document_type', 'purchase')
            ->first();
        $this->assertNotNull($seqRow);
        $this->assertEquals(2, $seqRow->last_number);
    }

    public function test_independent_namespaces_have_isolated_counters(): void
    {
        $nsPurchase = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');
        $nsSale = $this->allocator->buildNamespace(DocumentType::SALE, $this->settingA->id, '2026-08-15');
        $nsBusinessB = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingB->id, '2026-08-15');

        $p1 = DB::transaction(fn() => $this->allocator->allocate($nsPurchase));
        $s1 = DB::transaction(fn() => $this->allocator->allocate($nsSale));
        $b1 = DB::transaction(fn() => $this->allocator->allocate($nsBusinessB));

        $this->assertEquals('PD-BL-PR-2026-08-00001', $p1->reference);
        $this->assertEquals('PD-BL-SL-2026-08-00001', $s1->reference);
        $this->assertEquals('PD-JK-PR-2026-08-00001', $b1->reference);
    }

    public function test_rollback_does_not_advance_counter_permanently(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        // First successful transaction
        DB::transaction(fn() => $this->allocator->allocate($ns));

        // Second transaction fails and rolls back
        try {
            DB::transaction(function () use ($ns) {
                $this->allocator->allocate($ns);
                throw new \Exception("Rollback simulation");
            });
        } catch (\Exception $e) {
            // expected
        }

        // Third transaction should get number 2 (not 3)
        $alloc3 = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(2, $alloc3->number);
        $this->assertEquals('PD-BL-PR-2026-08-00002', $alloc3->reference);
    }

    public function test_stale_counter_reconciliation_advances_forward(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        // Create historical purchase with reference 00181
        Supplier::create([
            'supplier_name' => 'Supplier',
            'supplier_email' => 's@test.com',
            'supplier_phone' => '123',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingA->id,
        ]);
        PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->settingA->id,
        ]);

        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00181',
            'supplier_id' => Supplier::first()->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sequence row starts at 0 or empty
        $reconciled = $this->allocator->reconcileCounter($ns);
        $this->assertEquals(181, $reconciled);

        // Next allocation gives 182
        $alloc = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(182, $alloc->number);
        $this->assertEquals('PD-BL-PR-2026-08-00182', $alloc->reference);
    }

    public function test_execute_with_conflict_retry_recovers_from_stale_counter(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        Supplier::create([
            'supplier_name' => 'Supplier',
            'supplier_email' => 's@test.com',
            'supplier_phone' => '123',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingA->id,
        ]);
        PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->settingA->id,
        ]);

        // Existing row in database with reference 00001
        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00001',
            'supplier_id' => Supplier::first()->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // We run executeWithConflictRetry.
        // First attempt allocates 00001, attempts insert, hits unique constraint violation,
        // reconciles to 1, retries and allocates 00002, and succeeds!
        $purchase = $this->allocator->executeWithConflictRetry($ns, function () use ($ns) {
            $alloc = $this->allocator->allocate($ns);

            return Purchase::create([
                'date' => '2026-08-15',
                'due_date' => '2026-08-15',
                'reference' => $alloc->reference,
                'supplier_id' => Supplier::first()->id,
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_term_id' => PaymentTerm::first()->id,
                'setting_id' => $this->settingA->id,
                'is_tax_included' => false,
                'total_amount' => 100,
                'paid_amount' => 0,
                'due_amount' => 100,
                'payment_method' => 'Cash',
            ]);
        });

        $this->assertEquals('PD-BL-PR-2026-08-00002', $purchase->reference);
    }

    public function test_canonical_multi_namespace_locking(): void
    {
        $nsA = $this->allocator->buildNamespace(DocumentType::SALE, $this->settingB->id, '2026-08-15');
        $nsB = $this->allocator->buildNamespace(DocumentType::SALE, $this->settingA->id, '2026-08-15');

        DB::transaction(function () use ($nsA, $nsB) {
            $locked = $this->allocator->lockNamespacesCanonically([$nsA, $nsB]);
            $this->assertCount(2, $locked);
            $this->assertArrayHasKey($nsA->canonicalKey(), $locked);
            $this->assertArrayHasKey($nsB->canonicalKey(), $locked);
        });
    }
}

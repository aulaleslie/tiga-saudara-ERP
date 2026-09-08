<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosCartTotalsCalculator;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosRepairDraftCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected PosSession $session;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'PT Test Repair Draft',
            'row_total_rounding_increment' => 100.00,
        ]);

        $this->user = User::factory()->create();

        $terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal Repair',
            'code' => 'TR',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $this->user->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-REP',
            'category_name' => 'Cat Repair',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Barang Repair',
            'product_code' => 'BRG-REP',
            'product_quantity' => 100,
            'product_cost' => 500,
            'product_price' => 1083.00,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => false,
        ]);
    }

    public function test_repair_preview_and_apply_flow_on_case_3393_fixture(): void
    {
        // Setup draft replicating case 3393 bug:
        // Header total is 26,000.00 (snapshot_totals grand_total = 26000.00).
        // Line lacks line_total_minor and gross_minor, and (as in the real
        // transaction) was priced via customer-TIER pricing, not BASE.
        $draft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-REP-3393',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'snapshot_totals' => [
                'subtotal' => 26000.00,
                'grand_total' => 26000.00,
            ],
        ]);

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'product_code_snapshot' => $this->product->product_code,
            'qty' => 24,
            'unit_price' => 1083.00,
            'tax_id' => null,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => [
                'price_source' => 'TIER',
                // unpersisted line_total_minor!
            ],
        ]);

        // 1. Dry run preview
        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
        ])
            ->expectsOutputToContain('Preview Hash:')
            ->expectsOutputToContain('Dry-run preview complete')
            ->assertExitCode(0);

        // Verify line is still unpersisted
        $line->refresh();
        $this->assertArrayNotHasKey('line_total_minor', $line->line_meta ?? []);

        // 2. Apply with invalid/missing actor or missing preview-hash
        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
            '--apply' => true,
            '--actor' => 'not-a-number',
        ])
            ->expectsOutputToContain('valid numeric User ID must be provided')
            ->assertExitCode(1);

        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
            '--apply' => true,
            '--actor' => $this->user->id,
        ])
            ->expectsOutputToContain('--preview-hash option is required')
            ->assertExitCode(1);

        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
            '--apply' => true,
            '--actor' => $this->user->id,
            '--preview-hash' => 'wrong-hash-123456',
        ])
            ->expectsOutputToContain('Preview hash mismatch')
            ->assertExitCode(1);

        // 3. Apply with correct params (preview-hash computed from draft)
        $eval = (new \Modules\Pos\Console\PosRepairDraftCommand())->evaluateTransactionLines($draft, 100.00);
        $validHash = $eval['preview_hash'];

        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
            '--apply' => true,
            '--actor' => $this->user->id,
            '--preview-hash' => $validHash,
        ])
            ->expectsOutputToContain('successfully repaired')
            ->assertExitCode(0);

        // Assert line has been updated with authoritative minor amounts and fingerprint
        $line->refresh();
        $lineMeta = $line->line_meta;
        $this->assertSame(2600000, $lineMeta['line_total_minor']);
        $this->assertSame(2599200, $lineMeta['line_gross_minor']);
        $this->assertArrayHasKey(PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT, $lineMeta);

        // Assert header is unchanged
        $draft->refresh();
        $this->assertEquals(26000.00, (float) $draft->snapshot_totals['grand_total']);
        $this->assertSame($this->user->id, $draft->last_saved_by);
        $this->assertNotNull($draft->snapshot_hash);

        // 4. Repeated apply (idempotency)
        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('already carry authoritative amounts')
            ->assertExitCode(0);
    }

    public function test_repair_refuses_completed_wrong_setting_or_mismatched_header(): void
    {
        // 1. Wrong setting
        $draft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-REP-GUARD',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);
        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => 999999,
        ])
            ->expectsOutputToContain('not found for setting')
            ->assertExitCode(1);

        // 2. Non-DRAFT status (e.g. COMPLETED)
        $draft->update(['status' => PosTransaction::STATUS_COMPLETED]);
        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
        ])
            ->expectsOutputToContain('Transaction is not in DRAFT status')
            ->assertExitCode(1);

        // 3. Ambiguous / header mismatch
        $draft->update([
            'status' => PosTransaction::STATUS_DRAFT,
            'snapshot_totals' => ['grand_total' => 99999.00], // Mismatch against calculated line 26,000
        ]);
        PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'qty' => 24,
            'unit_price' => 1083.00,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
        ])
            ->expectsOutputToContain('Header mismatch')
            ->assertExitCode(1);
    }

    public function test_repair_preserves_manual_price_and_manual_overrides_without_rounding(): void
    {
        // Setup draft with manual price override row ($149) and base row ($151)
        // Manual price row: unit_price 149, qty 1, manual override without line_total_minor.
        // Base row: unit_price 151, qty 1, base price without line_total_minor.
        // Increment is 100. Base 151 rounds to 200. Manual 149 MUST REMAIN 149 (NOT rounded to 100).
        // Total = 149 + 200 = 349.
        $draft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-REP-MANUAL',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'snapshot_totals' => [
                'subtotal' => 349.00,
                'grand_total' => 349.00,
            ],
        ]);

        $manualLine = PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => 'Manual Line',
            'product_code_snapshot' => 'MAN-01',
            'qty' => 1,
            'unit_price' => 149.00,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => [
                'price_source' => 'MANUAL', // Manual price source!
            ],
        ]);

        $baseLine = PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 2,
            'product_id' => $this->product->id,
            'product_name_snapshot' => 'Base Line',
            'product_code_snapshot' => 'BASE-01',
            'qty' => 1,
            'unit_price' => 151.00,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => [
                'price_source' => 'BASE',
            ],
        ]);

        // Evaluate preview
        $eval = (new \Modules\Pos\Console\PosRepairDraftCommand())->evaluateTransactionLines($draft, 100.00);
        $this->assertEquals(34900, $eval['calculated_grand_total_cents']);
        $previewHash = $eval['preview_hash'];

        // Apply repair
        \Illuminate\Support\Facades\Log::spy();

        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
            '--apply' => true,
            '--actor' => $this->user->id,
            '--preview-hash' => $previewHash,
        ])
            ->expectsOutputToContain('successfully repaired')
            ->assertExitCode(0);

        // Verify manual line was NOT rounded to 100 (remains 149 -> 14900 minor)
        $manualLine->refresh();
        $this->assertSame(14900, $manualLine->line_meta['line_total_minor']);

        // Verify base line was rounded (151 -> 200 -> 20000 minor)
        $baseLine->refresh();
        $this->assertSame(20000, $baseLine->line_meta['line_total_minor']);

        // Verify audit log received before_snapshot and after_snapshot
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->with(
            'POS Draft Repaired',
            \Mockery::on(function ($payload) use ($draft) {
                return isset($payload['before_snapshot'])
                    && isset($payload['after_snapshot'])
                    && $payload['transaction_id'] === $draft->id
                    && count($payload['before_snapshot']['lines']) === 2
                    && count($payload['after_snapshot']['lines']) === 2;
            })
        );
    }

    public function test_repair_refuses_loaded_and_cancelled_transactions(): void
    {
        // 1. LOADED status
        $loadedDraft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-REP-LOADED',
            'status' => PosTransaction::STATUS_LOADED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);
        $this->artisan('pos:repair-draft', [
            'id' => $loadedDraft->id,
        ])
            ->expectsOutputToContain('Refusing repair')
            ->assertExitCode(1);

        // 2. CANCELLED status
        $cancelledDraft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-REP-CANCELLED',
            'status' => PosTransaction::STATUS_CANCELLED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);
        $this->artisan('pos:repair-draft', [
            'id' => $cancelledDraft->id,
        ])
            ->expectsOutputToContain('Transaction is not in DRAFT status')
            ->assertExitCode(1);
    }

    public function test_repair_refuses_manual_row_with_legacy_line_total_and_no_minor(): void
    {
        // A manual row with a legacy 'line_total' (Rupiah, authoritative per
        // PosTransactionLineAmountResolver) but no line_total_minor must not be
        // silently recomputed from qty * unit_price -- that could produce a
        // different total than the one actually charged.
        $draft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-REP-LEGACY-MANUAL',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'snapshot_totals' => [
                'grand_total' => 175.00,
            ],
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => 'Legacy Manual Line',
            'product_code_snapshot' => 'LEG-01',
            'qty' => 1,
            'unit_price' => 149.00,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => [
                'price_source' => 'MANUAL',
                'line_total' => 175.00, // legacy authoritative total, differs from qty * unit_price
            ],
        ]);

        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
        ])
            ->expectsOutputToContain('Refusing ambiguous recovery')
            ->assertExitCode(1);
    }

    public function test_preview_hash_changes_when_metadata_changes_without_changing_target_total(): void
    {
        // The same draft, re-evaluated after a metadata-only change (price_source
        // flips from BASE to TIER; both resolve to the same target total) must
        // produce a different preview hash, so a stale preview generated before
        // the change cannot be replayed against the new data.
        $draft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-HASH-SAME-DRAFT',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'snapshot_totals' => ['grand_total' => 26000.00],
        ]);
        $line = PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'product_code_snapshot' => $this->product->product_code,
            'qty' => 24,
            'unit_price' => 1083.00,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $command = new \Modules\Pos\Console\PosRepairDraftCommand();
        $evalBefore = $command->evaluateTransactionLines($draft, 100.00);

        $line->update(['line_meta' => ['price_source' => 'TIER']]);
        $draft->refresh();
        $evalAfter = $command->evaluateTransactionLines($draft, 100.00);

        $this->assertSame($evalBefore['calculated_grand_total_cents'], $evalAfter['calculated_grand_total_cents']);
        $this->assertNotSame($evalBefore['preview_hash'], $evalAfter['preview_hash']);
    }

    public function test_preview_hash_changes_when_header_tax_changes_without_changing_grand_total(): void
    {
        // A change to snapshot_totals (e.g. a tax component) that leaves grand_total
        // unchanged must still invalidate the preview hash, since header_cents alone
        // does not capture it.
        $draft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-HASH-TAX',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'snapshot_totals' => ['grand_total' => 26000.00, 'tax_total' => 0.00],
        ]);
        PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'product_code_snapshot' => $this->product->product_code,
            'qty' => 24,
            'unit_price' => 1083.00,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $command = new \Modules\Pos\Console\PosRepairDraftCommand();
        $evalBefore = $command->evaluateTransactionLines($draft, 100.00);

        $draft->update(['snapshot_totals' => ['grand_total' => 26000.00, 'tax_total' => 2000.00]]);
        $draft->refresh();
        $evalAfter = $command->evaluateTransactionLines($draft, 100.00);

        $this->assertSame($evalBefore['calculated_grand_total_cents'], $evalAfter['calculated_grand_total_cents']);
        $this->assertNotSame($evalBefore['preview_hash'], $evalAfter['preview_hash']);
    }

    public function test_apply_revalidates_increment_under_lock_against_preview_hash(): void
    {
        // If the setting's rounding increment changes between preview and apply,
        // the preview hash (which now covers the increment) must no longer match
        // what is recomputed, so the apply is refused -- whether caught at the
        // pre-lock check or (for a change that lands exactly between the two
        // reads) under the lock, either guard is acceptable.
        $draft = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-REP-INCREMENT-CHANGE',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'snapshot_totals' => ['grand_total' => 26000.00],
        ]);
        PosTransactionLine::create([
            'pos_transaction_id' => $draft->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'product_code_snapshot' => $this->product->product_code,
            'qty' => 24,
            'unit_price' => 1083.00,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => ['price_source' => 'TIER'],
        ]);

        $eval = (new \Modules\Pos\Console\PosRepairDraftCommand())->evaluateTransactionLines($draft, 100.00);
        $previewHash = $eval['preview_hash'];

        // Increment changes after preview was generated.
        $this->setting->update(['row_total_rounding_increment' => 500.00]);

        $this->artisan('pos:repair-draft', [
            'id' => $draft->id,
            '--setting' => $this->setting->id,
            '--apply' => true,
            '--actor' => $this->user->id,
            '--preview-hash' => $previewHash,
        ])
            ->expectsOutputToContain('Preview hash mismatch')
            ->assertExitCode(1);

        // The line must not have been repaired with the stale increment.
        $draft->refresh();
        $line = $draft->lines()->first();
        $this->assertArrayNotHasKey('line_total_minor', $line->line_meta ?? []);
    }
}

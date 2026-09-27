<?php

namespace Tests\Feature\Adjustment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\AdjustmentOwnershipGuard;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Focused tests for tasks 1.2-1.4: lifecycle schema/migration, ownership
 * guard, and draft persistence/notification behavior.
 */
class StockOpnameLifecycleFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);

        Permission::findOrCreate('adjustments.access', 'web');
        Permission::findOrCreate('adjustments.create', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        Permission::findOrCreate('adjustments.breakage.create', 'web');
        $this->user->givePermissionTo([
            'adjustments.access', 'adjustments.create', 'adjustments.edit',
            'adjustments.approval', 'adjustments.view-system-stock',
            'adjustments.breakage.create',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    private function makeMinimalDraftPayload(Product $product): array
    {
        return [
            'schema_version' => 1,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 3,
                    'bad_count' => 0,
                    'serials' => [],
                ],
            ],
        ];
    }

    private function makeStockManagedProduct(): Product
    {
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
            'is_active' => true,
        ]);

        return Product::create([
            'product_name' => 'Produk Uji',
            'product_code' => 'PU-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
    }

    // --- Task 1.2: schema / migration ---

    public function test_adjustment_has_lifecycle_columns_and_casts()
    {
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SCHEMA-1',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $this->location->id,
            'approval_result' => ['applied' => true],
        ]);

        $fresh = $adjustment->fresh();

        $this->assertNull($fresh->submitted_by);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->rejected_by);
        $this->assertNull($fresh->rejected_at);
        $this->assertNull($fresh->rejection_reason);
        $this->assertIsArray($fresh->approval_result);
        $this->assertTrue($fresh->approval_result['applied']);
    }

    public function test_migration_converts_normal_versioned_pending_to_draft_and_preserves_others()
    {
        // Roll the target migration back so we can insert pre-migration-shaped
        // rows (status still 'pending', no lifecycle columns needed for this
        // assertion), then re-run its up() and verify the actual behavior of
        // the migration file itself rather than a copy of its predicate.
        $migration = include base_path(
            'Modules/Adjustment/Database/Migrations/2026_09_10_090000_add_review_approval_lifecycle_to_adjustments_table.php'
        );

        $migration->down();

        DB::table('adjustments')->insert([
            'reference' => 'ADJ-MIG-NORMAL', 'date' => now()->toDateString(),
            'type' => 'NORMAL', 'status' => 'PENDING',
            'location_id' => $this->location->id,
            'count_draft' => json_encode(['schema_version' => 1, 'rows' => []]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('adjustments')->insert([
            'reference' => 'ADJ-MIG-BREAKAGE', 'date' => now()->toDateString(),
            'type' => 'BREAKAGE', 'status' => 'PENDING',
            'location_id' => $this->location->id,
            'count_draft' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('adjustments')->insert([
            'reference' => 'ADJ-MIG-LEGACY', 'date' => now()->toDateString(),
            'type' => 'NORMAL', 'status' => 'PENDING',
            'location_id' => $this->location->id,
            'count_draft' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertEquals(
            AdjustmentStatus::Draft->value,
            DB::table('adjustments')->where('reference', 'ADJ-MIG-NORMAL')->value('status')
        );
        $this->assertEquals(
            AdjustmentStatus::Pending->value,
            DB::table('adjustments')->where('reference', 'ADJ-MIG-BREAKAGE')->value('status')
        );
        $this->assertEquals(
            AdjustmentStatus::Pending->value,
            DB::table('adjustments')->where('reference', 'ADJ-MIG-LEGACY')->value('status')
        );

        // down() reverses the migrated row back to pending and drops the columns;
        // re-running down() here also verifies it doesn't error on an already-clean schema.
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('adjustments', 'approval_result'));

        // Restore schema for the rest of the test (RefreshDatabase resets between tests anyway).
        $migration->up();
    }

    public function test_breakage_creation_still_defaults_to_pending_status()
    {
        $product = $this->makeStockManagedProduct();
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $response = $this->post(route('adjustments.storeBreakage'), [
            'reference' => 'ADJ-BRK-1',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'product_ids' => [$product->id],
            'quantities_tax' => [2],
            'quantities_non_tax' => [0],
        ]);

        $response->assertRedirect(route('adjustments.index'));

        $adjustment = Adjustment::where('reference', 'ADJ-BRK-1')->firstOrFail();
        $this->assertEquals('BREAKAGE', $adjustment->type);
        $this->assertEquals(AdjustmentStatus::Pending, $adjustment->status);
    }

    // --- Task 1.3: lifecycle predicates + ownership guard ---

    public function test_can_edit_adjustment_true_for_draft_and_rejected_false_for_waiting_approval_and_approved()
    {
        $service = app(CountDraftService::class);

        $draft = Adjustment::create([
            'reference' => 'ADJ-EDIT-DRAFT', 'date' => now()->toDateString(),
            'type' => 'normal', 'status' => AdjustmentStatus::Draft,
            'location_id' => $this->location->id, 'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);
        $rejected = Adjustment::create([
            'reference' => 'ADJ-EDIT-REJ', 'date' => now()->toDateString(),
            'type' => 'normal', 'status' => AdjustmentStatus::Rejected,
            'location_id' => $this->location->id, 'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);
        $waiting = Adjustment::create([
            'reference' => 'ADJ-EDIT-WAIT', 'date' => now()->toDateString(),
            'type' => 'normal', 'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => $this->location->id, 'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);
        $approved = Adjustment::create([
            'reference' => 'ADJ-EDIT-APR', 'date' => now()->toDateString(),
            'type' => 'normal', 'status' => AdjustmentStatus::Approved,
            'location_id' => $this->location->id, 'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);

        $this->assertTrue($service->canEditAdjustment($draft));
        $this->assertTrue($service->canEditAdjustment($rejected));
        $this->assertFalse($service->canEditAdjustment($waiting));
        $this->assertFalse($service->canEditAdjustment($approved));
    }

    public function test_ownership_guard_allows_same_setting_non_consignment_location()
    {
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-OWN-OK', 'date' => now()->toDateString(),
            'type' => 'normal', 'status' => AdjustmentStatus::Draft,
            'location_id' => $this->location->id,
        ]);

        $guard = app(AdjustmentOwnershipGuard::class);
        $guard->assertOwned($adjustment, $this->setting->id);

        $this->assertTrue(true); // no exception thrown
    }

    public function test_ownership_guard_rejects_cross_setting_location()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);
        $otherLocation = Location::create([
            'name' => 'Gudang Lain', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-OWN-CROSS', 'date' => now()->toDateString(),
            'type' => 'normal', 'status' => AdjustmentStatus::Draft,
            'location_id' => $otherLocation->id,
        ]);

        $guard = app(AdjustmentOwnershipGuard::class);

        $this->expectException(ValidationException::class);
        $guard->assertOwned($adjustment, $this->setting->id);
    }

    public function test_ownership_guard_rejects_consignment_location()
    {
        $consignmentLocation = Location::create([
            'name' => 'Gudang Konsinyasi', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => true,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-OWN-CONSIGN', 'date' => now()->toDateString(),
            'type' => 'normal', 'status' => AdjustmentStatus::Draft,
            'location_id' => $consignmentLocation->id,
        ]);

        $guard = app(AdjustmentOwnershipGuard::class);

        $this->expectException(ValidationException::class);
        $guard->assertOwned($adjustment, $this->setting->id);
    }

    // --- Task 1.4: saveDraft persistence + notification behavior ---

    public function test_save_draft_on_create_persists_draft_status_without_notification()
    {
        $product = $this->makeStockManagedProduct();

        $service = app(CountDraftService::class);
        $adjustment = $service->saveDraft([
            'reference' => 'ADJ-DRAFT-CREATE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ]);

        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_save_draft_on_update_keeps_draft_status_without_notification()
    {
        $product = $this->makeStockManagedProduct();
        $service = app(CountDraftService::class);

        $adjustment = $service->saveDraft([
            'reference' => 'ADJ-DRAFT-UPDATE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ]);

        $updated = $service->saveDraft([
            'reference' => 'ADJ-DRAFT-UPDATE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ], $adjustment->fresh());

        $this->assertEquals(AdjustmentStatus::Draft, $updated->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_save_draft_on_rejected_document_returns_to_draft_and_retains_rejection_evidence()
    {
        $product = $this->makeStockManagedProduct();
        $service = app(CountDraftService::class);

        $adjustment = $service->saveDraft([
            'reference' => 'ADJ-REJ-REVISE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ]);

        $rejector = User::factory()->create(['is_active' => 1]);
        $adjustment->fresh()->update([
            'status' => AdjustmentStatus::Rejected,
            'rejected_by' => $rejector->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Jumlah tidak sesuai fisik gudang.',
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
            // Simulate leftover approval metadata from a prior (hypothetical)
            // decision cycle to prove revision clears it, not just submission.
            'approved_by' => $rejector->id,
            'approved_at' => now(),
            'approval_result' => ['applied' => true],
        ]);

        $revised = $service->saveDraft([
            'reference' => 'ADJ-REJ-REVISE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ], $adjustment->fresh());

        $fresh = $revised->fresh();
        $this->assertEquals(AdjustmentStatus::Draft, $fresh->status);

        // Prior rejection evidence retained as historical record.
        $this->assertEquals($rejector->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
        // BaseModel uppercases stored string attributes; compare case-insensitively.
        $this->assertEquals('JUMLAH TIDAK SESUAI FISIK GUDANG.', $fresh->rejection_reason);

        // Incompatible submission/approval metadata is cleared.
        $this->assertNull($fresh->submitted_by);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approval_result);

        $this->assertDatabaseCount('notifications', 0);
    }

    // --- Enforcement (not just helper existence) ---

    public function test_save_draft_rejects_existing_waiting_approval_document()
    {
        $product = $this->makeStockManagedProduct();
        $service = app(CountDraftService::class);

        $adjustment = $service->saveDraft([
            'reference' => 'ADJ-GUARD-WAIT',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ]);

        $adjustment->fresh()->update(['status' => AdjustmentStatus::WaitingApproval]);

        $this->expectException(\InvalidArgumentException::class);
        $service->saveDraft([
            'reference' => 'ADJ-GUARD-WAIT',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ], $adjustment->fresh());
    }

    public function test_save_draft_rejects_existing_approved_document()
    {
        $product = $this->makeStockManagedProduct();
        $service = app(CountDraftService::class);

        $adjustment = $service->saveDraft([
            'reference' => 'ADJ-GUARD-APR',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ]);

        $adjustment->fresh()->update(['status' => AdjustmentStatus::Approved]);

        $this->expectException(\InvalidArgumentException::class);
        $service->saveDraft([
            'reference' => 'ADJ-GUARD-APR',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ], $adjustment->fresh());
    }

    public function test_save_draft_rejects_document_owned_by_another_setting()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);
        $otherLocation = Location::create([
            'name' => 'Gudang Lain', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-GUARD-CROSS',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $otherLocation->id,
            'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);

        // Active session setting is $this->setting, document belongs to $otherSetting.
        $service = app(CountDraftService::class);

        $this->expectException(ValidationException::class);
        $service->saveDraft([
            'reference' => 'ADJ-GUARD-CROSS',
            'date' => now()->toDateString(),
            'location_id' => $otherLocation->id,
            'count_draft' => ['schema_version' => 1, 'rows' => [
                ['product_id' => 1, 'good_count' => 1, 'bad_count' => 0, 'serials' => []],
            ]],
        ], $adjustment);
    }

    public function test_save_draft_rejects_new_document_targeting_another_settings_location()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);
        $otherLocation = Location::create([
            'name' => 'Gudang Lain 2', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $product = $this->makeStockManagedProduct();
        $service = app(CountDraftService::class);

        // Active session setting is $this->setting; a crafted request targets
        // a location belonging to a different setting entirely.
        $this->expectException(\InvalidArgumentException::class);
        $service->saveDraft([
            'reference' => 'ADJ-GUARD-NEW-CROSS',
            'date' => now()->toDateString(),
            'location_id' => $otherLocation->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ]);
    }

    public function test_edit_route_rejects_document_owned_by_another_setting()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);
        $otherLocation = Location::create([
            'name' => 'Gudang Lain 3', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-EDIT-CROSS',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $otherLocation->id,
            'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);

        $response = $this->get(route('adjustments.edit', $adjustment));
        $response->assertForbidden();
    }
}

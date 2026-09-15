<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchaseStatusTransitionAudit;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Exceptions\PurchaseLifecycleTransitionException;
use Modules\Purchase\Services\PurchaseLifecycleService;
use Modules\Purchase\Services\PurchaseReceivingCompletionService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseReceivingApprovalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected User $user;
    protected Location $location;
    protected Unit $pcsUnit;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '1',
            'notification_email' => 'a@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->user = User::factory()->create();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['purchases.update', 'purchases.approval', 'purchases.receive.access', 'purchases.receive.approval', 'purchases.approved.edit', 'purchases.receive.complete_shortfall', 'purchases.received.monetary.edit'] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }
        $this->user->givePermissionTo([
            'purchases.update',
            'purchases.approval',
            'purchases.receive.access',
            'purchases.receive.approval',
            'purchases.approved.edit',
            'purchases.receive.complete_shortfall',
            'purchases.received.monetary.edit',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Category::create([
            'id' => 1,
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
        ]);

        $this->pcsUnit = Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);

        \Modules\Purchase\Entities\PaymentTerm::create([
            'id' => 1,
            'name' => 'Net 30',
            'number_of_days' => 30,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create(['id' => 1, 'name' => 'Loc A1', 'setting_id' => $this->setting->id]);
    }

    protected function createProduct(string $name, string $code, int $qty = 0): Product
    {
        return Product::create([
            'product_name' => $name,
            'product_code' => $code . '-' . uniqid(),
            'product_quantity' => $qty,
            'product_cost' => 10000,
            'product_price' => 15000,
            'product_unit' => $this->pcsUnit->id,
            'unit_id' => $this->pcsUnit->id,
            'category_id' => 1,
            'setting_id' => $this->setting->id,
        ]);
    }

    protected function createPurchase(string $status = Purchase::STATUS_APPROVED): array
    {
        $p1 = $this->createProduct('Product A', 'PROD-A', 0);
        $p2 = $this->createProduct('Product B', 'PROD-B', 0);

        $purchase = Purchase::create([
            'reference' => 'PO-' . uniqid(),
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'status' => $status,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 300000,
            'paid_amount' => 0,
            'due_amount' => 300000,
            'is_tax_included' => false,
        ]);

        $detail1 = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $p1->id,
            'product_name' => $p1->product_name,
            'product_code' => $p1->product_code,
            'quantity' => 10,
            'unit_price' => 10000,
            'price' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 100000,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => $this->pcsUnit->id,
        ]);

        $detail2 = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $p2->id,
            'product_name' => $p2->product_name,
            'product_code' => $p2->product_code,
            'quantity' => 20,
            'unit_price' => 10000,
            'price' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 200000,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => $this->pcsUnit->id,
        ]);

        return [$purchase, [$detail1, $detail2], [$p1, $p2]];
    }

    /**
     * 4.1: An all-zero receiving cannot be approved while a mixed positive/zero receiving approves and counts only positive quantities.
     */
    public function test_all_zero_receiving_cannot_be_approved_while_mixed_positive_zero_approves(): void
    {
        [$purchase, [$d1, $d2], [$p1, $p2]] = $this->createPurchase();

        // 1. All zero receiving
        $rnAllZero = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnAllZero->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 0,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnAllZero->id,
            'po_detail_id' => $d2->id,
            'quantity_received' => 0,
        ]);

        // Test JSON request produces 422 conflict with error code
        $responseAllZeroJson = $this->postJson(route('receivings.approve', $rnAllZero->id));
        $responseAllZeroJson->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'all_zero_quantity',
            ]);

        $rnAllZero->refresh();
        $this->assertEquals(ReceivedNote::STATUS_PENDING, $rnAllZero->status);
        $this->assertEquals(Purchase::STATUS_APPROVED, $purchase->fresh()->status);

        // 2. Mixed positive and zero receiving
        $rnMixed = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnMixed->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 5,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnMixed->id,
            'po_detail_id' => $d2->id,
            'quantity_received' => 0,
        ]);

        $responseMixed = $this->post(route('receivings.approve', $rnMixed->id));
        $rnMixed->refresh();
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $rnMixed->status);
        $this->assertEquals(5, $p1->fresh()->product_quantity);
        $this->assertEquals(0, $p2->fresh()->product_quantity);
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $purchase->fresh()->status);
    }

    /**
     * Negative authorization test: create-only and unrelated users cannot submit or approve lifecycle transitions.
     */
    public function test_negative_authorization_for_create_only_and_unrelated_users(): void
    {
        [$purchase, [$d1, $d2]] = $this->createPurchase(Purchase::STATUS_DRAFTED);

        // User with only purchases.create permission
        $createOnlyUser = User::factory()->create();
        Permission::findOrCreate('purchases.create', 'web');
        $createOnlyUser->givePermissionTo('purchases.create');

        $this->actingAs($createOnlyUser);
        $lifecycleService = app(PurchaseLifecycleService::class);

        // Cannot submit approval
        try {
            $lifecycleService->transitionUserStatus($purchase, Purchase::STATUS_WAITING_APPROVAL, null, $createOnlyUser);
            $this->fail('Expected 403 authorization exception for create-only user.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // Unrelated user with no permissions
        $unrelatedUser = User::factory()->create();
        $this->actingAs($unrelatedUser);

        try {
            $lifecycleService->transitionUserStatus($purchase, Purchase::STATUS_WAITING_APPROVAL, null, $unrelatedUser);
            $this->fail('Expected 403 authorization exception for unrelated user.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        $this->actingAs($this->user);
    }

    /**
     * Approval rejects inactive receiving location.
     */
    public function test_approval_rejects_inactive_receiving_location(): void
    {
        [$purchase, [$d1, $d2]] = $this->createPurchase();

        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 5,
        ]);

        // Deactivate location before approval
        $this->location->update(['is_active' => false]);

        $response = $this->postJson(route('receivings.approve', $rn->id));
        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'invalid_location',
            ]);

        $this->assertEquals(ReceivedNote::STATUS_PENDING, $rn->fresh()->status);
        $this->location->update(['is_active' => true]);
    }

    /**
     * 4.2: Full receipt derives RECEIVED, partial derives RECEIVED PARTIALLY, pending/rejected do not contribute.
     */
    public function test_full_receipt_derives_received_and_partial_derives_received_partially(): void
    {
        [$purchase, [$d1, $d2], [$p1, $p2]] = $this->createPurchase();

        // Pending note (should not contribute)
        $rnPending = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnPending->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 10,
        ]);

        // Rejected note (should not contribute)
        $rnRejected = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_REJECTED,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnRejected->id,
            'po_detail_id' => $d2->id,
            'quantity_received' => 20,
        ]);

        // Partial Note
        $rn1 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn1->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 10,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn1->id,
            'po_detail_id' => $d2->id,
            'quantity_received' => 5,
        ]);

        $this->post(route('receivings.approve', $rn1->id));
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $purchase->fresh()->status);

        // Full remaining note
        $rn2 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn2->id,
            'po_detail_id' => $d2->id,
            'quantity_received' => 15,
        ]);

        $this->post(route('receivings.approve', $rn2->id));
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);

        // Check transition audits
        $audits = PurchaseStatusTransitionAudit::where('purchase_id', $purchase->id)->get();
        $this->assertCount(2, $audits);
        $this->assertEquals(Purchase::STATUS_APPROVED, $audits[0]->old_status);
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $audits[0]->new_status);
        $this->assertEquals($rn1->id, $audits[0]->received_note_id);

        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $audits[1]->old_status);
        $this->assertEquals(Purchase::STATUS_RECEIVED, $audits[1]->new_status);
        $this->assertEquals($rn2->id, $audits[1]->received_note_id);
    }

    /**
     * 4.3: Stale or crafted lifecycle requests cannot leave or move a Purchase with positive approved receiving evidence to APPROVED or set receiving-derived statuses directly.
     */
    public function test_stale_or_crafted_lifecycle_requests_cannot_overwrite_received_purchases(): void
    {
        [$purchase, [$d1, $d2]] = $this->createPurchase();

        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 5,
        ]);
        $this->post(route('receivings.approve', $rn->id));
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $purchase->fresh()->status);

        // Attempt stale status transition to APPROVED
        $this->patch(route('purchases.updateStatus', $purchase->id), [
            'status' => Purchase::STATUS_APPROVED,
        ]);
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $purchase->fresh()->status);

        // Attempt crafted status transition to RECEIVED
        $this->patch(route('purchases.updateStatus', $purchase->id), [
            'status' => Purchase::STATUS_RECEIVED,
        ]);
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $purchase->fresh()->status);

        // Attempt transition through service directly
        $service = app(PurchaseLifecycleService::class);
        $this->expectException(PurchaseLifecycleTransitionException::class);
        $service->transitionUserStatus($purchase->fresh(), Purchase::STATUS_APPROVED);
    }

    /**
     * 4.4: Full edit versus receiving approval race, and shortfall completion versus receiving approval.
     */
    public function test_full_edit_and_shortfall_completion_guards(): void
    {
        [$purchase, [$d1, $d2]] = $this->createPurchase();

        // 1. Full edit on received partially purchase is rejected at controller
        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 10,
        ]);
        $this->post(route('receivings.approve', $rn->id));
        $purchase->refresh();
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $purchase->status);

        // Try updating full purchase via PUT
        $response = $this->put(route('purchases.update', $purchase->id), [
            'supplier_id' => $this->supplier->id,
            'reference' => $purchase->reference,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'payment_term' => 1,
        ]);
        $response->assertStatus(422);

        // Verify details are intact
        $this->assertCount(2, $purchase->purchaseDetails()->get());

        // 2. Shortfall completion on RECEIVED_PARTIALLY transitions to RECEIVED and records audit
        $completionService = app(PurchaseReceivingCompletionService::class);
        $completion = $completionService->complete(
            $purchase,
            'Completing shortfall for remaining items',
            $this->user->id
        );

        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);
        $audit = PurchaseStatusTransitionAudit::where('purchase_id', $purchase->id)
            ->where('action_or_source', PurchaseLifecycleService::SOURCE_SHORTFALL_COMPLETION)
            ->first();
        $this->assertNotNull($audit);
        $this->assertEquals(Purchase::STATUS_RECEIVED_PARTIALLY, $audit->old_status);
        $this->assertEquals(Purchase::STATUS_RECEIVED, $audit->new_status);
    }

    /**
     * Test stale replay approval guard:
     * Verifies that after a note is approved by a primary request, any replay request
     * originating from the prior PENDING state is safely rejected under lock with HTTP 422 (already_processed),
     * preventing double-increment of stock or inconsistent purchase derivation.
     *
     * Note: In single-process SQLite test runners, true multi-connection lock contention
     * is tested at application lock boundaries. This test verifies the post-transition replay guard.
     */
    public function test_stale_replay_approval_rejection_under_lock(): void
    {
        [$purchase, [$d1, $d2], [$p1, $p2]] = $this->createPurchase();

        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 5,
        ]);

        // Capture note instance before approval
        $staleNoteInstance = ReceivedNote::find($rn->id);
        $this->assertTrue($staleNoteInstance->isPending());

        // Request 1 approves
        $res1 = $this->post(route('receivings.approve', $rn->id));
        $this->assertEquals(5, $p1->fresh()->product_quantity);

        // Request 2 was dispatched when note was still pending, now reaches approval boundary with stale state
        $res2 = $this->postJson(route('receivings.approve', $staleNoteInstance->id));
        $res2->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'already_processed',
            ]);

        $this->assertEquals(5, $p1->fresh()->product_quantity); // Stock remains 5 (not 10)
    }

    /**
     * 4.5: Idempotency and rollback tests for duplicate approval and failure cases.
     */
    public function test_approval_idempotency_and_atomic_rollback(): void
    {
        [$purchase, [$d1, $d2], [$p1, $p2]] = $this->createPurchase();

        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $d1->id,
            'quantity_received' => 5,
        ]);

        // First approval
        $res1 = $this->post(route('receivings.approve', $rn->id));
        $this->assertEquals(5, $p1->fresh()->product_quantity);
        $this->assertEquals(1, PurchaseStatusTransitionAudit::where('purchase_id', $purchase->id)->count());

        // Second approval (Replay)
        $res2 = $this->post(route('receivings.approve', $rn->id));
        $this->assertEquals(5, $p1->fresh()->product_quantity); // Stock not doubled
        $this->assertEquals(1, PurchaseStatusTransitionAudit::where('purchase_id', $purchase->id)->count()); // No duplicate audit

        // Rollback test: Receiving detail linked to another purchase detail
        [$otherPurchase, [$otherD1]] = $this->createPurchase();
        $rnBad = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnBad->id,
            'po_detail_id' => $otherD1->id, // Belongs to other purchase
            'quantity_received' => 2,
        ]);

        $response = $this->postJson(route('receivings.approve', $rnBad->id));
        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'unmatched_purchase_detail',
            ]);

        $this->assertEquals(ReceivedNote::STATUS_PENDING, $rnBad->fresh()->status);
        $this->assertEquals(5, $p1->fresh()->product_quantity); // Stock remains unchanged
    }
}

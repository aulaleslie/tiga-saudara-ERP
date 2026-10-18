<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
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

    public function test_receiving_resolves_low_stock_only_when_sellable_stock_recovers(): void
    {
        [$purchase, [$detail], [$product]] = $this->createPurchase();
        $product->update([
            'product_quantity' => 12,
            'broken_quantity' => 8,
            'product_stock_alert' => 5,
        ]);
        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 12,
            'quantity_non_tax' => 12,
            'quantity_tax' => 0,
            'broken_quantity' => 8,
            'broken_quantity_non_tax' => 8,
            'broken_quantity_tax' => 0,
        ]);

        $notifications = collect([
            [Product::class, $product->id, null, 'global_low_stock'],
            [ProductStock::class, $stock->id, $this->location->id, 'location_low_stock'],
        ])->map(function (array $source) {
            return Notification::create([
                'user_id' => $this->user->id,
                'setting_id' => $this->setting->id,
                'location_id' => $source[2],
                'category' => 'stock',
                'type' => $source[3],
                'title' => 'Low stock',
                'message' => 'Low stock',
                'source_type' => $source[0],
                'source_id' => $source[1],
                'fingerprint' => 'stock:receiving:' . $source[3],
            ]);
        });

        foreach ([1, 1] as $quantity) {
            $note = ReceivedNote::create([
                'po_id' => $purchase->id,
                'date' => now()->toDateString(),
                'location_id' => $this->location->id,
                'status' => ReceivedNote::STATUS_PENDING,
            ]);
            ReceivedNoteDetail::create([
                'received_note_id' => $note->id,
                'po_detail_id' => $detail->id,
                'quantity_received' => $quantity,
            ]);

            $this->post(route('receivings.approve', $note->id));
            $this->assertEquals(ReceivedNote::STATUS_APPROVED, $note->fresh()->status);

            $resolved = $product->fresh()->product_quantity - $product->broken_quantity > 5;
            foreach ($notifications as $notification) {
                $this->assertEquals($resolved, $notification->fresh()->resolved_at !== null);
            }
        }

        $this->assertEquals(14, $product->fresh()->product_quantity);
        $this->assertEquals(14, $stock->fresh()->quantity);
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

    public function test_receiving_approval_propagates_discounted_hpp_across_three_tax_modes(): void
    {
        $boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'box', 'operator' => '*', 'operation_value' => 12]);
        $tax11 = \Modules\Setting\Entities\Tax::create(['name' => 'PPN 11%', 'value' => 11.0, 'is_active' => true]);

        // 1. Non-PKP mode: 1 BOX @ 12,000 with 1,200 fixed discount (canonical discount 100/PCS)
        // Subtotal = 10,800, tax = 0 -> HPP = 10,800 / 12 = 900
        $this->setting->update(['is_pkp' => false]);
        $prodNonPkp = $this->createProduct('Non-PKP Product', 'PROD-NON-PKP', 0);

        $poNonPkp = Purchase::create([
            'reference' => 'PO-HPP-NON-PKP',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'total_amount' => 10800,
            'paid_amount' => 0,
            'due_amount' => 10800,
            'is_tax_included' => false,
        ]);

        $detailNonPkp = PurchaseDetail::create([
            'purchase_id' => $poNonPkp->id,
            'product_id' => $prodNonPkp->id,
            'product_name' => $prodNonPkp->product_name,
            'product_code' => $prodNonPkp->product_code,
            'quantity' => 12,
            'entered_quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'entered_unit_price' => 12000,
            'product_discount_amount' => 100,
            'entered_product_discount_amount' => 1200,
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12,
            'sub_total' => 10800,
            'product_tax_amount' => 0,
            'purchase_unit_id' => $boxUnit->id,
            'pricing_source' => 'manual',
        ]);

        $rnNonPkp = ReceivedNote::create([
            'po_id' => $poNonPkp->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnNonPkp->id,
            'po_detail_id' => $detailNonPkp->id,
            'quantity_received' => 12,
        ]);

        $this->post(route('receivings.approve', $rnNonPkp->id));
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $rnNonPkp->fresh()->status);
        $priceNonPkp = ProductPrice::where('product_id', $prodNonPkp->id)->where('setting_id', $this->setting->id)->first();
        $this->assertEquals(900.0, (float) $priceNonPkp->average_purchase_price);

        // 2. PKP Tax Excluded mode: 1 BOX @ 12,000 with 1,200 fixed discount and 11% tax
        // Subtotal = 11,988, tax = 1,188 -> DPP = 10,800 -> HPP = 10,800 / 12 = 900
        $this->setting->update(['is_pkp' => true]);
        $prodExcluded = $this->createProduct('Tax Excluded Product', 'PROD-TAX-EXC', 0);

        $poExcluded = Purchase::create([
            'reference' => 'PO-HPP-TAX-EXC',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'total_amount' => 11988,
            'paid_amount' => 0,
            'due_amount' => 11988,
            'is_tax_included' => false,
        ]);

        $detailExcluded = PurchaseDetail::create([
            'purchase_id' => $poExcluded->id,
            'product_id' => $prodExcluded->id,
            'product_name' => $prodExcluded->product_name,
            'product_code' => $prodExcluded->product_code,
            'quantity' => 12,
            'entered_quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'entered_unit_price' => 12000,
            'product_discount_amount' => 100,
            'entered_product_discount_amount' => 1200,
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12,
            'sub_total' => 11988,
            'product_tax_amount' => 1188,
            'tax_id' => $tax11->id,
            'purchase_unit_id' => $boxUnit->id,
            'pricing_source' => 'manual',
        ]);

        $rnExcluded = ReceivedNote::create([
            'po_id' => $poExcluded->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnExcluded->id,
            'po_detail_id' => $detailExcluded->id,
            'quantity_received' => 12,
        ]);

        $this->post(route('receivings.approve', $rnExcluded->id));
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $rnExcluded->fresh()->status);
        $priceExcluded = ProductPrice::where('product_id', $prodExcluded->id)->where('setting_id', $this->setting->id)->first();
        $this->assertEquals(900.0, (float) $priceExcluded->average_purchase_price);

        // 3. PKP Tax Included mode: 1 BOX @ 12,000 with 1,200 fixed discount and 11% included tax
        // Subtotal = 10,800, tax = 1,070.27 -> DPP = 9,729.73 -> HPP = 9,729.73 / 12 = 810.810833...
        $prodIncluded = $this->createProduct('Tax Included Product', 'PROD-TAX-INC', 0);

        $poIncluded = Purchase::create([
            'reference' => 'PO-HPP-TAX-INC',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'total_amount' => 10800,
            'paid_amount' => 0,
            'due_amount' => 10800,
            'is_tax_included' => true,
        ]);

        $detailIncluded = PurchaseDetail::create([
            'purchase_id' => $poIncluded->id,
            'product_id' => $prodIncluded->id,
            'product_name' => $prodIncluded->product_name,
            'product_code' => $prodIncluded->product_code,
            'quantity' => 12,
            'entered_quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'entered_unit_price' => 12000,
            'product_discount_amount' => 100,
            'entered_product_discount_amount' => 1200,
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12,
            'sub_total' => 10800,
            'product_tax_amount' => 1070.27,
            'tax_id' => $tax11->id,
            'purchase_unit_id' => $boxUnit->id,
            'pricing_source' => 'manual',
        ]);

        $rnIncluded = ReceivedNote::create([
            'po_id' => $poIncluded->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rnIncluded->id,
            'po_detail_id' => $detailIncluded->id,
            'quantity_received' => 12,
        ]);

        $this->post(route('receivings.approve', $rnIncluded->id));
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $rnIncluded->fresh()->status);
        $priceIncluded = ProductPrice::where('product_id', $prodIncluded->id)->where('setting_id', $this->setting->id)->first();
        $this->assertEqualsWithDelta(9729.73 / 12, (float) $priceIncluded->average_purchase_price, 0.01);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Currency\Entities\Currency;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExpenseApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Permission::firstOrCreate(['name' => 'expenses.access']);
        Permission::firstOrCreate(['name' => 'expenses.approval']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['expenses.access', 'expenses.approval']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234',
            'company_address' => 'Test',
            'notification_email' => 'test@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->category = ExpenseCategory::create([
            'category_name' => 'Operational',
        ]);
    }

    private function createSubmittedExpense(string $reference): Expense
    {
        $expense = Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'reference' => $reference,
            'date' => now()->format('Y-m-d'),
            'amount' => 50000,
            'status' => Expense::STATUS_SUBMITTED,
            'details' => 'Test expense ' . $reference,
        ]);

        $expense->detailRows()->create([
            'name' => 'Item for ' . $reference,
            'amount' => 50000,
        ]);

        return $expense;
    }

    public function test_consecutive_approvals_by_same_non_admin_user_succeed(): void
    {
        $expense1 = $this->createSubmittedExpense('EXP-001');
        $expense2 = $this->createSubmittedExpense('EXP-002');

        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('expenses.approve', $expense1))
            ->assertSessionHasNoErrors();

        $this->assertEquals(Expense::STATUS_APPROVED, $expense1->fresh()->status);

        // Immediately approve second expense as the same non-admin user
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('expenses.approve', $expense2))
            ->assertSessionHasNoErrors();

        $this->assertEquals(Expense::STATUS_APPROVED, $expense2->fresh()->status);
    }

    public function test_duplicate_same_expense_approval_is_rejected_and_does_not_approve_twice(): void
    {
        $expense = $this->createSubmittedExpense('EXP-DUP-001');

        // First approval succeeds
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('expenses.approve', $expense))
            ->assertSessionHasNoErrors();

        $this->assertEquals(Expense::STATUS_APPROVED, $expense->fresh()->status);

        // Duplicate approval request for same expense within timeout is rejected by idempotency middleware
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('expenses.approve', $expense));

        $response->assertSessionHasErrors(['idempotency']);
    }

    public function test_duplicate_approval_renders_rejection_message_on_expense_show_page(): void
    {
        $expense = $this->createSubmittedExpense('EXP-SHOW-001');

        // First approval
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->from(route('expenses.show', $expense))
            ->post(route('expenses.approve', $expense))
            ->assertRedirect(route('expenses.show', $expense));

        $this->assertEquals(Expense::STATUS_APPROVED, $expense->fresh()->status);

        // Duplicate approval initiated from show page
        $duplicateResponse = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->from(route('expenses.show', $expense))
            ->followingRedirects()
            ->post(route('expenses.approve', $expense));

        $duplicateResponse->assertStatus(200);
        $duplicateResponse->assertSee('Permintaan yang sama sudah diproses. Silakan tunggu sebelum mencoba lagi.');
    }

    public function test_duplicate_approval_renders_rejection_message_on_expense_index_page(): void
    {
        $expense = $this->createSubmittedExpense('EXP-IDX-001');

        // First approval
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->from(route('expenses.index'))
            ->post(route('expenses.approve', $expense))
            ->assertRedirect(route('expenses.index'));

        $this->assertEquals(Expense::STATUS_APPROVED, $expense->fresh()->status);

        // Duplicate approval initiated from index page
        $duplicateResponse = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->from(route('expenses.index'))
            ->followingRedirects()
            ->post(route('expenses.approve', $expense));

        $duplicateResponse->assertStatus(200);
        $duplicateResponse->assertSee('Permintaan yang sama sudah diproses. Silakan tunggu sebelum mencoba lagi.');
    }

    public function test_failed_pre_commit_attempt_releases_claim_and_can_be_retried(): void
    {
        $expense = $this->createSubmittedExpense('EXP-FAIL-001');

        $mockNotification = $this->mock(DocumentNotificationService::class);
        $mockNotification->shouldReceive('resolveApproval')
            ->once()
            ->andThrow(new \RuntimeException('Pre-commit notification failure'));

        // Pre-commit failure results in 500 response and releases idempotency claim
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('expenses.approve', $expense))
            ->assertStatus(500);

        // Verify changes were not committed and expense remains submitted
        $this->assertEquals(Expense::STATUS_SUBMITTED, $expense->fresh()->status);

        // Now mock for successful retry
        $mockNotificationSuccess = $this->mock(DocumentNotificationService::class);
        $mockNotificationSuccess->shouldReceive('resolveApproval')->once();
        $mockNotificationSuccess->shouldReceive('resolveRevision')->once();

        // The user can immediately retry approval without waiting for idempotency timeout
        $retryResponse = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('expenses.approve', $expense));

        $retryResponse->assertSessionHasNoErrors();
        $this->assertEquals(Expense::STATUS_APPROVED, $expense->fresh()->status);
    }

    public function test_failed_403_approval_releases_claim_and_can_be_retried_after_cause_corrected(): void
    {
        $expense = $this->createSubmittedExpense('EXP-403-001');

        $otherSetting = Setting::create([
            'company_name' => 'Other Branch',
            'company_email' => 'other@test.com',
            'company_phone' => '9999',
            'company_address' => 'Other Address',
            'notification_email' => 'other@test.com',
            'footer_text' => 'Footer',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        // Attempt approval with mismatched active setting -> 403 forbidden
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $otherSetting->id])
            ->post(route('expenses.approve', $expense))
            ->assertStatus(403);

        // Expense remains submitted
        $this->assertEquals(Expense::STATUS_SUBMITTED, $expense->fresh()->status);

        // After user switches to the correct setting, retry must NOT be blocked by retained claim
        $retryResponse = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('expenses.approve', $expense));

        $retryResponse->assertSessionHasNoErrors();
        $this->assertEquals(Expense::STATUS_APPROVED, $expense->fresh()->status);
    }

    public function test_explicit_token_scoped_to_expense_allows_approving_distinct_expenses_with_same_token(): void
    {
        $expense1 = $this->createSubmittedExpense('EXP-TOKEN-001');
        $expense2 = $this->createSubmittedExpense('EXP-TOKEN-002');

        $sharedClientToken = 'shared-uuid-client-12345';

        // Approve first expense with explicit client token
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->withHeaders(['X-Idempotency-Token' => $sharedClientToken])
            ->post(route('expenses.approve', $expense1))
            ->assertSessionHasNoErrors();

        $this->assertEquals(Expense::STATUS_APPROVED, $expense1->fresh()->status);

        // Reusing the same explicit client token on a different expense must succeed (no collision)
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->withHeaders(['X-Idempotency-Token' => $sharedClientToken])
            ->post(route('expenses.approve', $expense2))
            ->assertSessionHasNoErrors();

        $this->assertEquals(Expense::STATUS_APPROVED, $expense2->fresh()->status);

        // Repeating same explicit token on the first expense must be rejected
        $duplicateResponse = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->withHeaders(['X-Idempotency-Token' => $sharedClientToken])
            ->post(route('expenses.approve', $expense1));

        $duplicateResponse->assertSessionHasErrors(['idempotency']);
    }

    public function test_stale_pre_fetched_submitted_instance_cannot_approve_already_approved_expense(): void
    {
        $expense = $this->createSubmittedExpense('EXP-RACE-001');

        $expenseService = app(\Modules\Expense\Services\ExpenseService::class);
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id]);

        // Simulate two concurrent requests fetching the model while both see STATUS_SUBMITTED
        $instanceA = Expense::find($expense->id);
        $instanceB = Expense::find($expense->id);

        $this->assertEquals(Expense::STATUS_SUBMITTED, $instanceA->status);
        $this->assertEquals(Expense::STATUS_SUBMITTED, $instanceB->status);

        // Request A commits the approval
        $expenseService->approve($instanceA);
        $this->assertEquals(Expense::STATUS_APPROVED, $instanceA->status);
        $this->assertEquals(Expense::STATUS_APPROVED, $expense->fresh()->status);

        // Request B (holding stale in-memory instance with status SUBMITTED) attempts approval
        // The atomic lockForUpdate query inside DB::transaction detects the committed state and aborts with 403
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $expenseService->approve($instanceB);
    }
}

<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Spatie\Permission\Models\Permission;

class POSReturnApprovalPreviewRouteTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $approver;

    protected $viewer;

    protected $terminal;

    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Approval Preview Route Test');

        foreach ([
            'pos.returns.view',
            'pos.returns.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Approver', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.approve',
        ]);

        $this->viewer = $this->createUserForSetting($this->setting, 'POS Return Viewer', [
            'pos.access',
            'pos.returns.view',
        ]);

        [$this->terminal] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->approver);
    }

    /** @test */
    public function it_requires_approve_permission_to_open_approval_preview(): void
    {
        $posReturn = $this->createPosReturn();

        $this->actingAsInSetting($this->viewer, $this->setting);

        $this->get(route('pos.returns.approval-preview', $posReturn))->assertStatus(403);
    }

    /** @test */
    public function it_blocks_preview_for_non_pending_returns_without_mutating_anything(): void
    {
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertRedirect(route('pos.returns.show', $posReturn));

        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'approved_by' => $this->approver->id,
        ]);
    }

    /** @test */
    public function preview_route_opens_preview_without_approving_immediately(): void
    {
        $posReturn = $this->createPosReturn();

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertOk()
            ->assertSee('Preview Persetujuan Retur POS')
            ->assertSee('Persetujuan final belum tersedia')
            ->assertDontSee('Setujui Retur');

        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /** @test */
    public function direct_approval_post_is_blocked_and_redirects_to_preview_without_mutating(): void
    {
        $posReturn = $this->createPosReturn();

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->post(route('pos.returns.approve', $posReturn), [
            'return_option' => PosReturn::OPTION_CASH_RETURN,
        ]);

        $response->assertRedirect(route('pos.returns.approval-preview', $posReturn));

        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    protected function createPosReturn(array $overrides = []): PosReturn
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->approver->id,
            'owner_user_id' => $this->approver->id,
            'last_saved_by' => $this->approver->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->approver->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 100000,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        return PosReturn::query()->create(array_merge([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'source_snapshot' => [
                'header' => [
                    'transaction_code' => $transaction->code,
                    'receipt_number' => $checkout->receipt_number,
                    'customer_name' => 'Preview Customer',
                    'date' => now()->toIso8601String(),
                    'grand_total' => 100000,
                ],
                'payments' => [
                    ['method_name' => 'Tunai', 'amount' => 100000],
                ],
            ],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 100,
            'created_by' => $this->approver->id,
            'updated_by' => $this->approver->id,
        ], $overrides));
    }
}
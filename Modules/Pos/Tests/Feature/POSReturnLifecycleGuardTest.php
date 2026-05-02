<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Spatie\Permission\Models\Permission;

class POSReturnLifecycleGuardTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $manager;

    protected $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Lifecycle Guard Test');

        foreach ([
            'pos.returns.view',
            'pos.returns.edit',
            'pos.returns.delete',
            'pos.returns.approve',
            'pos.returns.receive',
            'pos.returns.settle',
            'pos.returns.dispatch',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->manager = $this->createUserForSetting($this->setting, 'POS Return Guard Manager', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.edit',
            'pos.returns.delete',
            'pos.returns.approve',
            'pos.returns.receive',
            'pos.returns.settle',
            'pos.returns.dispatch',
        ]);

        $this->viewer = $this->createUserForSetting($this->setting, 'POS Return Guard Viewer', [
            'pos.access',
            'pos.returns.view',
        ]);
    }

    /** @test */
    public function it_blocks_edit_and_delete_after_approval()
    {
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        $this->actingAsInSetting($this->manager, $this->setting);

        $this->get(route('pos.returns.edit', $posReturn))->assertStatus(403);
        $this->delete(route('pos.returns.destroy', $posReturn))->assertStatus(403);

        $this->assertDatabaseHas('pos_returns', ['id' => $posReturn->id]);
    }

    /** @test */
    public function it_blocks_reject_after_approval_and_after_receiving()
    {
        $approvedReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        $receivedReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_AWAITING_SETTLEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'received_by' => $this->manager->id,
            'received_at' => now(),
        ]);

        $this->actingAsInSetting($this->manager, $this->setting);

        $this->post(route('pos.returns.reject', $approvedReturn), ['reason' => 'Should fail'])->assertRedirect();
        $this->post(route('pos.returns.reject', $receivedReturn), ['reason' => 'Should fail'])->assertRedirect();

        $this->assertSame(PosReturn::STATUS_APPROVED, $approvedReturn->fresh()->status);
        $this->assertSame(PosReturn::STATUS_AWAITING_SETTLEMENT, $receivedReturn->fresh()->status);
    }

    /** @test */
    public function it_requires_approve_permission_before_approval()
    {
        $posReturn = $this->createPosReturn();

        $this->actingAsInSetting($this->viewer, $this->setting);

        $this->post(route('pos.returns.approve', $posReturn))->assertStatus(403);
    }

    /** @test */
    public function it_requires_receive_permission_before_receiving()
    {
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        $this->actingAsInSetting($this->viewer, $this->setting);

        $this->post(route('pos.returns.receive', $posReturn))->assertStatus(403);
    }

    /** @test */
    public function it_requires_settle_permission_before_cash_settlement()
    {
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_AWAITING_SETTLEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'received_by' => $this->manager->id,
            'received_at' => now(),
        ]);

        $this->actingAsInSetting($this->viewer, $this->setting);

        $this->post(route('pos.returns.settle', $posReturn))->assertStatus(403);
    }

    /** @test */
    public function it_requires_dispatch_permission_before_replacement_dispatch()
    {
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_AWAITING_DISPATCH,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'received_by' => $this->manager->id,
            'received_at' => now(),
        ]);

        $this->actingAsInSetting($this->viewer, $this->setting);

        $this->post(route('pos.returns.dispatch', $posReturn))->assertStatus(403);
    }

    protected function createPosReturn(array $overrides = []): PosReturn
    {
        return PosReturn::query()->create(array_merge([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 100,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ], $overrides));
    }
}
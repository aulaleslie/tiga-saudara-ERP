<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Spatie\Permission\Models\Permission;

class POSReturnDraftListActionsTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Draft List Actions');

        foreach ([
            'pos.returns.view',
            'pos.returns.edit',
            'pos.returns.delete',
            'pos.returns.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Draft List User', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.edit',
            'pos.returns.delete',
            'pos.returns.approve',
        ]);
    }

    /** @test */
    public function it_shows_draft_actions_for_authorized_draft_rows(): void
    {
        $draftReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_DRAFT,
            'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
        ]);

        $this->actingAsInSetting($this->user, $this->setting);

        $response = $this->get(route('pos.returns.index'));

        $response->assertOk();
        $response->assertSee(route('pos.returns.edit', $draftReturn), false);
        $response->assertSee(route('pos.returns.submit-draft', $draftReturn), false);
        $response->assertSee('Edit', false);
        $response->assertSee('Delete', false);
        $response->assertSee('Ajukan Persetujuan', false);
        $response->assertSee('id="posReturnListActionModal"', false);
        $response->assertSee('data-pos-return-list-modal-trigger', false);
        $response->assertDontSee('confirm(', false);
        $response->assertDontSee('prompt(', false);
    }

    /** @test */
    public function it_shows_edit_and_delete_for_rejected_rows_but_not_submit(): void
    {
        $rejectedReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_REJECTED,
            'approval_status' => PosReturn::APPROVAL_STATUS_REJECTED,
        ]);

        $this->actingAsInSetting($this->user, $this->setting);

        $response = $this->get(route('pos.returns.index'));

        $response->assertOk();
        $response->assertSee(route('pos.returns.edit', $rejectedReturn), false);
        $response->assertSee(route('pos.returns.destroy', $rejectedReturn), false);
        $response->assertDontSee(route('pos.returns.submit-draft', $rejectedReturn), false);
    }

    /** @test */
    public function it_hides_draft_actions_for_non_draft_rows(): void
    {
        $pendingReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
        ]);

        $this->actingAsInSetting($this->user, $this->setting);

        $response = $this->get(route('pos.returns.index'));

        $response->assertOk();
        $response->assertSee(route('pos.returns.show', $pendingReturn), false);
        $response->assertDontSee(route('pos.returns.edit', $pendingReturn), false);
        $response->assertDontSee(route('pos.returns.submit-draft', $pendingReturn), false);
        $response->assertDontSee('Ajukan Persetujuan', false);
        $response->assertDontSee('Delete', false);
        $response->assertDontSee('Edit', false);
    }

    /** @test */
    public function it_shows_pending_approval_actions_for_authorized_rows(): void
    {
        $pendingReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
        ]);

        $this->actingAsInSetting($this->user, $this->setting);

        $response = $this->get(route('pos.returns.index'));

        $response->assertOk();
        $response->assertSee(route('pos.returns.approval-preview', $pendingReturn), false);
        $response->assertSee(route('pos.returns.reject', $pendingReturn), false);
        $response->assertSee('Preview Persetujuan', false);
        $response->assertSee('Tolak', false);
        $response->assertDontSee('id="posReturnListApproveModal"', false);
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
            'status' => PosReturn::STATUS_DRAFT,
            'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
            'total_amount' => 100,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ], $overrides));
    }
}
<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Spatie\Permission\Models\Permission;

class POSReturnRouteAuthorizationTest extends PosTransactionFeatureTestCase
{
    protected $user;

    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Route Authorization');
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Route User', []);
        
        // Ensure permissions exist
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.view', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
    }

    /** @test */
    public function guest_cannot_access_pos_returns()
    {
        $this->get(route('pos.returns.index'))->assertRedirect(route('login'));
    }

    /** @test */
    public function user_without_permission_cannot_access_pos_returns()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        $this->get(route('pos.returns.index'))->assertStatus(403);
    }

    /** @test */
    public function user_with_permission_can_access_pos_returns_index()
    {
        $this->user->givePermissionTo(['pos.access', 'pos.returns.view']);
        
        $this->actingAsInSetting($this->user, $this->setting);
        $this->get(route('pos.returns.index'))->assertStatus(200);
    }

    /** @test */
    public function user_without_create_permission_cannot_access_pos_returns_create()
    {
        $this->user->givePermissionTo(['pos.access', 'pos.returns.view']);
        
        $this->actingAsInSetting($this->user, $this->setting);
        $this->get(route('pos.returns.create'))->assertStatus(403);
    }

    /** @test */
    public function user_with_create_permission_can_access_pos_returns_create()
    {
        $this->user->givePermissionTo(['pos.access', 'pos.returns.view', 'pos.returns.create']);
        
        $this->actingAsInSetting($this->user, $this->setting);
        $this->get(route('pos.returns.create'))->assertStatus(200);
    }

    /** @test */
    public function user_without_approve_permission_cannot_submit_draft_for_approval()
    {
        $this->user->givePermissionTo(['pos.access', 'pos.returns.view']);

        $posReturn = PosReturn::query()->create([
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
        ]);

        $this->actingAsInSetting($this->user, $this->setting);
        $this->post(route('pos.returns.submit-draft', $posReturn))->assertStatus(403);
    }
}

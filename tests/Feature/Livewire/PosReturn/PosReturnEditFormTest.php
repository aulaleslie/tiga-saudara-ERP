<?php

namespace Tests\Feature\Livewire\PosReturn;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosReturn\PosReturnEditForm;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Modules\Setting\Entities\Setting;
use Modules\Pos\Entities\PosReturn;

class PosReturnEditFormTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $admin;
    protected $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        Permission::findOrCreate('pos.returns.edit', 'web');

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('pos.returns.edit');

        $this->clerk = User::factory()->create();
    }

    /** @test */
    public function it_denies_access_for_unauthorized_user()
    {
        $this->actingAs($this->clerk);
        
        $posReturn = $this->createReturn('RET-001', PosReturn::STATUS_DRAFT, PosReturn::APPROVAL_STATUS_DRAFT);

        Livewire::test(PosReturnEditForm::class, ['return' => $posReturn])
            ->assertStatus(403);
    }

    /** @test */
    public function it_denies_access_for_non_draft_rejected_returns()
    {
        $this->actingAs($this->admin);
        
        // PENDING_APPROVAL is now NOT editable in the draft-centric workflow
        $posReturn = $this->createReturn('RET-002', PosReturn::STATUS_PENDING_APPROVAL, PosReturn::APPROVAL_STATUS_PENDING);

        Livewire::test(PosReturnEditForm::class, ['return' => $posReturn])
            ->assertStatus(403);
    }

    protected function createReturn($ref, $status, $approvalStatus = 'pending')
    {
        return PosReturn::create([
            'setting_id' => $this->setting->id,
            'reference' => $ref,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-1',
            'receipt_number' => 'RCP-1',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => $status,
            'approval_status' => $approvalStatus,
            'source_snapshot' => ['header' => ['transaction_code' => 'TXN-1', 'receipt_number' => 'RCP-1', 'date' => now()->toDateTimeString()], 'lines' => [], 'payments' => []],
            'source_snapshot_hash' => 'HASH-1',
            'total_amount' => 100,
        ]);
    }
}

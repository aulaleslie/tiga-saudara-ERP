<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferListEditActionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Location $origin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => 'stockTransfers.edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'stockTransfers.show', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'stockTransfers.delete', 'guard_name' => 'web']);

        $this->actingAs($this->user);
    }

    private function makeTransfer(string $status): Transfer
    {
        return Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => null,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
    }

    /** @test */
    public function edit_action_is_shown_for_draft_with_permission()
    {
        $this->user->givePermissionTo('stockTransfers.edit');
        $data = $this->makeTransfer(Transfer::STATUS_DRAFT);

        $html = view('adjustment::transfers.partials.actions', compact('data'))->render();

        $this->assertStringContainsString(route('transfers.edit', $data->id), $html);
    }

    /** @test */
    public function edit_action_is_shown_for_pending_with_permission()
    {
        $this->user->givePermissionTo('stockTransfers.edit');
        $data = $this->makeTransfer(Transfer::STATUS_PENDING);

        $html = view('adjustment::transfers.partials.actions', compact('data'))->render();

        $this->assertStringContainsString(route('transfers.edit', $data->id), $html);
    }

    /** @test */
    public function edit_action_is_hidden_without_permission()
    {
        $data = $this->makeTransfer(Transfer::STATUS_DRAFT);

        $html = view('adjustment::transfers.partials.actions', compact('data'))->render();

        $this->assertStringNotContainsString(route('transfers.edit', $data->id), $html);
    }

    /** @test */
    public function edit_action_is_hidden_for_approved_transfers()
    {
        $this->user->givePermissionTo('stockTransfers.edit');
        $data = $this->makeTransfer(Transfer::STATUS_APPROVED);

        $html = view('adjustment::transfers.partials.actions', compact('data'))->render();

        $this->assertStringNotContainsString(route('transfers.edit', $data->id), $html);
    }
}

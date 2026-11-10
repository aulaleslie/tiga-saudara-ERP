<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Existing document-notification conventions for the buyout workflow (approval needed / revision needed / resolve). */
class ConsignmentBuyoutNotificationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
    }

    private function roleUser(string $roleName, array $permissions): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'web');
            $role->givePermissionTo($p);
        }
        $u = User::factory()->create(['is_active' => 1]);
        $u->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        return $u;
    }

    /** @test */
    public function submit_notifies_approvers_reject_notifies_editors_and_completion_resolves_them(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate('consignments.buyouts.reject', 'web');
        $this->user->givePermissionTo('consignments.buyouts.reject');
        $approver = $this->roleUser('Buyout Approver', ['consignments.buyouts.approve']);
        $editor = $this->roleUser('Buyout Editor', ['consignments.buyouts.edit']);
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $line = ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => '2', 'buckets' => ['NON_TAXED' => '2']];

        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [$line], $this->user, $this->meta());
        $this->assertSame(0, Notification::count(), 'drafts notify nobody');

        $b = $this->life->submit($b, $this->user);
        $approval = Notification::where('category', 'approval')->get();
        $this->assertCount(1, $approval);
        $this->assertSame($approver->id, (int) $approval->first()->user_id);
        $this->assertStringContainsString($b->reference, $approval->first()->message);
        $this->assertStringContainsString('Pembelian Putus Konsinyasi', $approval->first()->title);
        $this->assertSame(route('consignments.buyouts.show', $b->id, false), $approval->first()->action_url);

        $b = $this->life->reject($b, 'jumlah salah', $this->user);
        $revision = Notification::where('category', 'revision')->get();
        $this->assertCount(1, $revision);
        $this->assertSame($editor->id, (int) $revision->first()->user_id);
        $this->assertStringContainsString('jumlah salah', $revision->first()->message);
        $this->assertNotNull(Notification::where('category', 'approval')->first()->resolved_at, 'rejection resolves the approval request');

        // Revising clears the revision request; the next approval completes and resolves the new request.
        $b = $this->life->reviseDraft($b, [$line], $this->user);
        $this->assertNotNull(Notification::where('category', 'revision')->first()->resolved_at);
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);
        $this->assertSame('APPROVED', $b->status);
        $this->assertSame(0, Notification::where('category', 'approval')->whereNull('resolved_at')->count());
    }

    /** @test */
    public function a_notification_failure_never_blocks_the_transition(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $this->app->bind(\App\Services\Notification\DocumentNotificationService::class, fn () => throw new \RuntimeException('notification backend down'));

        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => '1', 'buckets' => ['NON_TAXED' => '1']]], $this->user, $this->meta());
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);

        $this->assertSame('APPROVED', $b->status);
        $this->assertSame(2, $b->reservations()->where('status', 'ACTIVE')->count());
    }
}

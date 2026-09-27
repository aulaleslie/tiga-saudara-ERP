<?php

namespace Tests\Feature\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PermissionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TestDocumentNotificationService extends DocumentNotificationService
{
    protected function getConfig(Model $document, string $workflow = 'default'): ?array
    {
        if ($workflow === 'dispatch') {
            return [
                'approval_permission' => 'test.dispatchApproval',
                'edit_permission' => 'test.dispatchEdit',
                'title_prefix' => 'Test Dispatch',
                'route_prefix' => 'test-dispatch',
            ];
        }
        return [
            'approval_permission' => 'test.approval',
            'edit_permission' => 'test.edit',
            'title_prefix' => 'Test Document',
            'route_prefix' => 'test-documents',
        ];
    }
}

class DocumentNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TestDocumentNotificationService $documentService;
    protected Setting $setting1;
    protected User $manager1;
    protected User $manager2;
    protected User $document; // Reusing user model as a dummy document to satisfy eloquent model typehint

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentService = new TestDocumentNotificationService(
            app(NotificationService::class),
            app(PermissionResolver::class)
        );

        $this->setting1 = Setting::factory()->create();

        $approvalPermission = Permission::firstOrCreate(['name' => 'test.approval', 'guard_name' => 'web']);
        $editPermission = Permission::firstOrCreate(['name' => 'test.edit', 'guard_name' => 'web']);
        
        $role1 = Role::firstOrCreate(['name' => 'Manager 1', 'guard_name' => 'web']);
        $role1->givePermissionTo($approvalPermission);
        $role1->givePermissionTo($editPermission);

        $role2 = Role::firstOrCreate(['name' => 'Manager 2', 'guard_name' => 'web']); // no permissions

        $this->manager1 = User::factory()->create(['is_active' => 1]);
        $this->manager1->settings()->attach($this->setting1->id, ['role_id' => $role1->id]);

        $this->manager2 = User::factory()->create(['is_active' => 1]);
        $this->manager2->settings()->attach($this->setting1->id, ['role_id' => $role2->id]);

        $this->document = User::factory()->create(); // dummy model representing document
    }

    public function test_notify_approval_needed_creates_notifications_for_permitted_users()
    {
        $this->documentService->notifyApprovalNeeded($this->document, 'DOC-001', $this->setting1->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->manager1->id,
            'setting_id' => $this->setting1->id,
            'category' => 'approval',
            'source_type' => User::class,
            'source_id' => $this->document->id,
            'title' => 'Persetujuan Test Document Dibutuhkan',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->manager2->id,
        ]);
    }

    public function test_notify_approval_needed_prevents_duplicates()
    {
        $this->documentService->notifyApprovalNeeded($this->document, 'DOC-001', $this->setting1->id);
        $this->documentService->notifyApprovalNeeded($this->document, 'DOC-001', $this->setting1->id);

        $this->assertEquals(1, Notification::where('user_id', $this->manager1->id)->count());
    }

    public function test_resolve_approval_resolves_active_notifications()
    {
        $this->documentService->notifyApprovalNeeded($this->document, 'DOC-001', $this->setting1->id);
        $this->assertEquals(1, Notification::unresolved()->count());

        $this->documentService->resolveApproval($this->document);
        $this->assertEquals(0, Notification::unresolved()->count());
    }

    public function test_notify_revision_needed_creates_notifications()
    {
        $this->documentService->notifyRevisionNeeded($this->document, 'DOC-001', $this->setting1->id, 'Missing file');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->manager1->id,
            'category' => 'revision',
            'title' => 'Revisi Test Document Dibutuhkan',
        ]);
    }

    public function test_resolve_revision_resolves_active_notifications()
    {
        $this->documentService->notifyRevisionNeeded($this->document, 'DOC-001', $this->setting1->id);
        $this->assertEquals(1, Notification::unresolved()->count());

        $this->documentService->resolveRevision($this->document);
        $this->assertEquals(0, Notification::unresolved()->count());
    }

    public function test_different_workflows_create_different_categories()
    {
        // Add permissions for dispatch
        $dispatchPermission = Permission::firstOrCreate(['name' => 'test.dispatchApproval', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Dispatcher', 'guard_name' => 'web']);
        $role->givePermissionTo($dispatchPermission);
        
        $dispatcher = User::factory()->create(['is_active' => 1]);
        $dispatcher->settings()->attach($this->setting1->id, ['role_id' => $role->id]);

        $this->documentService->notifyApprovalNeeded($this->document, 'DOC-001', $this->setting1->id, null, 'dispatch');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $dispatcher->id,
            'category' => 'approval:dispatch',
            'title' => 'Persetujuan Test Dispatch Dibutuhkan',
        ]);
    }
}

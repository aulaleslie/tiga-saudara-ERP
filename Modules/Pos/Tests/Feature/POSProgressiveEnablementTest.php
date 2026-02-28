<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSProgressiveEnablementTest extends TestCase
{
    use RefreshDatabase;

    private string $activationChecklistPath;
    private string $supportRunbookPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activationChecklistPath = base_path('docs/pos/pos-mvp-activation-checklist.md');
        $this->supportRunbookPath = base_path('docs/pos/pos-mvp-support-runbook.md');

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('sales.access', 'web');
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.sell', 'web');
    }

    public function test_activation_checklist_document_exists_and_covers_requirements(): void
    {
        $this->assertTrue(File::exists($this->activationChecklistPath), 'Activation checklist document is missing.');

        $content = File::get($this->activationChecklistPath);

        $this->assertStringContainsStringIgnoringCase('Pre-Activation Prerequisites', $content);
        $this->assertStringContainsStringIgnoringCase('Activation Steps', $content);
        $this->assertStringContainsStringIgnoringCase('Post-Activation Verification', $content);
        $this->assertStringContainsStringIgnoringCase('Rollback Procedure', $content);
        $this->assertStringContainsStringIgnoringCase('Sign-off', $content);
    }

    public function test_support_runbook_document_exists_and_covers_requirements(): void
    {
        $this->assertTrue(File::exists($this->supportRunbookPath), 'Support runbook document is missing.');

        $content = File::get($this->supportRunbookPath);

        $this->assertStringContainsStringIgnoringCase('Escalation Contacts', $content);
        $this->assertStringContainsStringIgnoringCase('Common Issue Triage', $content);
        $this->assertStringContainsStringIgnoringCase('Emergency Rollback Procedure', $content);
        $this->assertStringContainsStringIgnoringCase('Diagnostic Queries', $content);
    }

    public function test_progressive_enablement_multiple_businesses_isolation(): void
    {
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);
        $user = User::factory()->create();
        $user->assignRole($cashierRole);
        $user->givePermissionTo(['sales.access', 'pos.access', 'pos.sell']);

        $businessA = Setting::create([
            'company_name' => 'Business A',
            'company_email' => 'business.a@example.com',
            'company_phone' => '08001',
            'company_address' => 'Address A',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify.a@example.com',
            'footer_text' => 'Footer A',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true, // Enabled progressively
        ]);

        $businessB = Setting::create([
            'company_name' => 'Business B',
            'company_email' => 'business.b@example.com',
            'company_phone' => '08002',
            'company_address' => 'Address B',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify.b@example.com',
            'footer_text' => 'Footer B',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => false, // Still legacy only
        ]);

        $user->settings()->attach([
            $businessA->id => ['role_id' => $cashierRole->id],
            $businessB->id => ['role_id' => $cashierRole->id],
        ]);

        // Assert Business A can access POS shell bounds (will hit session guard and redirect to open)
        $responseA = $this->actingAs($user)->withSession(['setting_id' => $businessA->id])->get(route('pos.sell'));
        $responseA->assertRedirect(route('pos.sessions.create'));

        // Assert Business B cannot access POS shell bounds
        $responseB = $this->actingAs($user)->withSession(['setting_id' => $businessB->id])->get(route('pos.sell'));
        $responseB->assertRedirect(route('sales.index'));
        $responseB->assertSessionHas('warning', 'POS is disabled for the current business.');

        // Now progress rollout and enable B, but roll back A
        $businessA->update(['pos_enabled' => false]);
        $businessB->update(['pos_enabled' => true]);

        // Reversed assertions
        $responseRolledBackA = $this->actingAs($user)->withSession(['setting_id' => $businessA->id])->get(route('pos.sell'));
        $responseRolledBackA->assertRedirect(route('sales.index'));

        $responseEnabledB = $this->actingAs($user)->withSession(['setting_id' => $businessB->id])->get(route('pos.sell'));
        $responseEnabledB->assertRedirect(route('pos.sessions.create'));
    }
}

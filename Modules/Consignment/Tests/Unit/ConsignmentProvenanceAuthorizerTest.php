<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Services\ConsignmentProvenanceAuthorizer;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsignmentProvenanceAuthorizerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    private Setting $remote;
    private ConsignmentProvenanceAuthorizer $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuyoutFixtures();
        $this->remote = Setting::create([
            'company_name' => 'PERDANA', 'company_email' => 'p@example.com', 'company_phone' => '0812',
            'default_currency_id' => $this->setting->default_currency_id, 'default_currency_position' => 'prefix',
            'notification_email' => 'p@example.com', 'footer_text' => 'F', 'company_address' => 'A', 'is_pkp' => false, 'document_prefix' => 'PD',
        ]);
        $this->auth = new ConsignmentProvenanceAuthorizer;
    }

    private function user(array $settings, array $perms): User
    {
        $u = User::factory()->create();
        foreach ($settings as $s) {
            $u->settings()->attach($s->id, ['role_id' => Role::findOrCreate('PV', 'web')->id]);
        }
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
            $u->givePermissionTo($p);
        }

        return $u;
    }

    /** @test */
    public function origin_only_biller_cannot_acquire_remote_stock()
    {
        $u = $this->user([$this->setting], ['consignments.buyouts.acquire_unsold', 'consignments.buyouts.acquire_remote']);
        $this->auth->authorizeAcquisition($u, $this->setting->id, [$this->setting->id]); // local is fine

        $this->expectException(AuthorizationException::class);
        $this->auth->authorizeAcquisition($u, $this->setting->id, [$this->setting->id, $this->remote->id]);
    }

    /** @test */
    public function remote_membership_without_delegation_is_refused()
    {
        $u = $this->user([$this->setting, $this->remote], ['consignments.buyouts.acquire_unsold']);
        $this->expectException(AuthorizationException::class);
        $this->auth->authorizeAcquisition($u, $this->setting->id, [$this->remote->id]);
    }

    /** @test */
    public function delegated_member_of_both_businesses_may_acquire_remote()
    {
        $u = $this->user([$this->setting, $this->remote], ['consignments.buyouts.acquire_unsold', 'consignments.buyouts.acquire_remote']);
        $this->auth->authorizeAcquisition($u, $this->setting->id, [$this->remote->id]);
        $this->assertTrue(true);
    }

    /** @test */
    public function reconciliation_is_scoped_to_the_original_business()
    {
        $u = $this->user([$this->remote], ['consignments.provenance.reconcile']);
        $this->auth->authorizeOriginReconciliation($u, $this->remote->id);

        $this->expectException(AuthorizationException::class);
        $this->auth->authorizeOriginReconciliation($u, $this->setting->id);
    }

    /** @test */
    public function permissions_are_registered_in_the_catalog()
    {
        $flat = collect(require base_path('app/Config/Permissions.php'))->flatMap(fn ($g) => array_keys($g));
        $this->assertContains('consignments.provenance.reconcile', $flat);
        $this->assertContains('consignments.buyouts.acquire_remote', $flat);
    }
}

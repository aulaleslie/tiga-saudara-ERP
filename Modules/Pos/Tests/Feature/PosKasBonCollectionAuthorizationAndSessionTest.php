<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosKasBonReceivingSessionResolver;
use Modules\Pos\Support\PosPermissionMatrix;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosKasBonCollectionAuthorizationAndSessionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting1;
    protected Setting $setting2;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected Customer $customer;
    protected PosTransaction $transaction;
    protected PosKasBonReceivingSessionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PosKasBonReceivingSessionResolver();

        $this->setting1 = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Setting 1',
            'company_email' => 's1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->setting2 = Setting::firstOrCreate(['id' => 2], [
            'company_name' => 'Setting 2',
            'company_email' => 's2@example.com',
            'company_phone' => '0812',
            'company_address' => 'Addr 2',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's2@example.com',
            'footer_text' => 'F2',
            'document_prefix' => 'D2',
            'purchase_prefix_document' => 'P2',
            'sale_prefix_document' => 'S2',
            'pos_enabled' => true,
        ]);

        $this->user = User::factory()->create();

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'T1',
            'code' => 'T1',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'expected_cash_total' => 100000,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer Test',
            'customer_phone' => '0812345678',
            'customer_email' => 'test@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street',
        ]);

        $this->transaction = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'code' => 'TRX-KB-001',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        // Ensure permissions exist in DB
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.kasBon.collect', 'web');
        Permission::findOrCreate('posPayments.global.access', 'web');
        Permission::findOrCreate('posPayments.global.create', 'web');
    }

    public function test_pos_permission_matrix_registers_kas_bon_collect_in_cashier_and_manager_bundles(): void
    {
        $bundles = PosPermissionMatrix::supportedBundles();
        $this->assertContains('pos.kasBon.collect', $bundles['cashier']['permissions']);
        $this->assertContains('pos.kasBon.collect', $bundles['manager']['permissions']);

        $clusters = PosPermissionMatrix::capabilityClusters();
        $this->assertContains('pos.kasBon.collect', $clusters['checkout']['permissions']);
    }

    public function test_unauthenticated_request_is_redirected(): void
    {
        $this->get(route('pos.kas-bon.candidates'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_user_without_pos_access_is_forbidden(): void
    {
        $unauth = User::factory()->create();

        $this->actingAs($unauth)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('pos.kas-bon.candidates'))
            ->assertStatus(403);
    }

    public function test_user_with_pos_access_but_without_kas_bon_collect_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.access');

        $this->actingAs($user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('pos.kas-bon.candidates'))
            ->assertStatus(403);

        $this->actingAs($user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('pos.kas-bon.review', $this->transaction->id))
            ->assertStatus(403);

        $this->actingAs($user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->post(route('pos.kas-bon.collect', $this->transaction->id))
            ->assertStatus(403);
    }

    public function test_global_payment_permission_does_not_grant_kas_bon_collection_access(): void
    {
        $globalUser = User::factory()->create();
        $globalUser->givePermissionTo('posPayments.global.access');

        $this->actingAs($globalUser)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('pos.kas-bon.candidates'))
            ->assertStatus(403);
    }

    public function test_kas_bon_collect_permission_does_not_grant_global_payment_access(): void
    {
        $kasBonUser = User::factory()->create();
        $kasBonUser->givePermissionTo(['pos.access', 'pos.kasBon.collect']);

        $this->actingAs($kasBonUser)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('pos.global-payments.index'))
            ->assertStatus(403);
    }

    public function test_authorized_user_with_active_open_session_can_access(): void
    {
        $this->user->givePermissionTo(['pos.access', 'pos.kasBon.collect']);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->getJson(route('pos.kas-bon.candidates'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'session_id' => $this->session->id,
            ]);
    }

    public function test_authorized_user_with_terminal_less_open_session_can_access(): void
    {
        $terminalLessCashier = User::factory()->create();
        $terminalLessCashier->givePermissionTo(['pos.access', 'pos.kasBon.collect']);

        $terminalLessSession = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => null,
            'cashier_user_id' => $terminalLessCashier->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 2,
            'expected_cash_total' => 25000,
        ]);

        $response = $this->actingAs($terminalLessCashier)
            ->withSession(['setting_id' => $this->setting1->id])
            ->getJson(route('pos.kas-bon.candidates'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'session_id' => $terminalLessSession->id,
            ]);
    }

    public function test_resolver_rejects_missing_session(): void
    {
        $userWithoutSession = User::factory()->create();

        $this->expectException(ValidationException::class);
        $this->resolver->resolve($userWithoutSession, $this->setting1->id);
    }

    public function test_resolver_rejects_session_for_different_cashier(): void
    {
        $otherCashier = User::factory()->create();

        $this->expectException(ValidationException::class);
        // Actor is otherCashier, but session belongs to $this->user
        $this->resolver->resolve($otherCashier, $this->setting1->id);
    }

    public function test_resolver_rejects_session_in_different_business_setting(): void
    {
        $this->expectException(ValidationException::class);
        // Session exists in setting1, resolving in setting2
        $this->resolver->resolve($this->user, $this->setting2->id);
    }

    public function test_resolver_rejects_closing_or_closed_session(): void
    {
        $closingUser = User::factory()->create();
        $closingSession = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $closingUser->id,
            'opened_at' => now()->subHours(2),
            'status' => PosSession::STATUS_CLOSING,
            'active_marker' => null,
            'expected_cash_total' => 100000,
        ]);

        $this->expectException(ValidationException::class);
        $this->resolver->resolve($closingUser, $this->setting1->id);
    }

    public function test_resolver_validates_terminal_and_terminal_less_open_sessions(): void
    {
        // 1. Terminal session
        $resolved = $this->resolver->resolve($this->user, $this->setting1->id);
        $this->assertEquals($this->session->id, $resolved->id);
        $this->assertEquals($this->terminal->id, $resolved->terminal_id);

        // 2. Terminal-less session
        $cashierB = User::factory()->create();
        $sessionB = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => null,
            'cashier_user_id' => $cashierB->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 3,
            'expected_cash_total' => 50000,
        ]);

        $resolvedB = $this->resolver->resolve($cashierB, $this->setting1->id);
        $this->assertEquals($sessionB->id, $resolvedB->id);
        $this->assertNull($resolvedB->terminal_id);
    }
}

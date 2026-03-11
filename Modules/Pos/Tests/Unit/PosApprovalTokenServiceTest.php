<?php

namespace Modules\Pos\Tests\Unit;

use DomainException;
use Tests\TestCase;
use Modules\Pos\Services\PosApprovalTokenService;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosActionApprovalToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;

class PosApprovalTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private PosApprovalTokenService $tokenService;
    private Setting $setting;
    private User $user;
    private PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = new PosApprovalTokenService();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Biz',
            'company_email' => 'test@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => true,
        ]);

        $this->user = User::factory()->create([
            'email' => 'testuser@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('secret'),
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Loc 1',
            'setting_id' => $this->setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'code' => 'TERM-01',
            'name' => 'Term 01',
            'is_active' => true,
        ]);

        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA PM ' . $this->setting->id,
            'account_number' => 'ACC-PM-' . $this->setting->id . '-' . rand(100, 999),
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $method = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
            ['setting_id' => $this->setting->id, 'payment_method_id' => $method->id],
            ['is_enabled' => true]
        );

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $this->user->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);
    }

    public function test_issue_token_creates_token_and_returns_plaintext(): void
    {
        $request = PosActionApprovalRequest::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'action_type' => PosActionApprovalRequest::ACTION_CART_CLEAR,
            'target_type' => 'pos_session',
            'target_id' => $this->session->id,
            'requested_by' => $this->user->id,
            'status' => PosActionApprovalRequest::STATUS_APPROVED,
        ]);

        $plaintext = $this->tokenService->issueToken($request, 10);

        $this->assertNotEmpty($plaintext);
        $this->assertEquals(32, strlen($plaintext));

        $tokenRecord = PosActionApprovalToken::where('approval_request_id', $request->id)->first();
        $this->assertNotNull($tokenRecord);
        $this->assertEquals($plaintext, $tokenRecord->token_hash);
        $this->assertNull($tokenRecord->consumed_at);
        $this->assertTrue($tokenRecord->expires_at->isFuture());
    }

    public function test_validate_and_consume_successful(): void
    {
        $request = PosActionApprovalRequest::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'action_type' => PosActionApprovalRequest::ACTION_CART_CLEAR,
            'target_type' => 'pos_session',
            'target_id' => $this->session->id,
            'requested_by' => $this->user->id,
            'status' => PosActionApprovalRequest::STATUS_APPROVED,
        ]);

        $plaintext = $this->tokenService->issueToken($request, 10);

        $consumedRequest = $this->tokenService->validateAndConsume($plaintext, $this->user->id, ['ip' => '127.0.0.1']);

        $this->assertEquals($request->id, $consumedRequest->id);
        $this->assertEquals(PosActionApprovalRequest::STATUS_CONSUMED, $consumedRequest->status);

        $tokenRecord = PosActionApprovalToken::where('approval_request_id', $request->id)->first();
        $this->assertNotNull($tokenRecord->consumed_at);
        $this->assertEquals($this->user->id, $tokenRecord->consumed_by);
        $this->assertEquals('127.0.0.1', $tokenRecord->consumed_context['ip']);
    }

    public function test_validate_and_consume_fails_if_expired(): void
    {
        $request = PosActionApprovalRequest::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'action_type' => PosActionApprovalRequest::ACTION_CART_CLEAR,
            'target_type' => 'pos_session',
            'target_id' => $this->session->id,
            'requested_by' => $this->user->id,
            'status' => PosActionApprovalRequest::STATUS_APPROVED,
        ]);

        $plaintext = $this->tokenService->issueToken($request, -10); // expires in the past

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TOKEN_INVALID_OR_EXPIRED');

        $this->tokenService->validateAndConsume($plaintext, $this->user->id, []);
    }

    public function test_validate_and_consume_fails_if_already_consumed(): void
    {
        $request = PosActionApprovalRequest::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'action_type' => PosActionApprovalRequest::ACTION_CART_CLEAR,
            'target_type' => 'pos_session',
            'target_id' => $this->session->id,
            'requested_by' => $this->user->id,
            'status' => PosActionApprovalRequest::STATUS_APPROVED,
        ]);

        $plaintext = $this->tokenService->issueToken($request, 10);

        // First consume succeeds
        $this->tokenService->validateAndConsume($plaintext, $this->user->id, []);

        // Second consume fails
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TOKEN_ALREADY_USED');

        $this->tokenService->validateAndConsume($plaintext, $this->user->id, []);
    }
}

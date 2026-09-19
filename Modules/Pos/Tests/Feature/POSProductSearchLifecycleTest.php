<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Structural regression guards for preserve-pos-product-search-per-transaction.
 *
 * These tests inspect the rendered sell-page source to assert that the reset
 * call sites exist in the correct success/failure branches. They do not
 * execute the browser DOM or simulate asynchronous request ordering — actual
 * behavior (modal reopen preservation, live checkout/save-and-new resets,
 * and stale-response rejection) is verified by the task 3.2 manual browser
 * smoke check.
 *
 * @group pos-critical-path
 */
class POSProductSearchLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * Structural guard for task 1.1: Cari Produk open handler source must not
     * unconditionally clear the modal query/results — that cleanup now only
     * happens via the shared reset. Does not simulate an actual reopen; see
     * task 3.2 for the live close/select/reopen preservation check.
     */
    public function test_cari_produk_open_handler_no_longer_clears_modal_state_unconditionally(): void
    {
        $html = $this->getSellPageHtml();

        $handlerStart = strpos($html, "cariProdukButton.addEventListener('click'");
        $this->assertNotFalse($handlerStart, 'Cari Produk click handler not found');

        $handlerEnd = strpos($html, '});', $handlerStart);
        $this->assertNotFalse($handlerEnd);

        $handlerBody = substr($html, $handlerStart, $handlerEnd - $handlerStart);

        $this->assertStringNotContainsString(
            "modalSearchInput.value = '';",
            $handlerBody,
            'Opening the modal must no longer clear the previous query'
        );
        $this->assertStringNotContainsString(
            "searchResultsModalContainer.innerHTML = '';",
            $handlerBody,
            'Opening the modal must no longer clear the previous results'
        );
    }

    /**
     * Structural guard for task 1.2: the shared reset operation's source clears
     * query/results, restores the placeholder, and advances the request
     * generation counter. Does not execute the function or simulate a request.
     */
    public function test_shared_reset_operation_clears_state_and_invalidates_pending_requests(): void
    {
        $html = $this->getSellPageHtml();

        $this->assertStringContainsString('function resetProductSearchModalState()', $html);

        $fnStart = strpos($html, 'function resetProductSearchModalState()');
        $fnEnd = strpos($html, "\n            }", $fnStart);
        $fnBody = substr($html, $fnStart, $fnEnd - $fnStart);

        $this->assertStringContainsString('latestRequestId += 1;', $fnBody);
        $this->assertStringContainsString("modalSearchInput.value = '';", $fnBody);
        $this->assertStringContainsString("searchResultsModalContainer.innerHTML = '';", $fnBody);
        $this->assertStringContainsString('Ketik nama produk atau SKU lalu tekan Cari.', $fnBody);
    }

    /**
     * Structural guard for task 1.2's stale-response rejection: both the
     * success and error branches of executeSearchModal() compare their
     * captured requestId against the (post-reset) latestRequestId before
     * touching the DOM, so a response captured under an older generation is
     * ignored once resetProductSearchModalState() has advanced the counter.
     * This does not simulate an actual delayed fetch resolving after reset;
     * see task 3.2 for that live check.
     */
    public function test_search_response_handlers_guard_against_superseded_request_generation(): void
    {
        $html = $this->getSellPageHtml();

        $fnStart = strpos($html, 'async function executeSearchModal(query)');
        $this->assertNotFalse($fnStart);
        $fnEnd = strpos($html, "\n            }\n", $fnStart);
        $this->assertNotFalse($fnEnd);
        $fnBody = substr($html, $fnStart, $fnEnd - $fnStart);

        $this->assertStringContainsString('latestRequestId += 1;', $fnBody);
        $this->assertStringContainsString('const requestId = latestRequestId;', $fnBody);

        $guardCount = substr_count($fnBody, 'if (requestId !== latestRequestId) {');
        $this->assertSame(
            2,
            $guardCount,
            'Both the success and error branches of executeSearchModal() must bail out when a later reset has advanced latestRequestId past this response\'s captured requestId'
        );
    }

    /**
     * Structural guard for task 2.1: regular checkout success and staged/
     * multi-payment checkout success both call the shared reset within their
     * server-confirmed success branches. Does not execute a live checkout or
     * assert failure/cancellation preservation; see task 3.2.
     */
    public function test_reset_is_invoked_after_successful_checkout_boundaries(): void
    {
        $html = $this->getSellPageHtml();

        // Regular checkout: reset must appear after finalize response handling and
        // before the finally block re-enables the submit button.
        $regularStart = strpos($html, 'const response = await jsonRequest(finalizeEndpoint');
        $this->assertNotFalse($regularStart);
        $regularFinally = strpos($html, "} finally {", $regularStart);
        $this->assertNotFalse($regularFinally);
        $regularSlice = substr($html, $regularStart, $regularFinally - $regularStart);
        $this->assertStringContainsString('resetProductSearchModalState();', $regularSlice);

        // Staged checkout: reset must appear inside the setOnComplete callback.
        $stagedStart = strpos($html, 'PosStagedPayment.setOnComplete(');
        $this->assertNotFalse($stagedStart);
        $stagedGratitudeBtn = strpos($html, 'if (gratitudeBtn)', $stagedStart);
        $this->assertNotFalse($stagedGratitudeBtn);
        $stagedSlice = substr($html, $stagedStart, $stagedGratitudeBtn - $stagedStart);
        $this->assertStringContainsString('resetProductSearchModalState();', $stagedSlice);
    }

    /**
     * Structural guard for task 2.2: save-as-draft-and-new calls the shared
     * reset only inside its try block's success handling, and the catch
     * block (failure path) must not call it. Does not execute a live
     * save-and-new request; see task 3.2 for failure-preservation behavior.
     */
    public function test_reset_is_invoked_after_successful_save_as_draft_and_new(): void
    {
        $html = $this->getSellPageHtml();

        $tryStart = strpos($html, "const response = await jsonRequest(saveAndNewEndpoint, 'POST');");
        $this->assertNotFalse($tryStart);

        $catchStart = strpos($html, '} catch (error) {', $tryStart);
        $this->assertNotFalse($catchStart);

        $successSlice = substr($html, $tryStart, $catchStart - $tryStart);
        $this->assertStringContainsString('resetProductSearchModalState();', $successSlice);

        $catchEnd = strpos($html, '}', strpos($html, "setCartStatus(error.message || 'Gagal menyimpan transaksi.'", $catchStart));
        $catchSlice = substr($html, $catchStart, $catchEnd - $catchStart);
        $this->assertStringNotContainsString('resetProductSearchModalState();', $catchSlice);
    }

    /**
     * Structural guard for task 2.3: `Kosongkan Keranjang` calls the shared
     * reset only inside the successful cart-clear response branch (the
     * `if (response)` block within ApprovalManager.wrapAction's callback),
     * and never inside the click handler as a whole outside that branch.
     * A denied, cancelled, or still-pending-approval clear never invokes the
     * wrapAction callback at all, so the reset is structurally unreachable
     * on those paths. Does not execute a live cart-clear request; see
     * task 3.3 for the live behavioral check.
     */
    public function test_reset_is_invoked_only_inside_successful_cart_clear_response_branch(): void
    {
        $html = $this->getSellPageHtml();

        $handlerStart = strpos($html, "clearCartButton.addEventListener('click'");
        $this->assertNotFalse($handlerStart, 'Kosongkan Keranjang click handler not found');

        $wrapActionStart = strpos($html, 'ApprovalManager.wrapAction(', $handlerStart);
        $this->assertNotFalse($wrapActionStart);

        $responseCheckStart = strpos($html, 'if (response) {', $wrapActionStart);
        $this->assertNotFalse($responseCheckStart, 'Cart-clear success branch not found');

        $responseCheckEnd = strpos($html, '}', strpos($html, 'setCartStatus(', $responseCheckStart));
        $this->assertNotFalse($responseCheckEnd);

        $successSlice = substr($html, $responseCheckStart, $responseCheckEnd - $responseCheckStart);
        $this->assertStringContainsString('resetProductSearchModalState();', $successSlice);

        // Everything in the handler before the success branch (setup, the
        // wrapAction call itself, and the callback body up to the response
        // check) must not reference the reset — it can only be reached via
        // the truthy-response branch above.
        $preSuccessSlice = substr($html, $handlerStart, $responseCheckStart - $handlerStart);
        $this->assertStringNotContainsString('resetProductSearchModalState();', $preSuccessSlice);
    }

    // --- Helpers ---

    private function getSellPageHtml(): string
    {
        $setting = $this->createSetting('SEARCH LIFECYCLE TEST');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SEARCH LIFECYCLE CASHIER');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        return $response->getContent();
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    /**
     * @return array{0: User, 1: Location}
     */
    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $location];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'SEARCH LIFECYCLE LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SEARCH-LC-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Search Lifecycle Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }
}

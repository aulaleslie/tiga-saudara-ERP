<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Support\PosPermissionMatrix;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionSaveAndPrintTest extends PosTransactionFeatureTestCase
{
    private const FULL_PERMISSIONS = [
        'pos.access',
        'pos.sell',
        'pos.sessions.open',
        'pos.transactions.view',
        'pos.transactions.save',
        'pos.transactions.load',
        'pos.transactions.print-current',
    ];

    public function test_first_print_creates_draft_and_keeps_it_loaded_in_cart(): void
    {
        [$setting, $location, $user] = $this->bootstrapCashier('BIZ POS PRINT FIRST', self::FULL_PERMISSIONS);
        $product = $this->createStockedProduct($setting, $location);
        $this->addLine($product->id, 2);

        $response = $this->postJson(route('pos.sell.transactions.save-and-print'));

        $transactionId = (int) $response->json('transaction.id');
        $response->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED)
            ->assertJsonPath('receipt_url', route('pos.sell.transactions.print-receipt', $transactionId))
            ->assertJsonPath('cart_snapshot.active_transaction_id', $transactionId)
            ->assertJsonPath('cart_snapshot.meta.line_count', 1);

        $this->assertNotEmpty($response->json('transaction.code'));
        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'owner_user_id' => $user->id,
            'status' => PosTransaction::STATUS_LOADED,
        ]);
        $this->assertDatabaseHas('pos_transaction_lines', [
            'pos_transaction_id' => $transactionId,
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $this->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 1)
            ->assertJsonPath('cart_snapshot.active_transaction_id', $transactionId);
    }

    public function test_repeat_print_after_edit_updates_same_transaction_and_code(): void
    {
        [$setting, $location] = $this->bootstrapCashier('BIZ POS PRINT REPEAT', self::FULL_PERMISSIONS);
        $product = $this->createStockedProduct($setting, $location);
        $this->addLine($product->id, 1);

        $first = $this->postJson(route('pos.sell.transactions.save-and-print'))->assertOk();
        $transactionId = (int) $first->json('transaction.id');
        $code = $first->json('transaction.code');

        $other = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-PRINT-2']);
        $this->addLine($other->id, 3);

        $second = $this->postJson(route('pos.sell.transactions.save-and-print'))->assertOk();

        $second->assertJsonPath('transaction.id', $transactionId)
            ->assertJsonPath('transaction.code', $code)
            ->assertJsonPath('cart_snapshot.meta.line_count', 2);
        $this->assertSame(1, PosTransaction::query()->where('setting_id', $setting->id)->count());
        $this->assertDatabaseHas('pos_transaction_lines', [
            'pos_transaction_id' => $transactionId,
            'product_id' => $other->id,
            'qty' => 3,
        ]);
    }

    public function test_empty_cart_is_rejected(): void
    {
        $this->bootstrapCashier('BIZ POS PRINT EMPTY', self::FULL_PERMISSIONS);

        $this->postJson(route('pos.sell.transactions.save-and-print'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CART_EMPTY')
            ->assertJsonMissingPath('receipt_url');

        $this->assertSame(0, PosTransaction::query()->count());
    }

    public function test_failed_save_returns_error_without_receipt_and_keeps_cart(): void
    {
        [$setting, $location] = $this->bootstrapCashier('BIZ POS PRINT FAIL', self::FULL_PERMISSIONS);
        $product = $this->createStockedProduct($setting, $location);
        $this->addLine($product->id, 1);

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-print'))
            ->assertOk()
            ->json('transaction.id');

        PosTransaction::query()->whereKey($transactionId)->update(['status' => PosTransaction::STATUS_CANCELLED]);

        $this->postJson(route('pos.sell.transactions.save-and-print'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_SAVEABLE')
            ->assertJsonMissingPath('receipt_url');

        $this->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 1);
    }

    /**
     * @dataProvider missingPermissionProvider
     */
    public function test_missing_any_required_permission_is_forbidden_and_cart_unchanged(string $missing): void
    {
        $permissions = array_values(array_diff(self::FULL_PERMISSIONS, [$missing]));
        [$setting, $location] = $this->bootstrapCashier('BIZ POS PRINT NO ' . strtoupper($missing), $permissions);
        $product = $this->createStockedProduct($setting, $location);
        $this->addLine($product->id, 1);

        $this->postJson(route('pos.sell.transactions.save-and-print'))
            ->assertForbidden();

        $this->assertSame(0, PosTransaction::query()->count());
        $this->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 1)
            ->assertJsonPath('cart_snapshot.active_transaction_id', null);
    }

    public static function missingPermissionProvider(): array
    {
        return [
            'save' => ['pos.transactions.save'],
            'load' => ['pos.transactions.load'],
            'print-current' => ['pos.transactions.print-current'],
        ];
    }

    public function test_sell_screen_shows_button_only_with_all_three_permissions(): void
    {
        [$setting] = $this->bootstrapCashier('BIZ POS PRINT UI OK', self::FULL_PERMISSIONS);

        $this->get(route('pos.sell'))
            ->assertOk()
            ->assertSee('id="pos-save-print"', false)
            ->assertSeeInOrder(['Simpan dan Buka Baru', 'Simpan dan Cetak', 'Pilih Pembayaran']);

        $permissions = array_values(array_diff(self::FULL_PERMISSIONS, ['pos.transactions.print-current']));
        [$otherSetting] = $this->bootstrapCashier('BIZ POS PRINT UI NO', $permissions);

        $this->get(route('pos.sell'))
            ->assertOk()
            ->assertDontSee('id="pos-save-print"', false);
    }

    public function test_sell_screen_opens_receipt_in_new_tab_without_frame(): void
    {
        $view = file_get_contents(base_path('Modules/Pos/Resources/views/sell.blade.php'));
        $start = strpos($view, "if (savePrintButton) {\n                savePrintButton.addEventListener('click'");
        $end = strpos($view, "if (saveDraftButton) {", $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $handler = substr($view, $start, $end - $start);

        $this->assertStringContainsString("window.open('', '_blank')", $handler);
        $this->assertStringContainsString('closePrintWindow()', $handler);
        $this->assertStringContainsString('printWindow.location.href = receiptUrl', $handler);
        $this->assertStringNotContainsString('pos-current-receipt-print-frame', $handler);
        $this->assertStringNotContainsString("document.createElement('iframe')", $handler);

        $this->assertStringContainsString("jQuery(searchResultsModalElement).on('shown.bs.modal shown.coreui.modal', setupSearchResultsModalKeyboard)", $view);
        $this->assertStringContainsString("jQuery(searchResultsModalElement).on('shown.bs.modal shown.coreui.modal', focusSearchResultsKeyword)", $view);
    }

    public function test_search_card_keyboard_navigation_uses_one_delegated_handler(): void
    {
        $view = file_get_contents(base_path('Modules/Pos/Resources/views/sell.blade.php'));
        $start = strpos($view, 'function setupSearchResultsModalKeyboard() {');
        $end = strpos($view, '// Phase 3: Wire up modal keyboard navigation', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $keyboard = substr($view, $start, $end - $start);

        $this->assertStringNotContainsString("item.addEventListener('keydown'", $keyboard);
        $this->assertSame(1, substr_count($keyboard, "searchResultsModalContainer.addEventListener('keydown'"));
        $this->assertStringContainsString('card.click()', $keyboard);
    }

    public function test_receipt_shows_saved_lines_and_code_without_payment_details(): void
    {
        [$setting, $location] = $this->bootstrapCashier('BIZ POS PRINT RECEIPT', self::FULL_PERMISSIONS);
        $product = $this->createStockedProduct($setting, $location, [
            'product_code' => 'SKU-PRINT-RCPT',
            'product_name' => 'Produk Cetak Struk',
        ]);
        $this->addLine($product->id, 2);

        $response = $this->postJson(route('pos.sell.transactions.save-and-print'))->assertOk();

        $this->get($response->json('receipt_url'))
            ->assertOk()
            ->assertSee($response->json('transaction.code'))
            ->assertSee('Produk Cetak Struk')
            ->assertSee('Cetak Struk')
            ->assertDontSee("window.addEventListener('load', function () { window.print(); });", false)
            ->assertDontSee('Bayar:')
            ->assertDontSee('Kembalian');
    }

    public function test_receipt_opens_for_user_with_only_the_three_print_permissions(): void
    {
        $permissions = array_values(array_diff(self::FULL_PERMISSIONS, ['pos.transactions.view']));
        [$setting, $location] = $this->bootstrapCashier('BIZ POS PRINT NO VIEW', $permissions);
        $product = $this->createStockedProduct($setting, $location);
        $this->addLine($product->id, 1);

        $response = $this->postJson(route('pos.sell.transactions.save-and-print'))->assertOk();

        $this->get($response->json('receipt_url'))
            ->assertOk()
            ->assertSee($response->json('transaction.code'));

        // The general receipt route still requires pos.transactions.view.
        $this->get(route('pos.transactions.receipt', (int) $response->json('transaction.id')))
            ->assertForbidden();
    }

    public function test_print_receipt_rejects_transaction_not_active_in_cart(): void
    {
        [$setting, $location] = $this->bootstrapCashier('BIZ POS PRINT NOT ACTIVE', self::FULL_PERMISSIONS);
        $product = $this->createStockedProduct($setting, $location);
        $this->addLine($product->id, 1);

        $savedId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201)
            ->json('transaction.id');

        $this->get(route('pos.sell.transactions.print-receipt', $savedId))
            ->assertForbidden();
    }

    public function test_print_receipt_rejects_cancelled_transaction_still_in_cart(): void
    {
        [$setting, $location] = $this->bootstrapCashier('BIZ POS PRINT CANCELLED', self::FULL_PERMISSIONS);
        $product = $this->createStockedProduct($setting, $location);
        $this->addLine($product->id, 1);

        $response = $this->postJson(route('pos.sell.transactions.save-and-print'))->assertOk();
        $transactionId = (int) $response->json('transaction.id');

        $this->get($response->json('receipt_url'))
            ->assertOk()
            ->assertDontSee("window.addEventListener('load', function () { window.print(); });", false);

        PosTransaction::query()->whereKey($transactionId)->update(['status' => PosTransaction::STATUS_CANCELLED]);

        $this->get($response->json('receipt_url'))->assertStatus(409);
    }

    public function test_permission_is_registered_in_registry_and_bundles(): void
    {
        $registry = collect(config('permissions') ?? require app_path('Config/Permissions.php'))
            ->flatMap(fn ($group) => is_array($group) ? array_keys($group) : []);
        $this->assertTrue($registry->contains('pos.transactions.print-current'));

        $bundles = PosPermissionMatrix::supportedBundles();
        foreach (['manager', 'cashier', 'floor_staff'] as $bundle) {
            $this->assertContains('pos.transactions.print-current', $bundles[$bundle]['permissions']);
        }
        $this->assertContains(
            'pos.transactions.print-current',
            PosPermissionMatrix::capabilityClusters()['draft_handoff']['permissions']
        );
    }

    /**
     * @return array{0: \Modules\Setting\Entities\Setting, 1: \Modules\Setting\Entities\Location, 2: \App\Models\User}
     */
    private function bootstrapCashier(string $name, array $permissions): array
    {
        $setting = $this->createSetting($name);
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, $name . ' CASHIER', $permissions);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        return [$setting, $location, $user];
    }

    private function addLine(int $productId, int $qty): void
    {
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $productId,
            'qty' => $qty,
        ])->assertOk();
    }
}

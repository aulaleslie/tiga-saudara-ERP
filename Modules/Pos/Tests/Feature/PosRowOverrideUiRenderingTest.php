<?php

namespace Modules\Pos\Tests\Feature;

use Tests\TestCase;

/**
 * Structural guarantees for the two row monetary controls.
 *
 * The POS sell view is a large client-rendered Blade template, so these assert
 * the rendered source: two distinct controls, two distinct modals, no shared
 * DOM state, no cart-wide control, and no monetary control on bundle
 * components. The retired `price_override` markup drove both operations from a
 * single set of nodes, which is exactly what these tests prevent recurring.
 */
class PosRowOverrideUiRenderingTest extends TestCase
{
    private function sellView(): string
    {
        return file_get_contents(
            base_path('Modules/Pos/Resources/views/sell.blade.php')
        );
    }

    private function unitPriceModal(): string
    {
        return file_get_contents(
            base_path('Modules/Pos/Resources/views/sell/modals/line_unit_price_override.blade.php')
        );
    }

    private function rowTotalModal(): string
    {
        return file_get_contents(
            base_path('Modules/Pos/Resources/views/sell/modals/line_total_override.blade.php')
        );
    }

    // ------------------------------------------------- both row actions

    public function test_both_row_controls_are_rendered(): void
    {
        $view = $this->sellView();

        $this->assertStringContainsString('js-unit-price-edit', $view, 'The unit-price row control is missing.');
        $this->assertStringContainsString('js-row-total-edit', $view, 'The row-total control is missing.');
        $this->assertStringContainsString('Ubah Harga Satuan', $view);
        $this->assertStringContainsString('Ubah Total Baris', $view);
    }

    public function test_each_control_targets_its_own_action_type(): void
    {
        $view = $this->sellView();

        $this->assertStringContainsString("actionType: 'LINE_UNIT_PRICE_OVERRIDE'", $view);
        $this->assertStringContainsString("actionType: 'LINE_TOTAL_OVERRIDE'", $view);
    }

    public function test_each_control_posts_to_its_own_endpoint(): void
    {
        $view = $this->sellView();

        $this->assertStringContainsString("endpointSuffix: '/unit-price-override'", $view);
        $this->assertStringContainsString("endpointSuffix: '/line-total-override'", $view);
    }

    public function test_the_row_total_route_is_distinct_from_the_retired_cart_route(): void
    {
        // `/cart/total-override` is the retired cart-wide operation. The active
        // row endpoint must not be a bare `/total-override` suffix, or logs and
        // future maintenance can confuse the two.
        $routes = app('router')->getRoutes();

        $this->assertNotNull(
            $routes->getByName('pos.sell.cart.lines.line-total-override'),
            'The active row-total route must be named line-total-override.'
        );
        $this->assertNull(
            $routes->getByName('pos.sell.cart.lines.total-override'),
            'The ambiguous row-total route name must not remain registered.'
        );

        $this->assertSame(
            'pos/sell/cart/lines/{lineId}/line-total-override',
            $routes->getByName('pos.sell.cart.lines.line-total-override')->uri()
        );
    }

    public function test_the_row_total_control_displays_the_authoritative_row_total(): void
    {
        $view = $this->sellView();

        // `line_total` is post-allocated-bill-discount. Displaying it beside the
        // control would show one figure in the row and a different "current
        // total" in the modal whenever a bill discount is applied.
        $this->assertStringContainsString('authoritativeRowTotal', $view);
        $this->assertMatchesRegularExpression(
            '/authoritativeRowTotal\s*=\s*Number\(\s*line\.line_net_before_bill\s*!==\s*undefined/s',
            $view,
            'The row-total cell must display line_net_before_bill, the value the modal edits.'
        );
        $this->assertStringContainsString(
            'formatPrice(authoritativeRowTotal)',
            $view,
            'The row-total cell must render the authoritative total.'
        );
    }

    public function test_the_after_bill_discount_amount_is_labelled_separately(): void
    {
        $view = $this->sellView();

        // The post-bill-discount figure is still useful, but only with an
        // explicit label so it cannot be mistaken for the editable total.
        $this->assertStringContainsString('Setelah diskon nota:', $view);
        $this->assertMatchesRegularExpression(
            '/Number\(line\.bill_discount_amount\s*\|\|\s*0\)\s*>\s*0/s',
            $view,
            'The after-bill-discount line should appear only when a bill discount was allocated.'
        );
    }

    public function test_controls_are_visually_distinct(): void
    {
        $view = $this->sellView();

        // Different icons and colours so the two are unambiguous at a glance.
        $this->assertStringContainsString("icon: 'bi-tag'", $view);
        $this->assertStringContainsString("icon: 'bi-calculator'", $view);
        $this->assertStringContainsString("idleTextClass: 'text-primary'", $view);
        $this->assertStringContainsString("idleTextClass: 'text-info'", $view);
    }

    // ------------------------------------------------- separate modals

    public function test_the_two_modals_have_distinct_identifiers(): void
    {
        $this->assertStringContainsString('id="pos-line-unit-price-override-modal"', $this->unitPriceModal());
        $this->assertStringContainsString('id="pos-line-total-override-modal"', $this->rowTotalModal());
    }

    public function test_each_modal_has_its_own_form_state_nodes(): void
    {
        $unitPrice = $this->unitPriceModal();
        $rowTotal = $this->rowTotalModal();

        foreach (['current', 'new', 'error', 'reason', 'submit', 'product'] as $node) {
            $this->assertStringContainsString("pos-line-unit-price-override-{$node}", $unitPrice);
            $this->assertStringContainsString("pos-line-total-override-{$node}", $rowTotal);
        }
    }

    public function test_neither_modal_reuses_the_retired_price_override_identifiers(): void
    {
        foreach ([$this->sellView(), $this->unitPriceModal(), $this->rowTotalModal()] as $source) {
            $this->assertStringNotContainsString('pos-price-override-modal', $source);
            $this->assertStringNotContainsString('pos-price-override-new', $source);
            $this->assertStringNotContainsString('js-price-edit', $source);
        }
    }

    public function test_the_retired_partial_no_longer_exists(): void
    {
        $this->assertFileDoesNotExist(
            base_path('Modules/Pos/Resources/views/sell/modals/price_override.blade.php'),
            'The ambiguous price_override partial must be removed, not reassigned.'
        );
    }

    public function test_each_modal_shows_row_identity_and_its_own_current_value(): void
    {
        $this->assertStringContainsString('Harga Satuan Saat Ini', $this->unitPriceModal());
        $this->assertStringContainsString('Total Baris Saat Ini', $this->rowTotalModal());

        // Both identify the row being edited.
        $this->assertStringContainsString('Produk:', $this->unitPriceModal());
        $this->assertStringContainsString('Produk:', $this->rowTotalModal());
    }

    public function test_each_modal_captures_its_own_reason(): void
    {
        $this->assertStringContainsString('alasan perubahan harga satuan', $this->unitPriceModal());
        $this->assertStringContainsString('alasan perubahan total baris', $this->rowTotalModal());
    }

    // --------------------------------------------------- separate state

    public function test_approval_state_is_keyed_by_line_and_action(): void
    {
        $view = $this->sellView();

        // Per-action lookup, so one control cannot read the other's approval.
        $this->assertStringContainsString('latestApprovalFor', $view);
        $this->assertStringContainsString('clientPendingApprovals[lineIdForStorage][control.actionType]', $view);
    }

    public function test_each_control_reads_its_own_approved_value(): void
    {
        $view = $this->sellView();

        $this->assertStringContainsString('req.requested_unit_price', $view);
        $this->assertStringContainsString('req.requested_line_total', $view);
    }

    public function test_edit_state_is_tracked_per_action(): void
    {
        $view = $this->sellView();

        $this->assertStringContainsString('overrideEditState', $view);
        $this->assertMatchesRegularExpression(
            '/overrideEditState\s*=\s*\{\s*LINE_UNIT_PRICE_OVERRIDE:.*LINE_TOTAL_OVERRIDE:/s',
            $view,
            'Edit state must be tracked separately for each action.'
        );
    }

    // ------------------------------------------------ scope boundaries

    public function test_no_cart_wide_total_override_control_remains(): void
    {
        $view = $this->sellView();

        $this->assertStringNotContainsString('pos-total-override-modal', $view);
        $this->assertStringNotContainsString('js-total-override', $view);
        $this->assertStringNotContainsString("'/cart/total-override'", $view);
    }

    public function test_the_payment_summary_exposes_no_monetary_override(): void
    {
        $payment = file_get_contents(
            base_path('Modules/Pos/Resources/views/sell/shell/payment.blade.php')
        );

        $this->assertStringNotContainsString('Ubah Total', $payment);
        $this->assertStringNotContainsString('js-unit-price-edit', $payment);
        $this->assertStringNotContainsString('js-row-total-edit', $payment);
    }

    public function test_monetary_controls_are_gated_to_billable_rows(): void
    {
        $view = $this->sellView();

        $this->assertStringContainsString('isBillableRow', $view);
        $this->assertMatchesRegularExpression(
            '/if\s*\(!isBillableRow\)\s*\{\s*return\s+\'\';/s',
            $view,
            'Non-billable rows must render no monetary control.'
        );
    }

    public function test_the_bundle_detail_modal_carries_no_monetary_control(): void
    {
        $view = $this->sellView();

        $start = strpos($view, 'function openBundleDetailModal');
        $this->assertNotFalse($start, 'Bundle detail modal renderer not found.');

        $body = substr($view, $start, 4000);

        $this->assertStringNotContainsString('js-unit-price-edit', $body);
        $this->assertStringNotContainsString('js-row-total-edit', $body);
    }

    // --------------------------------------------- approval queue labels

    public function test_the_approval_queue_labels_both_active_actions(): void
    {
        $queue = file_get_contents(
            base_path('Modules/Pos/Resources/views/approval-queue/index.blade.php')
        );

        $this->assertStringContainsString("'LINE_UNIT_PRICE_OVERRIDE': '<span", $queue);
        $this->assertStringContainsString("'LINE_TOTAL_OVERRIDE': '<span", $queue);
        $this->assertStringContainsString('Ubah Harga Satuan', $queue);
        $this->assertStringContainsString('Ubah Total Baris', $queue);
    }

    public function test_the_approval_queue_labels_values_by_kind(): void
    {
        $queue = file_get_contents(
            base_path('Modules/Pos/Resources/views/approval-queue/index.blade.php')
        );

        // A unit-price request must present unit prices, a row-total request
        // row totals — never the other's wording.
        $this->assertStringContainsString("'LINE_UNIT_PRICE_OVERRIDE': 'Harga satuan'", $queue);
        $this->assertStringContainsString("'LINE_TOTAL_OVERRIDE': 'Total baris'", $queue);
    }

    public function test_the_approval_queue_computes_delta_from_canonical_minor_units(): void
    {
        $queue = file_get_contents(
            base_path('Modules/Pos/Resources/views/approval-queue/index.blade.php')
        );

        $this->assertStringContainsString('payload.source_value_minor', $queue);
        $this->assertStringContainsString('payload.requested_value_minor', $queue);
        $this->assertStringContainsString('requestedValue - sourceValue', $queue);
    }

    // ------------------------------------------------ queue escaping

    private function approvalQueueView(): string
    {
        return file_get_contents(
            base_path('Modules/Pos/Resources/views/approval-queue/index.blade.php')
        );
    }

    public function test_the_approval_queue_defines_an_escaping_helper(): void
    {
        $this->assertStringContainsString('const escapeHtml', $this->approvalQueueView());
    }

    public function test_every_payload_derived_string_is_escaped_before_interpolation(): void
    {
        $queue = $this->approvalQueueView();

        // The reason is free text a cashier typed and is the highest-risk field.
        $this->assertStringContainsString('escapeHtml(payload.reason)', $queue);
        $this->assertStringContainsString('escapeHtml(payload.product_name)', $queue);
        $this->assertStringContainsString('escapeHtml(payload.product_id', $queue);
        $this->assertStringContainsString('escapeHtml(payload.line_id', $queue);
        $this->assertStringContainsString('escapeHtml(payload.qty)', $queue);

        // Legacy payloads render through the same escaping.
        $this->assertStringContainsString('escapeHtml(req.request_payload.reason)', $queue);

        // Requester name and unknown action types are payload-derived too.
        $this->assertStringContainsString('escapeHtml(req.requester ? req.requester.name', $queue);
        $this->assertStringContainsString('escapeHtml(req.action_type)', $queue);
    }

    public function test_no_unescaped_interpolation_remains_in_queue_markup(): void
    {
        $queue = $this->approvalQueueView();

        preg_match_all('/\$\{([^}]+)\}/', $queue, $matches);

        // Exact-match allowlist only. Substring matching would let
        // `${payload.reason}` pass merely because "reason" appears in it, which
        // is precisely the hole being guarded.
        $allowedLocals = [
            'actionHtml',   // trusted label constant, or escaped fallback
            'targetHtml',   // assembled from escaped parts
            'valueLabel',   // trusted constant
            'deltaSign',    // computed sign
            'product',      // escaped above
            'qty',          // escaped above
            'reason',       // escaped above
            'id',           // fetch URL parameter, not markup
        ];

        $unescaped = [];

        foreach (array_unique($matches[1]) as $expression) {
            $trimmed = trim($expression);

            if (in_array($trimmed, $allowedLocals, true)) {
                continue;
            }

            // Anything else must route through escaping or a numeric formatter.
            $isSafeCall = str_contains($trimmed, 'escapeHtml(')
                || str_contains($trimmed, 'rp(')
                || str_contains($trimmed, 'formatDate(')
                || str_contains($trimmed, 'toLocaleString')
                || str_contains($trimmed, 'new Date');

            if (! $isSafeCall) {
                $unescaped[] = $trimmed;
            }
        }

        $this->assertSame(
            [],
            $unescaped,
            "Unescaped interpolation(s) reaching innerHTML:\n" . implode("\n", $unescaped)
        );
    }

    public function test_a_malicious_reason_would_be_rendered_as_text(): void
    {
        // Simulate the queue's escaping over a stored payload reason.
        $malicious = '<img src=x onerror=alert(1)>';

        $escaped = str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&#039;'],
            $malicious
        );

        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $escaped);
        $this->assertStringNotContainsString('<img', $escaped, 'The payload must not survive as markup.');

        // And the view must route the reason through that helper rather than
        // interpolating it raw.
        $queue = $this->approvalQueueView();
        $this->assertStringNotContainsString('${payload.reason}', $queue);
        $this->assertStringNotContainsString('${req.request_payload.reason}', $queue);
    }

    public function test_the_approval_queue_still_renders_retired_actions_read_only(): void
    {
        $queue = file_get_contents(
            base_path('Modules/Pos/Resources/views/approval-queue/index.blade.php')
        );

        $this->assertStringContainsString("'PRICE_OVERRIDE'", $queue);
        $this->assertStringContainsString("'TOTAL_PRICE_OVERRIDE'", $queue);
        $this->assertStringContainsString('dipensiunkan', $queue);
    }
}

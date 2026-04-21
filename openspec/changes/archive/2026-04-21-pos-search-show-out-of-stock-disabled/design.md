## Context

POS product search in the `Cari Produk` modal currently filters out any product whose aggregated allowed-location stock is zero. This improves add-to-cart success rate but hides valid catalog matches and causes operational confusion when a cashier expects to find a known product by name/SKU/barcode. The cart service already enforces stock sufficiency and rejects add attempts when stock is unavailable, so search visibility and add authorization are currently coupled more tightly than necessary.

## Goals / Non-Goals

**Goals:**
- Make keyword search results include matched stock-managed products even when `available_qty = 0`.
- Preserve safe cart behavior by preventing selection of out-of-stock items in the search modal.
- Provide clear out-of-stock visual signaling (`Stok Kosong`) in result cards.
- Prevent barcode/conversion auto-select from selecting out-of-stock results.
- Keep backend stock validation in cart add flow unchanged as defense-in-depth.

**Non-Goals:**
- Changing scan-resolve endpoint semantics for barcode/serial scanning.
- Changing stock deduction, checkout preflight, or allocation logic.
- Adding backorder/preorder flows.

## Decisions

1. Search service will return both in-stock and out-of-stock matches.
- Decision: remove the `available_qty > 0` filter in POS product keyword search and continue returning `available_qty` per row.
- Rationale: this enables discoverability without weakening enforcement, because add-to-cart remains validated in cart service.
- Alternative considered: keep backend filter and issue a second catalog lookup for zero-stock products. Rejected due to complexity and inconsistent ranking logic.

2. Out-of-stock rows will be represented as disabled cards in the modal.
- Decision: render out-of-stock results with non-interactive button state and out-of-stock styling/watermark.
- Rationale: preserves visual consistency with current card grid while making actionability explicit.
- Alternative considered: render out-of-stock rows in a separate section. Rejected because it fragments ranking and keyboard navigation.

3. Auto-select will only apply to in-stock exact matches.
- Decision: keep existing exact barcode/conversion auto-select behavior but exclude results where `available_qty <= 0`.
- Rationale: prevents immediate failed add attempts and keeps scan/manual UX deterministic.
- Alternative considered: allow auto-select then rely on cart error. Rejected because it creates noisy failed feedback for expected behavior.

4. Keyboard navigation will skip disabled results.
- Decision: focus order and Enter activation target only selectable cards.
- Rationale: accessibility and cashier speed; arrow navigation should not land on non-actionable targets.
- Alternative considered: keep disabled cards focusable with blocked Enter. Rejected for unnecessary key presses and operator friction.

## Risks / Trade-offs

- [Risk] Larger result sets may include more non-actionable items, reducing immediate conversion to cart adds. -> Mitigation: preserve ranking relevance and visually de-emphasize out-of-stock cards.
- [Risk] Divergence between search and scan flows if scan continues excluding zero-stock exact matches. -> Mitigation: explicitly document scope to search modal only and keep behavior intentional.
- [Risk] UI regressions in hover/focus behavior due to disabled styling. -> Mitigation: add targeted JS and CSS assertions in tests and preserve existing classes for enabled cards.

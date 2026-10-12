# Design

## Context

See proposal.md. `PosReceiptService` renders completed receipts from persisted `PosTransactionLine` and `PosCheckout` monetary snapshots. `PosSettlementProjectionService` already computes the current total, effective paid, and live due across reachable generated Sales. `pos_checkout_sales` associates a checkout with Sales, while `sale_details` has no originating POS line ID. Split posting retains `line_id` only in the in-memory group payload; `SaleBundleItem` amounts are nested original allocations and are not updated by `SaleMonetaryEditService`.

## Goals / Non-Goals

**Goals:**
- Keep the existing receipt template and checkout identity/composition facts while calculating completed reprint money from current authoritative Sales.
- Preserve exact owner and line attribution for new checkouts and refuse uncertain attribution for historical multi-line checkouts.
- Reuse the global POS settlement projection for current Total and `Sisa Utang`.

**Non-Goals:**
- Change Sale edit permissions, allowed monetary fields, stock/quantity rules, payment allocation, or bundle-item snapshots.
- Rewrite existing checkout, POS transaction line, or print-log history.
- Add a full-suite or automated browser verification gate.

## Decisions

### 1. Persist a direct line association at finalization

Add a nullable indexed `pos_transaction_line_id` foreign key on `sale_details`. At checkout finalization, establish a stable map from the finalized cart `line_id` to its persisted `PosTransactionLine.id` (persist the cart line ID in line metadata if required by the posting sequence). Carry that identity through inline and split group payloads and write it on every generated authoritative Sale detail, including component-only parent-shaped details with quantity zero. Assert that all generated details belong to the checkout's POS transaction before committing. Keep the column nullable for historical and non-POS Sales.

Alternatives: product ID, Sale ID, insertion order, `SaleBundleItem.line_group_key`, and dispatch links do not form a durable cross-owner POS-line key. They may collide for repeated bundles or be absent on legacy rows. A new link table would work but adds a second association entity without value for this one-to-many relation.

### 2. Build one completed-reprint monetary projection

Extend the receipt data construction with a reprint-specific current-money projection. Resolve the generated Sales through the existing reachable-Sales resolver; include Sale details once and exclude nested `SaleBundleItem` monetary fields. Group details by proven POS line. For each Sale, reconcile the current Sale header total against its current detail subtotals; allocate any header-level net discount/shipping difference among that Sale's mapped lines using deterministic minor-unit arithmetic and a final remainder. This makes the sum of current displayed line charges equal the canonical current Sale total even when an authorized monetary edit changed header fields. Keep unchanged checkout quantities and composition. Derive printable per-unit/breakdown monetary values from the assigned current line amount while preserving the existing unit labels and packing quantities; ensure displayed amounts reconcile to the assigned line charge to two decimals.

Use `PosSettlementProjectionService` for the current transaction Total and live due and reject a projection whose Sale mapping is invalid or whose allocated line money fails to reconcile to that Total. The receipt's original tender and change remain checkout facts; `Sisa Utang` comes from live due. Update the current conditional rendering so an original change row cannot suppress a positive current due. The existing print-history path remains, but a failed updated reprint does not log a successful reprint.

Alternatives: changing `PosCheckout` values would erase checkout evidence; summing `SaleBundleItem` would double bill component revenue; replacing only the grand total would leave inconsistent line prices.

### 3. Fail safely for historical ambiguous mapping

For historical records with null lineage, permit current-money projection when there is exactly one customer-facing POS line and the complete generated-Sale mapping is valid. For multi-line records, accept only a complete one-to-one assignment supported by persisted identities that are unique within that checkout; validate product/bundle identity and coverage across every generated Sale detail. Never use insertion order alone. If any detail could belong to more than one line, or any line/detail is unmatched, do not produce an updated-price receipt. Return a clear operator-facing explanation and provide an explicitly historical checkout-receipt view with original snapshot prices, total, tender, change, and checkout debt. Keep this historical view distinct from the current-price reprint action.

Alternatives: assigning all document deltas to one parent line or guessing by sequence makes repeated bundles incorrect. Blocking all historical records would unnecessarily exclude provable single-line checkouts.

### 4. Share current-money behavior across completed reprint routes

Route all completed reprint entry points through the same projection policy: global POS payment reprint, POS transaction reprint, and checkout reprint. Draft/loaded receipt previews and explicit historical checkout views keep snapshot behavior. Retain current authorization and print-log permissions for each route. A successful reprint logs once after projection succeeds; an ambiguity error does not log a printed document.

## Risks / Trade-offs

- [Historical multi-line records may lack enough provenance] → Refuse only updated-price reprinting for ambiguous cases and expose the original historical receipt.
- [Header discount/shipping or packed pricing may not divide evenly among lines/units] → Allocate in integer minor units with deterministic remainder and assert printed line, Sale, and POS totals reconcile.
- [A completed transaction has broken Sale mapping or fails line/total reconciliation] → Raise a typed projection-unavailable error and provide the original checkout receipt link; do not silently fall back to checkout money as if current or log a successful reprint.
- [Original checkout tender/change can differ from current Total or due] → Keep them identified as checkout-time facts; derive live `Sisa Utang` separately from Sales.

## Migration Plan

Deploy an additive nullable indexed Sale-detail lineage column. New posting paths populate it atomically; existing Sale details remain null and use the conservative historical resolver. Rollback of application code leaves the additive column harmless; dropping it returns all checkouts to historical mapping behavior, so schema rollback should only occur after disabling current-price reprint behavior.

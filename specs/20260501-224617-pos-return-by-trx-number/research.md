# Research: POS Return by Transaction Number

## Decision 1: Represent a POS return as a POS wrapper over existing Sales Return execution
- Decision: Create one `pos_returns` header per POS transaction return and link it to one or more owner/sale-aligned `sale_returns` records or lines. Each linked Sales Return executes the existing approval, receiving, settlement, and dispatch behavior for the original generated sale.
- Rationale: The spec requires one POS Return header while preserving owner/sale-aligned reversal. Existing Sales Return already contains approval, receiving, serial handling, settlement items, replacement dispatch, status rollups, and dispatch detail relationships.
- Alternatives considered:
  - Create only normal Sales Returns with no POS parent: rejected because the user needs a single POS transaction return record and snapshot.
  - Build a separate POS-only return lifecycle: rejected because it duplicates the Sales Return engine and increases financial/inventory risk.

## Decision 2: Resolve transaction numbers against completed POS transactions and posted checkouts
- Decision: Lookup should accept `pos_transactions.code` and `pos_checkouts.receipt_number`, then require exactly one active match where `PosTransaction::STATUS_COMPLETED`, `PosCheckout::STATUS_POSTED`, matching setting scope, and a completed checkout link all hold.
- Rationale: Current POS persistence has indexed transaction codes, posted checkout records, receipt numbers, checkout sales, payment allocations, and generated sale references. Restricting to completed/posted records prevents returns from drafts, failed checkouts, or cancelled transactions.
- Alternatives considered:
  - Search only transaction code: rejected because receipts are user-visible and the spec allows receipt/transaction identifiers.
  - Search generated sale reference: rejected as the entry point because it loses the one-POS-transaction context.
  - Pick the first match when identifiers collide: rejected because the spec requires ambiguous lookup to be blocked.

## Decision 3: Store a source snapshot hash and validate it at submission
- Decision: Build a canonical source snapshot from the transaction, checkout, checkout sales, generated sales, dispatch details, sale details, bundle rows, payment summary, and returnable quantity aggregates. Store the snapshot payload/hash on `pos_returns` during draft/submission and reject submission when the posted source state no longer matches the hash.
- Rationale: FR-003 and FR-004 require a populated immutable snapshot and stale snapshot blocking. Existing POS transactions already use snapshot hashes, so this follows local POS patterns.
- Alternatives considered:
  - Re-query source rows at submission without a hash: rejected because users could submit from stale UI data.
  - Persist only transaction id and no snapshot: rejected because audit and stale detection need immutable source context.

## Decision 4: Map return lines by checkout sale, generated sale, dispatch detail, and owner/location
- Decision: Each POS Return Line must carry `pos_checkout_sale_id`, `sale_id`, `sale_detail_id`, `dispatch_detail_id`, `source_setting_id`, `source_location_id`, `tax_id`, and quantity/money values. The service groups lines by generated sale before creating linked Sales Return records/details.
- Rationale: POS split posting can create multiple owner-aligned sales from one checkout. Reversal correctness depends on returning the exact sale and dispatch detail that originally moved stock.
- Alternatives considered:
  - Group only by product id: rejected because identical products can appear under different owners, locations, taxes, or dispatch details.
  - Put every line in one Sales Return: rejected because Sales Return currently belongs to one sale and its dispatch details.

## Decision 5: Enforce bundle returns through component expansion, not parent-only selection
- Decision: For bundle parent rows, the UI may expose the bundle as a group, but submission expands to every required component line based on original `sale_bundle_items` composition and returned bundle quantity. Component quantities must be proportional and bounded by still-returnable quantity; stock-managed components participate in dispatch/inventory effects, while stockless components are retained for audit and monetary mapping only.
- Rationale: FR-009 through FR-012a prohibit parent-only bundle returns and require all included items, including stockless components. Existing Sale Return eligibility already carries bundle context and supports standalone bundle component rows.
- Alternatives considered:
  - Let users pick arbitrary components: rejected because it violates bundle integrity.
  - Store only a parent bundle line: rejected because inventory and dispatch quantities move at component/product detail level.
  - Drop stockless components from the return: rejected because the POS return would no longer preserve the original bundle composition for audit and value allocation.

## Decision 6: Reuse Sales Return receiving to adjust returned inventory and sale status
- Decision: Receiving a POS return should delegate to linked Sales Return receive behavior so product stock, serial state, transaction history, and sale return status synchronization stay consistent. POS wrapper status is derived from linked Sales Return states.
- Rationale: Existing `SalesReturnController@receive` already performs locked stock updates, serial restoration, transaction history, and lifecycle sync.
- Alternatives considered:
  - Add a new POS stock return routine: rejected due to duplicated inventory mutation risk.
  - Update dispatch quantities directly without receiving stock: rejected because current business flow records returned goods through Sales Return receiving.

## Decision 7: Implement cash return as manual cash refund settlement after approval and receiving
- Decision: Cash-return POS lines map to `CASH_REFUND` settlement items after receiving. Refund amount is capped by returned item amount and allocated by owner/sale-aligned return lines; original split/staged payment methods do not drive automatic reversal.
- Rationale: Clarification requires manual cash refund after approval and receiving, capped by returned item amounts. Existing Sales Return settlement items support cash refund settlement methods and approval metadata.
- Alternatives considered:
  - Reverse original payments automatically: rejected because split/staged POS payments are out of scope and could affect external payment reconciliation.
  - Allow refund before receiving: rejected by the spec lifecycle.

## Decision 8: Implement product replacement as settlement dispatch from original owner/location
- Decision: Product replacement POS lines map to repair/replacement settlement flow and dispatch only the same product/SKU, in quantity equal to the received returned quantity, from the original `source_setting_id` and `source_location_id`; other sources require a separate transfer/override before dispatch.
- Rationale: FR-016, FR-016a, and FR-016b require replacement goods to preserve original SKU, quantity, owner, and location alignment. Existing Sales Return dispatch flow supports request, approval, and dispatch item states.
- Alternatives considered:
  - Allow any available stock location: rejected because it breaks owner/location accounting.
  - Treat replacement as a new POS sale: rejected because it loses the return lifecycle link.
  - Allow equivalent or substitute SKUs: rejected because this feature scope requires same-product replacement only.

## Decision 9: Register POS-specific permissions and map them into POS role governance
- Decision: Add `pos.returns.view`, `pos.returns.create`, `pos.returns.edit`, `pos.returns.delete`, `pos.returns.approve`, `pos.returns.receive`, and `pos.returns.dispatch` to `app/Config/Permissions.php` and `Modules/Pos/Support/PosPermissionMatrix.php`, then gate every route/server action with the matching permission plus existing POS access/scope middleware.
- Rationale: FR-018 requires POS-specific permissions and the project centralizes permission labels in `app/Config/Permissions.php`.
- Alternatives considered:
  - Reuse only `saleReturns.*`: rejected because the spec requires POS-specific permission boundaries.
  - Use only `pos.supervisor.approval`: rejected because create/edit/delete/receive/dispatch need separate grants.

## Decision 10: Verification must cover cross-module data integrity, not only screen access
- Decision: Add focused tests for lookup eligibility, permission matrix, snapshot stale blocking, split-owner mapping, bundle expansion including stockless components, quantity caps including active/non-reversed cumulative returns, approval/receiving guards, atomic rollback, cash/refund vs replacement mutual exclusion, and replacement same-SKU/owner/location constraints.
- Rationale: This feature touches POS, Sales Return, dispatch, stock, payment settlement, permissions, and audit behavior. The risk profile requires more than route smoke tests.
- Alternatives considered:
  - Manual UAT only: rejected because regression risk is high.
  - Full browser automation first: rejected for planning scope; Livewire/feature tests provide faster coverage and manual UAT remains in quickstart.

## Decision 11: Count only active non-reversed returns toward eligibility
- Decision: Cumulative quantity checks count pending, approved, received, settled, dispatched, and completed returns unless they have been fully reversed by rejected, deleted, or audited cancelled/archived terminal handling. Received/settled/dispatched/completed returns always count.
- Rationale: FR-023 and clarification require future eligibility to reflect only active, non-reversed obligations while preventing completed inventory/financial effects from being reused.
- Alternatives considered:
  - Count every historical return forever: rejected because rejected/deleted/audited cancelled returns should release eligibility.
  - Count only received returns: rejected because pending/approved active returns could over-reserve returnable quantity.

## Decision 12: Make lifecycle actions transactionally atomic
- Decision: Submit, approve, receive, cash-refund settlement, and replacement dispatch run inside database transactions that lock the POS Return, linked Sales Returns, and relevant dispatch/stock/settlement rows before mutation.
- Rationale: FR-026 requires rollback of all database changes on failure and audited manual correction if non-rollbackable effects occur. Existing Sales Return receiving already uses `DB::transaction` and row locks, which should be preserved for POS wrapper actions.
- Alternatives considered:
  - Let each linked Sales Return advance independently: rejected because multi-owner POS returns could be left partially advanced.
  - Retry after partial failure without audit: rejected because inventory and payment effects need traceable correction.

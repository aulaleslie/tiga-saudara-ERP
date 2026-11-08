# Design

## Context

See proposal.md for motivation and specs/return-serial-registration/spec.md for the behavior contract. This is a cross-module change with additive persistence and concurrency implications, so a design artifact is required.

Observed implementation:

- Standard eligibility and edit hydration derive `serial_number_required` from the current Product. `ValidatesSaleReturnForm` and `StandardReturnClaimService` require existing serial IDs and matching dispatch lineage. `SaleSerialNumberLoader` rejects unknown text.
- Standard receiving uses `StandardReturnCustodyService`; settlement row construction and `StandardReturnCoverage` also consult the current flag. Custody already separates physical holding from sellable stock.
- POS `PosReturnSnapshotService` consults current tracking, but ordinary lines with no resolved serials fall back to quantity rows. Bundle components instead take the tracked branch and can have no returnable quantity. `PosReturnSubmissionService` also consults current tracking during component expansion.
- POS form keys, previews, quantity validation, persisted plans, and lifecycle execution distinguish existing returned serial IDs from quantity-only lines. Unknown text cannot safely be represented as an invented serial ID. POS approval may execute receiving inside the same atomic action.
- `ProductSerialNumber::normalize` trims and uppercases UTF-8. The current schema has unique `(product_id, serial_number)` identity, replacing older global uniqueness. The extension preserves this scope.
- Existing durable contracts include sales-return-workflow, sales-return-custody, and pos-return-serial-claim-eligibility. The archived repair-sales-return-lifecycle-preserve-pos design explains standard/POS separation.

## Goals / Non-Goals

**Goals:** Separate original fulfillment evidence, return-time registration, and current inventory tracking. Reuse established lifecycle boundaries while supporting stable identity before a serial ID exists. Keep read-only preview and approval execution consistent.

**Non-Goals:** Rewriting original sale serial lists; accepting unknown substitutes for historically serialized sales; reconciling existing serials with missing lineage; broad conversion of existing stock; changing permissions, refund pricing, rounding, or standard/POS ownership.

## Decisions

### 1. Resolve source tracking from persisted fulfillment, separately from current tracking

Introduce a shared source classification contract consumed by both workflows. Resolve original sale-detail, dispatch, POS line/serial metadata, bundle-item identity, and historical serial evidence before deciding eligibility. Preserve the source identity and fingerprint in each new return claim. Classify as recorded-serial, quantity-only, or inconsistent; explicit serialized evidence with unresolved IDs is inconsistent and blocks this extension. Do not use the current catalog flag or mutable ProductSerialNumber.dispatch_detail_id alone to reconstruct history.

For legacy sources without a tracking snapshot, use persisted fulfillment lists and histories. The proposed compatibility default is quantity-only when all available persisted source evidence contains no recorded serials and no conflicting serialized indicator; no claim of proving the historical catalog flag is made. Store the basis for this classification. Serial registrations attributable to returns must be excluded from original-fulfillment inference. Source fingerprint checks must distinguish expected lifecycle changes from stale or externally changed fulfillment.

Alternative rejected: changing the form's required flag only, which leaves server claims, coverage, bundle expansion, and later lookups inconsistent.

### 2. Persist proposed serials and registration evidence separately

Add a return serial registration table with stable claim UUID, workflow, return/header and line/detail references, original source identity, component identity where applicable, source classification/basis, unit attribution key, normalized proposed text, nullable registered serial ID, and registration actor/time. Use real foreign keys for concrete standard/POS references and enforce valid workflow/reference combinations. Save no placeholder ProductSerialNumber records. Expose registered serial IDs through existing received detail/line relationships after receiving.

One row represents one unit, with unique claim UUID and one registration outcome per claim. Persist unit attribution separately from commercial source quantity. Existing returns without registration rows use existing behavior. Use additive source-context fields where existing POS snapshot and execution context cannot preserve the historical classification without ambiguity. Exact column names may follow module conventions during implementation; the identity and conservation constraints are fixed.

Alternative rejected: storing free text in serial ID arrays or creating inventory serial records on draft save, which breaks validation or leaks unreceived stock.

### 3. Extend existing entry surfaces and validate on the server

Keep the existing lookup first. After source selection, a not-found result for the selected product can become a proposed unknown value only when authoritative classification permits it. Show its pending registration state and allow correction through existing editable states. Normalize with the existing model helper and enforce existing serial length limits. A serial with matching text on another product does not collide because identity is product-scoped. Existing same-product records always enter existing validation; never silently change them.

Count selected existing and proposed serials against received units, reject normalized duplicates across all lines for the same product, and retain original source quantity checks and workflow access guards. Recheck product tracking and source classification at submit/receiving; a policy/source change invalidates the affected pending action for refresh rather than silently dropping entered serials. This is a stale-state guard, not a historical data migration.

Alternative rejected: a separate unrestricted serial-creation screen, which would lose source-bound eligibility and permit lineage bypass.

### 4. Register inside the receiving transaction with collision-safe retries

Use a shared registration service called by `StandardReturnCustodyService::receive` and the physical receiving portion of POS lifecycle approval/receiving. Follow existing lock ordering: source sale(s), return and source claims/details, registration rows, serial/product stock as required by the workflow. Serialize entitlement validation against the source. Preview validates proposals and emits registration intentions without calling the mutator. A missing serial ID alone must not make a valid pending registration unapprovable.

For each claim, first check committed registration evidence for this action. An already registered claim is an idempotent replay. Otherwise check product-scoped normalized uniqueness and create the serial within the enclosing transaction. Use the database unique constraint as the final guard against concurrent creators; translate collisions to a field-level review blocker and roll back the whole action. An independently created existing serial is never auto-attached. Preserve pending text and existing lifecycle state on failure. Do not catch a unique violation and continue a partially failed database transaction.

Record registration origin, source association, actor/time, and serial history as return-time evidence. An operational dispatch-detail anchor may be populated where existing guards require it, but audit and source classification must identify it as return registration, not original sale recording. Do not append IDs/text to original SaleDetails or DispatchDetail serial lists.

Alternative rejected: registration before approval/receiving or silent first-or-create reuse, which produces abandoned records or steals another process's serial identity.

### 5. Preserve disposition and use received evidence for downstream coverage

Standard receiving creates serial-backed held custody and no sellable quantity. Settlement and completion derive received occurrences from persisted received serial claims, not the current product flag alone. Inspection and original/replacement dispatch continue through standard custody services.

POS registration participates in existing good/bad receiving and owner/tax stock effects. Carry registration intentions through approval preview and plan persistence. Once registration resolves, apply the serialized physical path to the registered unit and any eligible serialized replacement. Existing quantity-only note-only replacements remain unchanged when no serial registration applies. This distinction must be explicit in the plan; it cannot switch merely because a nullable ID becomes populated late in execution. Maintain existing replacement stock and HPP guards.

Alternative rejected: forcing standard inspection into POS or treating a newly registered unit as an ordinary quantity-only note, either of which changes established workflow contracts.

### 6. Conserve unit entitlement across registration and subsequent returns

Bind each registered occurrence to a unit attribution under its original quantity-based source. Initial returns claim original quantity exactly once. A repair or replacement that returns a unit to the customer continues that attributed unit's chain; subsequent claims use its held occurrence and do not also decrement another untouched original unit. Cash return consumes the attributed unit's remaining refundable entitlement once. Lookup must show untouched quantity units and customer-held registered occurrences without counting either twice.

Extend standard eligibility/claim/coverage and POS quantity guards, replacement-chain resolution, lookup and receipt projection as needed. Preserve completed event ordering. Apply the same accounting per component using its own bundle allocation, not the carrier row's nominal quantity or parent product identity.

Alternative rejected: considering every newly registered serial an independent new sale entitlement, which permits over-return; or using all return registrations as historical sale serials, which strands untouched old units.

## Risks / Trade-offs

- [Legacy evidence cannot prove a past catalog flag] -> Classify from persisted fulfillment evidence, persist the basis, and block inconsistent serialized sources. Do not infer from the current flag alone.
- [Registration changes snapshot hashes and preview identity] -> Stable claim UUIDs, explicit plan intentions and expected registration mappings separate proposal identity from generated serial IDs.
- [Two returns compete with purchase receiving or each other] -> Source quantity locks plus existing product-scoped unique constraint, full transaction rollback, and production-database concurrency verification.
- [One source can contain untouched quantity and returned customer-held serials] -> Persist unit attribution and test mixed partial/repeated return chains, including refunds.
- [New registration bypasses held-serial guards] -> Register directly into workflow disposition within the receiving transaction and retain existing availability scopes and custody guards.
- [Older application versions cannot interpret registration claims] -> Roll out migrations before code; avoid rolling back code over pending or completed new claims without a compatible release.

## Migration Plan

1. Add nullable context fields and registration/attribution persistence without destructive backfills. Preserve existing serial uniqueness and existing return records.
2. Deploy shared classification/registration and both workflow adapters together. Existing lookup and currently non-serial flows remain compatible.
3. Run focused tests with `composer test:fresh-sqlite` or focused `php artisan test`; verify races on the deployed database engine because SQLite does not reproduce row-lock behavior.
4. Exercise ordinary and split-owner bundle UAT in both workflows, including collision, failure after registration, replay, inspection, repair/replacement, and a subsequent refund.
5. If rollback is required, prevent new registration submissions and retain all recorded serial/return evidence. Do not delete registered serials or reverse inventory via migration rollback. Use a compatible fix-forward release for already executed returns.

## Context

Product bundles are independent per-setting definitions with an administrative `is_active` state and optional date boundaries. Runtime bundle queries currently apply setting scope inconsistently and do not consistently evaluate enabled state, dates, composition, or component availability. POS drafts already persist bundle metadata and components in transaction-line snapshots, and Sales persists bundle composition in sale rows. Those snapshots must remain authoritative when live definitions later change.

The change spans Product bundle resolution, POS discovery/cart/draft/checkout, and Sales cart/draft/approval/dispatch. It must distinguish a live lifecycle warning from an operational failure: an inactive or deleted component can be acknowledged for captured work, but insufficient stock, serial conflicts, invalid persisted data, or unresolved dispatch mappings remain hard gates.

## Goals / Non-Goals

**Goals:**

- Provide one reusable, setting-scoped lifecycle evaluator for bundle selection and captured-snapshot warnings.
- Prevent new selection of bundles that are currently ineligible.
- Warn, rather than block, when live bundle or component eligibility changes after POS or Sales snapshot capture.
- Support request-scoped acknowledgement without a database or audit record.
- Preserve snapshot-authoritative checkout, approval, and dispatch behavior.
- Preserve current stock and other operational validation gates.

**Non-Goals:**

- Reworking bundle composition, pricing, allocation, or one-level expansion rules.
- Persisting acknowledgement history or suppressing future warnings.
- Refreshing old drafts from current bundle definitions.
- Weakening stock, serial, ownership, location, tax, integrity, idempotency, or dispatch checks.
- Applying lifecycle checks to completed receipts, returns, or reports.
- Backfilling bundle copies for settings created after bundle creation.

## Decisions

### 1. Use one evaluator with selection and captured-snapshot modes

A shared domain service will produce structured eligibility results instead of duplicating query clauses across POS and Sales. Selection mode evaluates the live bundle and rejects ineligible new choices. Captured-snapshot mode compares persisted bundle/component identity with the current setting-scoped definition and returns warning reasons without changing the snapshot.

The result should contain stable reason codes plus display context for bundle and component lines. Expected reasons include disabled, not started, expired, deleted definition, empty/invalid composition, inactive component, missing component, and component removed from the live composition.

Alternative considered: embed lifecycle clauses independently in each controller and Livewire component. Rejected because preflight/finalize and submission/approval could drift into different definitions of eligibility.

### 2. Treat activation boundaries as inclusive business dates

Eligibility will compare `active_from` and `active_to` with the current calendar date in the configured application business timezone. Null dates are open-ended, and equality with either boundary is eligible. The transaction document date and report date do not revive an expired live bundle for new selection.

Alternative considered: compare database timestamps or the entered Sales date. Rejected because the schema stores dates and backdating could unintentionally revive an expired offer.

### 3. Filter discovery and assert again at server-side selection

POS search/scan bundle-parent metadata, POS bundle options, and Sales bundle choices will expose only eligible setting-specific definitions. The authoritative add/confirm operation will repeat the assertion against bundle id, parent product, and transaction setting so direct requests cannot bypass the UI.

Alternative considered: UI filtering only. Rejected because current endpoints accept direct bundle identifiers and lifecycle state can change after discovery.

### 4. Use a two-request warning/acknowledgement contract

When a captured transaction encounters lifecycle warnings, the first request returns or surfaces a consolidated warning and performs no requested status transition or posting mutation. The user may cancel or resubmit the same operation with an explicit acknowledgement flag. The acknowledged request reevaluates warnings, then proceeds from the persisted snapshot if all hard validations pass.

The acknowledgement is carried by the immediate HTTP or Livewire action only. It is not stored in the database, audit metadata, or durable session state. Each later load, submit, approval, checkout, or dispatch action can warn again.

Alternative considered: store acknowledgement on the draft or in session. Rejected because the user explicitly does not require an acknowledgement record, and durable/session suppression could hide warnings from later operations or different drafts.

### 5. Keep persisted snapshots authoritative after capture

POS draft hydration continues to use persisted transaction-line bundle metadata. Sales edit, approval, and dispatch continue to use persisted `sale_details` and `sale_bundle_items`. Live data is consulted only to build lifecycle warnings; it cannot add, remove, rename, reprice, or requantity captured demand.

Missing live bundle/component records are therefore warning conditions when persisted identity and operational metadata remain usable. Legacy snapshots that cannot reconstruct demand remain integrity failures.

Alternative considered: reload live composition after acknowledgement. Rejected because it would silently change customer pricing and inventory demand inside an existing transaction.

### 6. Check at every mutation boundary without affecting historical readers

Captured-snapshot warnings will be evaluated at POS draft load, checkout preflight, and non-replay finalization, and at Sales draft load/edit, submit/update, approval, dispatch creation, and dispatch approval where those are distinct actions. Finalization must evaluate before new checkout ledger or posting mutation. An already-posted matching idempotent replay remains historical and returns its stored response.

Receipt/reprint, return, and report readers will not call live lifecycle eligibility. Their existing persisted-data reconstruction remains unchanged.

Alternative considered: check only when the draft is loaded. Rejected because lifecycle can change between load, payment, approval, and dispatch.

### 7. Lifecycle acknowledgement never bypasses operational gates

After acknowledgement, existing validation order continues through snapshot integrity, stock allocation, serial requirements, ownership/location/tax resolution, payment rules, and dispatch reconciliation. Component stock availability remains the practical execution gate even when the live component is inactive or missing.

POS should perform the warning check before entering or mutating staged payment flow when possible and repeat it before non-replay finalization. If payment stages already exist, lifecycle cancellation must use existing staged-payment recovery behavior rather than deleting payment state implicitly.

Alternative considered: make deleted/inactive components a hard lifecycle failure. Rejected because persisted composition is the agreed transaction authority and stock availability already determines whether component demand can execute.

## Risks / Trade-offs

- [A live component record may be missing even though the snapshot names it] → Build warning display from persisted names/identifiers and allow existing stock/dispatch resolution to determine executability.
- [The bundle changes again between warning and acknowledged retry] → Reevaluate warnings on the acknowledged request; acknowledgement applies to the current evaluated operation, while hard validations still run against current operational state.
- [Repeated prompts may inconvenience users] → Consolidate all bundle/component warnings per operation; do not persist suppression because later operations represent new decisions.
- [Normal Sales currently has unscoped bundle selection queries] → Route all new-selection resolution through the setting-scoped shared evaluator and cover cross-setting identifiers with focused tests.
- [Payment staging may precede a later lifecycle change] → Warn before payment entry and recheck before new finalization; preserve existing staged-payment recovery semantics when the user cancels.
- [Legacy data may not contain enough captured fields after a live product deletion] → Treat insufficient persisted identity as an integrity failure, not as an acknowledgeable lifecycle warning.
- [Static resolver caching could retain an eligibility result during a long request] → Keep cache keys setting-specific and ensure mutation-boundary assertions obtain an authoritative current result or explicitly clear/bypass stale cache state.

## Migration Plan

1. Introduce the shared evaluator and reason/result contract without changing persisted schemas.
2. Apply eligible-only discovery and authoritative selection assertions to Product resolver consumers, POS, and Sales.
3. Add captured-snapshot warning responses and acknowledgement handling to POS draft/checkout boundaries.
4. Add the same warning handling to Sales draft, submit/update, approval, and dispatch boundaries.
5. Add focused regression coverage for the touched paths and historical isolation.

Rollback removes the evaluator integrations and warning UI/request fields. No data rollback is required because acknowledgement is not persisted and no migration is introduced.

## Open Questions

None. Bundle/header lifecycle, inactive or deleted component handling, request-scoped acknowledgement, snapshot authority, and stock as a hard gate were resolved during exploration.

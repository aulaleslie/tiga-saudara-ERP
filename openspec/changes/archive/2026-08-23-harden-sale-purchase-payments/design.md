## Context

Sales and purchase payments use separate legacy controllers, Blade forms, action partials, and Yajra DataTables. Both edit forms still expose monetary fields, but their request contracts have drifted: the sales update requires `payment_method_id` while its form submits `payment_method`, and the purchase update still relies on the legacy payment-method string. More importantly, modifying settled payment facts in place weakens auditability.

Both parent models already derive effective paid amounts from active payment rows. `Sale` also exposes `reconcileFromActivePayments()`, including supported customer-credit applications, while purchase controllers repeat equivalent reconciliation logic. Purchase currently requires active payments to be invalidated before physical deletion; sales directly deletes ordinary payments but adjusts cached header totals by subtraction instead of rebuilding them from the ledger.

This change spans `Modules/Sale` and `Modules/Purchase`, must preserve setting isolation, permissions, archived-document behavior, credit-backed payment guards, attachments, global read-only contexts, and automated payment invalidation used by POS Return.

## Goals / Non-Goals

**Goals:**

- Make financial payment attributes immutable after creation while retaining note correction.
- Provide consistent read-only payment details and note-only maintenance for sales and purchases.
- Expose payment notes in both normal payment-history tables.
- Make eligible payment deletion a single user action and reconcile balances from authoritative active payment records atomically.
- Close parent-binding, setting-scope, permission, archived-parent, and dependency-guard gaps.
- Reuse existing models, permissions, views, and reconciliation semantics for a small brownfield change.

**Non-Goals:**

- Changing payment creation, global multi-payment allocation, POS staged payment, return settlement, or attachment-upload behavior.
- Adding payment revision history, soft deletes, new permissions, schema columns, or migrations.
- Allowing deletion of credit-backed or automatically invalidated audit records without a defined reversal process.
- Reworking sales or purchase document-note editing.

## Decisions

### 1. Convert existing payment maintenance into detail plus note-only update

The normal payment action will use an eye icon and a View label/title. The existing edit pages and routes may be renamed to explicit `show`/note-update routes where route compatibility permits; otherwise the existing route names can remain as compatibility aliases while their semantics become read-only detail and note-only mutation. The page renders stored financial fields as text or read-only presentation, not disabled form controls, and provides a compact note editor only when the user can modify the payment.

The update handler will validate only `note`, normalize an empty string to null, reload the payment's actual parent, enforce setting and archived-state guards, and update only the note column. Request-provided monetary or relationship fields will never be passed to `update()`.

Alternative considered: retain the edit form and disable financial controls. Rejected because disabled or read-only HTML is only a presentation control and leaves the server endpoint vulnerable to direct monetary updates.

### 2. Reuse existing payment edit permissions for note modification

`salePayments.edit` and `purchasePayments.edit` will govern the note modification button and endpoint. Existing payment access permissions govern viewing. Delete continues to use the module's payment-delete permission. No permission migration or role change is needed.

Invalidated payments and payments under archived documents remain readable but note-immutable. This retains their value as historical evidence while keeping the common active-payment note correction workflow simple.

Alternative considered: introduce dedicated `*.notes.edit` permissions. Rejected because it expands administration and deployment scope without a stated need for a separate role boundary.

### 3. Add the stored note directly to each DataTable projection

Each normal payment DataTable will add a Catatan column backed by the payment's existing `note` attribute. Rendering will escape untrusted content and use a consistent marker such as `-` for null/empty values. The column remains exportable and printable. Existing eager-loaded relationships remain unchanged because notes require no relationship lookup or per-row query.

Alternative considered: show only a note tooltip or detail link. Rejected because the requirement is to read notes without opening each payment and tooltips are poor for long or keyboard-accessed text.

### 4. Delete eligible payments directly but protect dependent accounting records

Active ordinary payments become directly deletable from the table after confirmation. Manually invalidated purchase payments remain deletable. The separate manual purchase Invalidate action can be removed from the normal history UI, while model status support and controller/service APIs needed by automated correction workflows remain.

Sales payments with customer-credit applications remain non-deletable because deletion must also restore credit balances and application state; that reversal is outside this change. Payments carrying automated invalidation source lineage remain protected from physical deletion because they are audit evidence for return/correction workflows. The same guard pattern should reject any newly discovered dependent settlement relationship unless it is explicitly reversed in the same transaction.

Alternative considered: preserve purchase invalidate-then-delete. Rejected because it is a redundant two-step operation for user-initiated removal and the user explicitly accepts immediate deletion. Alternative considered: delete every payment and cascade dependencies. Rejected because silent cascading can corrupt credit and return audit trails.

### 5. Reconcile from remaining active rows under a transaction and parent lock

Deletion will start a database transaction, resolve and lock the actual parent document, recheck authorization/ownership/eligibility against current state, delete the payment, and rebuild cached header fields from effective active payments.

Sales will use `Sale::reconcileFromActivePayments()` so monetary payments plus supported active credit applications remain canonical. Purchase will gain or reuse a single reconciliation method equivalent to its existing `getEffectivePaidAmount()` calculations, avoiding repeated controller formulas. Status comparisons will use the established small monetary tolerance and normalized `UNPAID`, `PARTIAL`, and `PAID` values.

Alternative considered: subtract the deleted amount from stored `paid_amount`. Rejected because stale header values remain stale and sales credit applications are omitted.

### 6. Bind maintenance to the payment's actual parent and normal setting scope

Every detail, note-update, and delete path derives the parent from the payment relationship. If the route includes a parent id, it must equal the related parent id. Normal purchase routes retain `withArchived()` lookup only for display and enforce active `setting_id`; sales receives the equivalent setting guard. Mutation additionally rejects archived parents. Global cross-setting payment pages remain read-only and are not granted normal mutation semantics.

Alternative considered: trust hidden `sale_id` or `purchase_id` inputs. Rejected because client-controlled identifiers can move or expose a payment outside its real relationship.

## Risks / Trade-offs

- [Existing bookmarks or integrations post full edit payloads] → Keep route aliases if practical, but accept only note input and document the monetary-edit behavior as intentionally removed.
- [Direct deletion reduces audit history for ordinary manual payments] → Require explicit confirmation, authorization, transactionality, and dependency/automated-lineage guards; a future change can add soft deletion or a dedicated reversal ledger if stronger audit retention becomes necessary.
- [Header balance values have pre-existing drift] → Canonical reconciliation intentionally repairs the affected parent from remaining active rows rather than preserving drift.
- [Case differences in historical payment-status values] → Write the established normalized uppercase values and assert them in focused tests.
- [Concurrent payment creation or deletion] → Lock the parent and compute effective totals inside the same transaction after deletion.
- [Global payment tables accidentally receive mutation controls] → Preserve existing global-mode read-only branching and cover it with a focused regression assertion where touched.

## Migration Plan

1. Add focused regression tests that capture immutable fields, note-only mutation, ownership guards, deletion eligibility, and canonical reconciliation.
2. Add or consolidate parent reconciliation helpers, then change controller detail/update/delete behavior inside transactions.
3. Replace normal action buttons and edit forms with read-only detail plus note modification controls.
4. Add Catatan columns to both DataTables and update their focused response/action tests.
5. Run only the related Sale and Purchase payment feature/unit tests and the specifically touched DataTable/global-mode regressions.

No database migration or data backfill is required. Rollback consists of reverting application code; existing payment data remains schema-compatible throughout.

## Open Questions

None. Credit-backed payments and protected automated invalidation records are deliberately excluded from immediate physical deletion until a separately specified atomic reversal workflow exists.

# Design

## Context

See proposal.md for the problem and scope. The existing editor (`app/Livewire/Adjustment/AdjustmentProductTable.php` and its Blade view) starts search-added rows at zero and derives serialized counts from serial entries. `CountDraftService` persists rows in `adjustments.count_draft`; `StockOpnameLifecycleService` submits them. Pending documents are locked against editing; rejection permits revision and resubmission.

`StockOpnameReconciliationService` classifies entered and omitted serials. `StockOpnameApprovalService::applySerializedPlan` starts from existing buckets and adds/removes quantities per serial. An empty list with no available serials therefore leaves an unmatched positive bucket unchanged. Creating a new serial also increments a bucket even when existing unmatched quantity represents the same physical unit. The main approval spec's unconditional unknown-serial increase must change; this change includes the full modified requirement block.

The observed example is shared product 4014: Perdana has quantity one with no available serial; White Knight has one serial-backed unit. Two approved zero proposals for Perdana recorded no inventory change. Those historical approvals are evidence, not documents to rewrite.

## Goals / Non-Goals

**Goals:** Keep the current counting form, lifecycle, permissions, serial validation, deterministic location ordering, atomic approval, and inventory audit mechanisms. Add explicit zero intent and a bounded correction to serialized reconciliation.

**Non-Goals:** No counted/un-counted state machine, extra confirmation modal, mandatory reason field, new table, new permission, generic inventory repair framework, background cleanup, or POS-return changes. No confirmation requirement for positive physical totals, even when one condition becomes zero. Zero confirmation is intent evidence, not proof that a physical count was accurate.

## Decisions

### 1. Dedicated row data in the existing document JSON

Add explicitly named `zero_confirmed`, `zero_confirmed_by`, and `zero_confirmed_at` properties to each count row. Store dedicated scope information if needed to compare the product and selected location set; do not hide intent in baseline signatures, notes, tax flags, count values, or `approved_by`/`approved_at`. Existing JSON is the document container, not a repurposed business field. No database migration is planned.

The editable payload expresses confirmation intent only. `CountDraftService` derives actor/time from the authenticated action when confirmation is newly established; it ignores client attribution. For unchanged rows preserve persisted attribution, including when another editor saves unrelated header changes. Missing metadata defaults to unconfirmed, never inferred from zero values or old approvals.

Invalidate and clear attribution whenever counts, serial entries (including condition changes), or locations change. Existing location changes already clear counting state. Row removal/re-addition starts unconfirmed. Apply reset behavior at each existing count/serial mutation path and compare saved versus submitted counting scope server-side; hidden JSON must not be the sole enforcement point. A fresh explicit checkbox action after changing a row can establish new attribution. Do not introduce a new signing or event-storage subsystem.

### 2. One checkbox with authoritative lifecycle checks

Show “Saya sudah menghitung: stok fisik kosong” on every zero-total row, including serialized rows. Showing it on zero rows regardless of system quantity avoids exposing whether stock exists to counters without `adjustments.view-system-stock` and supports a later approval-time stock increase.

Saving an unconfirmed draft remains valid. At submission and locked approval, require confirmation when the product's current selected-pool total is positive and entered good plus bad equals zero. Use existing stock resolution/locking rather than client baselines. Validate before lifecycle transitions, notifications, serial changes, or financial/inventory posting. Missing confirmation produces product-specific Indonesian validation without system-stock values for unauthorized users.

Preserve existing stock-drift review: changed live stock does not by itself clear an otherwise valid confirmation for the unchanged count/location scope, but approval must re-evaluate whether confirmation is required and refresh all inventory conflicts. A pending document lacking confirmation is rejected through the existing reason-required action, edited, and resubmitted. Approvers cannot tick confirmation during approval. Retried already-approved actions keep existing idempotency behavior.

Alternative rejected: a general counted-state workflow is larger than the agreed safeguard. Treating all zeros as intentional would turn the current reconciliation defect into accidental inventory removal.

### 3. Extend serialized planning to include unmatched quantities

Use one explainable plan for preview and approval. For each selected location and good/bad tax bucket, compare current physical-stock quantity with serial coverage that actually belongs to that stock bucket. Follow established availability, custody, condition, and reservation semantics: sold, dispatched, and missing records are not available stock; protected serial-backed stock must not become an unprotected residual merely because it is unavailable for sale. Bulk-load the required coverage together with existing serial context.

Maintain existing retained, moved, reclassified, omitted, and new-serial actions. Add explicitly named unmatched-quantity effects to the serialized plan:

- A complete physical serial count accounts for the selected pool's eligible unmatched units. Any unmatched quantity not represented by the physical count is removed; this also supports partial reductions with a positive entered total.
- For a newly entered serial, assign eligible unmatched quantity of its physical condition first, using the existing deterministic location ordering among eligible selected locations. Record any bucket/location change explicitly under destination-owner tax rules. When there is no such quantity left, use existing surplus destination selection and increase inventory only for the true excess.
- Existing serials retain their actual location when already in the pool; outside serials retain their existing validated source-debit/destination-credit movement. Unmatched stock in unselected locations cannot absorb a new serial or be corrected by this document.
- Handle good and bad independently. Quantity/coverage contradictions, negative buckets, reserved-stock shortfalls, held custody, and unsafe serial actions block approval rather than being clamped away.

Apply resulting bucket deltas under existing document/product/location/stock/serial locks and buyout/transfer/custody guards. Update global product quantities by actual net location deltas, never by replacing global quantity with the selected count. Record per-location inventory transactions, serial history, and notifications through the existing mechanisms. A zero net quantity change can still require serial registration history and explicit unmatched-unit attribution.

Examples: unmatched one → confirmed empty gives delta -1; unmatched one → one new physical serial gives delta 0; unmatched two → three new serials gives delta +1; quantity two with one retained serial → count only that serial gives delta -1. No hard-coded product or location repair.

Alternative rejected: blindly reset all quantity to an available-serial count. That can erase protected custody stock, change unselected locations, and obscure why quantity changed.

### 4. Extend existing projections and immutable results

Add dedicated confirmation and unmatched-reconciliation properties to existing view models/DTOs and the approval result. Keep confirmation actor/time distinct from approver/time. In the existing review show confirmation as entered evidence; only stock-authorized projections expose unmatched amounts, per-location allocations, and net effects. Extend immutable result rendering rather than recalculating historical documents from live stock. Keep existing field meanings intact; explicitly name added evidence such as unmatched-before, unmatched-removed, and existing-quantity-accounted-for-by-new-serials.

## Risks / Trade-offs

- [Accidental removal becomes possible once reconciliation works] → Ship confirmation validation and serialized correction together; direct-service and approval tests cover missing confirmation.
- [Legacy pending documents cannot proceed immediately] → Explain the existing reject/edit/resubmit route; no automatic confirmation or rewrite.
- [Stock quantity and serial coverage disagree for protected units] → Use stock-bearing coverage and existing guards; block contradictory states rather than infer that every unavailable serial is absent.
- [Preview differs from actual posting] → Derive both from the same planned unmatched effects and recompute under approval locks; test projected versus applied totals.
- [Multi-location allocation changes owner or condition silently] → Keep location/bucket evidence explicit, preserve existing serial locations, and test mixed owners, PKP status, good/bad, and unselected locations.

## Migration Plan

Deploy the UI, document-data validation, submission/approval guards, and serialized plan changes together. No automatic data backfill is needed: absent confirmation is false. Existing draft/rejected documents can be edited; pending zeroing documents require rejection and revision. Approved history stays readable with optional new fields.

Before release, verify the motivating case on a staging copy and the inverse case of entering a physical serial for existing unmatched stock. Roll back application code only if needed; retain new document evidence. Older code does not enforce the safeguard, so a code rollback also restores the prior behavior and must not be described as preserving zero protection. Do not reverse approved stock corrections by deleting audit data; use the normal audited adjustment workflow.

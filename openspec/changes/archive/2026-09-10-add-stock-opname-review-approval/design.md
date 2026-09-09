## Context

The supported normal Stock Opname editor persists a versioned `count_draft` JSON payload containing the selected location, its PKP snapshot, entered product counts, serial text and source metadata, and a signed location-stock baseline. Users without `adjustments.view-system-stock` receive only opaque baseline tokens, while permitted users can see the captured selected-location quantities. Saving currently assigns `pending`, sends an approval notification immediately, and then blocks approval because the only posting implementation understands the older detail format.

This change completes the redesigned workflow without preserving legacy approval behavior. It crosses adjustment lifecycle, permission-aware presentation, inventory reconciliation, serial movement/creation, tax buckets, notifications, audit evidence, and active-setting authorization. Product-facing text must be Bahasa Indonesia even though code and engineering discussion remain English.

## Goals / Non-Goals

**Goals:**

- Separate editable counting from explicit submission and approval.
- Show counters only their submitted facts while giving permitted reviewers an accurate, current, explainable reconciliation preview.
- Apply entered good/bad counts only to entered products at the selected location.
- Move registered serials from other locations, create unknown serials, reclassify condition and tax from destination PKP, and keep aggregate quantities consistent.
- Warn when a selected-location count exceeds total stock in the same owner scope and when stock or serial facts changed after counting.
- Make preview and approval share classification rules, revalidate under locks, post atomically, and retain the actual applied result.
- Enforce active-setting ownership on every document and lifecycle action.

**Non-Goals:**

- Supporting or preserving approval behavior for historical legacy-format adjustments.
- Counting products omitted from the document or zeroing an entire location implicitly.
- Targeting consignment locations or reconciling unrelated businesses.
- Fractional base quantities, camera scanning, or changes to breakage adjustment.
- Automated browser testing or planning a full test-suite run.

## Decisions

### 1. Use an explicit document lifecycle

Normal versioned Stock Opname uses `draft`, `waiting_approval`, `approved`, and `rejected`. Create and ordinary update persist `draft` and do not notify approvers. A dedicated idempotent submit action validates a nonempty draft, records submitter/time, locks editing, changes it to `waiting_approval`, and sends the approval notification. Only `waiting_approval` may be approved or rejected. Rejection requires a reason; editing a rejected document produces a draft that must be submitted again.

Store `submitted_by/at`, `approved_by/at`, `rejected_by/at`, and `rejection_reason` on the adjustment header, plus nullable `approval_result` JSON for the immutable applied reconciliation. Clear superseded decision metadata when a rejected document is revised. Header fields make lifecycle filtering and audit display reliable; JSON is reserved for the variable per-product and per-serial result.

Alternative: keep `pending` for both draft and review. Rejected because it cannot securely determine editability, notification timing, or approval eligibility.

### 2. Build one server-side reconciliation classifier

Introduce an adjustment-owned reconciliation service used by both the show page and approval. Given an adjustment and a read mode, it bulk-loads entered products, all relevant `ProductStock` rows, serials matching entered product/text pairs, locations/settings, and the saved baseline. It returns per-product selected-location baseline/current/count/difference, same-owner eligible-location current total, projected global effect, drift, and serial classifications.

The same service supports a locked approval mode inside a database transaction. The preview is informative and can become stale; approval recomputes it after locking affected stock and serial rows. The applied locked result, not the earlier browser preview, is persisted in `approval_result`.

Avoid queries in row/serial loops. Load records in bounded grouped queries keyed by product ID and normalized serial text. “All locations” means non-consignment inventory locations in the counted product's owner/setting scope. Unrelated settings are excluded.

Alternative: calculate classifications in Blade and separately in approval. Rejected because display and mutation could disagree.

### 3. Keep entered-product scope absolute and explicit

Every row in `count_draft.rows` is an absolute good/bad physical count for that product at the selected location. Approval updates only those products. A zero row explicitly sets that entered product's selected-location count to zero; an omitted product remains unchanged. For ordinary non-serialized products, other locations are never mutated.

For each product show:

- captured baseline at the selected location;
- current selected-location good, bad, and total;
- entered good, bad, and total;
- signed good/bad differences;
- current total across eligible same-owner locations;
- projected global total and any unexplained increase.

Warn when entered selected-location total exceeds current all-location total. Good-to-bad reclassification with an unchanged total is described separately from a global increase or decrease.

Alternative: apply entered values as deltas. Rejected because physical counts are absolute observations.

### 4. Destination PKP is authoritative

At preview and approval, reload the destination location and its setting. If `is_pkp` is true, every resulting good/bad quantity at the destination is assigned to tax buckets; otherwise every result is assigned to non-tax buckets. Never trust the saved client value or a serial's source `tax_id` for destination classification.

Moving a taxable serial to a non-PKP destination decrements its source tax/condition bucket and increments the destination non-tax/condition bucket; moving the reverse direction performs the inverse. The reviewer sees `Kena Pajak → Tidak Kena Pajak` or `Tidak Kena Pajak → Kena Pajak`. A new serial inherits destination classification. Pure movement/reclassification does not change global product quantity.

Alternative: retain serial tax identity across locations. Rejected because inventory tax classification is governed by the selected location's setting.

### 5. Classify every entered serial and every omitted destination serial

Re-resolve serial identity authoritatively by product ID plus normalized serial text. An entered serial is classified as already at destination, moved from another eligible location, new for this product, condition change, tax change, or conflict. The same text belonging only to another product remains a new serial for the entered product but produces a reviewer warning. Duplicate text for the same product is rejected.

Entering a serialized product asserts a complete serial set for that product at the selected location. Registered available serials currently at the destination but omitted from the count are listed as discrepancies and removed from that location during approval through the domain-consistent absence handling selected during implementation; they must never be silently deleted without an applied-result record. Serials tied to incompatible active dispatch/return or otherwise unsafe state are conflicts that block approval rather than being overwritten.

Movement updates the serial's location, condition/status representation, and destination-derived tax classification, as well as the exact source and destination good/bad tax buckets. Unknown serials are created only during approval, never during draft entry.

Alternative: reject cross-location and unknown serials. Rejected because finding misplaced and previously unregistered physical items is a core Stock Opname outcome.

### 6. Split counter and reviewer projections

The controller/service prepares an explicit view model rather than exposing raw `count_draft`. Without `adjustments.view-system-stock`, the page contains only reference/date/location/status/note and entered product names, codes, units, good/bad counts, serial text, and entered condition. It does not disclose baselines, current quantities, totals elsewhere, serial registration/source/tax state, classifications, or projected effects.

With `adjustments.view-system-stock`, show a Bahasa Indonesia review summary, warning cards, product comparison table, and expandable serial-impact table. Distinguish `Saat mulai dihitung`, `Saat ini`, `Hasil hitung`, and `Setelah disetujui`. After approval, render immutable `approval_result` as what actually happened instead of reconstructing it from later stock.

Approve/reject controls additionally require `adjustments.approval` and `waiting_approval`; review visibility alone never grants mutation authority. Remove the debug authorization panel from the page.

Alternative: conditionally hide columns while passing the full model to Blade. Rejected because Livewire/HTML state can leak protected stock details.

### 7. Post atomically with locked authoritative records

Approval begins a database transaction, verifies active-setting ownership and `waiting_approval`, locks the adjustment, affected product stocks, products, and matching serial rows, and recomputes reconciliation. Conflicts roll back with Bahasa Indonesia feedback. Successful posting:

1. adjusts source buckets for moved serials;
2. updates/creates entered serial records and destination classification;
3. handles omitted destination serial discrepancies;
4. sets destination good/bad tax buckets to the absolute entered totals;
5. changes product aggregate quantity by the net across all affected locations;
6. writes inventory transactions and stock notifications for actual changes;
7. stores `approval_result`, approver/time, and `approved` status;
8. resolves approval/revision notifications.

Submission, approval, and rejection use idempotency protection. Approval rejects a repeated or wrong-state request without duplicating inventory changes.

Alternative: invoke one transaction per product. Rejected because cross-location serial moves and aggregate stock must remain consistent as one document.

### 8. Treat active-setting ownership as part of authorization

Show, edit, update, delete, submit, approve, and reject verify that the document's destination location belongs to the active session setting and is not consignment. Product selection, draft validation, and reconciliation also constrain products and source/destination locations to the relevant owner scope. Permission checks remain necessary but are not sufficient.

## Risks / Trade-offs

- [Preview changes before approval] → Display drift prominently, recompute under locks, and persist the locked applied result.
- [A moved serial changes two locations and possibly two tax buckets] → Use one reconciliation plan and atomic transaction with exact source/destination bucket assertions.
- [Omitted serialized inventory can represent a loss or data error] → List every omission, require explicit approval confirmation, block unsafe linked serials, and retain its disposition in `approval_result`.
- [All-location totals can be expensive] → Query only entered product IDs, aggregate in SQL, eager-load relationships, and add/verify composite indexes on product/location and normalized serial lookup paths.
- [Raw JSON could expose protected facts] → Return role-specific view models and test response HTML plus Livewire/public state for absence of protected values.
- [Existing production rows use `pending`] → Because the workflow is unused, migrate versioned normal rows deterministically to `draft` and do not retain legacy approval compatibility.
- [Product aggregate quantity may already drift from location sums] → Base the approval plan on locked `ProductStock` totals, record before/after values, and surface pre-existing inconsistencies rather than hiding them.

## Migration Plan

1. Add lifecycle actor/timestamp/reason fields and nullable `approval_result`; index status and submission fields as needed.
2. Convert existing versioned normal `pending` documents to `draft`; do not migrate legacy-format approval data.
3. Deploy lifecycle guards, setting ownership checks, reconciliation service, permission-aware detail view, and approval posting together.
4. Run focused schema, lifecycle, permission-leakage, reconciliation, cross-location serial, PKP/non-PKP, atomic rollback, and idempotency tests.
5. Give a human a Bahasa Indonesia browser checklist for counter/reviewer views, submission, warnings, confirmation, approval, rejection, and approved history; record that execution separately.
6. For rollback, disable submit/approve first and preserve/export `count_draft` and `approval_result`; never deploy the old approval path over submitted redesigned documents.

## Open Questions

No blocking product-policy questions remain. During implementation, inspect the actual serial condition/status representation and active dispatch/return constraints to map the specified classifications to existing domain fields without inventing incompatible states.

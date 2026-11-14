# Proposal

## Why

Stock opname currently approves a serialized product's physical count of zero without removing quantity that has no matching available serial. Correcting this exposes another risk: a default zero on an unfinished count must not authorize removing stock.

## What Changes

- Add one per-product checkbox, “Saya sudah menghitung: stok fisik kosong”, covering the selected location pool. Require it before submission and approval when positive system stock would become a physical total of zero, for serialized and ordinary products.
- Allow incomplete drafts; clear confirmation when counts, serial entries, or selected locations change. Preserve existing partial-reduction behavior.
- Store explicitly named confirmation values with server-attributed confirmer and timestamp. Do not repurpose notes, quantities, approval metadata, or unrelated fields.
- Reconcile serialized quantity without matching available serials: confirmed zero removes it; a physical serial accounting for an existing unmatched unit does not add a duplicate unit.
- Show confirmation and unmatched-stock effects in existing permission-aware review and immutable approval history.
- Require existing unapproved zeroing documents to obtain confirmation; pending approvals use the existing reject, edit, and resubmit flow. Preserve approved history.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-opname-count-drafts`: Explicit zero intent, dedicated confirmation metadata, draft persistence, and invalidation on count/scope changes.
- `stock-opname-review-approval`: Authoritative zero safeguards and explainable serialized unmatched-quantity reconciliation in preview and atomic approval.

## Impact

Existing Adjustment Livewire table and Blade forms, count-draft validation/persistence, stock-opname lifecycle/reconciliation/approval services, review projections, and focused Adjustment tests. Use dedicated properties within the existing stock-opname document JSON and existing inventory/audit mechanisms; no new workflow, permissions, or database table is planned.

POS returns, standard sales returns, breakage adjustments, background data repair, and rewriting approved historical documents are outside this change. Product 4014 is a motivating example, not a hard-coded repair target.

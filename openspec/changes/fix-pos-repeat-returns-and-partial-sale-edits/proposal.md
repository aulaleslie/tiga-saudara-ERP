# Proposal

## Why

A valid partial quantity return can be blocked after another item on the same POS transaction has been replaced. Customers need repeated returns against remaining products and current replacement serials, and authorized users need to adjust monetary values on the remaining sale after a partial return.

## What Changes

- Allow any number of return processes per POS transaction while eligible quantities or customer-held serialized units remain; validate affected items rather than rejecting unrelated source changes.
- Allow independent pending returns while preventing overlapping quantity or serial claims.
- Follow completed replacement occurrences in order, including A → B → A, with one eligible replacement serial per returned serialized unit.
- Preserve existing replacement selection checks and returned-item good/bad disposition; introduce no repair or restoration workflow.
- Allow monetary-only editing of the remaining sale in RETURNED PARTIALLY status under existing permissions, preserving completed refunds and execution history.
- Require renewed review at the updated value when a monetary edit affects a pending return; unrelated item edits do not block approval.
- Verify only affected return, serial, monetary-edit, and receipt scenarios with focused tests; no full-suite requirement.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-return-approval-preview`: Repeated and independent returns use affected-item eligibility and renewed monetary review rather than transaction-wide drift blocking.
- `pos-return-serial-claim-eligibility`: Repeated replacement occurrences, including reused serial identities, remain returnable with exclusive current-unit claims.
- `privileged-post-fulfillment-monetary-edits`: Permit monetary edits of partially returned Sales while preserving completed refunds and protected execution facts.

## Impact

POS return snapshot, submission, approval planning/persistence, lifecycle locking, serial lineage resolution, and current receipt projection consumers; Sale edit eligibility, monetary edit service, Livewire cart/form and ordinary/global edit entry points. Reuse Laravel/Livewire/Eloquent patterns and existing permissions. Preserve archived OpenSpec history and checkout snapshots. No new dependencies or full test-suite gate.

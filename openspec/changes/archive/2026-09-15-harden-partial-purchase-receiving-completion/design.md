## Context

Purchase receivals intentionally allow detail rows with `quantity_received = 0` when at least one row in the receival is positive. Shortfall completion already aggregates only approved receiving notes, previews zero-approved lines as removed, and normalizes financial values from positive-approved lines. However, eligibility checks only for an approved note header, while persistence skips deletion whenever any `received_note_details` row exists. An approved zero-quantity row therefore counts as history and leaves its purchase detail behind after completion.

The completion operation already locks the purchase, purchase details, receiving notes/details, and active payments and records an immutable before/after audit snapshot. The hardening should remain inside that transaction and reuse its approved-quantity aggregate.

## Goals / Non-Goals

**Goals:**

- Define delivery evidence as a positive quantity on an approved receiving-note detail.
- Require at least one such positive approved row before preview or completion is eligible.
- Make preview and persistence classify every purchase detail from the same cumulative approved quantity.
- Delete purchase details with zero cumulative approved quantity even when approved receivals contain zero-quantity rows for them.
- Preserve atomic rollback and completion audit evidence.

**Non-Goals:**

- Disallowing zero-quantity rows in ordinary receival creation or approval.
- Changing stock, serial, transaction, pricing, payment, permission, or receiving-status behavior for positively received lines.
- Expanding verification to the full application test suite.

## Decisions

### Use positive approved detail quantity as the eligibility signal

Eligibility will use the existing approved-quantity aggregation and require at least one current purchase detail to have a cumulative approved quantity greater than zero. An approved header alone is insufficient.

This matches the receiving rule that a receival may contain zero rows but must contain at least one positive row, and it protects completion from malformed or legacy all-zero approved notes. A separate note-header count was rejected because it does not prove that any product was received.

### Classify and persist lines from one approved-quantity map

Preview and locked completion will both treat `approved quantity > 0` as retained and `approved quantity = 0` as removed. Completion will no longer use the existence of any receiving-detail history as a reason to retain a zero-approved purchase detail.

This keeps the displayed outcome, normalized financial inputs, audit snapshot, and persisted line set aligned. Treating zero-quantity history as delivery evidence was rejected because those rows intentionally represent products not delivered in that receival.

### Remove zero-quantity relational placeholders atomically

Deleting an unreceived purchase detail may cascade-delete its linked zero-quantity receiving details under the existing foreign key. The completion transaction will rely on that relationship behavior only for details whose cumulative approved quantity is zero. The original purchase line and its removal outcome remain captured in the immutable completion audit snapshot.

Any unexpected dependent material evidence that prevents deletion, such as a serial, stock transaction, or normalization record attached to a zero-approved detail, must make the transaction fail rather than leave a hidden or financially excluded purchase detail. Introducing soft deletion or a new archival table was rejected as disproportionate for zero-quantity placeholders and would require broad query changes.

### Verify the narrow regression surface

Focused Purchase receiving-completion tests will cover all-zero approved data rejection, eligibility when one approved row is positive, and deletion of a zero-approved line that has a zero-quantity approved receiving detail. Existing focused tests continue to cover normalization, audit, and transaction rollback behavior.

## Risks / Trade-offs

- [Deleting a purchase detail cascades its zero-quantity receiving-detail placeholders] → Limit deletion strictly to zero cumulative approved quantity and retain the completion decision in the immutable audit snapshot.
- [Unexpected material children exist below a zero-quantity detail] → Let database constraints fail the atomic completion instead of partially completing the purchase.
- [Preview becomes stale before submission] → Retain the existing locks and repeat eligibility and aggregation inside the completion transaction.
- [Malformed approved quantities are negative] → Treat only a strictly positive cumulative quantity as received; existing receiving validation remains responsible for preventing invalid input.

## Migration Plan

1. Deploy the service eligibility and zero-line deletion changes together.
2. Run the focused Purchase receiving-completion tests.
3. No schema or data migration is required; existing completed purchases are not rewritten.
4. Roll back the service change if necessary. Already completed audit records and normalized purchases remain unchanged.

## Open Questions

None.

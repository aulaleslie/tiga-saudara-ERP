## 1. Completion Eligibility and Line Persistence

- [x] 1.1 Update `PurchaseReceivingCompletionService` eligibility to require at least one strictly positive cumulative quantity from approved receiving-note details belonging to the purchase.
- [x] 1.2 Remove the generic receiving-history exemption so completion deletes every purchase-detail row whose cumulative approved received quantity is zero, while retaining positive-approved rows in place.
- [x] 1.3 Ensure zero-quantity receiving-detail placeholders and any database constraint failure are handled within the existing atomic completion transaction, with preview, audit, and persisted line outcomes aligned.

## 2. Focused Verification

- [x] 2.1 Add a focused regression test proving an approved receiving-note header with no positive detail quantity cannot preview or complete a purchase.
- [x] 2.2 Add a focused regression test proving a receival with at least one positive row remains eligible and its positive-approved purchase line is retained and normalized.
- [x] 2.3 Add a focused regression test proving a zero-approved purchase line is removed even when an approved receiving note contains a zero-quantity detail for it, and confirm the completion audit records the removal.
- [x] 2.4 Run the focused Purchase receiving-completion test class or equivalent narrow filters and resolve any regressions within this change's scope.

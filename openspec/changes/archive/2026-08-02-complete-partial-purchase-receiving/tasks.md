## 1. Permission and Persistence Foundation

- [x] 1.1 Add `purchases.receive.complete_shortfall` with its Indonesian label to the canonical receiving permission catalog and ensure the existing permission synchronization flow seeds it.
- [x] 1.2 Add a non-destructive `purchase_receiving_completions` migration with purchase, setting, actor, required reason, structured source/final snapshot, timestamps, indexes, and restrictive foreign keys.
- [x] 1.3 Create the completion audit Eloquent model and add its purchase, setting, and actor relationships.
- [x] 1.4 Add a purchase relationship for completion audit history without changing existing received-purchase correction semantics.

## 2. Completion Domain Service

- [x] 2.1 Implement a purchase-shortfall completion service that locks the purchase, purchase details, received notes/details, and active payments in deterministic transaction scope.
- [x] 2.2 Implement server-side eligibility checks for active setting ownership, non-archived partial status, approved receipt presence, remaining shortfall, and absence of pending received notes.
- [x] 2.3 Aggregate only approved received quantities per purchase detail and construct a stable preview containing original, received, retained, and removed line outcomes plus financial before/after values.
- [x] 2.4 Normalize retained detail quantities in place, remove only zero-received rows with no receipt history, and recalculate document totals through the existing purchase normalizer.
- [x] 2.5 Recalculate payment summary fields from active payments, reject completion when the normalized total is overpaid, set exact `RECEIVED` status, and persist the immutable audit snapshot atomically.
- [x] 2.6 Add guards to receiving creation and approval so a completed purchase cannot accept a new or late receiving note.

## 3. Authorization, Routes, and User Workflow

- [x] 3.1 Add setting-scoped preview and submit routes/controller actions protected by `purchases.receive.complete_shortfall`.
- [x] 3.2 Build one shared confirmation UI that shows the line-by-line and financial preview, requires a supplier-shortfall reason, and submits only the purchase-level completion command.
- [x] 3.3 Surface the shared action from eligible normal purchase-list row actions, purchase detail, and purchase receiving-history views.
- [x] 3.4 Hide every entry point for unauthorized or ineligible purchases while preserving backend authorization and lifecycle validation.
- [x] 3.5 Add success/error feedback that directs users to refresh the preview when concurrent lifecycle or payment changes invalidate it.

## 4. Verification

- [x] 4.1 Add feature tests for permission seeding, endpoint denial, active-setting ownership, and action visibility from the purchase list, purchase detail, and receiving history.
- [x] 4.2 Add service/controller tests for the single-line shortfall case: ordered 10, approved received 5, final quantity 5, exact `RECEIVED`, normalized balance, and audit snapshot.
- [x] 4.3 Add tests for the mixed-line case: retain a partially received line and remove an unreceived line while preserving approved receipt-detail identity and stock history.
- [x] 4.4 Add rejection tests for missing reason, pending note, no approved receipt, no outstanding shortfall, archived/foreign purchase, no permission, and active-payment overage.
- [x] 4.5 Add tests proving rejected notes do not count, completed purchases reject future receiving submission and late approval, and concurrent completion/approval does not leave partial mutations.
- [x] 4.6 Add payment eligibility coverage showing an unpaid completed-shortfall purchase appears in existing global purchase payment candidates and uses the normalized live balance.
- [x] 4.7 Run focused purchase receiving, payment, permission, and normalization tests, followed by the repository's appropriate fresh-SQLite or full PHPUnit verification command.

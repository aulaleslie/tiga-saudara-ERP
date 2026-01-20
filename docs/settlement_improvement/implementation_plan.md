# Implementation Plan: Purchase Return Settlement Improvement

## Recommended Order
Phase 0: Alignment (Completed)
- Confirm ticket scope, acceptance criteria, and no migration requirement.
- Lock definitions for "RETURNED" serial status and payment deletion behavior.

Phase 1: Settlement Selection and UX (Completed)
- Ticket 1: Remove cash settlement option (UI + validation).
- Ticket 2: Allow paid purchases for MODIFY_PURCHASE selection.
- Ticket 3: Add quantity mismatch warning (non-blocking).

Phase 2: Approval Effects and Credit Payment
- Ticket 4: Reset payments and set Unpaid on MODIFY_PURCHASE approval.
- Ticket 5: CREDIT approval dialog for notes + attachments.
- Ticket 6: Create purchase payment and credit linkage on CREDIT approval.

Phase 3: Receive Flow and Serial Lifecycle
- Ticket 7: PRODUCT_REPAIR receive rules and replacement serial entry.
- Ticket 8: Serial lifecycle updates (old RETURNED, new created).
- Ticket 9: BROKEN_STOCK receive quantity lock.

## Parallelizable Tasks
- Phase 1:
  - Ticket 1 and Ticket 2 can be implemented in parallel if ownership is split and UI conflicts are coordinated.
  - Ticket 3 depends on Ticket 2 data and should follow Ticket 2.
- Phase 2:
  - Ticket 4 can proceed in parallel with Ticket 5 UI work.
  - Ticket 6 depends on Ticket 5 UI fields and should follow Ticket 5.
- Phase 3:
  - Ticket 7 and Ticket 9 can be developed in parallel (different receive rules).
  - Ticket 8 depends on Ticket 7 and should follow it.

## Milestones
- Milestone 1: Settlement selection updated
  - Cash removed, paid purchases selectable, warning visible.
- Milestone 2: Approval effects complete
  - Modify Purchase payment reset and Credit payment creation with attachments.
- Milestone 3: Receive and serial lifecycle complete
  - Repair/broken stock receive rules enforced, serial lifecycle updated.

## Risks Per Phase
Phase 1 Risks
- UI-only removal could still allow CASH via API if validation is missed.
- Warning-only behavior may cause downstream errors if not aligned with approval logic.

Phase 2 Risks
- Payment deletion impacts audit/reporting; ensure explicit acknowledgement.
- Credit payment creation could conflict with existing payment workflows.
- Attachment handling must be transactional to avoid orphaned files.

Phase 3 Risks
- Serial status changes must be consistent across all search and validation paths.
- Replacement serial uniqueness constraints may block valid "same serial" use without explicit rules.
- Concurrency between approval and receive can cause stale data or double updates.

## Testing Strategy
Automated Tests
- Feature tests for settlement selection and approval flows:
  - Update or add tests in `Modules/PurchasesReturn/Tests/Feature` for selection, warnings, and approval effects.
- Livewire tests for settlement form behavior:
  - Validate method list excludes CASH and paid purchases are selectable.
- Controller tests for approval and receive:
  - Modify Purchase payment reset behavior.
  - Credit approval creates payment + attachments.
  - Repair/broken stock receive rules and serial lifecycle changes.

Manual Tests
- Settlement UI:
  - Verify CASH absent, paid purchases present, warning appears for non-serial overage.
- Approval flows:
  - Modify Purchase on paid/partial purchase deletes payments and sets Unpaid.
  - Credit approval requires notes/attachments and creates payment record.
- Receive flows:
  - PRODUCT_REPAIR serial quantity locked to 1, replacement serial required.
  - BROKEN_STOCK quantity locked, location selectable.
  - Old serials marked RETURNED and excluded from serial search.

Regression Checks
- Legacy settlements and print views render correctly.
- Serial search and validation exclude RETURNED serials.

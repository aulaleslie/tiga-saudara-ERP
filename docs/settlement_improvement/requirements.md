# Requirements: Purchase Return Settlement Improvement

1. Overview
- This document defines improvements to the purchase return settlement flow, focusing on settlement methods, approval behavior, receive handling, and serial lifecycle.
- Scope centers on per-item settlement in purchase returns and related approval/receive actions, with legacy data preserved.

2. Goals & Non-goals
Goals
- Remove "Pengembalian Tunai" as a selectable settlement method while preserving legacy data display.
- Allow MODIFY_PURCHASE to target paid, unpaid, and partial purchases, with correct approval side-effects.
- Enforce receive rules for PRODUCT_REPAIR and BROKEN_STOCK, including serial lifecycle updates.
- Provide warning-only feedback for non-serial quantity mismatches when selecting a target purchase.
- Enable CREDIT approval to capture attachments and notes and create a purchase payment for the target purchase.

Non-goals
- Do not redesign the overall purchase return creation or dispatch flows.
- Do not backfill or migrate historical settlement data (fallback only for legacy display).
- Do not implement new reporting or analytics beyond the settlement flow.

3. Personas
- Purchasing/AP Staff: configure per-item settlement methods and target purchases.
- Approver/Finance: approve settlements and ensure purchase/payment records remain accurate.
- Warehouse/Receiving Staff: receive repaired/replacement items and manage locations/serials.
- Auditor/Compliance: needs traceable serial lifecycle and payment documentation.

4. User Journeys
Journey A: Configure Settlement (AP Staff)
- Open a purchase return settlement.
- For each line, choose a settlement method (cash is not available).
- For MODIFY_PURCHASE, select any relevant purchase (paid/unpaid/partial).
- If non-serial return quantity exceeds purchase quantity, show warning but allow submission.

Journey B: Approve MODIFY_PURCHASE (Approver)
- Approve a submitted MODIFY_PURCHASE line.
- Purchase details are adjusted; if the purchase was paid/partial, all payments are removed and status becomes Unpaid.

Journey C: Approve CREDIT (Approver)
- Approver opens approval dialog for CREDIT.
- Enters notes and uploads attachments (jpg/png/pdf).
- On approval, a purchase payment is created for the selected purchase with the nominal value and attachments.

Journey D: Receive PRODUCT_REPAIR (Receiver)
- For serial items, quantity is locked to 1.
- Old serial is displayed; replacement serial is entered.
- Old serial becomes permanently returned and excluded from search; new serial is created.

Journey E: Receive BROKEN_STOCK (Receiver)
- Quantity is read-only (locked to expected value).
- Receiver selects location and submits; no serial replacement entry required.

5. Functional Requirements
Settlement Method Options
- Remove the cash settlement option from selectable settlement methods in UI and validation.
- Legacy settlement lines with cash must still render read-only labels correctly.

MODIFY_PURCHASE Selection
- Allow selecting purchases across paid, unpaid, and partial statuses.
- Display warning (non-blocking) if non-serial return quantity exceeds selected purchase quantity.
- Prevent selecting a purchase from a different supplier.

MODIFY_PURCHASE Approval Effects
- Adjust purchase details and totals based on returned quantity and affected items.
- If purchase is paid or partial at approval time:
  - Delete all purchase payments for that purchase.
  - Set paid_amount to 0, due_amount to total_amount, and payment_status to Unpaid.

CREDIT Approval Effects
- Require approval dialog to capture notes and attachments (jpg/png/pdf).
- Create a purchase payment for the selected purchase using the settlement nominal amount.
- Attach uploaded files to the created payment record.
- Create a linkage between the payment and supplier credit usage if applicable.

PRODUCT_REPAIR Receive Rules
- For serial items, quantity is locked to 1.
- Show old serial in the receive UI.
- Require entry of replacement serial number; allow same or different serial.
- Old serial is marked permanently returned and excluded from serial search.
- New serial is created as a new record for the product.

BROKEN_STOCK Receive Rules
- Received quantity is read-only (fixed).
- Only location selection is editable.

6. Non-Functional Requirements
- Auditability: approval actions must be traceable with notes and attachments for CREDIT.
- Data integrity: serial lifecycle changes must prevent returned serials from reappearing in selection/search.
- Compatibility: legacy data must display without migration requirements.
- Performance: settlement selection and warnings must remain responsive for typical line counts.

7. Assumptions
- The system uses per-item settlement lines and approval workflows.
- Serial numbers have a status field and are validated via existing serial search components.
- Media attachments are handled via the existing dropzone and media library pipeline.

8. Constraints
- No historical data backfill or migration is required; fallbacks should preserve legacy display.
- Warning-only quantity mismatch should not block submissions.
- Payment deletion for MODIFY_PURCHASE is explicit and irreversible within this flow.

9. Open Questions
- Should deleted purchase payments be soft-deleted or fully removed for audit compliance?
- Where should old-to-new serial mappings be persisted if explicit mapping is required later?
- Should CREDIT approvals enforce target purchase due_amount >= nominal when the purchase is already paid?

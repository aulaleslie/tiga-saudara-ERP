# Purchase Return Execution Plan

## 1. Recommended Order
1) Ticket 2: Update purchase return header (supplier required, remove header location).
2) Ticket 3: Implement multi-line return items with per-line location.
3) Ticket 4: Location search dropdown filtered by positive stock across tenants.
4) Ticket 5: Serial lookup to auto-select and lock location.
5) Ticket 6: Enforce serial uniqueness and consistency per return.
6) Ticket 7: Create pending return document without inventory mutation.
7) Ticket 8: Re-validate stock at approval (hook).
8) Ticket 1: Gate purchase return create by permission.
9) Ticket 9: Hide purchase price on purchase return create.
10) Ticket 10: Serial lookup to auto-select and lock location and purchase order.

Rationale: Build the core return data model and UI first, then add serial logic and validations, then lifecycle controls. Permissions and purchase return price visibility can proceed in parallel but are placed after core flow to minimize rework.

## 2. Parallelizable Tasks
- Ticket 1 can run in parallel with Tickets 2–7 (auth layer is independent).
- Ticket 9 can run in parallel with all return tickets (UI-only change to return create).
- Ticket 4 can start once product/line-item scaffolding is in place (Ticket 3), and can overlap with Ticket 5.
- Ticket 6 can overlap with Ticket 5 once serial input exists.

## 3. Milestones
Milestone A: Return data model and UI skeleton ready
- Tickets 2 and 3 complete.
- Result: Supplier required, header location removed, multi-line items with per-line location captured.

Milestone B: Location discovery and serial intelligence
- Tickets 4, 5, and 6 complete.
- Result: Positive-stock location filtering, serial lookup, and serial validation are enforced.

Milestone C: Lifecycle and integrity controls
- Tickets 7 and 8 complete.
- Result: Pending document creation without mutation; approval-time stock revalidation.

Milestone D: Access + purchase return price privacy
- Tickets 1 and 9 complete.
- Result: Access control enforced; purchase return create hides price.

## 4. Risks per Phase
Phase A (Milestone A)
- Risk: Legacy header location removal might break existing reads or reports.
- Mitigation: Deprecate field without destructive removal; add compatibility mapping if needed.

Phase B (Milestone B)
- Risk: Positive-stock query performance at scale.
- Mitigation: Use indexed stock snapshots or cached aggregates.
- Risk: Serial registry latency or mismatch errors.
- Mitigation: Add retries/timeouts and explicit error handling.

Phase C (Milestone C)
- Risk: Approval-time revalidation could block documents unexpectedly.
- Mitigation: Clear error messaging and recheck option before approval.
- Risk: “No mutation at create” could allow oversubscription before approval.
- Mitigation: Revalidation on approval; consider optional reservation in a future phase.
- Risk: Current dispatch flow mutates global `Product::product_quantity` (not per-location).
- Mitigation: Keep approval validation aligned with existing dispatch behavior until dispatch is redesigned.

Phase D (Milestone D)
- Risk: Permission mismatches between UI and API.
- Mitigation: Always enforce server-side permissions and align UI gates.
- Risk: Price hidden on purchase return create but exposed through cached UI state.
- Mitigation: Clear or sanitize cached payloads on create load.

## 5. Testing Strategy
- Unit tests:
  - Validation rules for supplier, line items, duplicate product/location, serial uniqueness.
  - Serial registry lookup outcomes (success, mismatch, not found).
  - Header location ignored on create.
- Integration tests:
  - Create return end-to-end with multiple lines and locations.
  - Location search returns only positive-stock locations and correct labels.
  - Serial entry auto-locks location and blocks invalid serials.
  - Pending status creation does not mutate inventory.
  - Approval revalidation blocks when stock is insufficient.
- API tests:
  - Permission gating returns 403.
  - Purchase return create API ignores price fields on create responses.
- UI tests:
  - Line-level location selection works across multiple lines.
  - Serial lines disable location input when auto-filled.
  - Price fields are hidden on purchase return create.

# Purchase Return Requirements

## 1. Overview
- This document defines the requirements for the redesigned purchase return creation flow and the removal of purchase price display on purchase return create.
- Scope focuses on document creation only; inventory mutation happens after approval.

## 2. Goals & Non-goals
### Goals
- Move location selection to the line item and filter to positive stock across tenants.
- Enforce serial-based location assignment from the global serial registry.
- Allow duplicate product lines when locations differ.
- Remove header-level location from purchase return.
- Hide purchase price on purchase return create.
- Create return documents without inventory mutation until approval.

### Non-goals
- Return approval UX/workflow design (beyond creating a pending document).
- Dispatch/settlement/receive flow changes.
- Return edit/history features.
- Reporting or analytics changes.
- Inventory valuation logic changes.
- Handling serial location changes between creation and approval (defined in next iteration).

## 3. Personas
- Return Creator: creates purchase return documents; needs accurate per-line location and should not see purchase price on return create.
- Inventory Controller: ensures returns map to correct tenant location and serials.

## 4. User Journeys
- Create return with non-serial product: select supplier, add product rows, choose tenant locations with positive stock, submit, document created in pending approval.
- Create return with serial-tracked product: enter serial, system finds location via global registry, locks location, submit; if no match, submission blocked.
- Create return with duplicate product lines: add same product multiple times with different locations; system accepts duplicates when locations differ.
- Create purchase return: user creates purchase return without any purchase price visible in the UI.

## 5. Functional Requirements
- Access control: only users with return creation permission can access purchase return create.
- Return header:
  - Supplier required; one supplier per return.
  - No header-level location stored.
- Return lines:
  - Multiple product lines per return.
  - Each line has product, quantity, and location.
  - Location is searchable and formatted `Tenant Name - Location Name`.
  - Location list shows only locations with positive stock for the selected product across tenants.
  - Duplicate product lines are allowed when location differs.
- Serial handling:
  - Serial-tracked products require serial input.
  - Serial lookup uses the global serial registry.
  - If serial resolves to a location, location is auto-set and read-only.
  - If no location match, submission is blocked with a clear error.
  - Serial values must be unique per return and validated for consistency.
- Document lifecycle:
  - Submission creates a return document in a pending approval state.
  - No inventory mutation or reservation occurs at creation time.
  - Approval re-validates stock against actual availability; reservation/mutation timing is handled in the next iteration (current flow mutates on dispatch).
- Purchase return create:
  - Purchase price is not displayed in the create UI.

## 6. Non-Functional Requirements
- Performance: location lookup and serial resolution respond quickly under expected load.
- Data integrity: prevent returns with invalid serials or zero-stock locations.
- Security: enforce tenant-aware visibility and permission checks.
- Auditability: actions are logged for return creation and validation failures.
- UX: location search is responsive and clear; serial errors are explicit.

## 7. Assumptions
- Global serial registry exists and reliably maps serials to tenant locations.
- Positive stock data is available per product per location.
- Location visibility is global for all return creators.
- Approval flow exists and can accept pending documents.

## 8. Constraints
- No inventory mutation or reservation at create stage.
- Location must be line-level only; header-level location removed.
- Serial must have a system-resolved location; manual override not allowed.
- Locations shown must have positive stock only.
- Migration integrity must be preserved during schema changes.
- Purchase return create must not display purchase price.
- No legacy compatibility layer is required for header-level location removal.

## 9. Open Questions
- None at this time.

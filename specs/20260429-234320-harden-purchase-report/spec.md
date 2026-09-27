# Feature Specification: Harden Purchase Report Validity

**Feature Branch**: `20260429-234320-harden-purchase-report`  
**Created**: 2026-04-29  
**Status**: Draft  
**Input**: User description: "look at this http://localhost:8000/reports/purchase-report I want to harden the implementation to provide valid report to the user"

## Clarifications

### Session 2026-04-29

- Q: Should export be allowed before a report is generated on screen? → A: Export requires a successful `Tampilkan Laporan` run and must use that exact validated result set snapshot.
- Q: Should report hardening include legacy unused purchase fields in validation/filter rules? → A: Use active lifecycle fields only; ignore legacy unused fields for filtering/validation while still allowing historical records to appear.
- Q: Which purchase statuses should appear by default in the report? → A: Include all purchase statuses by default.
- Q: How should payment completion be determined when payments can be cancelled or deleted? → A: Determine completion from active payment transactions only; cancelled/deleted/invalidated payments are excluded.
- Q: Which report dropdowns should be upgraded for scale-friendly search UX? → A: Upgrade Supplier and Tag dropdowns to searchable server-side typeahead; keep other dropdowns as standard selects.
- Q: What trigger behavior should searchable dropdowns use to balance responsiveness and server load? → A: Use server-side typeahead with minimum 2 characters and 300ms debounce before querying.

### Session 2026-04-30

- Q: Should Supplier and Tag filter controls support selecting multiple values (multi-select with pills), or remain single-select? → A: Multi-select: users can add multiple suppliers/tags as dismissible pills, query uses `whereIn`.
- Q: How should Pajak, Status, and Status Pembayaran dropdowns be styled for consistency? → A: Use `form-control` class only (standard CoreUI styled select), consistent with existing ERP conventions.
- Q: When a suggestion is clicked in the Supplier/Tag typeahead, what should happen to the dropdown and search input? → A: Close dropdown immediately, clear search input, add pill. User types again to add more.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Generate Valid On-Screen Purchase Report (Priority: P1)

As a purchasing/admin user, I want the purchase report to return only records that match the selected filters and allowed data scope, so I can trust the report for operational decisions.

**Why this priority**: The on-screen report is the primary source used before export and directly impacts daily purchasing decisions.

**Independent Test**: Can be fully tested by applying combinations of date, supplier, tax, status, and payment status filters, then validating that every displayed row matches the chosen criteria and access scope.

**Acceptance Scenarios**:

1. **Given** a user opens the purchase report page and applies valid filter values, **When** the report is displayed, **Then** all shown rows match the selected filters.
2. **Given** no data matches the selected filters, **When** the user runs the report, **Then** the page shows a clear empty-state message and no unrelated rows.
3. **Given** a non-global user, **When** the report is displayed, **Then** only records from the user’s permitted setting/cabang are shown.
4. **Given** a large supplier or tag dataset, **When** the user searches Supplier or Tag filters, **Then** matching options are loaded by server-side search without preloading the full dataset.

---

### User Story 2 - Export Data That Matches On-Screen Results (Priority: P2)

As a user, I want Excel/CSV/PDF exports to contain the same filtered dataset shown on screen, so offline sharing and audits remain accurate.

**Why this priority**: Mismatch between on-screen and exported data creates audit risk and reduces trust in the reporting feature.

**Independent Test**: Can be tested by running a filtered report, exporting each format, and confirming row counts and key transaction identifiers match the on-screen result set for the same filters.

**Acceptance Scenarios**:

1. **Given** a filtered report is displayed, **When** the user exports to Excel/CSV/PDF, **Then** each export contains the same filtered records as the on-screen report.
2. **Given** the report uses a defined date range, **When** the export is generated, **Then** the export header/period information reflects the same date range.

---

### User Story 3 - Prevent Invalid Report Inputs (Priority: P3)

As a user, I want invalid or contradictory filter inputs to be blocked with clear feedback, so I can correct input mistakes before running a report.

**Why this priority**: Input validation prevents silent report errors and reduces support incidents caused by accidental misuse.

**Independent Test**: Can be tested by entering invalid filter states (for example end date before start date) and confirming the report is not generated until input is corrected.

**Acceptance Scenarios**:

1. **Given** the user sets an end date earlier than start date, **When** they request the report or export, **Then** the system rejects the request and shows a clear validation message.
2. **Given** the user provides a filter value outside allowed options, **When** they request the report or export, **Then** the system rejects the request and does not produce a report.

### Edge Cases

- User attempts export before running the report with explicit filters.
- User filter combination yields a very large result set.
- User loses access permission while the report page remains open.
- Supplier/tag/status filters reference values that are deleted or no longer available.
- Report includes records with missing optional reference fields (for example supplier alias).
- Payment records are cancelled, deleted, or invalidated after purchase header totals were last computed.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST validate all report filter inputs before running on-screen report queries.
- **FR-002**: The system MUST reject a date range where end date is earlier than start date and provide a user-readable correction message.
- **FR-003**: The system MUST enforce allowed filter option values for tax flag, status, and payment status.
- **FR-004**: The system MUST apply identical filter logic and access scope rules to on-screen results and all export formats. Exports MUST consume the **Validated Filter State** (snapshot) from the latest successful report run; if the underlying database count changes between the run and export, the system SHOULD notify the user or require a re-run to ensure parity.
- **FR-005**: The system MUST enforce user access scope when querying report data, including non-global and global modes.
- **FR-006**: The system MUST show a deterministic empty-state result when no records match filters.
- **FR-007**: The system MUST ensure each exported row represents one valid purchase transaction that satisfies the same filters shown to the user.
- **FR-008**: The system MUST include the selected report period in exported output metadata.
- **FR-009**: The system MUST prevent export when no successful validated report run exists for the current filter state.
- **FR-010**: The system MUST provide clear user-facing error messaging for invalid filter submissions without exposing internal system details.
- **FR-011**: The system MUST determine report validity using active purchase lifecycle fields only (create/edit baseline data, approval status, receiving status, and payment completion status).
- **FR-012**: The system MUST NOT use legacy unused purchase fields as filter or validation determinants for report correctness.
- **FR-013**: The system MUST include all purchase statuses in default report results, while still supporting explicit status-based filtering.
- **FR-014**: The system MUST derive payment completion and related payment-status validity from active payment transactions only, excluding cancelled, deleted, or invalidated payments.
- **FR-015**: When header payment fields conflict with active payment transactions, the active payment transactions MUST be treated as the source of truth for report validity.
- **FR-016**: The system MUST provide searchable Supplier and Tag filter controls using server-side typeahead queries with multi-select capability. Each selected item MUST be displayed as a dismissible pill. The query MUST use `whereIn` for multiple selected values. The system MUST NOT preload the full supplier/tag option sets.
- **FR-017**: The searchable Supplier and Tag controls MUST trigger server-side lookup only after at least 2 typed characters and a 300ms debounce interval.
- **FR-018**: The Pajak, Status, and Status Pembayaran standard select controls MUST use the `form-control` CSS class to match the existing CoreUI theme conventions across the ERP.
- **FR-019**: When a user clicks a suggestion in the Supplier or Tag typeahead dropdown, the system MUST immediately close the dropdown, clear the search input, and display the selected item as a dismissible pill. The user types again to search and add additional selections.

### Key Entities *(include if feature involves data)*

- **Purchase Report Filter**: User-selected criteria used to constrain report output, including date range, supplier(s) (multi-select), tax flag, tag(s) (multi-select), status, payment status, and scope mode.
- **Purchase Report Result Row**: A single purchase transaction summary returned by the report and used in screen and export outputs.
- **Purchase Lifecycle Signal**: Canonical state indicators that describe purchase progression from creation/edit, approval, receiving, and payment completion.
- **Report Scope Context**: Access boundary determining whether the user sees only their permitted setting/cabang data or global cross-setting data.
- **Export Artifact**: A generated report file (Excel, CSV, PDF) that must mirror the filtered dataset and period context.

## Data Authority Policy

To satisfy FR-011 and FR-012, the following fields are defined as authoritative:
- **Active Lifecycle Fields**: `status` (from `purchases`), `is_tax_included`, `supplier_id` (filterable as multi-select array), and `date`.
- **Payment Source of Truth**: Sum of `amount` from the `purchase_payments` table where `status` is active (ignoring any legacy `payment_status` or `paid_amount` columns on the `purchases` table itself).
- **Legacy Fields (Ignore for Filtering)**: Any columns prefixed with `old_`, `legacy_`, or deprecated custom fields identified in `research.md`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of blocked invalid input attempts return a clear validation message and no report output.
- **SC-002**: In UAT sampling of at least 30 filter combinations, exported row counts match on-screen row counts for the same filters in 100% of cases.
- **SC-003**: Users can run a valid purchase report and see results in one attempt for at least 95% of test cases.
- **SC-004**: Support complaints about purchase report mismatch/invalidity decrease by at least 50% within one reporting cycle after release.
- **SC-005**: In UAT with datasets containing at least 1,000 suppliers/tags, searchable Supplier/Tag controls return relevant options without loading the full option list and remain usable without perceptible UI freezing.

## Assumptions

- The existing purchase transaction source data is already considered authoritative.
- User permissions for report access (standard and global) are already defined and remain unchanged.
- Existing report filters (date, supplier, tax, tag, status, payment status) remain in scope; no new business dimensions are added in this change.
- Export formats remain Excel, CSV, and PDF with current business usage.
- Historical purchase rows may contain legacy fields, but those fields are treated as non-authoritative for report validation and filtering decisions.

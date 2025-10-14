# Plan: Sales Return Module Enhancements

## Context
The sales return workflow needs a new entry experience that lets clerks search for eligible sales by reference number, restricts returns to dispatched quantities, and introduces an approval lifecycle. The existing documentation only covers troubleshooting reference lookups and does not capture the end-to-end behaviour we intend to build next.

## Objectives
1. Extend or refactor the existing purchase return module so the sales return experience reuses shared CRUD patterns instead of reinventing workflows.
2. Provide an autocomplete Livewire experience that lets users search by sales reference and only shows partially dispatched or dispatched sales.
3. Enforce quantity/serial validation so return requests cannot exceed the quantities or serialised units that were dispatched.
4. Implement an approval pipeline that progresses from **Pending** → **Approved/Rejected** → **Received**.
5. Ensure bundled sales products are returned accurately, preserving bundle composition and constraints.

## Workstreams & Tasks

### 1. Existing module and schema discovery
- Audit the current purchase return module to catalogue controllers, Livewire components, validation rules, and database interactions we can extend for sales returns.
- Map sales-return-specific tables and relationships using the latest `schema.sql` (skip historical migrations) to understand how dispatched quantities, serial numbers, and bundles are persisted.
- Smoke-test existing purchase return CRUD flows (create, edit, approve, receive) to document regressions we must address during the sales return enhancement.
- Document CRUD gaps so enhancements stay aligned with the established module architecture and coding conventions.

### 2. Autocomplete sales reference search
- Build a dedicated Livewire component for reference lookup (new component, not reused from existing troubleshooting plan).
- Query only sales with status `partially_dispatched` or `dispatched` and expose dispatched quantities/serials in the search payload.
- Surface clear UX for no matches and prevent selection of ineligible references.

### 3. Event-driven Livewire interactions & return line validation
- When the selected sale is partially dispatched, cap the return quantity at the dispatched amount for each line.
- For serialised products, ensure the UI forces selection of the dispatched serial numbers and limits the count to the serials available.
- Implement a Livewire serial number loader that filters serials where the dispatched sales ID equals the sales ID currently being returned.
- Restrict quantity capture during receiving to quantity-only input (no serial entry) while Livewire handles serial selection earlier in the flow.
- Add server-side guards that mirror the UI enforcement to prevent crafted requests from bypassing limits.

### 4. Approval flow lifecycle
- Model the status progression `pending → approved/rejected → received` with timestamps and actor attribution.
- Provide Livewire actions or back-office screens for approvers to transition requests and capture rejection reasons.
- Block inventory adjustments until the request reaches **Received**, ensuring approved returns can still be cancelled before stock movement.

### 5. Bundle-aware processing & exchange parity
- Expand return line loading to decompose bundles into their child items while respecting original quantities and serialisation rules.
- Ensure bundle returns propagate inventory and financial updates to each child SKU.
- Add scenarios/tests covering: single bundle item, bundle mixed with loose items, and serialised child items inside bundles.
- For product-exchange returns, enforce that exchanges ship the identical product (including bundle composition) and consume/replace the exact serials previously dispatched.

### 6. Return settlement options
- Design the workflow to support two return types:
  - **Return with money**: triggers refund/credit-note logic when the approval reaches **Received**.
  - **Return with product exchange**: issues equivalent products while ensuring serial numbers are released from the sold dispatch and new serials are dispatched from available stock (serial capture can be omitted here because it reuses the dispatched set).
- Outline accounting and inventory postings for each option, aligning with existing purchase return behaviours where possible.

## Deliverables
- Revised purchase/sales return module components that share CRUD patterns and honour existing validations.
- New Livewire autocomplete component integrated into the sales return creation flow.
- Validation layer (UI + backend) that constrains quantities and serial numbers to dispatched values, including the serial number loader.
- Approval lifecycle implementation with UI affordances and audit trail.
- Bundle-aware return handling backed by automated tests and QA checklist.
- Documented handling for monetary refunds and like-for-like product exchanges.

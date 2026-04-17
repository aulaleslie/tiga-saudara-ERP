## ADDED Requirements

### Requirement: Independent Sales Dispatch Menu Visibility
The system SHALL provide a dedicated "Pengiriman Barang" (Dispatch) menu item under the "Penjualan" sidebar group. This menu MUST be visible to users who have the `sales.dispatch` permission, regardless of whether they have `sales.access`.

#### Scenario: Dispatcher accesses sidebar
- **WHEN** a user with only `sales.dispatch` permission logs into the system
- **THEN** the "Penjualan" sidebar group is visible
- **AND** the "Pengiriman Barang" sub-menu item is visible
- **AND** the "Daftar Penjualan" sub-menu item is hidden (due to lacking `sales.access`)

### Requirement: Dedicated Sales Dispatch List Route
The system SHALL provide a dedicated route and view for listing sales that require dispatching. The route MUST be accessible exclusively to users with the `sales.dispatch` permission.

#### Scenario: Dispatcher views the dispatch list
- **WHEN** a user with `sales.dispatch` permission navigates to the "Pengiriman Barang" page
- **THEN** a table displaying sales is presented
- **AND** the table ONLY lists sales with status `APPROVED` or `DISPATCHED_PARTIALLY`
- **AND** the user can click the "Pengeluaran Barang" action for any record in this list

### Requirement: Unified Penjualan Guard Check
The "Penjualan" sidebar group MUST check for any of `sales.access`, `saleReturns.access`, or `sales.dispatch` to determine its visibility.

#### Scenario: Expanding parent menu visibility
- **WHEN** rendering the sidebar navigation
- **THEN** the "Penjualan" dropdown toggle is shown if the user has `sales.dispatch` permission

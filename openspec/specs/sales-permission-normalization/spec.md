## Purpose

Establish a canonical permission catalog for sales and sales-return lifecycle actions with consistent verb taxonomy and authorization enforcement.

## ADDED Requirements

### Requirement: Document missing Sales permissions
The system SHALL document natively used `sales.archive`, `saleReturns.archive`, and `sales.approved.edit` permissions in the central permissions configuration.

#### Scenario: Roles UI renders previously un-document permissions
- **WHEN** the permissions configuration is parsed
- **THEN** it yields these formerly missing keys, allowing administrators to allocate them

### Requirement: Proximity grouping of Sales capabilities
The permissions configuration SHALL group "Penjualan", "Pengiriman Penjualan", "Retur Penjualan", "Penyelesaian Retur Penjualan", "Pembayaran Penjualan", and "Pembayaran Retur Penjualan" closely together.

#### Scenario: Contiguous UI rendering
- **WHEN** the Role Management UI renders permission cards
- **THEN** it lays out the Sales-related module permission groups sequentially, removing visual fragmentation

### Requirement: Sales post-dispatch monetary edit permission is canonical and assignable
The system SHALL define `sales.dispatched.monetary.edit` in the canonical sales permission catalog while retaining `sales.approved.edit`. Role-management UI and permission synchronization SHALL expose both keys as distinct authorities.

#### Scenario: Roles can receive post-dispatch monetary edit authority
- **WHEN** an administrator opens role management after permissions are synchronized
- **THEN** the administrator SHALL be able to assign `sales.dispatched.monetary.edit`
- **AND** the existing `sales.approved.edit` permission SHALL remain available

### Requirement: Sales lifecycle edit checks use defined permissions
The system SHALL use `sales.approved.edit` for full edits of approved undispatched Sales and `sales.dispatched.monetary.edit` for monetary-only edits of dispatched Sales, in addition to ordinary `sales.edit` authority.

#### Scenario: Sales authorization layers agree
- **WHEN** a Sale edit action is evaluated in the UI, Livewire component, controller, or persistence service
- **THEN** every layer SHALL enforce the permission applicable to the persisted Sale lifecycle status

## REMOVED Requirements

### Requirement: Expose saleReturnPayments.show
**Reason**: Static analysis confirms the permission `saleReturnPayments.show` is fully unused and orphaned.
**Migration**: Removed statically from `app/Config/Permissions.php`.

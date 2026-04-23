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

## REMOVED Requirements

### Requirement: Expose saleReturnPayments.show
**Reason**: Static analysis confirms the permission `saleReturnPayments.show` is fully unused and orphaned.
**Migration**: Removed statically from `app/Config/Permissions.php`.

# pos-cart-management Specification

## Purpose
This specification defines the requirements for POS cart management, including item additions, quantity updates, and inventory validation.

## Requirements
### Requirement: Indonesian Cart Messages
Exception messages in POS cart operations must be in Bahasa Indonesia.

#### Scenario: Invalid Quantity
- **WHEN** Adding item with quantity less than 1
- **THEN** The system returns 'Kuantitas harus minimal 1.' instead of 'Quantity must be at least 1.'

#### Scenario: Stock Unavailable
- **WHEN** Requested quantity exceeds available stock
- **THEN** The system returns 'Kuantitas yang diminta melebihi stok tersedia untuk lokasi penjualan yang dikonfigurasi.'

### Requirement: Unified Transaction Record
The POS transaction finalization must persist exactly one `PosTransaction` record per checkout, regardless of how many settings provide stock for the line items.

#### Scenario: Cross-Tenant Sale Unification
- **WHEN** A checkout contains items from Setting A and Setting B.
- **THEN** Only one `PosTransaction` record is created, owned by the active session's Setting.
- **THEN** The transaction contains all lines from both settings.

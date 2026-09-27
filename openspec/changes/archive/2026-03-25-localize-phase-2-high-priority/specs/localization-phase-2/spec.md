## ADDED Requirements

### Requirement: POS Controller Authorization Messages Display in Indonesian
The system SHALL display authorization error messages in Indonesian when users attempt unauthorized operations in POS controllers.

#### Scenario: User accesses POS without active session
- **WHEN** user tries to access POS sell page without active session context
- **THEN** system displays "Konteks sesi POS yang aktif diperlukan." (Active POS session context is required.)

#### Scenario: User accesses POS without authentication
- **WHEN** user tries to access POS endpoints without authentication
- **THEN** system displays "Otentikasi diperlukan." (Authentication is required.)

#### Scenario: User attempts unauthorized receipt access
- **WHEN** user tries to view a receipt they don't have access to
- **THEN** system displays "Akses ke struk tidak sah." (Unauthorized access to receipt.)

#### Scenario: User accesses session endpoint without authentication
- **WHEN** user tries to access session controller endpoints without authentication
- **THEN** system displays "Otentikasi diperlukan." (Authentication is required.)

#### Scenario: POS session not found for current setting
- **WHEN** user requests session that doesn't exist for their setting
- **THEN** system displays "Sesi POS tidak ditemukan untuk pengaturan saat ini." (POS session not found for current setting.)

### Requirement: POS Validation Request Messages Display in Indonesian
The system SHALL display validation failure messages in Indonesian when session close requests fail validation.

#### Scenario: Invalid session close request
- **WHEN** user submits StorePosSessionCloseRequest with invalid data
- **THEN** system returns response with message "Validasi gagal" (Validation failed)

### Requirement: Livewire Component Flash Messages Display in Indonesian
The system SHALL display flash messages in Indonesian when users interact with product tables (barcode, transfer, adjustment).

#### Scenario: Barcode generation exceeds maximum quantity
- **WHEN** user attempts to generate barcode for more than 100 items
- **THEN** system displays "Kuantitas maksimal adalah 100 per pembuatan barcode!" (Max quantity is 100 per barcode generation!)

#### Scenario: Invalid product code type for barcode
- **WHEN** user attempts to generate barcode with unsupported product code type
- **THEN** system displays "Tidak dapat membuat Barcode dengan jenis Kode Produk ini" (Can not generate Barcode with this type of Product Code)

#### Scenario: Duplicate product in transfer table
- **WHEN** user adds a product that already exists in transfer product list
- **THEN** system displays "Sudah ada dalam daftar produk!" (Already exists in the product list!)

#### Scenario: Duplicate product in adjustment table
- **WHEN** user adds a product that already exists in adjustment product list
- **THEN** system displays "Sudah ada dalam daftar produk!" (Already exists in the product list!)

### Requirement: All Phase 2 Localization Messages Are User-Facing
The system SHALL ensure all localized messages in Phase 2 are visible to end users during normal operations.

#### Scenario: Authorization message in HTTP response
- **WHEN** unauthorized request is made
- **THEN** HTTP response includes 403/404 status with Indonesian error message body

#### Scenario: Validation message in JSON response
- **WHEN** validation fails on request submission
- **THEN** JSON response includes "message" field with Indonesian validation message

#### Scenario: Flash message in Livewire component
- **WHEN** Livewire component triggers validation
- **THEN** session flash bag contains Indonesian message that renders in browser

## ADDED Requirements

### Requirement: POS Transactions Toggle on Settings Page
The settings page (`/settings`) SHALL display a checkbox labelled "Aktifkan Transaksi POS" (`pos_transactions_enabled`) in the POS configuration row, next to the existing "Aktifkan POS" toggle.

#### Scenario: Toggle is visible when POS is enabled
- **WHEN** user opens the settings page and "Aktifkan POS" is checked
- **THEN** the "Aktifkan Transaksi POS" checkbox MUST be visible

#### Scenario: Toggle is hidden when POS is disabled
- **WHEN** user opens the settings page and "Aktifkan POS" is unchecked
- **THEN** the "Aktifkan Transaksi POS" checkbox MUST be hidden

#### Scenario: Saving the toggle persists the value
- **WHEN** user checks "Aktifkan Transaksi POS" and submits the settings form
- **THEN** the `pos_transactions_enabled` column on the `settings` record MUST be set to `true`

#### Scenario: Unchecking POS clears transactions toggle
- **WHEN** user unchecks "Aktifkan POS" and submits the settings form
- **THEN** `pos_transactions_enabled` MUST be set to `false` regardless of its checkbox state

### Requirement: Consistent Page Titles
All settings-related pages SHALL have consistent Indonesian-language page titles, card headers, and breadcrumb labels.

#### Scenario: Settings page titles
- **WHEN** user navigates to `/settings`
- **THEN** the browser title MUST be "Pengaturan Bisnis" and breadcrumb active item MUST be "Pengaturan"

#### Scenario: Business create page titles
- **WHEN** user navigates to `/businesses/create`
- **THEN** the browser title MUST be "Tambah Bisnis" and breadcrumb active item MUST be "Tambah Bisnis"

#### Scenario: Business edit page titles
- **WHEN** user navigates to `/businesses/{id}/edit`
- **THEN** the browser title MUST be "Ubah Bisnis" and breadcrumb active item MUST be "Ubah Bisnis"

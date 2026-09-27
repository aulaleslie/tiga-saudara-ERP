## ADDED Requirements

### Requirement: Target Tenant Resolution
The system MUST import expense CSV rows under the `CV Tiga Nusa Computer` setting, independent of the uploader's active session setting.

#### Scenario: Unique target setting exists
- **WHEN** an authorized user uploads an expense CSV and exactly one setting matches `CV Tiga Nusa Computer`
- **THEN** every processed expense, created supplier, and created category MUST use that setting ID

#### Scenario: Target setting is missing or ambiguous
- **WHEN** an authorized user uploads an expense CSV and the target setting cannot be resolved uniquely
- **THEN** the system MUST fail the batch before creating any expenses, suppliers, or categories

### Requirement: CSV Row Parsing
The system MUST parse expense CSV files with the columns `Tanggal`, `Transaksi`, `Nomor`, `Kategori`, `Deskripsi`, `Supplier`, `Jumlah`, `Tax`, `Status`, and `Sisa Tagihan`.

#### Scenario: Row contains stray tab characters
- **WHEN** a CSV field contains leading or trailing tab characters
- **THEN** the system MUST trim those characters before validation and mapping

#### Scenario: Required field is missing
- **WHEN** a row is missing `Tanggal`, `Nomor`, `Kategori`, `Supplier`, or `Jumlah`
- **THEN** the system MUST mark the row invalid with a row-level error and continue processing other rows

### Requirement: Accepted Expense Rows
The system MUST create one approved Expense and one Expense Detail for each valid paid expense row.

#### Scenario: Valid paid row is processed
- **WHEN** a row has `Transaksi` equal to `Expense`, `Status` equal to `Paid`, `Sisa Tagihan` equal to zero, and `Jumlah` greater than zero
- **THEN** the system MUST create an Expense with status `APPROVED`, the parsed date, the resolved category, the resolved supplier, the row amount, and one detail row for the same amount

#### Scenario: Row is not a paid expense
- **WHEN** a row has `Transaksi` other than `Expense`, `Status` other than `Paid`, or `Sisa Tagihan` greater than zero
- **THEN** the system MUST mark the row invalid and MUST NOT create an Expense for that row

### Requirement: Supplier Resolution And Creation
The system MUST resolve CSV suppliers within the target setting and create missing suppliers during import.

#### Scenario: Supplier already exists
- **WHEN** a row references a supplier name that already exists for the target setting
- **THEN** the imported Expense MUST reference the existing supplier

#### Scenario: Supplier is missing
- **WHEN** a row references a supplier name that does not exist for the target setting
- **THEN** the system MUST create a supplier under the target setting using deterministic import placeholder values for required contact and address fields

### Requirement: Category Resolution And Creation
The system MUST resolve CSV categories within the target setting and create missing categories during import.

#### Scenario: Category already exists
- **WHEN** a row references a category name that already exists for the target setting
- **THEN** the imported Expense MUST reference the existing category

#### Scenario: Category is missing
- **WHEN** a row references a category name that does not exist for the target setting
- **THEN** the system MUST create an expense category under the target setting and use it for the imported Expense

### Requirement: Duplicate Source Number Protection
The system MUST prevent duplicate imported expenses for the same target setting and CSV `Nomor`.

#### Scenario: Source number has not been imported
- **WHEN** a valid row has a `Nomor` value not previously imported for the target setting
- **THEN** the system MUST persist that value as the Expense source import identity

#### Scenario: Source number was already imported
- **WHEN** a row has a `Nomor` value already linked to an Expense for the target setting
- **THEN** the system MUST skip or reject the row with a row-level duplicate message and MUST NOT create another Expense

### Requirement: Import Batch Visibility
The system MUST provide batch and row-level visibility for expense CSV imports.

#### Scenario: Batch is processed
- **WHEN** an import batch is staged and processed
- **THEN** the system MUST track total rows, processed rows, successful rows, skipped rows or duplicates, invalid rows, and failed batch status when applicable

#### Scenario: Row processing fails
- **WHEN** one row fails validation or domain creation
- **THEN** the system MUST record the row error and continue processing remaining rows when the failure is row-scoped

## ADDED Requirements

### Requirement: Expense CSV rows are imported as approved expenses
The system SHALL create one approved Expense and one Expense Detail for each valid paid expense CSV row under the configured target setting.

#### Scenario: Valid paid expense row is processed
- **WHEN** an imported row has `Transaksi` equal to `Expense`, `Status` equal to `Paid`, `Sisa Tagihan` equal to zero, `Jumlah` greater than zero, `Tax` equal to zero, and valid `Tanggal`, `Nomor`, and `Kategori`
- **THEN** the system MUST create an Expense with status `APPROVED`, the parsed expense date, the resolved category, the resolved supplier, the parsed amount, and the source `Nomor` stored as the imported expense number
- **AND** the system MUST create one Expense Detail for the same amount

#### Scenario: Row is not eligible for approved expense import
- **WHEN** an imported row has `Transaksi` other than `Expense`, `Status` other than `Paid`, nonzero `Sisa Tagihan`, non-positive `Jumlah`, nonzero `Tax`, or an invalid calendar date
- **THEN** the system MUST mark the row invalid with a row-level error
- **AND** the system MUST NOT create an Expense for that row

### Requirement: Expense CSV supplier fallback
The system SHALL resolve a supplier for imported expense rows by using the CSV `Supplier` value when present and falling back to the CSV `Kategori` value when `Supplier` is blank.

#### Scenario: Supplier value is present
- **WHEN** an imported expense row has a nonblank `Supplier`
- **THEN** the system MUST resolve or create the supplier by the trimmed `Supplier` value within the target setting
- **AND** the imported Expense MUST reference that supplier

#### Scenario: Supplier value is blank and category is present
- **WHEN** an imported expense row has a blank `Supplier` and a nonblank `Kategori`
- **THEN** the system MUST resolve or create the supplier by the trimmed `Kategori` value within the target setting
- **AND** the imported Expense MUST reference that supplier

#### Scenario: Supplier and category are both blank
- **WHEN** an imported expense row has blank `Supplier` and blank `Kategori`
- **THEN** the system MUST mark the row invalid with a missing category error
- **AND** the system MUST NOT create a supplier or Expense for that row

### Requirement: Expense CSV category resolution
The system SHALL resolve imported expense categories within the target setting and create missing categories during import.

#### Scenario: Category already exists
- **WHEN** an imported expense row references a category name that already exists for the target setting
- **THEN** the imported Expense MUST reference the existing category

#### Scenario: Category is missing
- **WHEN** an imported expense row references a category name that does not exist for the target setting
- **THEN** the system MUST create an expense category under the target setting
- **AND** the imported Expense MUST reference the created category

### Requirement: Expense CSV duplicate source number protection
The system SHALL prevent duplicate imported expenses for the same target setting and CSV `Nomor`.

#### Scenario: Source number has not been imported
- **WHEN** a valid imported expense row has a `Nomor` value not previously linked to an Expense for the target setting
- **THEN** the system MUST persist that value as the Expense imported source identity

#### Scenario: Source number was already imported
- **WHEN** an imported expense row has a `Nomor` value already linked to an Expense for the target setting
- **THEN** the system MUST skip or reject the row with a row-level duplicate message
- **AND** the system MUST NOT create another Expense for that source number

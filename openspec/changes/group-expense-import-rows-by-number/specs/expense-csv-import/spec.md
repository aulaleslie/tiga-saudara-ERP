## MODIFIED Requirements

### Requirement: Expense CSV rows are imported as approved expenses
The system SHALL create one approved Expense for each valid CSV `Nomor` group under the configured target setting and SHALL create one Expense Detail for each source row in that group.

#### Scenario: Single-row paid expense group is processed
- **WHEN** an imported `Nomor` group contains one row with `Transaksi` equal to `Expense`, `Status` equal to `Paid`, `Sisa Tagihan` equal to zero, `Jumlah` greater than zero, parseable `Tax`, and valid `Tanggal`, `Nomor`, and `Kategori`
- **THEN** the system MUST create one Expense with status `APPROVED`, the parsed expense date from the first row, the resolved category from the first row, the resolved supplier from the first row, the parsed row amount, and the source `Nomor` stored as the imported expense number
- **AND** the system MUST create one Expense Detail for the same amount
- **AND** any parseable `Tax` value from the row MUST NOT be persisted as expense tax

#### Scenario: Multi-row source number is processed as one expense with multiple details
- **WHEN** an imported `Nomor` group contains multiple valid paid expense rows with the same `Nomor`
- **THEN** the system MUST create one Expense for that `Nomor`
- **AND** the Expense amount MUST equal the sum of each grouped row's parsed `Jumlah`
- **AND** the system MUST create one Expense Detail for each grouped row
- **AND** each grouped import row MUST reference the created Expense

#### Scenario: First row supplies grouped expense header fields
- **WHEN** an imported `Nomor` group contains multiple valid paid expense rows
- **THEN** the system MUST resolve the Expense date, category, and supplier from the first row in source row order
- **AND** later rows with different `Kategori`, `Supplier`, or `Deskripsi` values MUST NOT change the Expense header category or supplier

#### Scenario: Parseable nonzero source tax is ignored
- **WHEN** an imported paid expense row has a parseable nonzero `Tax` value
- **THEN** the row MUST remain eligible for import
- **AND** the created Expense MUST have `is_tax_included` set to false
- **AND** the created Expense Detail for that row MUST have no tax assigned
- **AND** the `Tax` value MUST NOT be added to the Expense amount

#### Scenario: Row group is not eligible for approved expense import
- **WHEN** an imported `Nomor` group contains any row with `Transaksi` other than `Expense`, `Status` other than `Paid`, nonzero `Sisa Tagihan`, non-positive `Jumlah`, unparseable `Tax`, or an invalid calendar date
- **THEN** the system MUST mark the affected group rows invalid with row-level errors
- **AND** the system MUST NOT create an Expense for that group

### Requirement: Expense CSV supplier fallback
The system SHALL resolve a supplier for imported expense groups by using the first row's CSV `Supplier` value when present and falling back to the first row's CSV `Kategori` value when `Supplier` is blank.

#### Scenario: First row supplier value is present
- **WHEN** an imported expense group has a nonblank `Supplier` on its first row
- **THEN** the system MUST resolve or create the supplier by the trimmed first-row `Supplier` value within the target setting
- **AND** the imported Expense MUST reference that supplier

#### Scenario: First row supplier value is blank and category is present
- **WHEN** an imported expense group has a blank `Supplier` and a nonblank `Kategori` on its first row
- **THEN** the system MUST resolve or create the supplier by the trimmed first-row `Kategori` value within the target setting
- **AND** the imported Expense MUST reference that supplier

#### Scenario: First row supplier and category are both blank
- **WHEN** an imported expense group has blank `Supplier` and blank `Kategori` on its first row
- **THEN** the system MUST mark the group rows invalid with a missing category error
- **AND** the system MUST NOT create a supplier or Expense for that group

### Requirement: Expense CSV category resolution
The system SHALL resolve imported expense categories within the target setting from the first row of each CSV `Nomor` group and create missing categories during import.

#### Scenario: Category already exists
- **WHEN** an imported expense group first row references a category name that already exists for the target setting
- **THEN** the imported Expense MUST reference the existing category

#### Scenario: Category is missing
- **WHEN** an imported expense group first row references a category name that does not exist for the target setting
- **THEN** the system MUST create an expense category under the target setting
- **AND** the imported Expense MUST reference the created category

#### Scenario: Later row category differs
- **WHEN** a later row in an imported `Nomor` group has a `Kategori` value different from the first row
- **THEN** the imported Expense header MUST keep the first row's resolved category
- **AND** the later row MUST still create an Expense Detail when the group is otherwise valid

### Requirement: Expense CSV duplicate source number protection
The system SHALL prevent duplicate imported expenses for the same target setting and CSV `Nomor` across previous imports while allowing repeated `Nomor` rows within the same batch to form one source document group.

#### Scenario: Source number has not been imported
- **WHEN** a valid imported expense group has a `Nomor` value not previously linked to an Expense for the target setting
- **THEN** the system MUST persist that value as the Expense imported source identity

#### Scenario: Source number appears on multiple rows in the same batch
- **WHEN** multiple staged rows in the same import batch share the same `Nomor`
- **THEN** the system MUST process those rows as one source document group
- **AND** the system MUST NOT treat the second and later rows in that group as duplicate imports

#### Scenario: Source number was already imported before the batch
- **WHEN** an imported expense group has a `Nomor` value already linked to an Expense for the target setting before the group is processed
- **THEN** the system MUST skip every row in the group with a row-level duplicate message
- **AND** the system MUST NOT create another Expense for that source number

### Requirement: Expense CSV batch counts remain row based
The system SHALL count successful, skipped, errored, and processed import results by source rows even when several rows create one Expense document.

#### Scenario: Multi-row group succeeds
- **WHEN** a valid imported `Nomor` group contains two source rows and creates one Expense with two Expense Details
- **THEN** the batch success count MUST increase by two
- **AND** the batch processed rows count MUST include both source rows

#### Scenario: Duplicate multi-row group is skipped
- **WHEN** an imported `Nomor` group contains two source rows for a previously imported source number
- **THEN** the batch skipped count MUST increase by two
- **AND** both source rows MUST be marked skipped

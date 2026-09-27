## ADDED Requirements

### Requirement: Global payment history SHALL display the related sale due date
The Global Sales Payment history list SHALL display a `Tanggal Jatuh Tempo` column using the existing `due_date` of each payment's related Sale, in addition to the payment's `Tanggal` column. It SHALL display a neutral placeholder when the related Sale has no due date.

#### Scenario: Payment history shows payment and sale dates distinctly
- **WHEN** an authorized user opens global payment history for a sale payment whose Sale has a due date
- **THEN** the row displays the payment date under `Tanggal`
- **AND** displays the related Sale due date under `Tanggal Jatuh Tempo`

#### Scenario: Missing sale due date displays safely
- **WHEN** a global payment-history row is associated with a Sale whose due date is null
- **THEN** the `Tanggal Jatuh Tempo` cell displays `-`

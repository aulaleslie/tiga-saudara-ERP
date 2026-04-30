## MODIFIED Requirements

### Requirement: Edited row values reach the saved bundle

The bundle item editor SHALL persist the user's most recent typed values for each row's `quantity` and `informational_item_price` when the surrounding HTML form is submitted, without requiring the user to perform any extra action (such as clicking elsewhere or pressing Enter) before pressing the form's save button. The editor SHALL treat currency formatting as presentation-only and SHALL submit canonical numeric values for `informational_item_price`.

#### Scenario: Price typed and immediately saved
- **WHEN** the user types a new value into a row's "Harga Informasi Item" input and clicks the form's save button
- **THEN** the request the server receives for that row's `informational_item_price` MUST equal the value the user typed (not the value previously rendered)
- **AND** the submitted value MUST be numeric (without `Rp` prefix or thousands separators)

#### Scenario: Quantity typed and immediately saved
- **WHEN** the user types a new value into a row's "Jumlah" input and clicks the form's save button
- **THEN** the request the server receives for that row's `quantity` MUST equal the value the user typed

#### Scenario: Multiple rows edited before save
- **WHEN** the user edits the price and quantity on several rows in any order and then clicks save
- **THEN** every edited row's submitted values MUST match the user's last typed values for that row

#### Scenario: Focus and blur preserve canonical numeric value
- **WHEN** a row's informational price is shown as formatted currency after blur and the user submits without re-focusing the field
- **THEN** the submitted payload for that row MUST still be the canonical numeric value

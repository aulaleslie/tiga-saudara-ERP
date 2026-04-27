## ADDED Requirements

### Requirement: Edited row values reach the saved bundle

The bundle item editor SHALL persist the user's most recent typed values for each row's `quantity` and `informational_item_price` when the surrounding HTML form is submitted, without requiring the user to perform any extra action (such as clicking elsewhere or pressing Enter) before pressing the form's save button.

#### Scenario: Price typed and immediately saved
- **WHEN** the user types a new value into a row's "Harga Informasi Item" input and clicks the form's save button
- **THEN** the request the server receives for that row's `informational_item_price` MUST equal the value the user typed (not the value previously rendered)

#### Scenario: Quantity typed and immediately saved
- **WHEN** the user types a new value into a row's "Jumlah" input and clicks the form's save button
- **THEN** the request the server receives for that row's `quantity` MUST equal the value the user typed

#### Scenario: Multiple rows edited before save
- **WHEN** the user edits the price and quantity on several rows in any order and then clicks save
- **THEN** every edited row's submitted values MUST match the user's last typed values for that row

### Requirement: Row removal removes the targeted row

The bundle item editor SHALL remove exactly the row whose "Hapus" button was clicked, regardless of the row's position in the list, without altering or duplicating the remaining rows' selected products, quantities, or prices.

#### Scenario: Removing a non-last row preserves other rows
- **WHEN** three rows exist (A, B, C) and the user clicks "Hapus" on row B
- **THEN** the editor MUST display only rows A and C, each retaining its previously selected product, quantity, and informational price

#### Scenario: Removing the last row
- **WHEN** the user clicks "Hapus" on the bottom-most row
- **THEN** that row MUST be removed and no other row's data MUST change

#### Scenario: Removed row is not submitted
- **WHEN** the user removes a row and then clicks the form's save button
- **THEN** the request the server receives MUST NOT include any item entry for the removed row

### Requirement: Each row's product picker is bound to its row identity

The bundle item editor SHALL identify each row's nested product-search component by a stable per-row identity, so that adding or removing rows does not cause a surviving row to display another row's selected product.

#### Scenario: Surviving rows keep their own product after a removal
- **WHEN** rows A, B, C have products P_A, P_B, P_C selected and the user removes row B
- **THEN** row A MUST continue to display P_A and row C MUST continue to display P_C

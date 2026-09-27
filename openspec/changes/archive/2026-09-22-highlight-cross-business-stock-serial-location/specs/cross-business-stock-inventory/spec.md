# Spec Delta

## MODIFIED Requirements

### Requirement: System SHALL support a single search box combining product-identity search and exact barcode/serial lookup
The search input SHALL apply two independent match paths, combined with OR: (1) the existing multi-token, order-independent search across product name, product code, barcode, category name, and brand name; (2) an exact-match lookup against `products.barcode` and `product_serial_numbers.serial_number`. A serial number match SHALL resolve to its owning product's row in the table. When the complete search value exactly matches a serial that is currently eligible for an operational Good or Bad serial dialog, the on-screen table SHALL apply a soft yellow stabilo-style marker only to that serial's corresponding stock cell. The marker SHALL identify the matching business and condition in collapsed view, and the matching location and condition in expanded view. It SHALL NOT alter displayed information, automatically open the serial dialog, or appear in Excel exports.

#### Scenario: Multi-word product identity search
- **WHEN** a user searches "acer 8 core i3"
- **THEN** products whose name contains all of "acer", "8", "core", and "i3" as substrings, in any order, are returned
- **AND** no serial-location marker is shown

#### Scenario: Exact barcode match
- **WHEN** a user searches a value that exactly matches a product's `barcode`
- **THEN** that product's row is returned
- **AND** no serial-location marker is shown

#### Scenario: Partial barcode does not match
- **WHEN** a user searches a value that is a substring or prefix of a product's `barcode` but not the full value
- **THEN** that product is not returned via the barcode path, though it may still be returned via the product-identity path if the fragment matches name, code, category, or brand
- **AND** no serial-location marker is shown

#### Scenario: Exact serial number match resolves to the owning product
- **WHEN** a user searches a value that exactly matches a `product_serial_numbers.serial_number`
- **THEN** the table shows the row for the product that owns that serial number
- **AND** the serial dialog does not open automatically
- **AND** marker visibility follows the serial's operational status and the currently visible business and location columns

#### Scenario: Exact operational good serial match in collapsed view
- **WHEN** the complete search value exactly matches an operational Good serial in a currently visible business
- **AND** that business is collapsed
- **THEN** the table shows the row for the product that owns that serial
- **AND** only the matching business's Good subtotal cell receives the serial-location marker
- **AND** the serial dialog does not open automatically

#### Scenario: Exact operational bad serial match in collapsed view
- **WHEN** the complete search value exactly matches an operational Bad serial in a currently visible business
- **AND** that business is collapsed
- **THEN** only the matching business's Bad subtotal cell receives the serial-location marker

#### Scenario: Exact serial match in expanded view
- **WHEN** the complete search value exactly matches an operational Good or Bad serial in a currently visible location
- **AND** the containing business is expanded
- **THEN** only the matching location's corresponding Good or Bad cell receives the serial-location marker

#### Scenario: Exact non-operational serial match
- **WHEN** the complete search value exactly matches a serial that is `MISSING`, `SOLD`, `RETURNED`, `RETURN_IN_PROCESS`, dispatched, or in a return process
- **THEN** the table continues to show the owning product according to the existing exact serial search behavior
- **AND** no Good or Bad stock cell receives the serial-location marker

#### Scenario: Matching serial is outside the visible business scope
- **WHEN** the complete search value exactly matches a serial whose business is not among the report's currently selected and visible businesses
- **THEN** no stock cell receives the serial-location marker

#### Scenario: Search changes or is cleared
- **WHEN** a marked exact serial search is changed to a non-matching value or cleared
- **THEN** the serial-location marker is removed

#### Scenario: Excel export during marked search
- **WHEN** a user exports the report while an exact operational serial search is marked on screen
- **THEN** the Excel export contains the existing data and formatting without the serial-location marker

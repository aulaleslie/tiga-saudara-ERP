## MODIFIED Requirements

### Requirement: System SHALL provide a serial number lookup dialog for serialized products
For any product where `products.serial_number_required` is true, each non-zero Good/Bad cell SHALL show a button that opens a dialog listing serial numbers scoped to that exact business, location (or all of the business's locations when the business is collapsed), and condition (Good or Bad). Good dialogs SHALL use the canonical sellable scope. Bad dialogs SHALL use the canonical available-broken scope. `MISSING`, `SOLD`, `RETURNED`, `RETURN_IN_PROCESS`, dispatched, and return-in-process serials SHALL not appear in either operational dialog.

#### Scenario: Opening the dialog from a Good cell
- **WHEN** a user clicks the serial button on a Good cell for a serialized product
- **THEN** the dialog opens listing only active-compatible, not-broken, not-returning, undispatched serials for that product and business/location scope

#### Scenario: Opening the dialog from a Bad cell
- **WHEN** a user clicks the serial button on a Bad cell for a serialized product
- **THEN** the dialog opens listing only available-broken serials for that product and business/location scope

#### Scenario: Missing serial is excluded from both dialogs
- **WHEN** a good-condition serial retains the requested location but has `status=MISSING`
- **THEN** it appears in neither the Good nor Bad operational dialog

#### Scenario: Good and Bad dialog counts reconcile with stock condition
- **WHEN** a serialized location has five active-good serials, one active-broken serial, and three missing serials while ProductStock reports five good and one broken
- **THEN** the Good dialog contains five serials and the Bad dialog contains one serial

#### Scenario: Non-serialized product has no serial button
- **WHEN** a product has `serial_number_required = false`
- **THEN** no serial button is shown on any of its cells, regardless of quantity

#### Scenario: Zero-quantity cell has no serial button
- **WHEN** a serialized product's Good or Bad quantity for a given business/location is zero
- **THEN** no serial button is shown for that specific cell

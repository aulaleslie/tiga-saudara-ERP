## ADDED Requirements

### Requirement: Conversion price formatter SHALL initialize without external library dependencies
The conversion-price formatter script embedded in the UnitConfiguration component SHALL initialize and bind all focus/blur/sync behavior using only vanilla JavaScript APIs, without depending on jQuery or any other library being loaded before the inline script executes.

#### Scenario: Formatter initializes during body parse before jQuery loads
- **WHEN** the browser parses the UnitConfiguration component's inline `<script>` tag during `@yield('content')`, before `@include('includes.main-js')` loads jQuery
- **THEN** the formatter SHALL still create all event handlers, MutationObserver, and form-submit sync
- **AND** conversion-price fields SHALL be fully functional (focus/blur/format/sync) once they appear in the DOM

#### Scenario: Hidden-input sync dispatches native events for Livewire wire:model
- **WHEN** the formatter syncs a canonical value to the hidden conversion-price input
- **THEN** it SHALL dispatch a native DOM `input` event (not a jQuery synthetic event)
- **AND** Livewire's deferred `wire:model` SHALL detect the change and include it in the next server request

### Requirement: Conversion price entry SHALL survive dynamic product conversion row lifecycle
The system SHALL preserve entered conversion prices across focus, blur, dynamic row creation, dynamic row removal, and Livewire rerenders in the stock-managed product configuration flow.

#### Scenario: Newly added conversion row retains entered price
- **WHEN** the user adds a new conversion row on the product create or edit form
- **AND** enters a conversion price
- **THEN** the visible field SHALL continue to display the entered value using the RP formatting contract after blur
- **AND** the row SHALL retain its canonical raw value for submission

#### Scenario: Existing conversion row remains synchronized after rerender
- **WHEN** a conversion row already contains a price
- **AND** the Livewire unit-configuration component rerenders because of row changes or other component state updates
- **THEN** the visible conversion price SHALL remain editable and correctly formatted
- **AND** the submitted/raw conversion price SHALL remain synchronized to the same numeric value

### Requirement: Conversion price submission SHALL use canonical numeric values
The system SHALL submit canonical numeric conversion-price values for each populated conversion row, independent of the visible RP formatting shown to the user.

#### Scenario: Filled visible price submits as numeric value
- **WHEN** the user selects a conversion unit and enters a visible conversion price on the product form
- **AND** submits the form
- **THEN** the request payload SHALL contain a canonical numeric value for `conversions.*.price`
- **AND** the payload SHALL NOT contain `null` for that row solely because the visible field was formatted or dynamically rendered

#### Scenario: Validation error round-trip preserves conversion price
- **WHEN** the product form fails validation for another field after the user has entered one or more conversion prices
- **THEN** the form SHALL re-render with the entered conversion prices preserved
- **AND** resubmitting the unchanged conversion rows SHALL continue to send canonical numeric values

### Requirement: Server-side preparation SHALL normalize nested conversion price values
The system SHALL normalize nested `conversions.*.price` values before validation on both product create and product update requests.

#### Scenario: Formatted conversion price is normalized before validation
- **WHEN** a product create or update request contains a formatted conversion price such as `RP 65.000,00`
- **THEN** request preparation SHALL normalize that value into a canonical numeric representation before validation rules run

#### Scenario: Empty conversion price remains empty
- **WHEN** a conversion row is submitted without a conversion price
- **THEN** request preparation SHALL preserve the row as missing or empty rather than manufacturing a positive numeric value
- **AND** existing validation rules SHALL still reject the row when a conversion unit requires a price

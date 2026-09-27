# Nested Modal Scoped Events

## Purpose
Define scoped Livewire modal events so nested quick-add modals inside the product modal open independently without breaking page-level quick-add flows.

## Requirements
### Requirement: Search dropdown dispatches configurable modal event

Each search-dropdown component (tax, category, brand, unit) SHALL accept a `modal-event` property that controls which Livewire event is dispatched when the user clicks the "add new" button. The property SHALL default to the current global event name (`openTaxModal`, `openCategoryModal`, `openBrandModal`, `openUnitModal` respectively).

#### Scenario: Default event name when no modal-event prop is provided
- **WHEN** a search-dropdown is rendered without a `modal-event` prop
- **THEN** clicking the "add new" button SHALL dispatch the default global event (e.g. `openTaxModal`)

#### Scenario: Custom event name when modal-event prop is provided
- **WHEN** a search-dropdown is rendered with `modal-event="openNestedTaxModal"`
- **THEN** clicking the "add new" button SHALL dispatch `openNestedTaxModal` instead of `openTaxModal`

### Requirement: Quick-add modal listens on configurable event name

Each quick-add modal component (TaxQuickAddModal, CategoryQuickAddModal, BrandQuickAddModal, UnitQuickAddModal) SHALL accept a `listen-event` property that controls which Livewire event triggers the modal to open. The property SHALL default to the current global event name.

#### Scenario: Default listener when no listen-event prop is provided
- **WHEN** a quick-add modal is rendered without a `listen-event` prop
- **THEN** it SHALL listen for the default global event (e.g. `openTaxModal`)

#### Scenario: Custom listener when listen-event prop is provided
- **WHEN** a quick-add modal is rendered with `listen-event="openNestedTaxModal"`
- **THEN** it SHALL listen for `openNestedTaxModal` and open when that event is dispatched
- **AND** it SHALL NOT respond to the default global event `openTaxModal`

### Requirement: Product modal nested instances use scoped events

The `product-quick-add-modal.blade.php` template SHALL configure all nested search-dropdown and quick-add modal instances to use scoped event names with the `Nested` prefix.

#### Scenario: Tax dropdown inside product modal dispatches scoped event
- **WHEN** a user clicks "add tax" inside the Product Quick-Add modal
- **THEN** only the nested TaxQuickAddModal (inside the product modal DOM) SHALL open
- **AND** the page-level TaxQuickAddModal SHALL NOT open

#### Scenario: Category dropdown inside product modal dispatches scoped event
- **WHEN** a user clicks "add category" inside the Product Quick-Add modal
- **THEN** only the nested CategoryQuickAddModal SHALL open
- **AND** the page-level CategoryQuickAddModal SHALL NOT open

#### Scenario: Brand dropdown inside product modal dispatches scoped event
- **WHEN** a user clicks "add brand" inside the Product Quick-Add modal
- **THEN** only the nested BrandQuickAddModal SHALL open
- **AND** the page-level BrandQuickAddModal SHALL NOT open

#### Scenario: Unit dropdown inside product modal dispatches scoped event
- **WHEN** a user clicks "add unit" inside the Product Quick-Add modal
- **THEN** only the nested UnitQuickAddModal SHALL open
- **AND** the page-level UnitQuickAddModal SHALL NOT open

### Requirement: Focus works in nested quick-add modals

When a quick-add modal opens inside the Product Quick-Add modal, users SHALL be able to focus and type in all input fields without focus being stolen by the parent modal's focus trap.

#### Scenario: User can type in nested tax modal inputs
- **WHEN** the nested TaxQuickAddModal is open inside the Product Quick-Add modal
- **THEN** the user SHALL be able to click into the "Nama" input field and type text
- **AND** the user SHALL be able to click into the "Nilai (%)" input field and type a number

#### Scenario: User can submit nested tax modal form
- **WHEN** the user fills in name and value in the nested TaxQuickAddModal
- **AND** clicks "Simpan"
- **THEN** the tax SHALL be created successfully
- **AND** the nested modal SHALL close
- **AND** the Product Quick-Add modal SHALL remain open

### Requirement: Page-level quick-add modals remain functional

Page-level quick-add modals triggered from outside the Product modal (e.g. from the product cart tax dropdown) SHALL continue to work with the existing global event names.

#### Scenario: Product cart tax add still works
- **WHEN** the Product Quick-Add modal is NOT open
- **AND** a user clicks "add tax" from the product cart tax dropdown
- **THEN** the page-level TaxQuickAddModal SHALL open
- **AND** the user SHALL be able to interact with all inputs normally

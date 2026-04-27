# quick-add-form-management Specification

## Purpose

Define general state management and DOM re-initialization requirements for all quick-add (mini-add) modals to maintain a consistent UX during high-volume data entry.

## Requirements

### Requirement: Quick-add modals MUST fully reset their state after successful submission
All quick-add (mini-add) modals SHALL completely reset their internal state, including both server-side variables and client-side (Alpine.js) display states, once a record has been successfully created and the operation is finalized.

#### Scenario: Product quick-add resets all inputs after creation
- **WHEN** a user successfully creates a product via the quick-add modal
- **THEN** the modal SHALL clear the product name, code, barcode, and category inputs
- **AND** the modal SHALL reset all price inputs (Harga Beli, Harga Jual, Tier prices) to their default or zero values
- **AND** the Alpine.js display formatting for those price inputs SHALL be cleared or reset to reflect the zero/default state
- **AND** any unit conversion rows SHALL be removed, leaving only the base unit input cleared.

#### Scenario: Supplier quick-add resets all inputs after creation
- **WHEN** a user successfully creates a supplier via the quick-add modal
- **THEN** the modal SHALL clear all contact information, address fields, and bank details
- **AND** any selected payment terms in the modal SHALL be reset to the default "Pilih Syarat Pembayaran".

#### Scenario: Tax and Payment Term quick-adds reset after creation
- **WHEN** a user successfully creates a Tax or Payment Term via their respective quick-add modals
- **THEN** the modal SHALL clear the name and value/longevity fields
- **AND** the modal SHALL be ready for another immediate entry if reopened.

### Requirement: Modal DOM elements MUST be re-keyed or re-initialized upon reset
The quick-add modals MUST use mechanisms (such as Livewire `wire:key` or custom events) to ensure that DOM elements with local state (like Alpine.js components or custom dropdowns) are completely re-initialized when the form is reset.

#### Scenario: Alpine.js currency fields refresh visual display on form reset
- **WHEN** the `ProductQuickAddModal` resets its form state
- **THEN** the system SHALL trigger a re-initialization of the `currencyField` Alpine components
- **OR** the system SHALL re-render the input containers with new unique keys to force Alpine to run `init()` again.

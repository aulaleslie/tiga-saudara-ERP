## ADDED Requirements

### Requirement: Toggle-controlled quick-add pricing sections MUST render reliably inside the modal
Quick-add modal sections that depend on a user toggle, including sale-pricing controls in the product quick-add modal, MUST remain reliably synchronized with the current toggle state even when the modal is already open and contains Alpine-managed inputs or nested Livewire children.

#### Scenario: Enabling a pricing toggle updates the visible quick-add section
- **WHEN** a user changes a quick-add toggle that activates a pricing section inside an open modal
- **THEN** the corresponding pricing controls SHALL become visible without requiring the modal to close, reopen, or refresh
- **AND** any existing local client-side input formatting SHALL remain usable after the section becomes active

#### Scenario: Disabling a pricing toggle clears inactive quick-add pricing state
- **WHEN** a user changes a quick-add toggle that deactivates a pricing section inside an open modal
- **THEN** the corresponding pricing controls SHALL move to an inactive state without leaving stale active values visible
- **AND** the modal SHALL remain ready for continued use in the same session

## Why

The POS Session Open form currently displays a currency prefix ("Rp") in the Total Saldo Awal field and lacks a clear/reset mechanism for the Terminal dropdown selection. This creates inconsistency with the Terminal Create form pattern and makes it difficult for users to correct terminal selection mistakes without clearing the entire form.

## What Changes

- Remove the "Rp" currency prefix from the Total Saldo Awal input field
- Add a clear/reset button (×) integrated into the Terminal dropdown button to allow users to deselect a terminal
- Hide the Total Saldo Awal field and its label when no terminal is selected
- Make the Total Saldo Awal field non-mandatory when no terminal is selected (mandatory only when a terminal IS selected)
- Update visual indicators (required asterisk and help text) to reflect field requirement state based on terminal selection

## Capabilities

### New Capabilities

- `pos-session-opening-form-ui-refinement`: Terminal dropdown clear functionality, currency prefix removal, and reactive field visibility for the session opening form

### Modified Capabilities

- `pos-session-opening-access-control`: Extends existing conditional visibility to include dynamic hiding/showing of Total Saldo Awal field based on terminal selection state (currently hidden/shown only by permission)

## Impact

- **Code Changes**:
  - [Modules/Pos/Resources/views/session/open.blade.php](../../../Modules/Pos/Resources/views/session/open.blade.php) — view template updates
  - [Modules/Pos/Livewire/PosTerminalSearchDropdown.php](../../../Modules/Pos/Livewire/PosTerminalSearchDropdown.php) — add clear action
  - [resources/views/livewire/modules/pos/pos-terminal-search-dropdown.blade.php](../../../resources/views/livewire/modules/pos/pos-terminal-search-dropdown.blade.php) — add clear button UI
- **No backend changes**: Existing validation rules in `StorePosSessionOpenRequest` already support optional terminal and conditional Saldo requirement
- **User Experience**: Improved form usability with clearer affordances and reactive validation feedback

## 1. Livewire Component Enhancements

- [x] 1.1 Add `clear()` method to PosTerminalSearchDropdown component to reset selected terminal
- [x] 1.2 Update `updatedSelected()` lifecycle to dispatch event when terminal selection changes

## 2. Terminal Dropdown View Updates

- [x] 2.1 Add clear button icon (×) inside dropdown toggle button, visible only when terminal selected
- [x] 2.2 Add click handler to clear button that calls `clear()` without toggling dropdown
- [x] 2.3 Implement `x-show` or `x-if` conditional to hide/show clear icon based on selection state

## 3. Session Open Form View Refactoring

- [x] 3.1 Remove "Rp" currency prefix from Total Saldo Awal input-group wrapper
- [x] 3.2 Wrap Saldo field and label in Alpine.js container with visibility toggle based on terminal selection
- [x] 3.3 Add Alpine `x-show` directive to hide/show the entire Saldo field section based on hidden terminal input value
- [x] 3.4 Add Alpine data binding to dynamically toggle `required` attribute on Saldo input when terminal is selected/cleared

## 4. Terminal Selection Change Handling

- [x] 4.1 Update Saldo input `required` attribute dynamically when terminal is selected via Alpine watcher
- [x] 4.2 Update Saldo input `required` attribute dynamically when terminal is cleared via Alpine watcher
- [x] 4.3 Ensure hidden terminal input field value updates immediately when selection/clear happens

## 5. Visibility and Label Updates

- [x] 5.1 Add Alpine logic to show/hide Saldo label when terminal is selected/cleared
- [x] 5.2 Update help text "Wajib diisi saat membuka sesi dengan terminal" to show/hide with field
- [x] 5.3 Verify error messages for Saldo field only display when field is visible

## 6. Testing and Verification

- [x] 6.1 Test form loads with no terminal selected - Saldo field is hidden
- [x] 6.2 Test selecting a terminal makes Saldo field visible and required
- [x] 6.3 Test clearing terminal selection hides Saldo field and removes required attribute
- [x] 6.4 Test number formatting still works without Rp prefix
- [x] 6.5 Test form submission with terminal and Saldo amount succeeds
- [x] 6.6 Test form submission without terminal and without Saldo succeeds
- [x] 6.7 Test form submission with terminal but no Saldo fails with validation error
- [x] 6.8 Test clear button appears/disappears based on selection state
- [x] 6.9 Test clear button doesn't toggle dropdown when clicked

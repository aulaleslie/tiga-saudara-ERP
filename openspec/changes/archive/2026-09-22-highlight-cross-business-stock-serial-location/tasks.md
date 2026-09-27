# Tasks

## 1. Serial Marker Context

- [x] 1.1 Add exact serial marker-context resolution to the cross-business stock inventory query service, reusing the operational Good/Bad eligibility rules and verifying focused service/feature coverage distinguishes eligible Good, eligible Bad, and unavailable serial states.
- [x] 1.2 Propagate marker flags into on-screen business and location row data without changing product filtering or export rows, and verify focused tests cover visible, unselected-business, and cleared/non-serial search cases.

## 2. Report Presentation

- [x] 2.1 Apply the dedicated stabilo-style class only to the matching collapsed business Good/Bad cell and verify focused Livewire assertions cover both conditions.
- [x] 2.2 Apply the same marker to the exact Good/Bad location cell when a business is expanded, preserving all existing cell values, buttons, tooltips, borders, and interactions; verify with focused Livewire assertions.
- [x] 2.3 Add soft yellow marker styling with readable contrast and sufficient specificity to remain visible during table hover, then verify the rendered view contains the class only for an exact operational serial match.

## 3. Focused Verification

- [x] 3.1 Extend `CrossBusinessStockInventoryFeatureTest` for exact Good/Bad serial searches, collapsed/expanded display, unavailable serials, non-serial searches, clearing/changing search, selected-business scope, and unchanged Excel output; run only the focused cross-business stock inventory test file or targeted test filters and confirm they pass.

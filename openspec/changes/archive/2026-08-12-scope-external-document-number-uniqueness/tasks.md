## 1. Active-document supplier-number validation

- [x] 1.1 Replace string-based supplier purchase number uniqueness rules in Purchase Livewire create and ordinary edit forms with active-record, same-setting constraints, including self-exclusion on edit.
- [x] 1.2 Apply the same active-record uniqueness rule to the standalone supplier purchase number correction component while preserving its current authorization and archive protections.
- [x] 1.3 Apply the same active-record uniqueness rule to legacy StorePurchaseRequest and UpdatePurchaseRequest validation paths.

## 2. Canonical customer sales number presentation

- [x] 2.1 Present `imported_sales_reference_number` on the Sale detail view as the external customer sales/invoice number when populated, without creating a second persisted field.
- [x] 2.2 Preserve the internal Sale reference and omit the external-number presentation when no imported customer invoice number exists.

## 3. Regression coverage

- [x] 3.1 Add Purchase create and ordinary-edit tests proving an active same-setting supplier number is rejected, an archived conflict is accepted, and the edited Purchase is excluded from its own check.
- [x] 3.2 Add document-level supplier-number correction tests for active and archived conflicts, including existing authorization/lifecycle behavior.
- [x] 3.3 Add Purchase and Sales import tests proving archived external-number matches are imported while active same-setting matches retain duplicate-skip behavior.
- [x] 3.4 Add Sale detail view tests for visible populated external customer number and omitted empty value.

## 4. Verification

- [x] 4.1 Run the focused Purchase, Sale, and import test suites covering the changed validation and presentation paths.
- [x] 4.2 Run the project-recommended PHP test command or an equivalent focused SQLite-compatible suite and resolve regressions.

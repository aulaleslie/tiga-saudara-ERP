# Tasks

## 1. Status Label Foundation

- [x] 1.1 Inventory raw status output and duplicated English status labels within the scoped purchase, sales, return, global payment, report/export, and POS surfaces; verify each finding is assigned to a domain mapping or explicitly excluded as non-status content.
- [x] 1.2 Consolidate purchase and sales lifecycle label resolution around their domain mappings, including context-specific partial wording and `Draf`; verify focused unit or view tests cover every supported lifecycle value.
- [x] 1.3 Update shared payment-status presentation to use `Lunas`, `Dibayar Sebagian`, and `Belum Dibayar` while accepting historical casing variants; verify focused `PaymentStatus` tests pass.
- [x] 1.4 Add or consolidate Sales Return, Purchase Return, POS transaction, and POS Return label resolution without changing canonical values; verify focused mapping tests cover approval, receiving, settlement, dispatch, completion, cancellation, and archival states.

## 2. Operational Screens

- [x] 2.1 Apply the label resolvers to purchase and sales list/detail views, including embedded POS sale previews; verify focused purchase and sales rendering tests contain no raw English lifecycle or payment labels.
- [x] 2.2 Apply the label resolvers to Sales Return and Purchase Return list/detail/status partials; verify focused return rendering tests assert the Indonesian lifecycle, approval, payment, and settlement labels.
- [x] 2.3 Apply the label resolvers to POS transaction lists/details and POS Return lists/details/approval previews; verify focused POS feature or view tests assert Indonesian status output while underlying model values remain canonical.
- [x] 2.4 Translate the affected global purchase and sales payment table headings, actions, and pagination labels, plus payment-record status output; verify the focused global payment table/detail tests pass with Indonesian text.

## 3. Reports, Exports, and Filters

- [x] 3.1 Replace raw status output in affected purchase, sales, and return reports with the shared/domain mappings; verify focused report rendering tests cover paid, partially paid, unpaid, and relevant lifecycle statuses.
- [x] 3.2 Ensure affected filter options display Indonesian labels but submit canonical values; verify focused Livewire filter tests prove filtering behavior is unchanged.
- [x] 3.3 Ensure affected printable and exported output uses the same Indonesian labels as operational screens; verify focused export parity tests for representative purchase and sales statuses pass.

## 4. Focused Verification

- [x] 4.1 Search the scoped production views and presentation code for direct interpolation of known English status values, resolve remaining leaks, and verify any intentional internal occurrences are not user-facing.
- [x] 4.2 Run only the focused unit, feature, Livewire, report, export, and POS tests changed or selected by Tasks 1–3, and record the passing commands; do not run the full application test suite.

# Tasks

## 1. Filters and original sold occurrences

- [x] 1.1 Add report filter data and validation for required multiple products/bundles, inclusive original sale-date range, and authorized business scope; verify focused validation tests cover missing products/dates, reversed range, boundary dates, empty-scope fallback, and tampered business IDs.
- [x] 1.2 Add scoped approved-dispatch candidate loading and normalized occurrence identities for regular sales and POS; verify focused projection tests include partial dispatches and exclude pending/rejected dispatches and blank SNs without relying on current serial status or reduced active quantities.
- [x] 1.3 Add historical bundle/component selection expansion, overlap deduplication, current product naming, and deterministic original-date/name/SN ordering; verify focused tests cover direct component selection, renamed products, duplicate SN text across products, bundle overlap, and financial reporting-date overrides leaving original-date results unchanged.

## 2. Return and replacement projection

- [x] 2.1 Normalize physical return evidence from standard custody, POS receipt execution, and legacy receipt/history sources to the exact sale/dispatch occurrence; verify focused tests retain requested/approved-but-unreceived SNs, remove only physically received partial returns, and handle duplicate receipt evidence once.
- [x] 2.2 Normalize standard settlement DISPATCHED replacements and POS approved replacement dispatch lineage; verify focused tests show B instead of received A, omit both during the pending/rejected replacement gap, and preserve A's original date even when B dispatches outside the period.
- [x] 2.3 Traverse replacement chains with cycle protection and suppress replacement-generated sale roots using explicit execution lineage; verify focused tests cover A-to-B-to-C, returned terminal replacement without successor, cross-owner replacement without duplicate rows, and unrelated sale preservation.
- [x] 2.4 Keep ordinary resales as new occurrences and distinguish same-sale repeated dispatches from earlier received occurrences; verify focused resale/legacy fixtures demonstrate that receipt of an earlier occurrence does not hide the later sale or dispatch.

## 3. Report UI and access integration

- [x] 3.1 Add the report controller, route, page shell, and Reports landing/navigation entry using `saleReports.access`; verify focused access/navigation tests allow authorized users and existing Super Admin bypass and deny unauthorized page and Livewire actions.
- [x] 3.2 Build the Livewire report with global tokenized name/code selection, existing authorized business selector, applied filters, and paginated five-column preview backed by the shared projection; verify focused Livewire tests cover validation, scope sanitization, bundle selection, stable pagination, and empty state.
- [x] 3.3 Add concise user-facing guidance that dates are original sale dates and later received returns/approved replacements update prior periods; verify the rendered page explains this without adding columns or exposing internal identifiers.

## 4. Excel export

- [x] 4.1 Add XLSX export through the shared ordered projection and row mapper with exactly Product name, SN, Tgl, Bulan, Tahun; verify focused workbook tests compare all preview pages against export, preserve leading-zero SN text, use numeric day/month/year, and produce headings only for an empty result.
- [x] 4.2 Apply authorization, business re-sanitization, and applied-filter consistency checks to export; verify focused tests block unauthorized, never-applied, and edited-filter exports and prevent unassigned-business data disclosure.

## 5. Focused integration verification

- [x] 5.1 Run `php artisan test` with the explicit new SoldSerialNumberReport test paths/filters plus directly affected report scope and landing/navigation tests; record commands/results and broaden only for a demonstrated failure or unresolved concern.
- [x] 5.2 Review a representative regular-sale/POS preview and downloaded XLSX across receipt and replacement stages; verify exact five-column output, original dates, latest replacement substitution, no duplicate roots, and unchanged transaction records.
- [x] 5.3 Run `openspec validate add-sold-serial-number-report --strict` and inspect the final implementation diff; verify all report requirements are covered and no unrelated workflow changes, migrations, packages, or broad verification requirements were introduced.

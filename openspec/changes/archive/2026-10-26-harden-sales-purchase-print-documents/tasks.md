# Tasks

## 1. Authorized document loading and business context

- [x] 1.1 Move purchase PDF generation from the inline route into a controller, preserving the existing named default route; add focused endpoint tests proving default PDFs still stream and unknown variants are rejected.
- [x] 1.2 Implement shared per-module authorization/loading for ordinary, archived, and Global Payments printing; verify focused tests for missing view/global permissions, inaccessible business, authorized cross-business access, and unchanged session business.
- [x] 1.3 Resolve current identity and PKP from the owning business with bounded eager loading; verify cross-business and changed-business-identity fixtures never print active-session or hardcoded branding.

## 2. Commercial presentation and tax correctness

- [x] 2.1 Prepare saved commercial rows using current-name fallback, purchase entered units/discounts, and nonduplicated standalone sales bundles; verify focused fixtures for renamed/missing products, fractional conversion quantities, fixed/percentage discounts, and bundles.
- [x] 2.2 Prepare authoritative overall discount, shipping, tax, DPP, and grand-total presentation from saved amounts and effective allocations; verify focused cases for percentage-mode headers, fixed discounts, manual totals, rounding, mixed tax rates, tax-inclusive/exclusive modes, and explicit legacy reconciliation differences without data mutation.
- [x] 2.3 Apply current-PKP visibility to paired commercial templates; verify PKP true renders recorded tax including zero-tax cases, PKP false hides all tax-specific fields, historical-tax non-PKP rows retain gross amounts, and both PKP transitions leave saved values untouched.
- [x] 2.4 Use canonical active settlement methods for Faktur and Pembelian, with sales credits separately identified; verify invalidated payments/credits are excluded, live balances match the application, and no unsupported purchase credit mechanism is added.

## 3. Commercial variants and detail-page integration

- [x] 3.1 Add Nota Penawaran and Pesanan Pembelian endpoints and paired layouts with distinct titles/filenames and no settlement sections; verify focused rendering tests compare shared commercial amounts and omitted payment/credit/balance fields.
- [x] 3.2 Display all commercial print actions on ordinary and Global Payments detail pages across every status, including archived records; verify parameterized focused visibility and direct-endpoint tests for draft, pending, approved, fulfillment, return, rejected, and archived states.
- [x] 3.3 Add Indonesian current-status labels and prominent preapproval/rejected/archived markings while keeping underlying status on archived copies; verify rendered titles, references, and labels in focused tests.
- [x] 3.4 Add repeated table headings, consistent monetary precision, and intact signature layout to commercial PDFs; verify actual generated short and multipage PDF samples for both PKP modes and record inspection outcomes.

## 4. Approved dispatch printing

- [x] 4.1 Filter the existing latest delivery-note action to approved dispatches ordered by descending ID and support a selected approved dispatch under its authorized sale; verify focused tests for newer pending/rejected dispatches, no approved dispatch, archived sale, foreign dispatch IDs, and unapproved direct requests.
- [x] 4.2 Add per-approved-dispatch print actions to ordinary and Global Payments history and latest-approved shortcut eligibility; verify focused view tests and selected PDF quantities/serials with no prices or settlement and no mixing of dispatches.

## 5. Focused integration verification

- [x] 5.1 Run only the new/affected print feature/rendering tests with targeted php artisan test paths or filters, plus narrowly related existing tests if affected; record exact commands and outcomes without running or requiring the full repository suite.
- [x] 5.2 Complete a focused smoke review of all five document types, including global/archived entry points and current-owner identity; record representative PDF inspection results and confirm printing makes no transaction, payment, reference, or inventory changes.

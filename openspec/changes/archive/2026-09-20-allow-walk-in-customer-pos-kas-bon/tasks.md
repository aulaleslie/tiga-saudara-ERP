# Tasks

## 1. Kas Bon Customer Eligibility

- [x] 1.1 Update POS checkout finalization so Kas Bon accepts any resolved active customer, regardless of whether the resolution source is explicit selection or the configured walk-in default; verify the existing unresolved- and inactive-customer validation paths remain in place through focused assertions.

## 2. Focused Regression Coverage

- [x] 2.1 Add a POS debt checkout feature test proving a configured default walk-in customer can complete a zero-down-payment Kas Bon transaction and that the resulting Sale is unpaid and linked to that customer.
- [x] 2.2 Add or adjust focused cases proving explicitly selected customers remain eligible while unresolved and inactive customers remain rejected with the appropriate customer validation error.
- [x] 2.3 Run `php artisan test Modules/Pos/Tests/Feature/POSDebtCheckoutTest.php` (or equivalently narrow filters for the affected cases) and verify the focused POS debt checkout coverage passes; a full-suite run is not required.

# Tasks

## 1. Recovery endpoint

- [x] 1.1 Add the setting-scoped, row-locked LOADED-to-DRAFT recovery service operation, leaving cart-clear unloading unchanged; verify focused tests cover status transition, saved number/content/hash preservation, and rejection of DRAFT/COMPLETED/CANCELLED and repeated requests.
- [x] 1.2 Add the POST reset-to-draft route and controller response using existing view/load permissions and POS feature gates without active-session middleware; verify focused endpoint tests cover success without an active POS session, missing authority, foreign-business access, disabled transactions, and ineligible status responses.

## 2. Transaction list action

- [ ] 2.1 Add Kembalikan ke Draf for authorized users on LOADED rows and wire the JSON request, pending-button disabling, result messages, and filter-preserving refresh; verify manually that other statuses hide the action, success exposes Muat under all-status filtering, LOADED-only filtering removes the row, and failure shows an Indonesian message and re-enables interaction.

## 3. Focused integration verification

- [x] 3.1 Add a focused recovery-then-load feature test using existing POS transaction fixtures; verify the same ID/number and saved contents are loaded through normal load validation, and that recovery leaves any abandoned cart untouched.
- [ ] 3.2 Run only the new recovery feature class and relevant existing transaction load/unload tests using `php artisan test` with explicit paths or a focused filter; record results and the list-action manual check. Do not run or plan a full test suite or `composer test:fresh-sqlite`.

Verification note: implementation and focused automated verification are reported complete; tasks 2.1 and 3.2 remain open because their manual browser checks have not been performed.

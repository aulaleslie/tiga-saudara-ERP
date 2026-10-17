# Tasks

## 1. Business preference persistence

- [x] 1.1 Add an additive settings migration for five receipt booleans with OFF/OFF/OFF/OFF/ON defaults and matching model casts/defaults; verify existing-row migration and newly created settings receive the agreed defaults and explicit false remains false through a focused migration/model test.
- [x] 1.2 Extend settings validation, update persistence and session/cache synchronization for the five booleans; verify independent saves including unchecked signature space, invalid input rejection, existing permission enforcement and isolation between two businesses with focused settings tests.

## 2. Existing settings page grouping

- [x] 2.1 Group current inline POS enablement, saved-transaction and default-customer controls under `Pengaturan POS` and add labeled receipt switches using the existing save form; verify displayed defaults/saved values, unchecked submissions and validation redisplay, including when POS is disabled, while preserving customer-search behavior and separate configuration pages.

## 3. Receipt configuration and rendering

- [x] 3.1 Resolve a normalized visibility payload once from fresh receipt-owned business configuration for checkout, transaction, global-payment, historical and draft/loaded builders; verify a setting change is observed on the next build and that a different active-business context or stale relation cannot supply another business's preferences.
- [x] 3.2 Gate current note and return-history containers and all existing standard/packed/return-time price annotations in place, retaining existing eligibility checks; verify independent toggles, no leftover headings/gaps, no relocation of hidden information, and both switches required for return-time comparisons using a compact parameterized rendering matrix.
- [x] 3.3 Separate PPN markup from thank-you/receipt-number content and independently gate PPN and the 28 mm signature space with its associated separator/margins; verify PPN/signature combinations preserve closing content, correct order and no hidden-section blank space in focused rendered output checks.

## 4. Focused regression verification

- [x] 4.1 Update existing optional-content test fixtures to explicitly enable the sections they inspect, and add one adjusted/returned transaction comparison proving visibility changes leave purchase lines, quantities, serials, totals, payments, debt, validation and persisted financial records unchanged; verify representative print/reprint parity plus historical and preview behavior through existing focused fixtures.
- [x] 4.2 Run only affected settings/receipt test files or precise `php artisan test --filter=...` selections sequentially; record commands and results without running the full suite or requiring `composer test:fresh-sqlite`.
- [x] 4.3 Inspect representative rendered 72 mm output with all optional information hidden and then enabled, verifying no residual spacing and intact closing footer; record the inspection method and run `openspec validate configure-pos-receipt-section-visibility --strict` successfully.

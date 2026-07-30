## 1. Authorization and standard purchase-price semantics

- [x] 1.1 Register `products.manage_cross_business_prices` in centralized permissions and ensure the existing permission synchronization grants it according to current role policy.
- [x] 1.2 Update product creation so manual purchase price seeds `last_purchase_price` while new per-business price rows start with `average_purchase_price` at zero.
- [x] 1.3 Update standard product editing so manual purchase price changes only the active setting's `last_purchase_price` and preserves `average_purchase_price`.
- [x] 1.4 Add focused tests for permission registration and manual create/edit preservation of inventory-derived average purchase price.

## 2. Cross-business price management backend

- [x] 2.1 Add Product module routes and authorized controller/page entry points for showing and saving a selected product's cross-business prices.
- [x] 2.2 Add a request/service layer that loads every setting with matching price-row data, defaults absent values to zero, and returns per-row version metadata.
- [x] 2.3 Implement complete non-negative validation and transactional bulk persistence for sales, tier 1, tier 2, and last purchase prices.
- [x] 2.4 Implement existing-row conditional updates and missing-row creation with optimistic stale-version and unique-create-race conflict handling; preserve existing average purchase price and tax metadata.

## 3. Cross-business price management interface

- [x] 3.1 Add the permission-gated cross-business price action to the product list.
- [x] 3.2 Build the dedicated IDR price page with one row per business, Back navigation, and an initial read-only presentation.
- [x] 3.3 Add page-level Ubah, Batal, and Simpan behavior so only the four commercial price fields become editable and average purchase price stays read-only.
- [x] 3.4 Disable Simpan immediately while submission is pending and show validation, stale-data, and successful-save feedback without partial state changes.

## 4. Verification

- [x] 4.1 Add feature tests for authorized and unauthorized list action, page access, and sensitive-price data exposure.
- [x] 4.2 Add feature tests for existing rows, missing-row zero defaults/upserts, average/tax preservation, and all-or-nothing validation failure.
- [x] 4.3 Add feature tests for stale existing rows, concurrent missing-row creation, and duplicate save interaction behavior.
- [x] 4.4 Run the focused product price and permission test suites, then run the appropriate Laravel test command and address failures.

# Codebase Files Over 300 Lines Report

_Generated from tracked text files on 2026-02-26 using `git ls-files`; binary files skipped._

## Scope

- Includes tracked text files only (via `git ls-files`).
- Includes application source, tests, docs, sample/import data, and tracked compiled assets.
- Binary files are skipped (null-byte detection).
- Recommendation target is code maintainability, not strict line-count compliance.

## Summary

- Total tracked text files >300 lines: **160**
- Application source files >300 lines: **71**
- Test files >300 lines: **32**
- Non-refactor targets (data/docs/generated/lockfiles/assets): **57**

| Category | Count |
|---|---:|
| asset | 2 |
| compiled_asset | 3 |
| data | 43 |
| docs | 7 |
| lockfile | 2 |
| source | 71 |
| test | 32 |

## What This Codebase Looks Like (from the >300-line files)

- Most oversized application files are in transaction-heavy domains: **Sale**, **Purchase**, **PurchasesReturn**, **Adjustment**, and **Product**.
- The largest source files are mostly **controllers** and **Livewire components**, which suggests business logic and UI workflow logic are concentrated at the edges.
- Several large **Blade views** indicate view composition can be improved with partials/components.
- There are many large **feature tests** in purchase return flows; these are often valid but can be split by scenario/phase for readability.
- Import/export and search flows are also concentrated in large service/export classes, which are good candidates for collaborator extraction.

## High-Priority Refactor Candidates (Source Files)

| Lines | File | Kind | Recommendation |
|---:|---|---|---|
| 1691 | `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php` | controller | Split by settlement commands (`submit/approve/reject/execute`) and stock-effect handlers; move serial + stock mutations to a dedicated settlement domain service. |
| 1372 | `app/Livewire/Sale/ProductCart.php` | livewire | Extract pricing/tax/discount calculation service and bundle-handling trait; keep component focused on UI events/state. |
| 1274 | `Modules/Sale/Services/SalesImportService.php` | service | Split CSV mapping, preloading caches, grouping, and invoice dispatch into separate classes; keep service as coordinator. |
| 1166 | `Modules/Sale/Http/Controllers/SaleController.php` | controller | Separate CRUD, dispatch workflow, and PDF/render actions into dedicated controllers/actions. |
| 1152 | `Modules/Purchase/Http/Controllers/PurchaseController.php` | controller | Separate CRUD, receiving workflow, attachments, and status transitions into dedicated controllers/services. |
| 974 | `Modules/Adjustment/Http/Controllers/TransferStockController.php` | controller | Split transfer lifecycle actions (create/approve/dispatch/receive/return) and extract serial validation/inventory mutation services. |
| 963 | `app/Livewire/Purchase/CreateForm.php` | livewire | Extract payment-term/supplier sync, tax/discount handlers, and debug helpers into traits; consider child components for sections. |
| 944 | `Modules/Adjustment/Http/Controllers/AdjustmentController.php` | controller | Split normal vs breakage flows; move approval/rejection stock mutations into shared adjustment service. |
| 896 | `Modules/Product/Http/Controllers/ProductController.php` | controller | Split product CRUD, stock initialization, serial input, and CSV upload endpoints. |
| 892 | `app/Livewire/Purchase/ProductCart.php` | livewire | Mirror `Sale/ProductCart` refactor: shared cart math/tax services + smaller UI handlers. |
| 830 | `app/Exports/InventoryValuationReportExport.php` | php_other | Split transaction loading, sorting/meta enrichment, and row rendering/formatting. |
| 818 | `Modules/Purchase/Services/PurchaseImportService.php` | service | Split row parsing, grouping, supplier/product resolution, and purchase creation orchestration. |
| 715 | `app/Livewire/Purchase/EditForm.php` | livewire | High priority: extract traits/child components/calculation services. |
| 697 | `Modules/Purchase/Resources/views/show.blade.php` | blade_view | Extract partials/components for sections, modals, and tables. |
| 687 | `app/Services/GlobalPurchaseAndSalesSearchService.php` | service | Split search strategies (serial/reference/party/product) into strategy classes and unify result formatting. |
| 633 | `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php` | livewire | Extract form state rules, calculations, and side effects into traits/services. |
| 610 | `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php` | blade_view | Extract partials/components for sections, modals, and tables. |
| 583 | `resources/js/alpine-components/searchable-dropdown.js` | javascript | Split DOM wiring, state, helpers, and API logic into modules. |
| 573 | `resources/views/layouts/menu.blade.php` | blade_view | Drive menu from config/array and render recursively with a partial/component to reduce repeated markup/conditionals. |
| 559 | `Modules/PurchasesReturn/Resources/views/show.blade.php` | blade_view | Extract partials/components for sections, modals, and tables. |

## Refactoring Playbook (to get under ~300 lines)

| Kind | Practical split strategy |
|---|---|
| blade_view (21) | Extract repeated sections into partials/components and move inline scripts to JS modules. |
| controller (17) | Use FormRequest + Action classes (`Store*`, `Approve*`, `Dispatch*`) and move stock/serial mutations to domain services. |
| livewire (15) | Move calculations/state sync into traits/services; split UI into child Livewire components or Blade partials. |
| service (4) | Separate parsing/preloading/grouping/persistence/query steps into collaborators. |
| php_other (4) | Depends on role; exports/models often benefit from helper classes and traits. |
| job (3) | Keep job as orchestrator; push parsing/validation/persistence into dedicated classes. |
| javascript (2) | Split event bindings, state, rendering, and network calls. |
| migration (2) | Only split when independent schema changes can safely run separately. |
| config (2) | Usually leave as-is unless custom config generation is introduced. |
| route (1) | Group routes by domain or feature and load from module route files. |

## Source Files >300 Lines

| Lines | File | Kind | Can be <300? | Recommendation |
|---:|---|---|---|---|
| 1691 | `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php` | controller | Yes | High priority: split by workflow/actions + FormRequests/services. |
| 1372 | `app/Livewire/Sale/ProductCart.php` | livewire | Yes | High priority: extract traits/child components/calculation services. |
| 1274 | `Modules/Sale/Services/SalesImportService.php` | service | Yes | Split into parser/normalizer/query/persistence collaborators. |
| 1166 | `Modules/Sale/Http/Controllers/SaleController.php` | controller | Yes | High priority: split by workflow/actions + FormRequests/services. |
| 1152 | `Modules/Purchase/Http/Controllers/PurchaseController.php` | controller | Yes | High priority: split by workflow/actions + FormRequests/services. |
| 974 | `Modules/Adjustment/Http/Controllers/TransferStockController.php` | controller | Yes | High priority: split by workflow/actions + FormRequests/services. |
| 963 | `app/Livewire/Purchase/CreateForm.php` | livewire | Yes | High priority: extract traits/child components/calculation services. |
| 944 | `Modules/Adjustment/Http/Controllers/AdjustmentController.php` | controller | Yes | High priority: split by workflow/actions + FormRequests/services. |
| 896 | `Modules/Product/Http/Controllers/ProductController.php` | controller | Yes | High priority: split by workflow/actions + FormRequests/services. |
| 892 | `app/Livewire/Purchase/ProductCart.php` | livewire | Yes | High priority: extract traits/child components/calculation services. |
| 830 | `app/Exports/InventoryValuationReportExport.php` | php_other | Yes | Extract query builders, row mappers, and formatting helpers. |
| 818 | `Modules/Purchase/Services/PurchaseImportService.php` | service | Yes | Split into parser/normalizer/query/persistence collaborators. |
| 715 | `app/Livewire/Purchase/EditForm.php` | livewire | Yes | High priority: extract traits/child components/calculation services. |
| 697 | `Modules/Purchase/Resources/views/show.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 687 | `app/Services/GlobalPurchaseAndSalesSearchService.php` | service | Yes | Split into parser/normalizer/query/persistence collaborators. |
| 633 | `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 610 | `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 583 | `resources/js/alpine-components/searchable-dropdown.js` | javascript | Yes | Split DOM wiring, state, helpers, and API logic into modules. |
| 573 | `resources/views/layouts/menu.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 559 | `Modules/PurchasesReturn/Resources/views/show.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 551 | `Modules/Product/Resources/views/products/create.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 546 | `Modules/Product/Jobs/ProcessProductImportBatch.php` | job | Yes | Split orchestration from row/chunk processors and validators. |
| 505 | `Modules/Product/Resources/views/products/edit.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 498 | `app/Livewire/Sale/EditForm.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 488 | `Modules/Purchase/Resources/views/includes/product-cart-alpine.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 486 | `resources/views/errors/illustrated-layout.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 472 | `Modules/Sale/Resources/views/show.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 469 | `resources/views/livewire/sales-return/sale-return-settlement-form.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 459 | `database/migrations/2025_12_01_130006_add_unique_constraints_across_entities.php` | migration | Maybe | Prefer atomic correctness; split only if changes are independent. |
| 457 | `app/Livewire/Sale/CreateForm.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 451 | `Modules/User/Resources/views/roles/edit.blade.php` | blade_view | Yes | Extract partials/components for sections, modals, and tables. |
| 448 | `Modules/Product/Jobs/OrchestrateProductImportJob.php` | job | Maybe | Can split if responsibilities mix orchestration and domain logic. |
| 445 | `resources/views/livewire/modules/product/modals/product-quick-add-modal.blade.php` | blade_view | Maybe | Likely split into partials if template contains multiple sections. |
| 440 | `Modules/User/Resources/views/roles/create.blade.php` | blade_view | Maybe | Likely split into partials if template contains multiple sections. |
| 437 | `Modules/Purchase/Resources/views/create-alpine.blade.php` | blade_view | Maybe | Likely split into partials if template contains multiple sections. |
| 427 | `routes/api.php` | route | Yes | Split by module/domain/version route files and require/include them. |
| 426 | `Modules/SalesReturn/Http/Controllers/SalesReturnController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 422 | `Modules/SalesReturn/Resources/views/partials/settlement-items-table.blade.php` | blade_view | Maybe | Likely split into partials if template contains multiple sections. |
| 407 | `Modules/Sale/Http/Controllers/SalesUploadController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 395 | `Modules/Reports/Http/Controllers/MekariConverterController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 392 | `Modules/SalesReturn/Database/Migrations/2025_10_05_120000_enhance_sale_returns_with_settlement_structures.php` | migration | Maybe | Prefer atomic correctness; split only if changes are independent. |
| 383 | `Modules/PurchasesReturn/Http/Controllers/PurchaseReturnDispatchController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 378 | `Modules/Product/Jobs/ProcessProductImportChunk.php` | job | Maybe | Can split if responsibilities mix orchestration and domain logic. |
| 378 | `app/Exports/ProfitLossReportExport.php` | php_other | Yes | Extract query builders, row mappers, and formatting helpers. |
| 377 | `app/Livewire/Reports/StockMutationReport.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 373 | `Modules/Sale/Resources/views/global-sales-search/detail.blade.php` | blade_view | Maybe | Likely split into partials if template contains multiple sections. |
| 372 | `Modules/SalesReturn/Http/Controllers/SaleReturnDispatchController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 369 | `app/Exports/StockMutationReportExport.php` | php_other | Yes | Extract query builders, row mappers, and formatting helpers. |
| 369 | `app/Livewire/Adjustment/AdjustmentProductTable.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 365 | `resources/views/livewire/sale/product-cart.blade.php` | blade_view | Maybe | Likely split into partials if template contains multiple sections. |
| 361 | `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 360 | `app/Livewire/Modules/Product/Modals/ProductQuickAddModal.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 355 | `Modules/PurchasesReturn/Entities/PurchaseReturn.php` | php_other | Maybe | Move domain workflows/scopes to services/traits if cohesion improves. |
| 350 | `Modules/PurchasesReturn/Resources/views/partials/settlement-items-table.blade.php` | blade_view | Maybe | Optional split; balance against template readability. |
| 347 | `Modules/Product/Livewire/TaxSearchDropdown.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 347 | `Modules/Sale/Resources/js/global-sales-filters.js` | javascript | Yes | Split DOM wiring, state, helpers, and API logic into modules. |
| 343 | `Modules/Purchase/Resources/views/receive.blade.php` | blade_view | Maybe | Optional split; balance against template readability. |
| 341 | `Modules/Sale/Http/Controllers/GlobalSalesSearchController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 341 | `config/backup.php` | config | No | Large config arrays are often acceptable and package-driven. |
| 338 | `app/Livewire/Adjustment/BreakageProductTable.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 333 | `app/Livewire/Expense/ExpenseForm.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 331 | `app/Livewire/SalesReturn/SaleReturnSettlementForm.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 329 | `Modules/People/Http/Controllers/SuppliersController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 323 | `Modules/Sale/Services/SerialNumberSearchService.php` | service | Yes | Split into parser/normalizer/query/persistence collaborators. |
| 320 | `Modules/People/Http/Controllers/CustomersController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 319 | `Modules/Setting/Http/Controllers/BusinessController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 318 | `Modules/Product/Resources/views/products/show.blade.php` | blade_view | Maybe | Optional split; balance against template readability. |
| 317 | `resources/views/livewire/purchase/product-cart.blade.php` | blade_view | Maybe | Optional split; balance against template readability. |
| 308 | `Modules/Purchase/Http/Controllers/PurchaseUploadController.php` | controller | Yes | Move validation/business logic to actions/services; keep controller thin. |
| 307 | `Modules/Product/Livewire/CategorySearchDropdown.php` | livewire | Yes | Extract form state rules, calculations, and side effects into traits/services. |
| 301 | `config/dompdf.php` | config | No | Large config arrays are often acceptable and package-driven. |

## Test Files >300 Lines

| Lines | File | Kind | Can be <300? | Recommendation |
|---:|---|---|---|---|
| 1377 | `Modules/Purchase/Tests/Feature/PurchaseShowReturnedSerialVisibilityTest.php` | php_other | Yes | Split into scenario-focused test classes and shared fixtures/builders. |
| 897 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementPhase3Test.php` | php_other | Yes | Split into scenario-focused test classes and shared fixtures/builders. |
| 809 | `tests/Feature/PurchaseReturnSettlementLogicTest.php` | php_other | Yes | Split into scenario-focused test classes and shared fixtures/builders. |
| 744 | `tests/Feature/SaleMonetaryValuesTest.php` | php_other | Yes | Split into scenario-focused test classes and shared fixtures/builders. |
| 696 | `tests/Feature/ProductQuantityProjectionTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 620 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnLifecycleWorkflowTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 604 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnItemApprovalTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 475 | `tests/Feature/PurchaseReturnSerialUniquenessTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 444 | `Modules/PurchasesReturn/Tests/Feature/PurchasesReturnGranularSettlementTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 440 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnUnifiedStatusTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 431 | `tests/Feature/PurchaseShowHistoryFirstReturnedSerialTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 421 | `Modules/PurchasesReturn/Tests/Feature/BackfillMigrationTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 419 | `Modules/Sale/Tests/Feature/StandardSaleLocationScopeRegressionTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 416 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSerialSettlementAutoSelectTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 410 | `Modules/Sale/Tests/Feature/SalesDispatchTaxTest.php` | php_other | Maybe | Consider splitting by workflow phase or behavior group. |
| 398 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementPhase2Test.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 394 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementDamagedGoodsTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 387 | `tests/Feature/ProductListDisplayTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 367 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementStockEffectTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 360 | `Modules/PurchasesReturn/Tests/Feature/ModifyPurchaseInvalidatesPaymentsTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 347 | `Modules/SalesReturn/Tests/Feature/SaleReturnReceiveSerialStatusTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 345 | `tests/Feature/PurchaseStoreReceiveSerialPolicyTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 335 | `tests/Feature/PurchaseReturnSerialReuseTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 334 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementPhase1Test.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 323 | `tests/Feature/PurchaseApproveReactivatesReturnedSerialTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 316 | `Modules/Sale/Tests/Feature/DispatchApprovalTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 311 | `Modules/Sale/Tests/Feature/SaleRequestAuthorizationTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 310 | `tests/Feature/SalesReturn/SettlementWorkflowTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 309 | `Modules/PurchasesReturn/Tests/Feature/PurchasesReturnSettlementTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 309 | `tests/Feature/PurchaseShowReusedSerialColorStateTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 302 | `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementArchivalTest.php` | php_other | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |
| 302 | `tests/Feature/Livewire/PurchaseCreateFormPaymentTermTest.php` | livewire | Maybe | Keep if cohesive; extract test helpers to reduce duplication. |

## Docs >300 Lines

| Lines | File | Kind | Can be <300? | Recommendation |
|---:|---|---|---|---|
| 1195 | `sample.md` | - | Maybe | Split by topic/phase if navigation is hard; otherwise acceptable. |
| 464 | `ai-docs/global-purchase-and-sales-search/todos.md` | - | Maybe | Optional split for readability only. |
| 372 | `ai-docs/global-purchase-and-sales-search/REQUIREMENT.md` | - | Maybe | Optional split for readability only. |
| 352 | `docs/phase-3-todo-breakdown-tests-first.md` | - | Maybe | Optional split for readability only. |
| 339 | `docs/troubleshooting/purchase-return-m2m-receiving-break-investigation.md` | - | Maybe | Optional split for readability only. |
| 327 | `ai-docs/global-sales-search/TROUBLESHOUTING.md` | - | Maybe | Optional split for readability only. |
| 311 | `ai-docs/global-sales-search/TROUBLESHOOTING.md` | - | Maybe | Optional split for readability only. |

## Sample/Data Files >300 Lines

| Lines | File | Kind | Can be <300? | Recommendation |
|---:|---|---|---|---|
| 25563 | `upload-data/sales/Sales-2024-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 24454 | `upload-data/sales/Sales-2025-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 23288 | `upload-data/sales/Sales-2023-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 22036 | `upload-data/sales/Sales-2025-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 21208 | `upload-data/sales/Sales-2022-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 20350 | `upload-data/sales/Sales-2021-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 18614 | `upload-data/sales/Sales-2023-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 18553 | `upload-data/sales/Sales-2022-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 18254 | `upload-data/sales/Sales-2023-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 18229 | `upload-data/sales/Sales-2024-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 18215 | `upload-data/sales/Sales-2024-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 18109 | `upload-data/sales/Sales-2021-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 17832 | `upload-data/sales/Sales-2024-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 17792 | `upload-data/sales/Sales-2021-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 17551 | `upload-data/sales/Sales-2025-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 16797 | `upload-data/sales/Sales-2022-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 16584 | `upload-data/sales/Sales-2022-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 15849 | `upload-data/sales/Sales-2020-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 15280 | `upload-data/sales/Sales-2023-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 15203 | `upload-data/sales/Sales-2025-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 15074 | `upload-data/sales/Sales-2021-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 4302 | `upload-data/product.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 2702 | `upload-data/purchase/Purchase-2025-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 2319 | `upload-data/purchase/Purchase-2025-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 2070 | `upload-data/purchase/Purchase-2020-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1861 | `upload-data/purchase/Purchase-2024-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1728 | `upload-data/purchase/Purchase-2022-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1622 | `upload-data/purchase/Purchase-2023-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1600 | `upload-data/purchase/Purchase-2021-Q3.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1474 | `upload-data/purchase/Purchase-2022-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1472 | `upload-data/purchase/Purchase-2022-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1469 | `upload-data/purchase/Purchase-2023-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1461 | `upload-data/purchase/Purchase-2021-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1372 | `upload-data/purchase/Purchase-2024-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1345 | `upload-data/purchase/Purchase-2021-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1329 | `upload-data/purchase/Purchase-2024-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1324 | `upload-data/purchase/Purchase-2025-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1309 | `upload-data/purchase/Purchase-2024-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1273 | `upload-data/purchase/Purchase-2023-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1264 | `upload-data/purchase/Purchase-2025-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1251 | `upload-data/purchase/Purchase-2021-Q4.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1195 | `upload-data/purchase/Purchase-2023-Q1.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 1154 | `upload-data/purchase/Purchase-2022-Q2.csv` | - | No | Tracked data/generated asset; do not split for line-count goals. |

## Compiled Public Assets >300 Lines

| Lines | File | Kind | Can be <300? | Recommendation |
|---:|---|---|---|---|
| 3830 | `public/js/dropzone.js` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 400 | `public/js/alpine-components/searchable-dropdown.js` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 396 | `public/css/dropzone.css` | - | No | Tracked data/generated asset; do not split for line-count goals. |

## Tracked Assets >300 Lines

| Lines | File | Kind | Can be <300? | Recommendation |
|---:|---|---|---|---|
| 839 | `public/fonts/vendor/@coreui/icons/CoreUI-Icons-Brand.svg` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 512 | `public/fonts/vendor/@coreui/icons/CoreUI-Icons-Free.svg` | - | No | Tracked data/generated asset; do not split for line-count goals. |

## Lockfiles >300 Lines

| Lines | File | Kind | Can be <300? | Recommendation |
|---:|---|---|---|---|
| 12248 | `composer.lock` | - | No | Tracked data/generated asset; do not split for line-count goals. |
| 10471 | `package-lock.json` | - | No | Tracked data/generated asset; do not split for line-count goals. |

## Suggested Refactor Order (Highest ROI)

1. `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php` (largest source file, dense workflow + stock/serial effects).
2. `app/Livewire/Sale/ProductCart.php` and `app/Livewire/Purchase/ProductCart.php` together (shared cart math/tax logic can likely be centralized).
3. `Modules/Sale/Http/Controllers/SaleController.php` and `Modules/Purchase/Http/Controllers/PurchaseController.php` (split CRUD vs dispatch/receiving workflows).
4. `Modules/Sale/Services/SalesImportService.php` and `Modules/Purchase/Services/PurchaseImportService.php` (parser/preload/grouping/persistence decomposition).
5. Large Blade templates in purchase/sale/return screens (`show`, `create`, settlement tables) by extracting components/partials.

## Notes

- A strict “<300 lines” rule is most useful for **controllers, Livewire components, services, and JS modules**.
- It is less useful for **config files**, **schema migrations**, **docs**, **test fixtures/data**, and **generated assets**.
- For tests, prioritize **cohesion and scenario clarity** over arbitrary line count, but extract builders/fixtures to remove repeated setup.

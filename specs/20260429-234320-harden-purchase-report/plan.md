# Implementation Plan: Harden Purchase Report Validity

**Branch**: `20260429-234320-harden-purchase-report` | **Date**: 2026-04-30 | **Spec**: `specs/20260429-234320-harden-purchase-report/spec.md`
**Input**: Feature specification from `/specs/20260429-234320-harden-purchase-report/spec.md`

## Summary

Harden purchase report generation so on-screen and export outputs are validated, scope-safe, and snapshot-consistent. Upgrade Supplier and Tag filters to **multi-select pill-based typeahead** (`minChars=2`, `debounce=300ms`, dismiss-on-select, `whereIn` queries) and ensure Pajak/Status/Status Pembayaran standard selects are styled consistently with CoreUI `form-control` conventions.

## Technical Context

**Language/Version**: PHP 8.x with Laravel 10 and Livewire 3  
**Primary Dependencies**: Laravel Framework, Livewire, Maatwebsite/Laravel-Excel, Barryvdh/DomPDF, Eloquent ORM, Spatie Tags  
**Storage**: MySQL/MariaDB (existing ERP DB)  
**Testing**: PHPUnit via `php artisan test` and `composer test:fresh-sqlite`  
**Target Platform**: Web application (server-rendered Blade + Livewire)  
**Project Type**: Modular Laravel ERP (nwidart modules + app layer)  
**Performance Goals**: Preserve current report and export responsiveness; searchable Supplier/Tag controls must avoid full option preload and stay responsive on datasets >=1,000 rows  
**Constraints**: Must reuse existing module/patterns, enforce setting/global scope, preserve purchase/payment invariants, apply typeahead query guards (`minChars=2`, `300ms` debounce), use CoreUI `form-control` class for standard selects, implement multi-select pill interaction for Supplier/Tag  
**Scale/Scope**: Purchase report page and its export flows (Excel/CSV/PDF), plus Supplier/Tag multi-select filter UX, standard select styling, and focused report tests

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- Brownfield First: PASS. Changes extend current report behavior and UI controls without replacing module boundaries.
- Domain and Data Integrity: PASS. Scope, lifecycle status, and active-payment source-of-truth remain explicit. Multi-select supplier/tag filters use existing `whereIn`/`whereHas` Eloquent patterns.
- Laravel Pattern Fidelity: PASS. Work stays in Laravel/Livewire/Eloquent and existing Reports module patterns. Multi-select uses dedicated Livewire action methods (not inline `$set` chaining). Standard selects use `form-control` class consistent with CoreUI conventions.
- Verification Proportional to Risk: PASS. Plan includes focused feature tests for validation/export parity/typeahead/multi-select behavior and manual checks.
- Spec Traceability: PASS. Artifacts map to FR-001..FR-019 and SC-001..SC-005.

## Project Structure

### Documentation (this feature)

```text
specs/20260429-234320-harden-purchase-report/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── purchase-report-contract.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Livewire/
│   └── Reports/
│       └── PurchaseReport.php
├── Exports/
│   └── PurchaseReportExport.php
└── Services/
    └── Reports/
        ├── PurchaseReportFilterData.php
        ├── PurchaseReportValidator.php
        ├── PurchaseReportQueryService.php
        └── PurchaseReportSnapshotService.php

Modules/
├── Reports/
│   ├── Http/Controllers/PurchaseReportController.php
│   ├── Resources/views/purchase-report/index.blade.php
│   └── Routes/web.php
└── Purchase/
    └── Entities/
        ├── Purchase.php
        └── PurchasePayment.php

resources/
└── views/livewire/reports/purchase-report.blade.php

Modules/Reports/Tests/Feature/
└── purchase report hardening and export parity coverage
```

**Structure Decision**: Keep implementation inside existing `app/` and `Modules/Reports` boundaries; shared validation/query/snapshot/typeahead logic lives in `app/Services/Reports/*`. Multi-select state management handled within the Livewire component using array properties and dedicated action methods.

## Phase 0: Research Output

Completed in `specs/20260429-234320-harden-purchase-report/research.md` with decisions for shared report pipeline, snapshot-gated exports, strict validation, active payment signal derivation, scale-safe Supplier/Tag multi-select typeahead, CoreUI dropdown styling, and dismiss-on-select interaction.

## Phase 1: Design Output

Completed:
- `specs/20260429-234320-harden-purchase-report/data-model.md`
- `specs/20260429-234320-harden-purchase-report/contracts/purchase-report-contract.md`
- `specs/20260429-234320-harden-purchase-report/quickstart.md`

Agent context update:
- `AGENTS.md` `<!-- SPECKIT START -->` block points to `specs/20260429-234320-harden-purchase-report/plan.md`.

## Post-Design Constitution Check

- Brownfield First: PASS
- Domain and Data Integrity: PASS
- Laravel Pattern Fidelity: PASS
- Verification Proportional to Risk: PASS
- Spec Traceability: PASS

No constitution violations identified; complexity tracking table not required.

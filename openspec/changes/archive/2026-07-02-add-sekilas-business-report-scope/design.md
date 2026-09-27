## Context

The Reports landing page groups Laporan Laba Rugi, Neraca, Buku Besar, Arus Kas, and Neraca Saldo under the Sekilas Bisnis tab. Laporan Laba Rugi already supports selecting one or more `settings` as the business source scope, defaulting to `session('setting_id')` when no selection is made. The other four Sekilas Bisnis reports still hard-code the current session setting in their Livewire components, services, and export filters.

The affected reports have different data shapes:

- Neraca queries sales, purchases, payments, expenses, purchase returns, sale returns, and products directly as of a date.
- Buku Besar and Neraca Saldo consume `OperationalMovementEventService`, then calculate bucket/running or trial-balance output from the normalized events.
- Arus Kas queries cash movement records directly and calculates both opening cash before the start date and movement inside the selected period.

The system currently uses IDR as the only real currency, so currency remains a label and does not require conversion logic.

## Goals / Non-Goals

**Goals:**

- Add a business source selector to Neraca, Buku Besar, Arus Kas, and Neraca Saldo using the existing Laporan Laba Rugi behavior as the product contract.
- Preserve default active-setting behavior when no business source is selected.
- Apply selected setting IDs consistently to screen rendering, XLSX exports, CSV exports, opening balances, period movement, and as-of calculations.
- Preserve existing calculation semantics, including DPP/HPP operational movement rules, current inventory valuation limitations, purchase-return legacy scaling, and cash-basis Arus Kas behavior.
- Keep access control aligned with Laporan Laba Rugi: `reports.access` is sufficient for selecting one or more businesses.

**Non-Goals:**

- No schema changes.
- No new currency conversion, since currency is only an IDR label.
- No new accounting journal, chart-of-account, or ledger implementation.
- No change to report landing navigation.
- No change to non-Sekilas Bisnis reports.
- No change to Laporan Laba Rugi behavior except optional extraction of reusable helper code if needed.

## Decisions

### D1: Model report scope as selected setting IDs

Each affected report should use an effective setting ID array:

```text
selectedSettingIds is empty -> [session('setting_id')]
selectedSettingIds has values -> normalized positive integer IDs intersected with existing settings
```

This matches Laporan Laba Rugi and keeps default behavior stable.

Alternative considered: add separate global report permissions or reuse existing global sale/purchase report permissions. Rejected because Laporan Laba Rugi already established that `reports.access` is sufficient for this financial-report scope selector.

### D2: Keep services backwards-compatible where practical

Report services and shared event services should normalize an `int|array` setting scope internally before querying. This reduces churn in existing tests and callers while allowing new callers to pass arrays.

Example shape:

```text
generate(int|array $settingScope, ...)
  -> normalizeSettingIds($settingScope)
  -> whereIn('setting_id', $settingIds)
```

Alternative considered: switch all signatures immediately to arrays only. Rejected because many existing service tests call `generate($setting->id, ...)`; an abrupt signature change adds noise without improving report behavior.

### D3: Widen filters at the source query level, not after aggregation

All direct and relationship-based setting filters must become scope-aware:

- direct document filters: `whereIn('setting_id', $settingIds)`
- payment filters through parents: `whereHas('sale', fn($q) => $q->whereIn('setting_id', $settingIds))`
- purchase return branches: `whereHas('purchaseReturn', fn($q) => $q->whereIn('setting_id', $settingIds))`
- product/inventory valuation: `whereIn('setting_id', $settingIds)`

Filtering after records are loaded would be slower and more error-prone.

Alternative considered: loop over selected settings and merge complete single-setting reports. Rejected because opening/running balances, bucket ordering, and exports should be calculated once from one scoped event/data set.

### D4: Centralize movement scope for Buku Besar and Neraca Saldo

`OperationalMovementEventService` should accept the setting scope and emit events for all selected settings. Buku Besar and Neraca Saldo should continue to consume this shared event set so their totals remain aligned.

Alternative considered: make Buku Besar and Neraca Saldo each query multiple settings independently. Rejected because it duplicates the highest-risk report logic and can reintroduce disagreement between the two reports.

### D5: Preserve purchase-return scaling logic exactly

The legacy vs Livewire purchase return logic must remain structurally unchanged except for widening setting filters. The implementation should keep:

- legacy return detection through absence of detail `location_id`
- Livewire return detection through presence of detail `location_id`
- initial legacy payment cents scaling
- settlement payment cents scaling via `PAY-RET/`
- edited initial payment decimal handling

Alternative considered: refactor purchase-return scaling into a shared helper as part of this change. This is acceptable only if tightly scoped and covered by existing tests; otherwise defer to avoid mixing business-scope work with historical amount-normalization refactoring.

### D6: Show and export scope labels

Each affected report should display and export a scope label:

- one effective setting: that setting's `company_name`
- all available settings: `Semua Perusahaan`
- any other multi-setting selection: `Beberapa Perusahaan`

This mirrors Laporan Laba Rugi and prevents users from reading a multi-business report as a single-business report.

Alternative considered: show selected business names joined together. Rejected for now because Laporan Laba Rugi already uses summarized labels and long company lists can make headers noisy.

## Risks / Trade-offs

- [Risk] Export code can silently keep using the old `settingId` filter. -> Mitigation: pass `settingIds` through every export class and add export parity tests for multi-business selections.
- [Risk] Opening balances can be scoped differently from period movement. -> Mitigation: add tests where one selected business has pre-period movement and another unselected business has pre-period movement.
- [Risk] Relationship filters on payments and returns can be missed. -> Mitigation: test selected and unselected settings across sale payments, purchase payments, sale return payments, purchase return payments, and approved expenses.
- [Risk] Legacy purchase-return scaling can regress during `whereIn` conversion. -> Mitigation: preserve existing scaling branches and keep focused tests for legacy, edited legacy, settlement, and Livewire purchase returns.
- [Risk] Duplicating the Select2 UI in four views can create DOM ID collisions or inconsistent behavior. -> Mitigation: use unique select element IDs per report or extract a small shared Blade partial/helper.
- [Risk] Services accepting both int and array setting scopes reduce type strictness. -> Mitigation: normalize at method entry and keep typed internal helper methods returning `array<int>`.

## Migration Plan

1. Add or extract reusable setting-scope normalization, available setting loading, and scope-label helpers based on the Laporan Laba Rugi behavior.
2. Update the four affected Livewire components to maintain `selectedSettingIds`, calculate effective setting IDs, pass scope labels to views, and pass setting IDs to exports.
3. Add the business source selector to the four affected report views with unique IDs or a shared partial.
4. Update `OperationalMovementEventService`, `OperationalGeneralLedgerReportService`, and `OperationalTrialBalanceReportService` to accept scoped setting IDs and use one scoped event set.
5. Update `OperationalCashFlowReportService` to scope period movement and opening cash queries to selected setting IDs while preserving cash-basis behavior.
6. Update `OperationalBalanceSheetReportService` to scope as-of cash, receivable, inventory, payable, tax, and return calculations to selected setting IDs.
7. Update all four report export classes, including CSV exports, to preserve selected setting IDs and scope labels.
8. Add targeted tests for default active-setting behavior, selected multi-business inclusion, unselected business exclusion, opening balances, export parity, and purchase-return scaling.

Rollback strategy: restore the old single-setting filters and export payload keys. No database rollback is required.

## Open Questions

None. Currency is confirmed to be IDR-only for these reports.

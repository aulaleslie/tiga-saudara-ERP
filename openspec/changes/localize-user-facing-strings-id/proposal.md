## Why

The application is a single-language Indonesian ERP, but its user-facing text is only partially Indonesian and the translation was applied ad-hoc rather than through a localization layer. Three concrete defects follow from this:

1. **Status labels are structurally unsafe to translate.** In several partials the same expression serves as branch condition, CSS-class selector, *and* visible label:

   ```blade
   @if ($data->status == 'Pending')        {{-- branch key (DB value) --}}
       <span class="badge badge-info">     {{-- CSS bound to that key --}}
           {{ $data->status }}             {{-- ...and also the visible label --}}
   ```

   Any translation applied at the data layer (changing the stored value or the comparison string) silently breaks both the branch and the badge colour, dropping the row to the `@else` arm. This is the primary risk in the whole effort and is why the work needs a written contract before any edits.

2. **Validation errors are half-translated.** `resources/lang/en/validation.php` was overwritten in place with Indonesian message text (note: no `lang/id/` exists; `config/app.php` still declares `'locale' => 'en'`), but `'attributes' => []` is empty and a few rules (e.g. `alpha`) remain English. Users therefore see Indonesian grammar wrapped around raw English snake_case column names — *"customer id diterima."*, *"discount_percentage harus diantara 0 dan 100."* Half-translated errors read worse than untranslated ones.

3. **Raw database IDs are shown as user-facing values.** Table headers read `ID`, `Txn ID`, `Product ID`, `Sale Detail ID`, and cells echo `{{ $r->product_id }}` directly. These are internal surrogate keys with no meaning to an operator, and in the product-import screens they appear where a human-readable handle (`product_code`, `reference`) already exists on the model.

The codebase currently contains three coexisting maturity levels — a correct decoupled pattern (`PosReturn::STATUS_LABELS`, `PurchasesReturn/partials/settlement-status.blade.php`), hardcoded Indonesian without any key (`->title('Referensi')`), and the unsafe Level-1 coupling above. Left alone, the drift continues: DataTable titles today include both `title('Referensi')` and `title('Reference')`, both `title('Pelanggan')` and `title('Customer')`.

## What Changes

- **Establish a status-label contract** (documented in `design.md`): stored status values, comparison strings, and CSS class names are *protocol* and MUST NOT change; only the leaf echo that renders text to the user becomes Indonesian, via a `STATUS_LABELS` lookup on the owning entity. This makes branch logic and badge styling untouched by construction rather than by careful review.
- Add `STATUS_LABELS` maps (following the existing `PosReturn::STATUS_LABELS` precedent) to the entities whose statuses currently render raw, and convert the Level-1 partials to render `Entity::STATUS_LABELS[$value] ?? $value` while leaving every `@if`/`@elseif` condition and every `badge-*` class byte-identical.
- Apply the same contract to **client-rendered** status badges (e.g. `Modules/Pos/Resources/views/transactions/index.blade.php` builds badges in JavaScript from `row.status`), which a Blade-only sweep would miss.
- Populate `'attributes'` in the validation lang file with Indonesian names for the field keys actually used in validation rules, and finish translating the residual English rule messages. This is the single highest-leverage edit: it corrects error text across every form at once without touching any form.
- **Replace user-facing raw IDs with human-readable handles** where one exists (`product_code`, `reference`), and translate the remaining ID column headers. Diagnostic/audit surfaces keep their IDs (see below) but get Indonesian labels.
- Normalize the residual English DataTable column titles (`Customer`, `Reference`, `Supplier`, `Seller`, `Products`, `Serial Numbers`, `Tags`) to Indonesian, matching the majority already translated.
- Translate remaining English labels, readonly/disabled field labels, and help text in Blade views.
- **BREAKING**: none. No stored value, enum constant, route, API payload, or CSS class changes. All edits are presentation-layer.

## Non-Goals

- **No `lang/id/` directory and no `locale` switch.** This is a single-language product; introducing locale machinery buys nothing unless English returns, and would mean relocating files that already work. Indonesian continues to live in the `en/` lang files. This is a deliberate decision, recorded in `design.md` so it is not re-litigated later.
- **No translation of developer-facing surfaces**: log messages, exception messages, artisan command output, and code comments stay as-is.
- **No removal of IDs from audit/diagnostic panels.** The POS return `readonly-detail.blade.php` ID block sits inside a `<details>` audit drawer alongside `Snapshot Transaksi Asli`; import-batch and receivings screens are operator tooling where the surrogate key *is* the useful handle. These keep their IDs and only gain Indonesian labels.

## Capabilities

### New Capabilities
- `indonesian-user-facing-language`: Defines the language contract for user-facing text — that displayed labels, status text, validation errors, and column headers are Indonesian; that status protocol values and CSS classes are excluded from translation; and that internal surrogate IDs are not presented as user-facing values outside diagnostic surfaces.

### Modified Capabilities
(none — this adds a cross-cutting presentation requirement and does not alter the behavioural requirements of any existing capability.)

## Impact

- **Entities** (additive constants only): `Sale`, `Purchase`, and the other status-bearing entities gain `STATUS_LABELS` maps. No existing constant changes value.
- **Views**: status partials under `Modules/*/Resources/views/partials/`, DataTable column titles across 25 `*DataTable.php` files, and Blade views carrying English labels (49 files contain `form-label` markup; the English subset is to be counted during implementation).
- **Lang**: `resources/lang/en/validation.php` — `attributes` array populated, residual English messages translated.
- **JS**: status-badge label maps added to client-rendered tables that build badges from raw status values.
- **Verification risk to watch**: DataTable exports, the Reports module, and CSV/XLSX exporters also consume status values. If an export ships a translated label while a filter matches the stored value, the two silently disagree — exports must be confirmed to serialize the stored value, not the label, or to translate both sides consistently.

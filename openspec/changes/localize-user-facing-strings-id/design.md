## Context

The system has no localization layer. `config/app.php` declares `'locale' => 'en'`, there is no `lang/id/`, and Indonesian text was pasted directly over `resources/lang/en/*.php` and hardcoded into Blade views. Translation is therefore a string-by-string edit, not a configuration change — which means every edit carries the risk of touching a string that something else depends on.

Three maturity levels coexist today:

```
LEVEL 3 — Decoupled, correct                                    ✅ target state
  PosReturn::STATUS_LABELS ['pending_approval' => 'Menunggu Persetujuan']
  PurchasesReturn/partials/settlement-status.blade.php
    → $label and $badgeClass computed as separate variables

LEVEL 2 — Hardcoded Indonesian, no key                          ⚠ acceptable, drifts
  DataTable ->title('Referensi'), ->title('Jumlah Total')
  Works. But unindexed, so English stragglers survive unnoticed:
  title('Customer'), title('Reference'), title('Seller'), title('Products')

LEVEL 1 — status IS the label IS the branch condition           ❌ the hazard
  Sale/partials/status.blade.php
  Purchase/partials/status.blade.php
  Sale/partials/payment-status.blade.php
  Pos/transactions/index.blade.php  (same shape, in JavaScript)
```

Level 3 already exists in this codebase and works. The design goal is to make Level 1 into Level 3 without inventing a new pattern.

## Goals / Non-Goals

**Goals**
- Make status translation *structurally* incapable of breaking branch logic or badge CSS — safe by construction, not by reviewer diligence.
- Fix validation error text globally with a minimal, high-leverage edit.
- Stop presenting internal surrogate keys as user-facing values, while preserving them where they are the legitimate operator handle.

**Non-Goals**
- Introducing `lang/id/`, a locale switch, or `__()` wrapping of every string. Rejected — see Decision 1.
- Translating logs, exceptions, artisan output, or code comments.
- Removing IDs from audit/diagnostic drawers.

## Decisions

### Decision 1: Keep Indonesian in the `en/` lang files; do not introduce `lang/id/`

**Chosen:** Continue placing Indonesian text in `resources/lang/en/`, leaving `locale => 'en'`.

**Alternative considered:** Create `lang/id/`, set `'locale' => 'id'`, and wrap user-facing strings in `__()`.

**Rationale:** This is a single-language Indonesian ERP with no stated multi-language requirement. The locale machinery only pays for itself if a second language arrives; until then it is pure indirection, and migrating would mean relocating lang files that currently work plus touching every string site twice (once to move, once to key). The `en/` directory name is admittedly a misnomer, but it is a cosmetic wart, not a defect.

**Consequence / reversibility:** If multi-language is ever required, this decision is cleanly reversible — the lang files move to `lang/id/`, the locale flips, and the hardcoded Blade strings become the migration backlog. Recording the decision here prevents it being silently re-litigated mid-implementation.

### Decision 2: The status-label contract — protocol vs. prose

This is the core safety rule of the change.

**Every status value has three consumers. Exactly one of them is translatable.**

```
                    stored value: 'Pending'
                           │
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
   branch condition    CSS class        visible label
   @if(... =='Pending') badge-info      {{ $data->status }}
         │                 │                 │
      PROTOCOL          PROTOCOL           PROSE
     do not touch      do not touch      translate this
```

**Rule:** stored status values, enum constants, comparison string literals, and CSS class names are **protocol**. They MUST NOT be translated, renamed, or reformatted by this change. Only the leaf expression that renders text into the DOM becomes Indonesian.

**Mechanism:** a `STATUS_LABELS` constant on the owning entity, mapping protocol value → Indonesian prose, exactly as `PosReturn::STATUS_LABELS` already does.

```blade
{{-- before --}}
@if ($data->status == 'Pending')
    <span class="badge badge-info">
        {{ $data->status }}
    </span>

{{-- after: condition and class byte-identical, only the echo changes --}}
@if ($data->status == 'Pending')
    <span class="badge badge-info">
        {{ \Modules\Purchase\Entities\Purchase::STATUS_LABELS[$data->status] ?? $data->status }}
    </span>
```

**Why the `?? $data->status` fallback is required:** legacy or unmapped values must degrade to the raw value rather than rendering blank. A missing key producing an empty badge is a worse failure than an untranslated one, and status columns are known to carry historical values not covered by current constants.

**Review test — a diff satisfying this contract touches no `@if`, no `@elseif`, and no `badge-*` token.** Any hunk that does is out of contract and must be rejected. This is mechanically checkable, which is the point: it does not rely on the reviewer understanding each status machine.

### Decision 3: Client-rendered badges need a parallel map

`Modules/Pos/Resources/views/transactions/index.blade.php` builds badges in JavaScript:

```js
if (status === 'COMPLETED') return 'badge-success';
...
<td><span class="badge ${statusBadgeClass(row.status)}">${escapeHtml(row.status || '-')}</span></td>
```

Identical Level-1 coupling, but invisible to a Blade-only sweep. These need a client-side label map applied at the same leaf position, under the same contract: `statusBadgeClass(row.status)` keeps receiving the raw value; only the rendered text is looked up.

**Implementation note:** the map should be emitted from the PHP constant rather than hand-duplicated in JS, so the two cannot drift.

### Decision 4: Validation `attributes` before per-form messages

Populating `'attributes'` in the validation lang file corrects error text across every form at once, because the existing Indonesian rule templates already interpolate `:attribute`. The messages are already translated; only the noun is wrong. One file, roughly the count of distinct field keys used in validation rules, and *no form is touched*.

This is sequenced first among the validation work. Per-form custom messages, if any remain awkward afterwards, are a follow-up — most will be fixed by this alone.

### Decision 5: IDs — replace, or relabel, based on surface

Investigation showed ID exposure is narrower than the general concern suggested and splits cleanly:

| Surface | Files | Disposition |
|---|---|---|
| Import batch / import row tables | `products/imports/*`, `purchases/imports/*`, `sales/imports/*`, `expenses/imports/*` | Operator tooling; ID is the useful handle. **Relabel only** (`ID` → Indonesian). Where `product_code` exists, prefer it over `Product ID`. |
| Receivings index | `receivings/index.blade.php` | Same — relabel. |
| POS return audit drawer | `returns/partials/readonly-detail.blade.php` | Inside a `<details>` audit block, siblings already Indonesian (`Snapshot Transaksi Asli`). **Keep IDs, translate labels.** |
| Purchase show — `Transaksi ID` | `Purchase/show.blade.php` | Already Indonesian-ish; normalize wording. |

**Rationale for keeping IDs in diagnostics:** an audit drawer exists precisely so an operator can quote a key to a developer. Removing the key defeats the surface's purpose. The defect is an *unlabelled or English-labelled* key on a normal business screen, not the presence of a key in a diagnostic one.

`product_code` and `reference` are confirmed present on the relevant models, so substitution has a real target where it applies.

## Risks / Trade-offs

**Risk: exports and filters disagree after translation.** DataTable exports, the Reports module, and the CSV/XLSX exporters consume status values. If an export serializes a translated label while a filter matches the stored value, they silently stop agreeing — and silently, because nothing errors.

*Mitigation:* before editing any status partial, confirm each export path serializes the stored value (not the rendered label). Where an export deliberately shows a human label, both sides must go through `STATUS_LABELS` so they cannot drift. This check is a blocking prerequisite, not a cleanup step.

**Risk: DataTable column search.** `SalesDataTable` filters on `request('status')` against the stored column. Because the contract forbids changing stored values, this keeps working — but it is the exact thing that would have broken under a data-layer translation, and is worth an explicit regression check.

**Trade-off: Level 2 remains.** Hardcoded Indonesian in DataTable titles is not promoted to keyed lookups. It is not worth the churn for a single-language product, but it does mean future English stragglers stay possible. Accepted, given Decision 1.

**Trade-off: `en/` directory holding Indonesian is confusing to newcomers.** Accepted and documented rather than fixed.

## Open Questions

- **How many client-rendered status tables exist?** One is confirmed (`Pos/transactions/index.blade.php`). A count is needed during implementation to size Decision 3 — grep for badge construction in `<script>` blocks, not just Blade.
- **How many of the 49 `form-label` files carry English text?** Only the file count is known; the English subset has not been separated. Needs a count before the label sweep is scoped.
- **Do any status values reach an external consumer** (API client, integration, saved report definition) that would notice a changed label? The contract protects stored values, so this should be safe, but it has not been verified.

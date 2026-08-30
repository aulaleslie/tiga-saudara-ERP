## Context

`product_prices` stores all five price columns as `decimal(10,2)`, the model casts them to two decimal places, and the update request already accepts non-negative numeric values. The cross-business page currently narrows those values at the browser boundary: Blade rounds them, `jquery-mask-money` uses zero precision, dirty detection calls `Math.round`, and submission removes only thousands separators. The same plugin installs keyboard handlers that directly assign input values, allowing a focused `readonly` input to change before the page enters edit mode.

The solution must retain the existing permission boundary, one-row-per-setting form, apply-to-all convenience, atomic service transaction, optimistic locking, tax preservation, and non-editable average purchase price. It must also remain compatible with Indonesian input conventions (`.` thousands separator and `,` decimal separator).

## Goals / Non-Goals

**Goals:**

- Preserve and accept non-negative prices with up to the database-supported two decimal places.
- Provide one consistent conversion between canonical decimal values and Indonesian-formatted display values.
- Make the initial view state resistant to both native and plugin-driven mutation until `Ubah` is activated.
- Keep cancel, dirty detection, apply-to-all, validation restoration, and unchanged submission numerically stable.
- Verify the behavior with focused Product module coverage.

**Non-Goals:**

- Changing `product_prices` precision, currency, routes, permissions, optimistic locking, or transactional persistence.
- Making average purchase price editable or copyable.
- Supporting more than two fractional digits, negative prices, automatic saving, or a new masking dependency.
- Running the full application test suite.

## Decisions

### Decision 1: Use canonical two-decimal values as the page's numeric baseline

Server-rendered values and `data-original` baselines will retain their decimal magnitude rather than being rounded. Client helpers will convert between a canonical dot-decimal representation used for comparison/submission and the Indonesian display representation used by the mask. Comparisons will use a two-decimal-safe normalized representation instead of `Math.round`.

Keeping formatted strings as the baseline was rejected because equivalent values such as `1234.50` and `1.234,50` would appear dirty. Continuing to use floating-point rounding to whole numbers was rejected because it discards supported database precision.

### Decision 2: Configure the existing mask for two-place Indonesian decimals

The page will continue using the bundled `jquery-mask-money` asset, configured with `thousands: '.'`, `decimal: ','`, and `precision: 2`. Submission will explicitly remove thousands separators and translate the decimal comma to a decimal point before the form is sent. The normalization path will cover manual entry, copied values, old input, and unchanged values.

Replacing the mask library was rejected because the existing dependency can represent the required values and a replacement would expand scope. Sending comma-decimal strings directly was rejected because Laravel's `numeric` validation expects canonical numeric syntax.

### Decision 3: Keep mask mutation handlers inactive outside edit mode

Commercial inputs will be protected with a browser-enforced non-editable state while viewing. The money-mask event bindings that directly write values will only be active when edit mode is enabled, or will be destroyed/guarded before returning to view mode. Activating `Ubah` will enable the four commercial columns and their mask behavior; `Batal` will restore canonical originals, reformat them, disable mutation again, and hide edit-only controls.

Relying on the HTML `readonly` attribute alone was rejected because the current plugin's key handlers bypass native mutation protection by calling `.val()` directly. A permanently disabled field was rejected unless its enabled/disabled lifecycle is kept aligned with form submission, because disabled controls are omitted from requests.

### Decision 4: Preserve backend persistence semantics

The form request and `CrossBusinessPriceService` remain the authoritative validation and atomic save boundaries. Only canonical non-negative values with no more than two fractional digits will reach the service; existing average prices, tax IDs, versions, and all-or-nothing behavior remain unchanged.

Adding decimal parsing inside the service was rejected because locale parsing is an HTTP/UI boundary concern and the service should continue accepting canonical numeric data.

### Decision 5: Use focused verification

Existing `CrossBusinessPriceMaskTest` expectations will be updated from whole-number rounding to decimal preservation, and focused backend cases will prove canonical decimal persistence. Interaction coverage will target the view/edit/cancel state logic sufficiently to demonstrate that typing, deletion, and paste cannot mutate a field before `Ubah`; no full-suite requirement is added.

## Risks / Trade-offs

- [Risk] Naive separator replacement could turn a decimal into a value 100 times larger. → Use one explicit locale-to-canonical helper and cover representative large and fractional values.
- [Risk] Floating-point comparisons could report false dirty states. → Compare normalized fixed two-decimal strings or integer minor units rather than raw display strings.
- [Risk] Disabling inputs could omit them from a legitimate save. → Enable every editable commercial input synchronously when entering edit mode and verify the submitted row shape in focused tests.
- [Risk] Rebinding the mask repeatedly could duplicate event handlers. → Destroy or idempotently initialize bindings at each state transition and verify cancel/re-enter behavior.
- [Risk] Always showing `,00` is visually noisier for whole prices. → Prefer correctness and consistent two-place precision across all rows; formatting remains aligned with the schema.

## Migration Plan

Deploy the Blade/JavaScript change and focused test updates together. No database migration, backfill, permission synchronization, or dependency installation is required. Existing fractional values become visible and editable without data transformation.

Rollback is a code rollback. Decimal values saved while deployed remain valid `decimal(10,2)` data, although the previous interface would round them when subsequently edited.

## Open Questions

None. The storage contract fixes precision at two decimal places, and the existing page fixes the locale to Indonesian Rupiah formatting.

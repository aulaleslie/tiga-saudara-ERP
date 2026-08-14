## Context

`ProductPrice` casts monetary columns as `decimal:2`, so Laravel exposes values such as `2500000.00` as strings. The cross-business price Blade view writes those strings directly into text inputs and then initializes `jquery-maskMoney` with Indonesian separators and `precision: 0`. In that mode the plugin treats every digit in the initial string as part of the whole-number amount, turning `2500000.00` into `250000000` before adding thousands separators.

The persisted values and cross-business price service are correct. The defect is confined to the presentation boundary, but it affects editable sale, tier 1, tier 2, and last-purchase fields as well as the read-only average-purchase field. The cancel flow also reuses the unnormalized `data-original` value.

## Goals / Non-Goals

**Goals:**

- Present decimal-backed Rupiah prices at their correct magnitude with zero-decimal Indonesian formatting.
- Keep the displayed, editable, cancel-restored, validation-restored, and submitted representations consistent.
- Preserve existing decimal storage, optimistic locking, validation, permissions, and atomic bulk-save behavior.
- Cover the exact two-decimal cast that caused `2500000.00` to display as `250000000`.

**Non-Goals:**

- Changing the `product_prices` schema or Eloquent decimal casts.
- Introducing fractional Rupiah editing or changing the page's current zero-decimal interaction.
- Changing price calculation, business scoping, tax metadata, or average-purchase-price ownership.
- Replacing the shared currency-mask library across unrelated forms.

## Decisions

### Normalize values before mask initialization

Convert each initial price value to the page's intended zero-decimal numeric representation before placing it in `value` and `data-original`. This gives the mask an unambiguous whole-number input such as `2500000`, after which it can safely produce `2.500.000`.

Normalizing at this boundary is preferred over changing `ProductPrice` casts because the two-decimal representation remains appropriate for persistence and other consumers. Modifying the shared `jquery-maskMoney` implementation is rejected because it would broaden the change to every masked form and could alter valid decimal-aware uses.

### Apply one explicit zero-decimal rounding policy

Use the same numeric normalization for all five displayed price fields and for restored old input. Values with a fractional component SHALL be rounded to the nearest whole Rupiah, matching the form's declared `precision: 0`, rather than silently multiplying or preserving an invisible fraction.

Truncation was considered but rejected because it disagrees with conventional zero-precision formatting and can bias values downward. Enabling two-decimal editing was also rejected because it would change established Rupiah UI behavior.

### Preserve numeric submission and existing server validation

Continue unmasking editable fields before submission so the request receives plain numeric values. Do not change the request rules, save service, optimistic-lock version fields, average-price immutability, or transaction handling.

### Test both rendered state and persistence round-trip

Add focused coverage that asserts decimal-cast values are emitted in a mask-safe form and that an unchanged submission preserves their magnitude. Include cancel/original-value behavior at the most practical UI test layer available in the project. Existing backend tests remain the guard for atomic updates and metadata preservation.

## Risks / Trade-offs

- **[Risk] A fractional stored price is rounded when shown by this zero-decimal form** → Make the rounding rule explicit and apply it consistently; this matches the existing UI precision instead of exposing misleading cents.
- **[Risk] Validation redirects may return already-normalized or locale-formatted old input** → Normalize old input through the same boundary helper before mask initialization and cover the redirect case.
- **[Risk] A display-only assertion may miss JavaScript remasking behavior** → Assert the emitted mask input and, where the test stack permits, exercise initialization/cancel behavior; retain a persistence round-trip test as the data-integrity guard.
- **[Risk] Users may previously have saved an inflated value** → This change prevents further UI inflation but does not rewrite historical prices; any existing bad data requires separate evidence and remediation.

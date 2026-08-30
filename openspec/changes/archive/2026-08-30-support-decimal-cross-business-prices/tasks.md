## 1. Decimal Price Presentation and Normalization

- [x] 1.1 Replace whole-Rupiah rounding at the Blade boundary with stable two-decimal canonical values for editable prices, original baselines, restored old input, and displayed average purchase price.
- [x] 1.2 Configure the existing Indonesian money mask for two decimal places and add shared client-side helpers for locale display, canonical submission, and decimal-safe dirty comparison.
- [x] 1.3 Update apply-to-all, cancel, and form submission flows so values such as `1.234,56` retain the numeric value `1234.56` without rounding or magnitude changes.

## 2. View and Edit State Protection

- [x] 2.1 Prevent native and money-mask keyboard, deletion, and paste handlers from mutating commercial price controls before `Ubah` is activated.
- [x] 2.2 Make edit, cancel, and re-entry transitions initialize or remove mask behavior idempotently, restore exact decimal originals, and keep average purchase price permanently non-editable.
- [x] 2.3 Ensure all four commercial price fields are included in a legitimate edit-mode submission while edit-only apply-to-all and save controls remain unavailable in view mode.

## 3. Focused Verification

- [x] 3.1 Update `CrossBusinessPriceMaskTest` to cover two-decimal rendering, validation restoration, unchanged round trips, cancel baselines, and decimal apply-to-all hooks instead of whole-number rounding.
- [x] 3.2 Add focused request/service coverage proving canonical decimal values persist atomically while average purchase price and tax metadata remain unchanged.
- [x] 3.3 Add focused interaction coverage, or the project's nearest existing JavaScript verification pattern, proving typing, Backspace/Delete, and paste cannot alter a commercial price before `Ubah` and that edit/cancel/re-enter remains stable.
- [x] 3.4 Run only the focused Product cross-business price tests and address failures.

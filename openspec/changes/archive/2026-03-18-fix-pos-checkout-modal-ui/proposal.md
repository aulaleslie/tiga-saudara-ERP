## Why

The multi-stage payment checkout modal has three critical UX issues that make it difficult for cashiers to complete transactions efficiently: the payment method input appears transparent (background invisible), the payment amount field lacks thousand separators and quick-add buttons for common amounts, and the payment chain display is unclear when multiple payments are involved. These issues increase transaction time and user frustration, requiring fixes before the feature can be used in production.

## What Changes

- **Payment method search input**: Add opaque white background with proper border styling (`!important` overrides to ensure visibility)
- **Payment amount input**: Replace numeric input with formatted text input that displays values with thousand separators (e.g., `150.000` instead of `150000`), and add quick-add buttons for common amounts (`+1.000`, `+5.000`, `+10.000`, `+50.000`) plus a "Sisa" button to auto-fill the remainder
- **Payment chain display**: Restructure badge layout to clearly show payment method, amount, and reference number on separate lines or in distinct sections
- **JavaScript formatter logic**: Add real-time number formatter that maintains numeric accuracy while displaying formatted values; add quick-add button handlers

## Capabilities

### New Capabilities
- `pos-amount-formatter`: Real-time formatting of numeric input to display thousands-separated values (1000 → 1.000) while maintaining raw numeric value for calculation and submission
- `pos-quick-add-buttons`: Quick-add payment buttons that append common amounts (+1K, +5K, +10K, +50K) or fill to remainder, with validation against payment chain state
- `pos-payment-chain-display`: Improved visual rendering of payment chain showing method, amount, and reference information with clear visual hierarchy

### Modified Capabilities
- `pos-staged-payment`: Update existing staged payment modal to use transparent-free inputs and formatted amounts (implementation detail, behavior unchanged)

## Impact

**Affected files:**
- `Modules/Pos/Resources/views/sell.blade.php` (lines 1068-1080): HTML markup for staged payment modal inputs
- `public/js/pos-staged-payment.js` (lines 186-210, 370-436, initialize function): JavaScript for input formatting, quick-add handling, and payment chain rendering

**User-facing impact:**
- Cashiers can now clearly see and interact with payment method dropdown
- Payment amounts are easier to read and verify
- Quick-add buttons speed up common payment entry patterns
- Payment history in modal is clearer with multi-line display

**No breaking changes** to APIs or downstream systems - all changes are purely UI/UX.

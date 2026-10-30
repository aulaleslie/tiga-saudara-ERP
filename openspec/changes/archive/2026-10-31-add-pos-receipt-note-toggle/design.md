# Design

## Context

See proposal.md for motivation. Checkout, transaction, global-payment, historical and draft/loaded receipt paths share `pos::receipt`. It resolves `$showNote` from `receipt_visibility`, and currently omits the note markup entirely when the setting is off. `PosReceiptService::resolveReceiptVisibility` already reads the receipt business configuration. Kas Bon collections use a separate template with a `transaction()` relation but no transaction-note section. Both templates already hide `.no-print` controls on paper.

This design resolves the difference between a persisted visibility setting and an individual print choice, including the second template.

## Goals / Non-Goals

**Goals:** Keep one effective note visibility state for preview and paper, use existing receipt business resolution, and reuse a small control/script between templates where practical.

**Non-Goals:** Note editing, changing other visibility switches, remembering choices across receipts, changing POS success modals, or introducing new financial, audit or permission behavior.

## Decisions

### Keep the override in the receipt document

Render an accessible native checkbox labeled `Tampilkan Catatan Transaksi` beside `Cetak Struk` inside `.no-print`. Its initial checked state uses the resolved business setting. Attach a small change listener that updates the note container's hidden state immediately; explicitly enforce the hidden state in print CSS as needed. Browser print and the existing button therefore print the same content. Do not issue a request or store the choice in local/session storage. A server round trip or query override would add needless state and could repeat receipt access audit events.

Do not automatically open a print dialog or change existing optional auto-print behavior. Initialize the control before any existing auto-print hook runs. Reloads initialize from the setting, including clearing browser-restored checkbox state; do not accidentally reuse form restoration as a saved preference.

### Separate note eligibility from initial visibility

For a nonblank eligible transaction note, render the escaped note container even if initially hidden, so a cashier can show it when the setting is off. Preserve existing placement, multiline whitespace, wrapping and escaped Blade output. The business default controls its initial hidden state, not whether eligible content exists in the document. A blank or unresolved note yields no note container, heading or gap, while the checkbox remains usable and harmless. Avoid `innerHTML` or putting unescaped notes into JavaScript.

This deliberately makes eligible hidden note text available in the authorized receipt page source. The business switch controls presentation; existing receipt authorization remains the access boundary.

### Cover Kas Bon collection receipts using their actual source

Resolve note text from the collection's linked POS transaction and visibility from its own `setting_id` using the existing resolver. Never fall back to another active business for note visibility. Reuse that data setup for both collection print and reprint. Place the note after the payment/debt details and before the footer. Keep `collection.notes`, Sales notes and snapshots separate; no extra note source is invented when the transaction is missing.

### Verify the changed behavior narrowly

Extend `PosReceiptSectionVisibilityTest` for both setting defaults, eligible hidden markup, empty notes and safe multiline text. Add focused Kas Bon receipt cases in `PosKasBonUiAndReceiptTest` (or a small dedicated test class if that keeps fixtures manageable). Reuse existing receipt-business isolation and receipt path tests; only add path assertions where coverage does not already prove use of the shared template/data.

Run the affected cases with `php artisan test --filter=...` using the repository's isolated test database setup. Perform one browser check for each template with a multiline note: toggle both directions, inspect print preview through the button and browser print, confirm the controls do not print and hidden notes leave no gap, then reload and confirm the business default returns. Automated HTML assertions alone cannot prove interactive printing. Do not require the full suite or real paper printing for this presentation change.

## Risks / Trade-offs

- Print CSS could reveal a screen-hidden note: enforce the same hidden state in both media and inspect print preview.
- Rendering hidden text could weaken escaping: retain escaped Blade output and test literal HTML characters and multiline notes.
- Browser restoration could preserve a prior checkbox value on reload: explicitly initialize from the rendered business default and verify reload behavior.
- Kas Bon could read the operator's active business: resolve visibility from the collection business explicitly and add a focused isolation case.
- Without JavaScript, the receipt retains its server-rendered default; the local interactive override requires JavaScript, as does the existing print button.

## Migration Plan

Deploy Blade/script and focused test changes together. No database migration or data backfill is needed. Reverting those changes restores setting-only visibility without changing stored records.

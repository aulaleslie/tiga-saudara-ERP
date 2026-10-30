# POS opening cash: UI implementation handoff

## Objective and review basis

Refine the implemented opening-session page into a compact, readable cash-counting workspace. The user reports that the current result looks ugly. This review inspected the Blade template; it did not inspect a live rendered page or capture screenshots. Layout findings below are inferred from the markup and must be checked in the browser before and after implementation.

The functional implementation has already passed focused opening/lifecycle tests. This handoff concerns presentation and interaction. Preserve the approved global denomination, permission, persistence, and terminal-free contracts in this change's specs.

## References

1. **Lightspeed Retail X-Series — Opening and closing a register**
   https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534185400347-Opening-and-closing-a-register
   The computer closing flow offers total-only versus denomination counting, quantity fields, automatically calculated amounts, and a running cash total. Use the counting interaction as a reference; this example is closing, not opening. Its permissions differ from ours and must not be copied.
   Official denomination-count screenshot linked from the article:
   https://x-series-support.lightspeedhq.com/hc/article_attachments/39738050251675
2. **Oracle Retail Xstore 22.0 — Register Open and Close**
   https://docs.oracle.com/en/industries/retail/retail-xstore-point-of-service/22.0/rpxmg/register-open-and-close.htm
   See Opening the Register → To Perform a Begin Count and Figure 8-19. The opening flow records counts by denomination, supports moving to the next denomination with Enter, and reviews the resulting amount. Borrow keyboard efficiency and a clear total; do not reproduce its legacy screen styling or multi-step tender workflow.

These sources establish comparable workflows. The layout below is a proposed adaptation for this ERP, not a claim that either product uses this exact layout.

## Current presentation problems

- The outer container and denomination table span the available page width while the terminal control uses only half a row. This creates inconsistent alignment and long distances between denomination, quantity, and subtotal.
- A bordered small table, shaded group rows, shaded header, input-group boxes and unit add-ons produce competing visual boundaries on every row.
- The main result, Total Saldo Awal, appears only at the bottom of the table with roughly the same emphasis as other cells.
- The nominal bypass has a long switch label that competes with the primary task title.
- Multi-sentence terminal/role guidance consumes attention before the cashier can count cash.
- Quantity inputs have no explicit associated labels. Improve their accessible names while restructuring the rows.

## Proposed layout

Use the existing app shell, Bootstrap/CoreUI styling, and Blade/Livewire conventions. Keep this as a page, with one form and one final submit. No new modal, wizard, framework, imagery, or dependency is needed.

Desktop wireframe (proposed):

```text
Buka Sesi POS
Pilih terminal dan hitung uang di laci.

Terminal [ Pilih terminal...                         ]
Hint singkat sesuai hak akses.

+------------------------------------+-------------------------+
| Hitung saldo awal                  | Ringkasan saldo awal    |
| Isi jumlah lembar atau keping.      |                         |
|                                    | Rp130.000               |
| UANG KERTAS                        | Terminal: KASIR-01      |
| Pecahan     Jumlah       Subtotal   |                         |
| Rp50.000    [ 2 ] lembar Rp100.000   | Catatan (opsional)      |
| Rp10.000    [ 3 ] lembar  Rp30.000   | [                     ] |
| ...                                |                         |
| UANG LOGAM                         | [ Buka Sesi ]           |
| ...                                | Kembali                 |
+------------------------------------+-------------------------+
```

Only enabled denominations are shown; example rows are illustrative, not a hardcoded currency list. Keep the configured group and row order. Do not replace configuration ordering with descending value sorting.

### Sizing and styling

- Constrain the page workspace to approximately 1040px, centered within the existing content area. Use the same workspace width for the title, terminal section, and cash section.
- At desktop widths (Bootstrap lg and up), use an approximately 2:1 split for counting and summary. Below that breakpoint stack counting first, summary second. At 375px, all rows and actions must fit without horizontal page scrolling.
- Use one subtle border around each main panel, a consistent 16–24px internal padding, and restrained row separators. Remove full cell borders, heavy group-row backgrounds and repeated boxed unit add-ons.
- Display group names as small muted section headings. Keep denomination labels medium weight, currency right aligned, and subtotals with tabular numerals.
- Quantity controls should be approximately 88–104px wide and at least 44px high for touch. Keep unit text immediately beside the field, outside an input-group box. Use a flexible row layout on narrow screens; preserve denomination, quantity and subtotal relationships.
- Give the summary total the strongest typographic emphasis (about 28–32px). Use existing primary color for the final button; avoid adding colors per banknote or decorative money pictures.
- Keep the desktop summary visible with sticky positioning only if the existing shell/header permits it; confirm the offset in the browser. On mobile use normal document flow so a fixed panel cannot cover rows or errors.

### Copy and mode selection

- Main section: `Hitung saldo awal`.
- Helper: `Isi jumlah lembar atau keping. Kolom kosong dihitung nol.`
- Summary label: `Total saldo awal`.
- Keep nominal entry secondary: for authorized users only, add a compact `Input nominal` button near the cash-section heading. In nominal mode show `Input saldo awal` and a `Hitung pecahan` return button. Use real buttons with type=button and expose the selected state accessibly.
- Nominal mode replaces the count area with a clearly labeled currency amount field; the summary remains in the same position. Preserve the existing permission check and explicit mode payload. Do not show disabled bypass controls to unauthorized users.
- Shorten the visible terminal hint while retaining its meaning: cashier `Tanpa terminal, pembayaran belum tersedia.`; manager `Terminal opsional untuk sesi manager.`; floor staff `Sesi tanpa terminal untuk menyiapkan dan menyerahkan draf.` Keep fuller operational explanations in existing help/documentation if needed.

### Behavior and accessibility

- Selecting a terminal reveals the cash workspace. Clearing it hides cash inputs and summary, leaving optional notes and Buka Sesi available for the existing terminal-free flow. Floor-staff pages remain simple and do not display the counting workspace.
- Recalculate subtotals and summary total on quantity edits, including clearing a field. Show Rp0 for an untouched count. Do not silently round fractional quantities or convert them into valid integers.
- Tab moves through quantities in displayed order. Enter inside a quantity field moves to the next quantity, without accidentally submitting the form. On the final quantity, move focus to the next relevant form control; submission remains explicit through Buka Sesi.
- Give each quantity a unique id and an associated accessible label such as `Jumlah Rp50.000, lembar`. Link validation guidance with aria-describedby. Announce the total politely on committed changes rather than interrupting every keystroke.
- Preserve values during in-page mode switching and validation retry, including the reviewed scalar guards for malformed flashed input. Disable inactive-mode controls as today. A fresh page always defaults to denomination mode.
- Keep all-disabled configuration feedback within the count panel. Authorized users can choose Input nominal; other users receive configuration guidance. Do not add a silent fallback.
- Show field feedback beside its relevant control/section; ensure it is visible after submission. Preserve the active-session warning and effective disabled state, including during JS updates.
- Client calculations are previews. Server calculations, permissions, actor evidence and database transactions remain authoritative.

## Files and implementation sequence

1. Capture current page screenshots with a selected terminal and all enabled denominations; also inspect a terminal-free page. Confirm inherited spacing, sidebar width and header offsets in the actual app.
2. Refactor `Modules/Pos/Resources/views/session/open.blade.php` into the constrained workspace, counting panel and summary panel. Reuse the existing global denomination data, Livewire terminal picker and form route/payload.
3. Adapt page-scoped styles and existing script for summary updates, compact mode buttons and keyboard behavior. Avoid global overrides of `.table`, `.card` or `.form-control`. Ensure terminal select, clear and reselect still work after Livewire DOM updates without duplicate listeners or stale element references.
4. Adjust existing view assertions only where presentation markup intentionally changed. Preserve existing behavioral coverage and add meaningful browser checks for keyboard navigation, summary recalculation and the Livewire terminal selection transitions.
5. Run the focused test command below and review before/after screenshots. Do not mark visual acceptance complete from Blade assertions alone.

No lifecycle service, permission registry, cash configuration, event data or migration change is expected for this UI refinement. If a functional defect is discovered, report it separately before expanding the UI scope.

## Acceptance checklist

- [ ] Desktop screenshot at 1366×768 shows consistent alignment, compact count rows and an immediately identifiable total/primary action.
- [ ] Mobile screenshot at 375×812 has no horizontal scrolling, clipped inputs, overlapping actions or hidden feedback.
- [ ] Authorized and unauthorized count pages both default to count entry; only the authorized page exposes nominal mode.
- [ ] Rp50.000 × 2 plus Rp10.000 × 3 updates the summary to Rp130.000; blanking both returns Rp0 without submitting.
- [ ] Terminal select → clear → reselect works with the Livewire picker; terminal-free and floor-staff flows still submit as before.
- [ ] Keyboard count entry, accessible labels, nominal mode switching, zero count, all-disabled configuration and active-session blocking are checked in the browser.
- [ ] Both malformed-input POST-follow-redirect regression cases still render validation feedback.
- [ ] `composer test:fresh-sqlite -- --filter='POSOpeningFloatCaptureTest|POSSessionLifecycleTest'` passes; run relevant browser checks using existing tooling if available, otherwise record manual verification.
- [ ] Supply before/after screenshots and test results with the implementation handoff response; identify anything not verified.

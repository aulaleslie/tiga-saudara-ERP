## Why

Purchase and sale header notes are currently embedded beneath the reference in Global Payment lists, which mixes two kinds of information in one cell and makes rows difficult to scan. The normal Purchase and Sales lists should expose the same notes consistently without duplicating or further complicating the reference presentation.

## What Changes

- Add a dedicated `Catatan` column immediately after `Ref` in the Purchase and Sales document lists.
- Use the same column position and presentation in normal lists, standalone Global Payment lists, and embedded customer or supplier Global Payment workspaces.
- Remove the document note from beneath the reference while keeping reference-related identifiers in the reference cell.
- Reuse the compact note presentation so short notes remain readable, blank notes use a stable placeholder, and long or multiline notes retain per-row `Lihat selengkapnya` and `Tampilkan lebih sedikit` controls.
- Bound and wrap the note column so long content does not force the table wider, while preserving line breaks and escaping note text.

## Capabilities

### New Capabilities

- `document-list-note-column`: Defines the shared position, compact presentation, blank state, expansion behavior, and safe wrapping of purchase and sale header notes across normal and Global Payment document lists.

### Modified Capabilities

- `global-sales-multi-payment`: Move sale header notes from beneath the document number into the dedicated `Catatan` column while retaining search and compact expansion behavior.
- `global-purchase-multi-payment`: Move purchase header notes from beneath the document number into the dedicated `Catatan` column while retaining search and compact expansion behavior.

## Impact

- Affects the shared Livewire Purchase and Sales table templates and the shared document-note Blade component/styles.
- May require aligning or retiring older DataTable-only note markup where those list paths remain active.
- Requires focused presentation tests for column order, normal/global visibility, empty notes, long-note expansion markup, escaping, and unchanged note search behavior.
- Does not change note persistence, validation, permissions, eligibility, allocation logic, routes, or database schema.

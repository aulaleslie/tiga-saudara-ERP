## Why

Global sales- and purchase-payment lists currently render complete document notes inside nowrap table cells. Long notes can make the tables difficult to scan and unnecessarily wide, while stored newline characters are visually collapsed instead of being presented as authored.

## What Changes

- Present sale and purchase document notes as compact previews in Global Payment lists.
- Allow users to expand and collapse an individual long or multiline note without leaving the list.
- Preserve authored line breaks and safely wrap long words in both collapsed and expanded presentations.
- Keep short notes directly readable, blank notes hidden, note text escaped, and existing note-based search behavior unchanged.
- Apply the same interaction and visual rules to the sales and purchase variants through a shared presentation pattern.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-sales-multi-payment`: Refine Global Payment sale-note presentation to support compact, expandable, newline-preserving notes.
- `global-purchase-multi-payment`: Refine Global Payment purchase-note presentation to support compact, expandable, newline-preserving notes.

## Impact

- Affects the Global Payment list renderers in the existing sale and purchase Livewire tables.
- May add a shared Blade component or partial and narrowly scoped CSS/Alpine presentation behavior.
- Requires focused updates to the existing Global Sale Payment Table and Global Purchase Payment Table feature tests.
- Does not change persistence, validation limits, search queries, payment allocation logic, routes, permissions, or database schema.

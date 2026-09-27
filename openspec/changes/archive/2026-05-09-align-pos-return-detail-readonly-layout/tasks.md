## 1. Readonly Surface Preparation

- [x] 1.1 Review the existing create/edit form surface and current detail view to identify shared readonly display sections.
- [x] 1.2 Define the readonly view data needed from persisted POS Return lines, source snapshot, linked Sales Returns, and serial/replacement metadata.
- [x] 1.3 Ensure the detail controller loads or prepares the relationships needed by the readonly surface without changing lifecycle behavior.

## 2. Detail Layout Implementation

- [x] 2.1 Create a dedicated readonly POS Return detail partial that mirrors the create/edit grouped surface without Livewire mutation controls.
- [x] 2.2 Update the detail page header into a title, status, and lifecycle-action toolbar while preserving existing permission and lifecycle guards.
- [x] 2.3 Replace the flat line table with grouped readonly product cards showing resolution badges, returned quantities, serial details, replacement serials, bundle traces, and cash amounts.
- [x] 2.4 Add linked Sales Return summary visibility and lightweight line-level execution linkage.
- [x] 2.5 Move source snapshot hash and technical source identifiers into a collapsed audit/details section.
- [x] 2.6 Add a collapsed original transaction snapshot section that marks non-returned lines as not returned when snapshot context is available.

## 3. Verification

- [x] 3.1 Add or update feature/view tests for the detail page readonly layout structure and absence of editable controls.
- [x] 3.2 Add test coverage for cash return and product replacement resolution badges.
- [x] 3.3 Add test coverage for serial returned/replacement serial display in the same row.
- [x] 3.4 Add test coverage for linked Sales Return summary and line-level linkage visibility.
- [x] 3.5 Add test coverage for collapsed snapshot hash/audit details and collapsed original snapshot context.
- [x] 3.6 Run focused POS Return tests for the detail view and existing create/edit shared surface behavior.

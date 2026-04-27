## ADDED Requirements

### Requirement: Save-and-New SHALL persist bundle metadata for each bundled cart line

When "Simpan dan Buka Baru" persists the cart as a draft transaction, every cart line that carries a selected bundle SHALL store its bundle identifier, bundle name, legacy bundle add-on price, and bundled child item snapshots so the line can be faithfully restored when the draft is loaded again.

#### Scenario: Persisted draft line records bundle identity
- **WHEN** a cashier triggers "Simpan dan Buka Baru" for a cart containing a parent line with a selected bundle
- **THEN** the persisted transaction line MUST store the selected `bundle_id`, `bundle_name`, and the legacy `bundle_price` (add-on) value
- **AND** these fields MUST be retrievable from the persisted line metadata after the cart is cleared

#### Scenario: Persisted draft line records bundled child item snapshots
- **WHEN** a cashier triggers "Simpan dan Buka Baru" for a cart containing a parent line with a selected bundle
- **THEN** the persisted transaction line MUST store the bundled child item snapshots (product id, product name, quantity-per-bundle, stock-managed flag, serial-tracking flag, informational price) as captured at save time
- **AND** these snapshots MUST be retrievable as part of the persisted line metadata

#### Scenario: Non-bundled lines are unaffected
- **WHEN** a cashier triggers "Simpan dan Buka Baru" for a cart with no bundled lines
- **THEN** the persisted transaction lines MUST NOT carry bundle fields
- **AND** the persistence behavior MUST match the pre-existing draft-save flow for non-bundled lines

### Requirement: Loaded drafts SHALL restore bundle metadata from the saved snapshot

When a draft POS transaction is loaded back into the cart, every persisted line that carries bundle metadata SHALL be restored with its bundle identifier, bundle name, legacy bundle add-on price, and bundled child item snapshots taken from the saved snapshot. The system SHALL NOT re-resolve bundle composition from live `product_bundles` data at load time.

#### Scenario: Loaded line restores bundle pill and detail
- **WHEN** a cashier loads a draft transaction whose persisted line was saved with a bundle selection
- **THEN** the hydrated cart line MUST include `bundle_id`, `bundle_name`, `bundle_price`, and `bundle_items` taken from the saved snapshot
- **AND** the cart row MUST display the bundle pill ("Paket: …") and allow opening the bundle-detail modal

#### Scenario: Loaded bundled line uses the saved unit price
- **WHEN** a cashier loads a draft transaction whose persisted line was saved with a bundle selection
- **THEN** the hydrated cart line `unit_price` MUST equal the unit price recorded at save time
- **AND** the unit price MUST NOT be recomputed from the current `bundle_sale_price` value

#### Scenario: Live changes to the bundle definition do not propagate to loaded drafts
- **WHEN** the underlying bundle definition (name, items, prices) is edited after a draft is saved and before it is loaded
- **THEN** the loaded cart line MUST reflect the bundle metadata captured at save time
- **AND** the loaded cart line MUST NOT show the post-edit bundle name, items, or pricing

#### Scenario: Snapshot drift detection covers the persisted bundle reference
- **WHEN** a draft transaction is loaded and the persisted snapshot's recomputed hash diverges from the stored hash because the persisted `bundle_id` was altered
- **THEN** the system MUST reject the load with a snapshot-drift error
- **AND** the cart MUST NOT hydrate the altered bundle reference

#### Scenario: Pre-existing drafts without bundle metadata still load
- **WHEN** a draft transaction saved before this capability was introduced is loaded
- **THEN** the system MUST hydrate the cart lines without bundle metadata
- **AND** the unit price recorded at save time MUST be preserved

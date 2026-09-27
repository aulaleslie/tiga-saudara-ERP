## Purpose

Save-and-new draft workflow allows cashiers and floor staff to quickly park an active POS cart as a saved draft and clear the cart to immediately serve the next customer.

## Requirements

### Requirement: Synchronized POS Save & Open New Activation
The "Simpan dan Buka Baru" button on the POS sell page SHALL be enabled only when all transaction validation rules are met, matching the behavior of the "Pilih Pembayaran" button, and SHALL only be actionable by users with POS shell access and draft-save permission for handoff flow. The supported `cashier` and `floor staff` bundles SHALL both satisfy this handoff requirement, while payment authority SHALL remain a separate capability.

#### Scenario: Button is disabled on empty cart
- **WHEN** the POS cart is empty
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is disabled when prices are invalid
- **WHEN** any item in the cart has an invalid price (e.g., below minimum)
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is disabled when serial numbers are missing
- **WHEN** an item requiring serial numbers does not have the required count of serials assigned
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is disabled when no customer is selected
- **WHEN** no customer is selected and no default customer is resolved
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is enabled when all conditions are met
- **WHEN** there are items in the cart, total > 0, customer is resolved, prices are valid, and all required serials are assigned
- **THEN** the "Simpan dan Buka Baru" button MUST be enabled

#### Scenario: Button is unavailable without shell or save-draft authority
- **WHEN** the logged-in user lacks POS shell access or lacks permission to save POS draft handoff transactions
- **THEN** the "Simpan dan Buka Baru" control MUST be hidden or disabled
- **AND** submission MUST be rejected server-side

#### Scenario: Cashier and floor staff can trigger valid handoff save
- **WHEN** a user in the supported `cashier` or `floor staff` bundle has shell access, save-draft permission, and all validation rules pass
- **THEN** the system MUST allow "Simpan dan Buka Baru" to persist the draft and clear the cart for the next customer

### Requirement: Success Dialog and TRX Number Display
After a successful "Simpan dan Buka Baru" action, the system SHALL display a confirmation dialog showing the unique POS TRX number generated for the draft transaction.

#### Scenario: Displaying TRX number after save
- **WHEN** the "Simpan dan Buka Baru" button is clicked and the transaction is successfully saved server-side
- **THEN** a success modal MUST appear
- **AND** the modal MUST display the transaction code (e.g., "TRX-20260404-0001")
- **AND** the cart MUST be cleared in the background

### Requirement: Save-and-New Modal Action Buttons
The save-and-new success modal SHALL provide two clear actions for the user to continue their workflow.

#### Scenario: Lanjut (Continue) action
- **WHEN** the "Lanjut" button in the success modal is clicked
- **THEN** the modal MUST close
- **AND** the POS shell MUST be ready for the next customer (cart cleared, search focused)

#### Scenario: Cetak Struk (Print Receipt) action
- **WHEN** the "Cetak Struk" button in the success modal is clicked
- **THEN** the system MUST open a new tab/window for the draft receipt of the specific transaction
- **AND** the modal SHOULD remain open or close based on user preference (closing is default)

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


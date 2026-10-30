## Purpose

Allow each business to control optional POS receipt information through its existing business settings while preserving transaction behavior.

## Requirements

### Requirement: Business settings group inline POS controls
The existing business settings page at `/settings` SHALL contain a labeled `Pengaturan POS` section grouping existing inline POS enablement, saved-transaction and default-customer controls with receipt-visibility controls. Existing settings permissions, validation and POS behavior SHALL remain in force. Separate POS configuration pages SHALL remain separate.

#### Scenario: Open POS business settings
- **WHEN** an authorized operator opens the active business settings
- **THEN** the page groups existing inline POS controls and the five receipt switches under `Pengaturan POS`
- **AND** existing enablement and customer selection behavior remains available

### Requirement: Five independent visibility defaults apply to every business
The business SHALL store five independent boolean settings: Catatan OFF, Riwayat Retur OFF, price-adjustment information OFF, PPN information OFF, and blank stamp/signature space ON by default. These defaults SHALL apply to both existing businesses at introduction and newly created businesses. Missing legacy values SHALL resolve to the same defaults. Explicit saved false values, including a disabled signature space, MUST NOT be replaced by defaults.

#### Scenario: Existing and new business defaults
- **WHEN** an existing business receives the new settings or a business is created without explicit visibility overrides
- **THEN** the four informational switches are disabled and signature space is enabled

#### Scenario: Toggle persistence and business isolation
- **WHEN** an operator with existing settings-edit permission independently changes any receipt switches and saves
- **THEN** those booleans persist for the active business and are reflected on reload
- **AND** other businesses' settings are unchanged
- **AND** an unchecked signature-space control saves false rather than reverting to true

#### Scenario: Authorization and invalid values
- **WHEN** an unauthorized operator attempts a settings update or a submitted visibility value is not a valid boolean
- **THEN** the existing authorization or validation response rejects the update
- **AND** saved receipt preferences remain unchanged

### Requirement: Receipt building reads current receipt business configuration
Every receipt build SHALL resolve the current persisted visibility configuration belonging to the receipt business, including checkout and transaction print, reprint, global-payment print/reprint, historical receipt, draft/loaded preview and Kas Bon collection print/reprint paths. The persisted note switch SHALL initialize the receipt-page checkbox; subsequent checkbox changes SHALL override only note visibility for that open page. Visibility MUST NOT be frozen in transaction snapshots or read from a different active business. A newly built receipt after saving configuration SHALL use the new configuration without changing the original transaction facts. Enabling a switch SHALL only show information already eligible under the existing receipt mode.

#### Scenario: Reprint observes a later visibility change
- **WHEN** a receipt is printed, its business visibility configuration is subsequently changed, and that transaction is reprinted
- **THEN** the newly built receipt follows the latest persisted visibility configuration
- **AND** no checkout or return snapshot is rewritten

#### Scenario: Historical and preview visibility
- **WHEN** an explicit historical receipt or draft/loaded preview is built
- **THEN** current business visibility switches apply to its existing eligible content
- **AND** historical prices remain original and unavailable return or price-adjustment content is not invented

#### Scenario: Receipt business differs from active context
- **WHEN** an authorized receipt path resolves a receipt owned by a business different from the operator's active business
- **THEN** visibility uses the receipt business configuration
- **AND** existing receipt authorization remains enforced

### Requirement: Receipt note overrides are ephemeral
Receipt-page note checkbox changes SHALL NOT persist to business settings, transaction or collection records, snapshots, user preferences or browser storage. Each newly opened or reloaded receipt SHALL initialize from its own business's latest persisted note visibility setting; a missing setting SHALL default to unchecked. Existing receipt access permissions SHALL govern this presentation-only choice without requiring settings-edit permission.

#### Scenario: Setting disabled and cashier shows a note
- **WHEN** a cashier with receipt access opens a receipt whose business note setting is disabled
- **THEN** the checkbox starts unchecked and the note is hidden
- **AND** checking it shows eligible note content for that page without changing the saved setting

#### Scenario: Setting enabled and cashier hides a note
- **WHEN** a cashier opens a receipt whose business note setting is enabled
- **THEN** the checkbox starts checked and eligible note content is visible
- **AND** unchecking it hides that note without changing the saved setting

#### Scenario: Reopen resets the local choice
- **WHEN** a cashier changes the checkbox then reloads the page or opens another receipt
- **THEN** the new page starts from its receipt business's current persisted note setting
- **AND** the previous page's choice is not reused

#### Scenario: Missing configuration
- **WHEN** the receipt business's note setting cannot be resolved
- **THEN** the checkbox starts unchecked
- **AND** checking it still shows an otherwise eligible transaction note

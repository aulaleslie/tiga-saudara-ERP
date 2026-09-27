## ADDED Requirements

### Requirement: Persistent User Notifications
The system SHALL persist notification rows per recipient user with setting context, category, type, source reference, action URL, read state, resolved state, and creation timestamp.

#### Scenario: Notification row is created for a recipient
- **WHEN** a supported source event requires notifying a user
- **THEN** the system creates a notification row for that user containing the source type, source id, setting id, title, message, category, action URL, unread state, and unresolved state

#### Scenario: Duplicate active notification is prevented
- **WHEN** the same unresolved source notification is generated again for the same user
- **THEN** the system MUST NOT create a duplicate unresolved notification row for that user and source fingerprint

### Requirement: Cross-Business Visibility
The system SHALL show notifications across all businesses/settings the user can access, subject to permissions in each setting.

#### Scenario: User has approval permission in one business only
- **WHEN** a user can approve purchase returns in Business A but not Business B
- **THEN** the system shows approval notifications for eligible Business A purchase returns and hides Business B purchase return approvals from that user

#### Scenario: Notification row includes business context
- **WHEN** the header dropdown or notification page displays a notification from a non-active business
- **THEN** the notification includes enough business context for the user to distinguish the source business

### Requirement: Header Notification Dropdown
The system SHALL replace the current header low-stock query with service-backed notification count and dropdown items.

#### Scenario: Badge counts unread unresolved notifications
- **WHEN** a user has unread unresolved notifications across accessible businesses
- **THEN** the header badge shows the count of those unread unresolved notifications

#### Scenario: Dropdown shows unread before read
- **WHEN** a user opens the header notification dropdown
- **THEN** the system shows at most 10 unresolved notifications ordered with newest unread notifications first and newest read notifications filling any remaining slots

#### Scenario: Dropdown has ten unread notifications
- **WHEN** a user has 10 or more unread unresolved notifications
- **THEN** the dropdown shows unread notifications only

#### Scenario: Dropdown fills remaining slots with read notifications
- **WHEN** a user has 2 unread unresolved notifications and at least 8 read unresolved notifications
- **THEN** the dropdown shows 2 unread notifications followed by 8 read notifications

### Requirement: Notification Index Page
The system SHALL provide a notification page that displays all notifications visible to the user, including past read notifications.

#### Scenario: Page orders unread before read
- **WHEN** a user opens the notification page
- **THEN** the system lists notifications with unread notifications first, read notifications next, and newest notifications first within each group

#### Scenario: Page includes past read notifications
- **WHEN** a user has old read notifications
- **THEN** the notification page includes those notifications unless they have been manually pruned

### Requirement: Read State Actions
The system SHALL allow users to mark notifications as read individually and in bulk.

#### Scenario: Clicking a notification marks it read before redirect
- **WHEN** a user clicks a notification row with an action URL
- **THEN** the system marks that notification as read for that user before redirecting to the action URL

#### Scenario: Mark all as read
- **WHEN** a user selects mark all as read from the header or notification page
- **THEN** the system marks all matching unresolved notifications visible to that user as read

### Requirement: Low Stock Notifications
The system SHALL generate low-stock notifications for global product stock and per-location product stock using the existing product stock alert threshold.

#### Scenario: Global product stock crosses into low-stock state
- **WHEN** a product's global quantity changes from above `product_stock_alert` to less than or equal to `product_stock_alert`
- **THEN** the system creates low-stock global notifications for users with `notifications.lowStock` in that product's setting

#### Scenario: Location product stock crosses into low-stock state
- **WHEN** a product stock row's location quantity changes from above the related product's `product_stock_alert` to less than or equal to that threshold
- **THEN** the system creates low-stock location notifications for users with `notifications.lowStock` in that product stock's setting

#### Scenario: Already-low stock decreases further
- **WHEN** a stock quantity was already less than or equal to the threshold and decreases again
- **THEN** the system MUST NOT create a new duplicate low-stock notification for the same unresolved user and stock source

#### Scenario: Low stock resolves
- **WHEN** a global or location stock quantity rises above the product stock alert threshold
- **THEN** the system resolves the active low-stock notifications for that stock source

#### Scenario: Low stock crosses again after resolution
- **WHEN** a previously resolved stock source later changes from above threshold to less than or equal to threshold
- **THEN** the system creates a new active low-stock notification for eligible users

### Requirement: Approval Notifications
The system SHALL create approval-needed notifications for supported document approval flows.

#### Scenario: Document enters approval-needed state
- **WHEN** a supported document enters a pending approval state
- **THEN** the system creates approval notifications for users with the relevant approval permission in the document's setting

#### Scenario: Approval notification resolves
- **WHEN** the document is approved, rejected, deleted, archived, cancelled, or otherwise leaves the approval-needed state
- **THEN** the system resolves unresolved approval notifications for that document

#### Scenario: Return sub-flow approval is requested
- **WHEN** an existing return receiving, dispatch, settlement, or related return sub-flow enters a pending approval state
- **THEN** the system creates approval notifications for users with the relevant approval permission in that source setting

### Requirement: Revision Notifications
The system SHALL create revision-needed notifications when supported documents are rejected or require correction.

#### Scenario: Document is rejected
- **WHEN** a supported document is rejected or marked as needing revision
- **THEN** the system creates revision notifications for users with the relevant edit permission in the document's setting

#### Scenario: Revision notification resolves
- **WHEN** the rejected or revision-needed document is edited, resubmitted, approved, deleted, archived, cancelled, or otherwise leaves the revision-needed state
- **THEN** the system resolves unresolved revision notifications for that document

### Requirement: Notification Repair and Sync Command
The system SHALL provide a command to repair missing and stale notifications from current source state.

#### Scenario: Missing active notification is repaired
- **WHEN** the repair/sync command finds an active low-stock, approval-needed, or revision-needed source without required notification rows
- **THEN** the command creates the missing notification rows for eligible users

#### Scenario: Stale active notification is resolved
- **WHEN** the repair/sync command finds an unresolved notification whose source no longer needs attention
- **THEN** the command resolves that notification

### Requirement: Notification Prune Command
The system SHALL retain notifications forever unless a manual prune command deletes old rows.

#### Scenario: Notifications are retained by default
- **WHEN** notifications become read or resolved
- **THEN** the system keeps those notifications in history by default

#### Scenario: Manual prune deletes old notifications
- **WHEN** an authorized operator runs the prune command with an age or cutoff argument
- **THEN** the system deletes notifications older than the requested cutoff according to command rules

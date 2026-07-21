## ADDED Requirements

### Requirement: Display Logged-in User Name
The POS UI SHALL display the name of the currently authenticated user in the information strip at the top of the interface, accompanied by a visual icon, to clearly identify the operator.

#### Scenario: User is logged in
- **WHEN** a user views the POS sell interface (`/pos/sell`)
- **THEN** their name is prominently displayed in the `pos-info-title` section replacing the static "Kasir Information" text.
- **AND** a person icon (`bi-person-circle`) is shown alongside their name.

### Requirement: Responsive Session Information Display
The POS session information display SHALL adapt its layout based on screen width to prevent text truncation on tablet devices in portrait orientation.

#### Scenario: Viewing on a desktop screen (>= md breakpoint)
- **WHEN** the POS interface is viewed on a screen wide enough to accommodate the full text (e.g., standard desktop monitor)
- **THEN** the session metric labels ("Sesi:", "Terminal:", "Dibuka:") are displayed as text.
- **AND** the icons (`bi-hash`, `bi-pc-display`, `bi-clock`) are hidden.

#### Scenario: Viewing on a tablet screen (< md breakpoint)
- **WHEN** the POS interface is viewed on a tablet or smaller screen
- **THEN** the textual session metric labels ("Sesi:", "Terminal:", "Dibuka:") are hidden.
- **AND** the compact icons (`bi-hash`, `bi-pc-display`, `bi-clock`) are displayed in their place.
- **AND** the "Dibuka" timestamp is shortened to only display the time (`H:i`) to further conserve horizontal space.

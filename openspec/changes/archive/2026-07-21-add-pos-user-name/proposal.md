## Why

Currently, the POS user interface does not display the name of the logged-in user. This can lead to confusion about who is currently operating the POS terminal, especially in shared environments, reducing accountability and increasing the risk of session mix-ups.

## What Changes

- Replace the static "Kasir Information" text with the logged-in user's name on the POS UI info strip.
- Add an icon (`bi-person-circle`) beside the user's name to visually indicate it represents the current user.
- Make the POS info strip fully responsive so that on tablets or smaller screens, bulky text labels ("Sesi:", "Terminal:", "Dibuka:") are replaced by compact icons (`bi-hash`, `bi-pc-display`, `bi-clock`) to ensure the user's name and critical session details fit on one line without truncation.
- Shorten the "Dibuka" timestamp display to just show the time (`H:i`) on smaller screens to further save horizontal space.

## Capabilities

### New Capabilities
- `pos-user-display`: Capability to visually identify the logged-in user operating the POS and ensure responsive display of session information.

### Modified Capabilities
- (None)

## Impact

- `Modules/Pos/Resources/views/sell/shell/info.blade.php`: Will be modified to include the user's name, responsive Bootstrap classes (`d-none d-md-inline`, `d-inline d-md-none`), and relevant icons for the session metrics.

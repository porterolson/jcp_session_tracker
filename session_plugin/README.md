# JCP Session Tracker

## Installation

1. Copy the `session_plugin` folder into your WordPress `wp-content/plugins/` directory.
2. Rename the folder to `jcp-session-tracker` if you want the plugin directory name to match the main plugin file.
3. Activate **JCP Session Tracker** from **Plugins** in wp-admin.
4. Visit **Users -> Session Tracker Settings** to confirm tracking behavior, cookie settings, proxy trust, and retention windows.
5. Visit **Users -> Sessions** to review tracked sessions.

## Notes

- The plugin stores a first-party cookie named `jcp_session_id` by default.
- Session IDs are generated with cryptographically secure randomness and inserted with duplicate-collision retry handling.
- Cleanup runs daily with WP-Cron based on the configured retention settings.

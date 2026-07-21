# Changelog

All notable changes to this module are documented here going forward. History before this
file existed is in the git log and in README.md's "Migrating from the earlier `printbridge`
technical name" section.

## [0.3.0]

### Added
- Self-update from the setup page: a new **Updates** section checks `github.com/sayehava/Pridge-Dolibarr-Endpoint` for new releases (hourly, or on demand with "Check for updates"). Dolibarr has no native module-update mechanism to hook into (unlike WordPress), so this is a bespoke, explicit two-step flow:
  - **Back up and download** takes a full zip backup of the module's own files to `documents/pridge/updates/backups/` — deliberately outside the module's own code directory, so an update can never risk overwriting the backup it just took — then downloads and unpacks the release into a staging area. Nothing live changes at this point.
  - **Apply update now** is a separate, explicit confirmation that overlays the staged files onto the live module. Any file that fails to copy is reported by name instead of failing silently, since a partial copy can leave the module in a mixed state that only a restore can safely resolve.
  - The last 5 backups are kept automatically, each with a one-click **Restore this backup** button, and a **Discard staged update** button is available before applying.
  - Requires the PHP `curl` and `zip` extensions, and that PHP can write to the module's own installation directory.

### Fixed
- The client sent `X-PrintBridge-Metadata` on every job submission, which never matched what the server actually reads (`X-Pridge-Metadata`) — job metadata (source, profile ref) has been silently dropped by the server since the earlier PrintBridge-to-Pridge rebrand. Now sends the correct header name.
- Added `X-Pridge-Module-Version` to job submissions; the server's response now includes its own `server_version` and, only when this module's and the server's major versions differ, an advisory `compatibility_warning` shown on the setup page. Never blocks a job.
- Cleaned up leftover "PrintBridge" naming in code comments, translation strings, and the README that survived the earlier rebrand to Pridge (the database-migration section of the README intentionally keeps the old names, since it documents exactly what to rename them from).

## [0.2.0]

### Added
- Advisory version-compatibility warning shown on the setup page when this module and the connected Pridge Server drift onto incompatible major versions.

## [0.1.0]

Initial tracked release: HTTP-based "Pridge" printer driver for Dolibarr's built-in Receipt Printers module and TakePOS, via a custom `pridge://` PHP stream wrapper — server/profile management, adopt-existing-printer flow, bundled test receiver, and the Recent Prints test log.

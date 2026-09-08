# Changelog

All notable changes to the Cereus Monitor plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `cereus_backup.php` is now tracked in this repository. It was previously
  maintained outside version control.
- Backups now include the Cacti application directory as `cacti/` inside the
  archive — plugin code, custom scripts and `include/config.php`. RRD data
  remains a separate top-level `rra/` member and is not stored twice.
- Backups now include OS-level configuration as `system-config/`: the Apache or
  nginx configuration, `php.ini` with its scanned `.ini` directory and FPM
  pools, `spine.conf`, cron entries, and matching systemd units. Files keep
  their original absolute path so a restore is unambiguous.
- A `system-config/manifest.txt` restore manifest recording OS release, kernel,
  SELinux mode, Cacti/PHP/database/RRDtool/Spine/webserver versions, PHP
  extensions, directory ownership, poller type and the installed plugin list.
- New settings: *Include Cacti Directory*, *Cacti Directory Exclusions* and
  *Include System Configuration* (Console → Settings → Monitor).

### Fixed

- `cereus_backup.php` failed to start when run from its current location. It
  resolved the Cacti root by moving one directory up from itself, which is only
  correct for a script under `cli/`. It now searches upward for
  `include/cli_check.php` and runs correctly from any depth.
- The Backups page and the health recommendations advertised the script under
  `cli/`, where it no longer lives.
- `cereus_monitor_backup_retain_days` was configurable but never enforced; only
  the archive count limit was applied. Age-based pruning now runs first.
- A backup of a live system no longer fails when a file changes while `tar`
  reads it. This previously produced a non-zero exit and deleted an otherwise
  valid archive.

### Security

- Backup archives are now created with mode `0600`. They contain database
  credentials in `include/config.php` and `spine.conf`, plus password hashes,
  SNMP communities and plugin tokens in the database dump.

## [1.0.0]

- Initial release.

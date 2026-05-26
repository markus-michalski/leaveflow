# Changelog

All notable changes to LeaveFlow will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Nothing yet

### Changed
- Nothing yet

### Deprecated
- Nothing yet

### Removed
- Nothing yet

### Fixed
- Nothing yet

### Security
- Nothing yet

## [1.0.3] - 2026-05-26

### Changed
- add PolyForm NC license headers to all PHP files (#112)

### Fixed
- handle turbo:frame-missing for session expiry (#111) (#111)

## [1.0.2] - 2026-05-26

### Fixed
- move CSS to <link> tags to fix preload warnings (#109) (#110)

## [1.0.1] - 2026-05-25

### Changed
- support running make inside the container (#107)

## [1.0.0] - 2026-05-24

### Changed
- ignore /logs/ directory (#106)
- ignore Symfony and Doctrine major version bumps in Dependabot (#104)
- bump the actions-all group with 3 updates (#100)
- bump twig/twig from 3.24.0 to 3.26.0 (#102)
- add CHANGELOG.md covering v0.1.0–v0.18.0 (#103)
- add PolyForm NC license, CLA, and community files (#99)
- add phases 15-17 and mark phase 14 as complete

## [0.18.0] - 2026-05-23

### Added
- Machine-to-machine REST API with Bearer token authentication (#98)
- Dedicated exit dialog with branding for employee offboarding (#97)
- Probation end date field and BUrlG §4 leave cap for probationary employees (#83)
- Employee active/inactive status filter in employee overview (#88)
- Employee CSV export with filter modal (#89)
- Flatpickr date picker for all date input fields (#87)
- Holiday highlights in team calendar

### Fixed
- Calendar CSV and iCal export edge cases; holiday fixture alignment
- Validate that Freistellung AbsenceType exists before saving company settings (#84)
- Include unexpired prior-year carryovers in exit balance calculation (#85)
- Render `probationEndsAt` field above action buttons in employee form
- Reduce help/description text size across all templates for visual consistency
- Inject Flatpickr via form theme to avoid duplicate date widget rendering
- Add `bin/.phpunit.result.cache` to `.gitignore`
---

## [0.17.1] - 2026-05-22

### Added
- User-selectable UI language (German / English) in profile settings (#93)
---

## [0.17.0] - 2026-05-22

### Added
- Daily scheduler automatically deactivates user accounts on exit date (#81, #82)
- DSGVO anonymization engine for former employees with configurable retention period
- Pro-rata entitlement calculation and employee exit workflow (Phase 13)

### Fixed
- Wrap anonymization lock call in transaction to prevent partial state
- Center anonymization confirmation dialog
---

## [0.16.0] - 2026-05-21

### Added
- Slack integration for leave request workflow notifications (#79)
- Microsoft Teams incoming webhook notifications (#77)
---

## [0.15.1] - 2026-05-21

### Security
- Encrypt LDAP bind password at rest using `sodium_crypto_secretbox` (#65, #66)
- Validate LDAP user filter on save (wildcards, injection chars, parentheses) (#65, #66)
- Throw `AuthenticationException` on decrypt failure — no silent anonymous bind (#70, #71)
- Validate-before-mutate in `setLdap()` controller, skip validation on disable (#70, #71)
---

## [0.15.0] - 2026-05-18

### Added
- Personal dashboards for employees and managers with leave balance summary (Phase 12, #68)
---

## [0.14.0] - 2026-05-17

### Added
- LDAP / Active Directory login with group-to-role mapping (Phase 11.3, #67)
- Microsoft Entra ID OAuth2 / Azure AD login (Phase 11.2, #62)
- Google OAuth2 / Google Workspace login (Phase 11.1, #61)
- Auth adapter foundation with `AuthSource` and `UserProvisioningService` (Phase 11.0, #60)

### Fixed
- Wrap each statistics dashboard section in its own card (#59)
- Remove explanatory subtext from statistics all-clear banner (#58)
- Use separate save and toggle forms for Google/Entra OAuth settings in admin
---

## [0.13.0] - 2026-05-13

### Added
- Editable company profile at `/admin/company/settings`: name, address, tax ID,
  commercial register, accent color, retention period, escalation days (Phase 10.6)
- Company logo upload (PNG/JPG/SVG, 1 MB max) with PDF letterhead rendering
- First-run setup wizard at `/setup` for initial admin and company bootstrapping
- Pre-flight system check in setup wizard (PHP version, extensions, DB, write permissions)

### Fixed
- Docker image gains `gd`, `mbstring`, `fileinfo` extensions for QR code and logo pipeline
- dompdf chroot whitelists `public/` so logo renders in PDF export
---

## [0.12.0] - 2026-05-13

### Added
- TOTP-based 2FA via `scheb/2fa-bundle` 8.5, compatible with Google Authenticator,
  Authy, 1Password, Microsoft Authenticator (Phase 10.5, #55)
- Backup codes: 10 one-time codes per user, 8-char alphabet, SHA-256 hashed in DB,
  plaintext shown once with copy + TXT download
- Admin lockout recovery via `/admin/users/{id}/2fa-reset` with CSRF confirmation
- Company-wide 2FA enforcement with configurable grace deadline and site-wide banner
---

## [0.11.0] - 2026-05-11

### Added
- Admin statistics dashboard with KPIs (utilization, illness rate, pending,
  avg remaining), Chart.js monthly and per-department bar charts, and k=3
  anonymity for team aggregates (Phase 10, #53)
- PDF export of statistics dashboard as printable A4 letterhead via dompdf
- CSV export of entitlements (semicolon-delimited, UTF-8 BOM, year filter)
- iCal subscription feeds for personal (`/ical/personal/{token}.ics`) and
  team (`/ical/team/{token}.ics`) calendars via `eluceo/ical`

### Fixed
- Fix DQL property name in `findIllnessRequestsForEmployee` (latent Phase 9 bug
  that would raise `QueryException` on the illness-alert cron job)
- Upgrade PHPUnit 12.5.21 → 12.5.22 (CVE-2026-41570 argument injection patch)
---

## [0.10.0] - 2026-05-10

### Added
- Admin leave type reclassification with audit trail and notification (#27)
- Manual entitlement overrides with full audit trail (#45, #46)
- 6-week illness alert sweep for §3 EntgFG threshold (#41, #42)
- Employee CSV import with dry-run preview (#40)
- Location-scoped `HolidayOverride` for municipality-level public holidays (#47, #48)
- Manual per-day WorkSchedule distribution UI in admin (#43, #44)
- Database-backed scheduler with admin UI, toggles, and run-state tracking (#35)
- Year-transition entitlement carry-over runs annually via scheduler (#35)
- Cron and systemd setup documentation for scheduled jobs (#35, #39)
- Manager approval history and per-employee request drilldown (#17, #18, #33)
- Edit granted hours and delete unused entitlements in admin (#32)
- Carryover source-year hint in entitlement view (#25, #34)
- Admin user list: status filter, search, and pagination (#3, #4, #30)
- Global app header on every authenticated page (#14, #29)
- Per-row delete and bulk clear-read for notifications (#26)
---

## [0.9.0] - 2026-05-03

### Added
- Full notification system: in-app notifications for all leave request state changes,
  manager assignment events, and admin broadcasts (Phase 8)
---

## [0.8.0] - 2026-05-01

### Added
- Team calendar with month/week view, colleague absence overlay, and capacity
  indicator per day (Phase 7)
---

## [0.7.0] - 2026-04-22

### Added
- Approval workflow: managers approve/reject requests with comment; automated
  state machine (Pending → Approved/Rejected/Cancelled); audit log (Phase 6)
---

## [0.6.0] - 2026-04-22

### Added
- Leave request creation with Turbo-Frame live preview showing per-day breakdown
  (weekends, public holidays, work schedule, employee active window) (Phase 5)
- Per-year balance check at request creation time
- Admin read-only leave request overview with filters
- Employee self-service cancellation for own Pending/Recorded requests
---

## [0.5.1] - 2026-04-20

### Added
- Dashboard header with admin and user dropdown menus (#11)
---

## [0.5.0] - 2026-04-20

### Added
- Absence types management (paid leave, sick leave, Freistellung, custom types)
- Annual entitlement management with pro-rata calculation and carryover
  configuration per absence type (Phase 4)
---

## [0.4.1] - 2026-04-19

### Fixed
- Test naming conventions, fixture honesty, and assertion alignment for Phase 3
  test suite
---

## [0.4.0] - 2026-04-19

### Added
- Holiday engine with German state-specific public holidays via
  `azuyalabs/yasumi` (Phase 3)
- Holiday admin CRUD with year-based filtering
- Holiday import from Yasumi presets
- WorkSchedule integration for accurate working-day counts
---

## [0.3.0] - 2026-04-18

### Added
- Employee profile management (personal data, contract details, work schedule)
- Location entity for region-specific holiday rule scoping
- WorkSchedule entity (Mon–Sun hours per day, weekly total) with admin CRUD
- Employee–Location and Employee–WorkSchedule assignment (Phase 2)
---

## [0.2.0] - 2026-04-17

### Added
- Single-tenant `Company` model with Symfony Security form login (Phase 1)
- Password reset flow via `symfonycasts/reset-password-bundle`
- Admin user CRUD with invitation flow (admins never see plaintext passwords)
- Role hierarchy: `ADMIN > MANAGER > EMPLOYEE`
- `UserChecker` for deactivated account blocking
- Bilingual UI (DE/EN) via Symfony Translation
- Fixtures: 1 Company, admin/manager/employee test accounts
- PHPStan Level 8 clean, Deptrac 0 violations baseline established
---

## [0.1.0] - 2026-04-17

### Added
- FrankenPHP-based Docker environment with MariaDB 11.4 and Mailpit (Phase 0)
- PHP 8.3/8.4 matrix CI pipeline on GitHub Actions
- Quality gate suite: PHPUnit, PHPStan Level 8, Deptrac, PHP CS-Fixer
- Makefile with targets: `make test`, `make stan`, `make cs`, `make cs-fix`,
  `make deptrac`, `make ci`
- Base Symfony 7.4 skeleton with Twig, Tailwind CSS, and Stimulus/Turbo
---

[Unreleased]: https://github.com/markus-michalski/leaveflow/compare/v1.0.3...HEAD
[0.18.0]: https://github.com/markus-michalski/leaveflow/compare/v0.17.1...v0.18.0
[0.17.1]: https://github.com/markus-michalski/leaveflow/compare/v0.17.0...v0.17.1
[0.17.0]: https://github.com/markus-michalski/leaveflow/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/markus-michalski/leaveflow/compare/v0.15.1...v0.16.0
[0.15.1]: https://github.com/markus-michalski/leaveflow/compare/v0.15.0...v0.15.1
[0.15.0]: https://github.com/markus-michalski/leaveflow/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/markus-michalski/leaveflow/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/markus-michalski/leaveflow/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/markus-michalski/leaveflow/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/markus-michalski/leaveflow/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/markus-michalski/leaveflow/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/markus-michalski/leaveflow/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/markus-michalski/leaveflow/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/markus-michalski/leaveflow/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/markus-michalski/leaveflow/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/markus-michalski/leaveflow/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/markus-michalski/leaveflow/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/markus-michalski/leaveflow/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/markus-michalski/leaveflow/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/markus-michalski/leaveflow/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/markus-michalski/leaveflow/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/markus-michalski/leaveflow/releases/tag/v0.1.0
[1.0.0]: https://github.com/markus-michalski/leaveflow/releases/tag/v1.0.0
[1.0.1]: https://github.com/markus-michalski/leaveflow/releases/tag/v1.0.1
[1.0.2]: https://github.com/markus-michalski/leaveflow/releases/tag/v1.0.2
[1.0.3]: https://github.com/markus-michalski/leaveflow/releases/tag/v1.0.3

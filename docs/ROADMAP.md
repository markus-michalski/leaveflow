# LeaveFlow — Roadmap

Modern open-source leave management for SMBs, built with Symfony 7.4.

**Strategy:** MVP first (Phases 0-6), then layered additions. Each phase is sequentially executable with a hard exit criterion.

---

## Tech Stack

- **Backend:** Symfony 7.4 LTS, PHP 8.4+
- **Frontend:** Twig + Turbo + Stimulus, Tailwind CSS + Flowbite via Asset Mapper
- **Database:** MariaDB 11 (Doctrine ORM 3)
- **Calendar:** FullCalendar.js via Stimulus controller
- **i18n:** German + English from day one
- **Testing:** PHPUnit 11 (unit + integration) + Symfony Panther (E2E, headless Chrome)
- **Static Analysis:** PHPStan Level 8, PHP-CS-Fixer, Deptrac (architecture boundaries)
- **Observability:** Monolog with structured logs

---

## Testing & Quality Philosophy

### TDD Pragmatism

**Strict TDD (Red-Green-Refactor, test-first):**
- All pure domain/business logic in `src/Service/`, `src/Calculator/`, `src/Workflow/`, `src/Domain/`
- State machines, calculators, validators
- Anything DSGVO-critical (anonymization)

**Test-after (still mandatory, just not test-first):**
- Controllers (HTTP-Kernel tests)
- Twig templates (Panther E2E for happy path + critical flows)
- Forms
- Repositories (integration test against real DB)

**Not tested:**
- Fixtures, migrations (self-evident), asset/Tailwind config, dependency injection wiring.

### Coverage Targets
- Service layer: **≥ 80%**
- Overall: **≥ 70%**
- Enforced in CI as a gate.

### Testing Stack
- **PHPUnit 11** with attributes (`#[Test]`, `#[DataProvider]`, `#[CoversClass]`)
- **Symfony Panther** for E2E with headless Chrome
- **DAMA/DoctrineTestBundle** for transactional test isolation
- **Symfony Clock (MockClock)** — never `new \DateTime('now')` in production code
- **Pcov** for coverage (faster than Xdebug)
- No Behat/Gherkin — overkill for this project scope

---

## CI/CD First-Class Citizen

### Why this gets priority in Phase 0
"Works on my machine" bugs come from environment drift (timezone, locale, DB version, permissions, async timing). We prevent them from day one rather than debugging them in week 8.

### GitHub Actions Workflow (`.github/workflows/ci.yml`)

**Matrix:** PHP 8.4 (PHP 8.3 end-of-active-support Nov 2025)
**Services:** MariaDB 11 (identical to local), Mailpit
**Env:** Timezone `Europe/Berlin`, Locale `de_DE.UTF-8`

**Sequential stages, fail-fast:**
1. `composer validate --strict`
2. `composer install --no-dev --optimize-autoloader` (prod installability check)
3. `composer install` (dev)
4. `php-cs-fixer fix --dry-run`
5. `phpstan analyse`
6. `phpunit --coverage-clover`
7. `panther` E2E (headless Chrome)
8. `deptrac` (architecture boundaries)
9. `security-checker`

### Dev/CI Parity Rules

- **`compose.yaml` is the source of truth** for service versions (DB, Mailpit), not `.env`.
- **`Makefile`** provides unified commands (`make test`, `make ci`, `make stan`) — identical locally and in CI.
- **Pre-commit hook** (lefthook) runs PHPStan + CS before each commit → fail fast before push.
- **`.env.test`** is committed with deterministic defaults.
- **Non-root user in Docker** matches CI user context → no permission surprises.

### Typical CI Pitfalls We Proactively Avoid

| Pitfall | Mitigation |
|---|---|
| Timezone differences | `date.timezone=Europe/Berlin` in php.ini, Symfony Clock everywhere |
| DB collation quirks (ORDER BY) | `utf8mb4_unicode_ci` enforced in migration + tested |
| Test order dependencies | `phpunit.xml` with `resolveDependencies="true"` + random order |
| File permissions | Docker container runs as non-root matching CI |
| Panther network timing | Explicit `WebDriver::wait`, `--headless=new` |
| Leftover DB state | `DAMA/DoctrineTestBundle` transaction rollback per test |
| Messenger async race conditions | `in-memory` transport in tests |
| Flaky SMTP | Mailpit as service, not real SMTP |
| Coverage tool drift | Pcov pinned, same version locally |

### Exit Criterion per Phase (strict, non-negotiable)

A phase is **done** only when:
1. New services have unit tests (service-layer coverage ≥ 80%)
2. Happy path + critical edge cases covered in E2E (Panther)
3. PHPStan Level 8 green
4. CI fully green on feature branch
5. PR merged with all checks passing (no direct push to `main`)

### CD Strategy (post-v1.0)

- GitHub Release workflow → Docker image to GHCR
- SemVer via conventional commits + semantic-release
- Auto-generated changelog
- No auto-deploy to production (open-source tool, self-hosters deploy themselves)

---

## MVP Phases (1-6)

### Phase 0 — Foundation & CI

**Scope:** Production-ready but empty skeleton. This phase is larger than typical setup because we frontload all quality infrastructure.

**Deliverables:**
- Tailwind CSS + Flowbite integrated via Asset Mapper
- PHPStan Level 8 config, PHP-CS-Fixer config, Rector (optional)
- PHPUnit 11 + Panther setup, DAMA bundle installed
- Pcov for coverage
- Base layout: Twig templates, navigation shell, flash messages
- i18n infrastructure: translations directory, DE + EN base files
- `compose.yaml`: MariaDB 11 + Mailpit
- `Makefile` with unified targets
- `lefthook.yml` for pre-commit hooks
- `deptrac.yaml` with layer definitions (Domain / Application / Infrastructure)
- GitHub Actions CI workflow — **must be fully green before phase exits**
- Smoke test: a trivial test that validates CI pipeline works end-to-end

**Exit criterion:** Empty project compiles, CI green, all gates configured, one smoke test passing.

---

### Phase 1 — Auth & Tenant

**Entities:**
- `Company` (name, locations as Collection, retentionPeriodMonths default 36)
- `User` (email, password, roles: `ROLE_ADMIN`, `ROLE_MANAGER`, `ROLE_EMPLOYEE`)

**Features:**
- Symfony Security with form login, logout, password reset (via Mailer)
- Admin CRUD for users
- Role-based navigation visibility
- Security fixtures for dev (admin/manager/employee test accounts)

**Tests (test-first for policy logic):**
- Role-based access control
- Password reset token lifecycle
- Email uniqueness constraints

---

### Phase 2 — Employee Profile ✅ (v0.3.0)

**Entities:**
- `Employee` (0..1 to User — nullable, optional for imports/archived ex-employees; fullName, employeeNumber unique-per-company, joinedAt, leftAt nullable)
- `Location` (country ISO-2, federalState ISO 3166-2, city — part of Company)
- `WorkSchedule` embeddable value object: weeklyHours, per-weekday hours map (auto + manual distribution, both tested)

**Features delivered:**
- `/admin/employees` CRUD with work schedule (auto-distribution UI) + location + optional user link
- `/admin/locations` CRUD
- `/profile` self-service view with graceful fallback when no employee record is linked

**Deferred (with rationale):**
- "Pre-existing approved absences" on creation → Phase 5 (LeaveRequest) — nothing to import without LeaveRequest entity
- Manual per-day schedule distribution UI → Phase 9 admin polish (VO supports it, just no form widget yet)

**Tests (strict TDD for `WorkSchedule`, Location, Employee):**
- Auto + manual distribution, sum epsilon tolerance, invalid weekday rejection, equality
- Employee ↔ User cross-company rejection, joinedAt/leftAt invariants, activeOn window

---

### Phase 3 — Holiday Engine

**Critical phase — pulled forward before calculator logic.**

**Entities:**
- `HolidayOverride` (year, federalState, name, date, type: added | removed)
- `CompanyHoliday` (company-wide non-working days)

**Services:**
- `HolidayCalculator` — pure function: `(year, federalState) → Collection<Holiday>`
- Easter-based calculation (Gauss formula) + fixed dates
- All 16 German federal states supported
- DB overrides merged at query time
- Optional: `feiertage-api.de` sync with admin confirmation

**Admin UI:**
- View holidays per state/year
- Add/remove overrides
- Manage company-wide non-working days

**Tests (strict TDD, DataProvider-heavy):**
- Fixture-based tests: known holidays 2024-2027 × 16 states
- Easter edge cases (2025: 20.4., 2027: 28.3.)
- Override application precedence
- **Starting point:** existing `~/projekte/script_collection/php/holiday.php`

**Known limitation — municipality-level holidays (deferred to Phase 9):**

Some holidays are legally tied to the municipality (Gemeinde), not the federal
state. v1 models them at state level with pragmatic defaults that match the
population majority; outliers use `HolidayOverride` to add or remove.

| Holiday | Legal scope | Our default | Workaround for outliers |
|---|---|---|---|
| Mariä Himmelfahrt (BY) | Gemeinden mit überwiegend katholischer Bevölkerung (Art. 1 Abs. 1 Nr. 2 BayFTG) — ~1.704 / 2.056 | BY-wide `on` (83% match) | Protestant-majority Gemeinden add `removed` override |
| Fronleichnam (SN) | Nur bestimmte Regionen (§ 1 Abs. 1 SächsSFG, Teile von Bautzen/Hoyerswerda/Kamenz) | SN-wide `off` (~97% match) | Catholic Gemeinden add `added` override |
| Fronleichnam (TH) | Nur bestimmte Gemeinden (§ 2 Abs. 2 ThürFGtG, Eichsfeld + Teile Unstrut-Hainich / Wartburgkreis) | TH-wide `off` | Eichsfeld-based companies add `added` override |
| Augsburger Friedensfest (BY) | Nur Stadt Augsburg (Art. 1 Abs. 1 Nr. 4b BayFTG) | not modeled | Firms with Augsburg office use `CompanyHoliday` (company-wide) until Phase 9 introduces location-scoped overrides |

Location-scoped overrides (`HolidayOverride.location_id`) land in Phase 9 along
with the other Admin-Power refinements. Until then, single-office companies
can use `CompanyHoliday`; multi-office companies accept that firm-wide entries
apply to all locations.

---

### Phase 4 — AbsenceTypes & Entitlements

**Entities:**
- `AbsenceType` (name, deductsFromLeave, requiresApproval, color hex, icon, active)
- `LeaveEntitlement` (employee, year, type: `regular` | `carryover`, hoursGranted, hoursUsed, hoursRemaining computed, expiresAt nullable)

**Fixtures — default AbsenceTypes:**
- Urlaub (deducts, requires approval)
- Resturlaub (deducts, requires approval)
- Krankheit (no deduct, no approval — eAU since 2023, no upload)
- Überstundenabbau (deducts separate balance)
- Sonderurlaub (deducts, requires approval)
- Fortbildung (no deduct, requires approval)

**Services:**
- `EntitlementConsumer` — FIFO logic: consume carryover first (expires earlier), then regular
- `YearTransitionService` — manual trigger (console command): creates carryover entitlement from remaining regular balance
- `EntitlementBalanceReader` — current state for dashboard

**Console command:**
- `app:entitlement:year-transition --year=2027 --dry-run`

**Tests (strict TDD):**
- FIFO consumption order
- Expiry date handling
- Year transition with various remaining balances
- Negative balance prevention

---

### Phase 5 — LeaveRequest + Calculator (Core Feature)

**Entities:**
- `LeaveRequest` (employee, absenceType, startDate, endDate, dayType: `full_day` | `half_day_am` | `half_day_pm`, status, requestedAt)
- `LeaveRequestDay` (derived detail: per-day hours breakdown for audit/display)

**Services:**
- `LeaveCalculator` — pure function: `(employee, absenceType, startDate, endDate, dayType) → LeaveBreakdown`
  - Iterates date range
  - Filters: weekends, holidays (based on workLocation federal state), non-working days (from WorkSchedule)
  - Computes hours per working day from WorkSchedule
  - Returns: total hours, list of working days with per-day hours, excluded days with reasons

**UI:**
- Request form with **Turbo Frame live preview**: select dates → preview updates inline showing "12 working days = 96h" + daily breakdown
- Half-day only selectable for single-day requests or start/end days
- "My leave balance" dashboard: regular + carryover entitlements with expiry warnings

**Tests (strict TDD for calculator):**
- Full-time 40h/5 days: 30 days leave = 240h
- Part-time 24h/3 days: 18 days leave = 144h
- Bridge days around holidays
- Different federal states for home vs work location
- Half-day variants
- Year boundary spanning requests

---

### Phase 6 — Approval Workflow

**Core of user experience. State machine via Symfony Workflow Component.**

**States:** `pending` → `approved` | `rejected` | `cancelled` | `cancel_requested` → `cancelled`

**Transitions:**
- `submit` (initial): pending
- `approve` (manager): pending → approved
- `reject` (manager, reason required): pending → rejected
- `cancel_direct` (employee, only if pending): pending → cancelled
- `request_cancel` (employee, if approved, future only): approved → cancel_requested
- `confirm_cancel` (manager): cancel_requested → cancelled
- `deny_cancel` (manager): cancel_requested → approved

**Services:**
- `ApproverResolver` — dept manager → fallback to dept manager's manager → manual deputy
- `ApprovalNotifier` — triggers basic email on status change

**Audit:**
- `AuditTrail` entity: every state change logged (who, when, reason if applicable)
- Viewable by admin + request owner

**Basic email notifications (Messenger async):**
- Request submitted → manager
- Approved → employee
- Rejected → employee (with reason)

**Tests (strict TDD for state machine):**
- Every transition with valid + invalid preconditions
- Permission checks per role
- Cancellation timing rules (future vs past)
- ApproverResolver fallback chain

---

### **MVP Cut** — at end of Phase 6

LeaveFlow is now usable by a real team. Everything after is enhancement.

---

## Post-MVP Phases (7-14)

### Phase 7 — Team Calendar & Capacity

- FullCalendar.js integration via Stimulus controller
- Team view: all members' absences
- Team capacity soft warning: "X people already absent this week"
- Blackout periods: hard block (admin-managed, per company)
- Filters: by team, absence type, date range

---

### Phase 8 — Full Notification System

- In-app notifications via Turbo Streams (live updates without refresh)
- Entitlement expiry warnings (30 days before)
- Approval escalation on non-response (configurable threshold → next-level manager)
- Admin type-change notifies affected employee
- Per-user notification preferences (email on/off per event type)

---

### Phase 9 — Admin Power Features ✅ (v0.10.0)

- ✅ **Admin type change with automatic recalculation** — admin reclassifies an approved request's absence type, entitlement booking rebalances under the new type, audit entry + employee notification. Mandatory reason.
- ✅ **Manual entitlement overrides (with audit)** — Admins können `hoursGranted` und Carryover-`expiresAt` ändern. Pflicht-`reason` schreibt einen `LeaveEntitlementAuditEntry` (alt/neu/Aktor/Datum). History-Panel direkt unter dem Edit-Form. (#45 / PR #46)
- ✅ **6-week illness alert** — täglicher Sweep um 06:00 erkennt 42 konsekutive Kalendertage Krankheit (§3 EntgFG), dispatcht `IllnessSixWeekAlert`-Notification an Department-Lead mit Admin-Fallback. AbsenceType bekommt `isIllnessTracking`-Flag. Idempotenz via `illness_alerts`-Tabelle. Job über `/admin/scheduled-jobs` toggelbar. (#41 / PR #42)
- ✅ **CSV import** — Two-step Upload-Preview-Commit-Flow für Mitarbeiter mit downloadbarer Vorlage. Per-Row-Validation, Re-Validate vor Commit (race-tolerant), Pflicht-Spalten + Optional-Fields. Akzeptiert ISO und deutsche Datumsformate plus Komma-Dezimal. (PR #40)
- ✅ **Location-scoped `HolidayOverride`** — optional `location_id` auf `HolidayOverride`. Neuer Service-Einstieg `HolidayService::getHolidaysForEmployee(Employee, year)` löst die Phase-3-Limitierungen rund um Augsburger Friedensfest, Fronleichnam in Eichsfeld/Bautzen, Mariä Himmelfahrt in protestantischen BY-Gemeinden. (#47 / PR #48)
- ✅ **Manual per-day WorkSchedule distribution UI** — Admin-Form bekommt Modus-Switch (Auto / Manuell). Manuell-Modus zeigt 7 Stunden-Inputs (Mo-So) mit Live-Sum-Indikator. Edit erkennt aus dem gespeicherten Schedule, ob ein Mitarbeiter ungleichmäßig verteilt ist. (#43 / PR #44)
- ✅ **Admin-User-List: filter + search + pagination** — Status-Filter, Email/Name-Suche, paginierte Liste. (#3, #4)

**Exit:** v0.10.0 (2026-05-10)

---

### Phase 10 — Reports & Export ✅ (v0.11.0)

- ✅ **Admin statistics dashboard** — anonymized KPIs (Urlaubs-Auslastung,
  Krankenquote, offene Anträge, Ø verfügbarer Urlaub) plus an action-briefing
  section ("Handlungsbedarf": Carryover-Verfallsrisiko, liegende Anträge),
  "Aktuell abwesend"-Karte mit Drill-down zum Team-Kalender, Monats- und
  Department-Charts via Chart.js, k=3 anonymity threshold for per-team
  aggregates. Admins land on `/admin/statistics` after login instead of the
  generic personal dashboard. (#50)
- ✅ **CSV + PDF export** — Statistik-Dashboard als druckbares A4-PDF mit
  KPI-Tabelle, Monats-Text-Zusammenfassung (Spitzenmonat, stärkstes Quartal,
  leere Monate, Gesamtstunden) und Department-Aufstellung via `dompdf`.
  Urlaubskonten als semicolon-CSV mit UTF-8 BOM, Year-Filter, deutscher
  Dezimalkomma — Excel-/Numbers-tauglich. Drive-by: phpunit 12.5.21 →
  12.5.22 (CVE-2026-41570). (#51)
- ✅ **iCal subscription feeds** — `/ical/personal/{token}.ics` (eigene
  approved + recorded Abwesenheiten) und `/ical/team/{token}.ics` (Department
  approved-only, name-prefixed Summary) via `eluceo/ical`. Token-basiert
  weil Calendar-Apps keine Session-Cookies senden, 64-char hex Token im
  User lazy-generiert beim ersten Profile-View, Reset-Action mit CSRF +
  Confirm. 404 für jeden Failure-Mode (Token-Enumeration-resistent).
  Range −3M..+12M relativ zu heute. (#52)

**Exit:** v0.11.0 (2026-05-11)

---

### Phase 10.5 — Local-Auth Hardening ✅ (v0.12.0)

Inserted between Phase 10 and Phase 11 to close the local-auth gap
before OAuth adapters land — tenants on local auth get 2FA
immediately instead of having to wait for an IdP migration.

- ✅ **TOTP-based 2FA** via `scheb/2fa-bundle` + `scheb/2fa-totp`.
  Server-side QR rendering (SVG, no client-side library), manual
  base32 fallback. Compatible with Google Authenticator, Authy,
  1Password, Microsoft Authenticator. Candidate secret lives in
  the session until confirmation so abandoned setup doesn't leave
  half-configured users.
- ✅ **Backup codes** — 10 one-time codes per user, 8-char from a
  32-symbol unambiguous alphabet, SHA-256 hashed in DB. Plaintext
  shown once with copy + TXT-download. Regenerate flow gated by
  current TOTP code.
- ✅ **Admin lockout recovery** — `/admin/users/{id}/2fa-reset`
  (CSRF + confirm). Drops the user's secret + codes so they
  re-enroll on next sign-in.
- ✅ **Company-wide enforcement** — `/admin/company/settings` toggle
  with future-deadline grace period. Soft banner site-wide during
  grace, EnforcementSubscriber redirects to setup once the deadline
  passes. Allow-listed paths keep `/profile/2fa`, `/login`, `/logout`,
  `/2fa`, `/_profiler`, `/assets` reachable so locked-out users can
  actually fulfill the requirement.

Drive-by fixes during the slice: backup-code download had literal
"%0A" instead of newlines (Twig single-quote-vs-double-quote in
join), copy-to-clipboard controller refactored to use proper
Stimulus targets, `Company::isTwoFactorEnforced` accepts any
`DateTimeInterface` (Twig's `date()` returns mutable).

**Exit:** v0.12.0 (2026-05-13)

---

### Phase 10.6 — Company Profile & Onboarding ✅ (v0.13.0)

The Company entity existed since Phase 1 but had no UI; production
installs had to seed it via SQL. Inserted here to close that gap
before the OAuth adapters in Phase 11 land — those need a configured
tenant to bind sessions to.

- ✅ **Editable company profile** — `/admin/company/settings` rebuilt
  around a `CompanyProfileFormType` with name, multi-line address,
  tax ID, commercial register, accent color, retention period,
  approval escalation days. New columns: `address`, `logo_path`,
  `primary_color`, `tax_id`, `commercial_register`. Setters
  normalize blank-as-null and validate `#RGB` / `#RRGGBB` for the
  color.
- ✅ **Logo upload** — PNG/JPG/SVG up to 1 MB. Sluggified +
  random-suffixed filename so re-uploads don't overwrite, previous
  file deleted on replace. Stored under `public/uploads/company/`
  (now gitignored). Rendered in a letterhead at the top of the
  statistics PDF: logo left, name + address + tax ID + commercial
  register right.
- ✅ **First-run setup wizard** — `FirstRunSubscriber` on
  `kernel.request` redirects every route to `/setup` while no
  Company row exists; cached lookup so configured tenants pay zero
  overhead. `/setup` collects company name + first admin (email +
  password ≥ 12 chars + confirm) in one transaction, then bounces
  the user to `/login`. Strictly one-shot — once a Company exists
  the wizard redirects away.
- ✅ **Pre-flight system check** — before the wizard form unlocks,
  six checks gate the form (PHP ≥ 8.4, required extensions,
  database connection, migrations current, `var/` + `public/uploads/`
  writable). Each failed check shows a copy-pasteable remedy
  (e.g. `apt-get install php-gd`). Posts that bypass the disabled
  attribute are bounced.
- ✅ **Drive-by**: Docker image gained `gd`, `mbstring`, `fileinfo`
  (needed by endroid/qr-code from Phase 10.5 and the new logo
  upload pipeline). dompdf `chroot` whitelists `public/` so the
  logo image actually renders. `/public/uploads/` is now in
  `.gitignore` with a `.gitkeep` anchor.

**Exit:** v0.13.0 (2026-05-13)

---

### Phase 11 — Auth Adapters

Delivered as four slices so each is independently testable and mergeable.
Foundation audit (2026-05-14) confirmed: `User.password` is nullable, `UserChecker` is auth-source-agnostic, the Doctrine entity provider is a plain sibling — no monolithic blocker. Four debt items must land in Phase 11.0 before any IdP work starts.

#### Phase 11.0 — Foundation Prep

- **`authSource` enum column on `User`** (`local` | `ldap` | `google` | `entra`) — migration defaults existing rows to `local`. Drives every conditional below.
- **`externalId` nullable string column** on `User` + unique composite index `(auth_source, external_id)` — for IdP `sub` / `objectGuid`, survives email changes.
- **`UserProvisioningService`** — extract `new User(...)` + persist logic out of `AdminUserController` and `SetupController` into a single service with `provisionLocal()` and `provisionFromIdpClaims()`. JIT-provisioning from OAuth/LDAP builds on top.
- **Gate reset-password and local login on `authSource === local`** — `ResetPasswordController` must not mail tokens to SSO-only users; a `LocalLoginAuthenticator` (or `UserChecker::checkPreAuth` branch) must refuse password auth for non-local accounts.
- **2FA-vs-IdP-MFA policy decision** (document in ROADMAP + enforce in code): bypass `scheb/2fa` for `google` / `entra` (IdP enforces MFA), keep for `ldap` and `local`.

**Exit:** migration + tests green, existing local-auth smoke tests unchanged, PHPStan L8, Deptrac 0.

#### Phase 11.1 — OAuth2: Google Workspace ✅

- ✅ `knpuniversity/oauth2-client-bundle` + `league/oauth2-google`
- ✅ `GoogleUserResolver` (Application layer) — JIT-provisioning, `hd`-claim validation, email-conflict guard
- ✅ `UserProvisioningServiceInterface` extracted so `GoogleUserResolver` is unit-testable
- ✅ `GoogleAuthenticator` (Infrastructure) — OIDC flow, delegates resolution to `GoogleUserResolver`
- ✅ `GoogleAuthController` — `/connect/google` + `/connect/google/check`
- ✅ `Company.googleOAuthEnabled` + `Company.googleOAuthHostedDomain` — admin toggle + hd restriction
- ✅ Admin UI in `/admin/company/settings` — enable/disable Google login + hosted domain field
- ✅ Login page — conditional "Mit Google anmelden" button (shown only when enabled)
- ✅ `authSource=google` users bypass 2FA (via existing `skipsTwoFactor()` from Phase 11.0)
- ✅ 8 unit tests for `GoogleUserResolver` — all green, no PHPUnit notices
- ✅ PHPStan L8, Deptrac 0, CS clean, 945 total tests

**Exit:** dev-environment login with a real Google account works; existing local-auth tests unchanged.

#### Phase 11.2 — OAuth2: Microsoft Entra ID

- `TheNetworg/oauth2-azure` or `thenetworg/oauth2-azure` — same OIDC shape as Google, adds `tenant_id` config
- `EntraAuthenticator` — identical pattern to `GoogleAuthenticator`, `authSource=entra`
- Admin UI toggle + Tenant ID field

**Exit:** same criteria as 11.1 with Entra credentials.

#### Phase 11.3 — LDAP / Active Directory

- `symfony/ldap` + `security.yaml` LDAP provider + a custom `LdapUserProvisioner` (group → `UserRole` mapping)
- Bind credentials via env (`LDAP_HOST`, `LDAP_DN`, `LDAP_PASSWORD`)
- Admin UI: LDAP config fields per company (host, base DN, user filter, group-to-role map)
- `authSource=ldap` users keep 2FA (corporate AD typically has no MFA)
- Test against OpenLDAP Docker image in CI (`osixia/openldap`)

**Exit:** CI passes against the OpenLDAP fixture container; PHPStan L8, Deptrac 0.

**Implementation order rationale:** Google first (pure cloud, dev-testable without infra), Entra second (same OIDC shape, cheap), LDAP last (on-prem dependency worst dev-loop — must ride on solid abstractions, not drive them). Target audience for LDAP is German SMBs with on-prem AD; it lands on proven ground.

---

### Phase 12 — Chat Integrations

- Slack bot (OAuth app): `/urlaub request`, `/team-abwesend`, approval actions inline
- Microsoft Teams connector (adaptive cards)

---

### Phase 13 — DSGVO Lifecycle

- `retentionPeriodMonths` per company (default 36)
- Monthly cronjob: check for due anonymizations
- Anonymization routine: name → "Ehemaliger Mitarbeiter #ID", email removed, absence data retained for statistics
- 30-day advance warning to admin before anonymization runs
- Employee exit workflow: immediate account deactivation, anonymization after retention period
- **Mid-year entry/exit handling:** prorated entitlement on entry, on exit configurable per company: pay out | mandatory consumption | "Freistellung" (own absence type)

---

### Phase 14 — REST API & v1.0 Release

- REST API via API Platform OR handrolled with JWT authentication
- OpenAPI spec generated + published
- Official Docker image on GHCR
- README, CONTRIBUTING.md, LICENSE check
- Semantic versioning infrastructure
- v1.0 tag + release announcement

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Scope creep (HR tools grow uncontrolled) | Every post-MVP feature must pass the "would a 10-person SMB actually use this?" test |
| Holiday logic complexity per federal state | Phase 3 prioritized + heavy DataProvider tests + existing `holiday.php` as starting point |
| Auth adapter abstraction gets messy | Defer multi-provider to Phase 11, but lay clean `UserProviderInterface` foundation in Phase 1 |
| DSGVO anonymization must be watertight | Phase 13 isolated, comprehensive tests, admin confirmation gates |
| Multi-tenancy pressure | Explicitly deferred to v2 — single-tenant is the v1 contract |

---

## Market Context

Existing alternatives are inadequate:
- **TimeOff.Management** (Node.js, ~1000 stars): outdated, poor UX, no modern frontend
- **Jorani** (legacy PHP): dead since 2018
- **No modern Symfony project exists** — this is the gap LeaveFlow fills

Target users: German SMBs and startups needing DSGVO-compliant, self-hosted leave management with proper German labor law support (federal state holidays, eAU, Bundesurlaubsgesetz).

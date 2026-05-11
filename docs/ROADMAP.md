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

### Phase 11 — Auth Adapters

- LDAP/Active Directory adapter
- OAuth2: Google Workspace
- OAuth2: Microsoft Entra ID (formerly Azure AD)
- Clean abstraction via `UserProviderInterface` — architectural foundation must be laid in Phase 1

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

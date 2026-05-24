# Contributing to LeaveFlow

Thank you for your interest. This document explains how to contribute and what to expect.

## Governance Model

LeaveFlow follows a **Benevolent Dictator For Life (BDFL)** model. [Markus Michalski](https://github.com/markus-michalski) is the sole maintainer with final say on all changes, direction, and releases. Contributions are welcome within that structure.

## License & CLA

**Important — read this before opening a PR:**

1. This project is licensed under the **[PolyForm Noncommercial License 1.0.0](LICENSE.md)**. It is source-available but **not** OSI Open Source. Commercial use is prohibited.
2. All contributors must sign the **[Contributor License Agreement (CLA)](CLA.md)** before their PR can be merged. The [cla-assistant.io](https://cla-assistant.io/) bot will comment on your PR with a one-click signing link.

Why a CLA? The CLA grants the maintainer the rights needed to keep the project viable (relicensing flexibility, legal protection). Without it, your contribution cannot be accepted.

## Branch Model

Single-branch model:

- **`main`** — the only long-lived branch. Always in a releasable state.
- **Feature branches** — short-lived, branched from `main`, merged via squash PR.

Branch naming:

- `feat/description` — new features
- `fix/description` — bug fixes
- `docs/description` — documentation
- `chore/description` — maintenance
- `refactor/description` — refactoring

## Development Workflow

### 1. Fork and Clone

```bash
git clone https://github.com/YOUR-USERNAME/leaveflow.git
cd leaveflow
git remote add upstream https://github.com/markus-michalski/leaveflow.git
```

### 2. Create a Feature Branch

```bash
git checkout main
git pull upstream main
git checkout -b feat/your-feature-name
```

### 3. Set Up Local Development

```bash
# Install PHP dependencies
composer install

# Copy environment file
cp .env .env.local

# Start Docker services
docker compose up -d

# Create database and run migrations
make db-reset

# Install pre-commit hooks
make install-hooks
```

Requires: PHP 8.4+, Composer 2, Docker, Make.

### 4. Make Your Changes

Follow existing code patterns. Key conventions:

- **New feature**: Create in `src/` following the feature-based structure. Controller max 200 lines. Business logic in `Service/Command/` or `Service/Query/`. Write tests first.
- **New entity**: PHP 8 Attributes only (no annotations). Create migration with `make console CMD="make:migration"`.
- **New form type**: Place in `src/Form/`. Never in Controller.
- **New voter**: Place in `src/Security/Voter/`.
- **Frontend**: Twig + Stimulus + Tailwind/Flowbite. No raw JS files, use Stimulus controllers.

#### Architecture rules (enforced by Deptrac)

```
Domain → Application → Infrastructure
```

Backward dependencies fail CI. Run `make deptrac` before pushing.

### 5. Test Locally

```bash
# Run the full CI suite (same as GitHub Actions)
make ci

# Individual checks
make test          # PHPUnit (unit + integration)
make stan          # PHPStan Level 8
make cs            # PHP-CS-Fixer (dry-run)
make cs-fix        # PHP-CS-Fixer (apply)
make deptrac       # Layer dependency check
```

All checks must pass before opening a PR.

### 6. Commit with Conventional Commits

Format: `<type>(<scope>): <subject>`

| Type | Version Bump |
|------|--------------|
| `feat:` | MINOR |
| `fix:` | PATCH |
| `feat!:` or `BREAKING CHANGE:` | MAJOR |
| `docs:`, `chore:`, `refactor:`, `test:` | None |

Examples:

```
feat(leave-request): add half-day leave support
fix(calculator): correct federal-state holiday lookup for Bavaria
docs(api): add OpenAPI spec for leave endpoints
chore(deps): bump phpstan/phpstan to 2.1
```

When working with Claude Code, include the co-author line:

```
Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
```

### 7. Push and Open a PR

```bash
git push -u origin feat/your-feature-name
```

Open a PR via the GitHub UI or `gh pr create --base main`.

The PR template will guide you through the checklist. The CLA bot will comment with a signing link on first contribution.

## PR Review Process

1. **Automated checks** must pass (PHPUnit, PHPStan, CS-Fixer, Deptrac, CLA signed)
2. **Maintainer review** — @markus-michalski reviews every PR personally. Expect feedback cycles.
3. **Squash merge** — all PRs are squash-merged into `main` with a Conventional Commit message
4. **Release** — the maintainer batches features into releases and cuts version tags

## Release Process (maintainer only)

Releases are managed by the maintainer via the release script. Contributors do not need to bump versions or edit the changelog — that is handled at release time.

1. Commit: `chore: release vX.Y.Z`
2. Tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z" && git push --tags`
3. Create GitHub Release from the tag

## Code Style

- **PHP**: PHP 8.4+, strict types in every file, PHP-CS-Fixer (PSR-12), PHPStan Level 8. English code comments.
- **Twig**: Component-based templates. No business logic in templates.
- **Tailwind/Flowbite**: Utility classes, no inline styles.
- **Translations**: German user-facing strings via `translations/messages.de.yaml`.
- **No emoji** in code, comments, or commit messages.
- **No annotations** — PHP 8 Attributes only (`#[Route(...)]`, `#[ORM\Entity]`, `#[Test]`).

## What Does NOT Belong in a PR

- Data exports, user-specific configuration, or `.env.local` files
- API keys, tokens, or any secrets (see [SECURITY.md](.github/SECURITY.md))
- Commented-out code without justification
- Unrelated refactoring bundled with a feature change
- Changes to `LICENSE.md`, `CLA.md`, or `.github/CODEOWNERS` (maintainer-only)

## Questions

- **Bug reports** → Open a Bug Report issue
- **Feature ideas** → Open a Feature Request issue
- **Security issues** → [Private Vulnerability Reporting](https://github.com/markus-michalski/leaveflow/security/advisories/new)

## Code of Conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

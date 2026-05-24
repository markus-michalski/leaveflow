## Description

<!-- Brief description of the change and the problem it solves. -->

## Type of Change

- [ ] feat: New feature (minor version bump)
- [ ] fix: Bug fix (patch version bump)
- [ ] docs: Documentation only
- [ ] chore: Maintenance (no version bump)
- [ ] refactor: Code refactoring without behavior change
- [ ] BREAKING CHANGE (major version bump)

## Checklist

### Before Submitting

- [ ] I have read the [CONTRIBUTING.md](../CONTRIBUTING.md) guidelines
- [ ] I have signed the [Contributor License Agreement (CLA)](../CLA.md) via cla-assistant
- [ ] My commits follow [Conventional Commits](https://conventionalcommits.org/)
- [ ] I have disclosed any AI assistance used (e.g. `Co-Authored-By: Claude <noreply@anthropic.com>`) where applicable

### Quality Gates (all must pass)

- [ ] `make test` — PHPUnit green
- [ ] `make stan` — PHPStan Level 8 clean
- [ ] `make cs` — PHP-CS-Fixer reports no violations
- [ ] `make deptrac` — no layer dependency violations

### Documentation

- [ ] I have updated relevant docs in `docs/` if behavior changed
- [ ] I have updated translations in `translations/` if new strings were added

### Testing

**Automated CI checks (run automatically):**
- PHPUnit (unit + integration)
- PHPStan Level 8
- PHP-CS-Fixer
- Deptrac layer check
- Symfony smoke tests (lint:container, doctrine:schema:validate)

**Manual testing performed:**
<!-- Describe what you tested manually, if applicable. -->

## Related Issues

Closes #<!-- issue number -->

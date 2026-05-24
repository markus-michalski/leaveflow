# Security Policy

## Reporting a Vulnerability

**Do not open public issues for security vulnerabilities.**

Use GitHub's [Private Vulnerability Reporting](https://github.com/markus-michalski/leaveflow/security/advisories/new) feature to report security issues privately. You will receive an acknowledgement within 72 hours.

For non-sensitive security questions, open a regular issue.

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | :white_check_mark: |
| < 0.18  | :x:                |

Security fixes are released as patch versions on the latest minor.

## Code Review Requirements

### Workflow Files (`.github/workflows/*`)

**Workflow files execute code in CI/CD and require strict security review.**

Requirements for workflow changes:
- Must be reviewed and approved by @markus-michalski
- Manual security audit required (no automated approval)
- Changes must be explained in the PR description
- Do not include executable code in PR descriptions or issue comments

Security considerations:
- Workflows run with `GITHUB_TOKEN` access
- Can read repository contents
- Can create releases and tags
- Can access repository secrets (if configured)

### AI-Assisted Development

This project uses Claude Code (AI pair programming). When contributing:

**DO:**
- Review all code changes carefully before submitting
- Use Conventional Commits format
- Follow existing code patterns
- Test changes locally before opening a PR (`make ci`)

**DO NOT:**
- Include prompts or instructions for AI in code comments or docstrings
- Attempt to manipulate AI review via PR descriptions
- Include executable commands or scripts in PR descriptions
- Assume AI-reviewed code is automatically safe

## Secrets and Credentials

**Never commit secrets to this repository.** This includes:
- API keys or tokens (GitHub PATs, Slack tokens, etc.)
- Private keys (`.pem`, `.key` files)
- Credentials (passwords, database URLs with credentials)
- Environment files (`.env.local`, `.env.test.local`)

Application configuration with secrets belongs in `.env.local` — outside version control.

If you accidentally commit a secret:
1. Immediately revoke or rotate the credential
2. Contact the maintainer via Private Vulnerability Reporting
3. Do NOT just delete the commit — it remains in git history

## Dependencies

PHP dependencies are managed via Composer. Dependabot scans weekly and opens PRs for updates. When adding dependencies:
- Prefer well-maintained, actively developed packages
- Check for known vulnerabilities (`composer audit`)
- Pin to compatible version ranges in `composer.json`

## Attribution

This project uses AI assistance (Claude Code) for development. Commits may include the co-author line:
```
Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
```

This is for transparency. All AI-generated code is reviewed by the maintainer before merging.

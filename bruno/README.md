# LeaveFlow REST API — Bruno Collection

API test collection for the LeaveFlow REST API (Phase 14).

## Setup

1. Install [Bruno](https://www.usebruno.com/) (free, open source)
2. Open Bruno → **Open Collection** → select the `bruno/` folder
3. Select the **local** environment
4. Create `bruno/.env` with your API token:
   ```
   apiToken=your-64-char-hex-token-here
   ```
   Generate a token at `/admin/api-tokens` (Admin → System → API-Zugriffstoken).

The `.env` file is gitignored — your token stays local.

## Requests

| # | Name | Method | Endpoint |
|---|------|--------|----------|
| 1 | List Employees | GET | `/api/v1/employees` |
| 2 | Get Employee | GET | `/api/v1/employees/{id}` |
| 3 | Create Employee | POST | `/api/v1/employees` |
| 4 | Update Employee | PATCH | `/api/v1/employees/{id}` |
| 5 | Deactivate Employee | DELETE | `/api/v1/employees/{id}?exitDate=YYYY-MM-DD` |
| 6 | No Auth → 401 | GET | `/api/v1/employees` |
| 7 | Admin role blocked → 422 | POST | `/api/v1/employees` |
| 8 | Invalid token → 401 | GET | `/api/v1/employees` |

## Variables

| Variable | Description | Set in |
|----------|-------------|--------|
| `baseUrl` | Base URL of your instance | `environments/local.bru` |
| `employeeId` | Employee ID for single-resource requests | `environments/local.bru` |
| `apiToken` | Bearer token (secret) | `bruno/.env` (gitignored) |

## Interactive docs

Swagger UI is available at `{{baseUrl}}/api/doc` (no auth required).

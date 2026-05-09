# Deployment

Production-deployment notes for LeaveFlow. Companion to `docs/ROADMAP.md` (which tracks features) and `CLAUDE.md` (local dev). This doc covers the ops-side concerns that don't fit either: scheduled jobs, env vars, observability.

## Scheduled jobs (cron / systemd)

LeaveFlow uses [Symfony Scheduler](https://symfony.com/doc/current/scheduler.html) for time-driven background work. Three jobs ship today:

| Name | Cadence | Purpose |
|---|---|---|
| `year-transition` | `0 1 1 1 *` (Jan 1, 01:00) | Rolls last year's remaining Regular balance into Carryover entries |
| `entitlement-expiry-check` | `0 3 * * *` (daily 03:00) | Notifies employees about Carryover entries expiring within 30 days |
| `approval-escalation-check` | `0 * * * *` (hourly) | Notifies admins about Pending requests past their company's escalation threshold |

Each job has a row in `scheduled_job_configs` (DB-backed toggle + last-run state). Admins control the on/off state via:
- `/admin/scheduled-jobs` (UI)
- `bin/console app:scheduled-job:toggle list|on|off <name>` (CLI bridge)

### Two messenger transports

The schedules are split across two messenger transports so a stuck notification queue can't delay the year-end work:

- `scheduler_notifications` — drives the daily expiry sweep + hourly escalation sweep. High-frequency, user-facing.
- `scheduler_maintenance` — drives the annual year-transition. Low-frequency, mutates entitlement state.

In production you run **two separate workers**, one per transport. Both must be alive for the schedule to fire. If either dies the missed ticks are lost (the scheduler doesn't backfill across downtime — that's a Symfony Scheduler limitation, not ours).

### Option A — crontab (simplest)

One crontab entry per transport. Symfony's `messenger:consume` blocks; `--time-limit=60` makes it exit after a minute, then cron starts a fresh worker. The blocking consumer reads schedule ticks within its window, so a 60-second cycle ensures hourly + daily jobs fire on time.

```cron
# m h dom mon dow command
* * * * * cd /var/www/leaveflow && php bin/console messenger:consume scheduler_notifications --time-limit=60 --memory-limit=128M --quiet
* * * * * cd /var/www/leaveflow && php bin/console messenger:consume scheduler_maintenance --time-limit=60 --memory-limit=128M --quiet
```

A working example lives at `docs/examples/crontab.example`.

**Why `--time-limit=60`?** Without it the worker runs forever and a memory leak (yours or in a dependency) will eventually OOM. The 60-second cycle cost-effectively recycles the process while keeping the scheduler responsive.

**Caveats:**
- `cron` runs commands as the user that owns the crontab. Make sure that user can read your `.env` and write logs.
- Stderr from `messenger:consume` goes nowhere by default. Pipe to syslog or a logfile (`>> /var/log/leaveflow/messenger.log 2>&1`) if you want forensics.

### Option B — systemd timer (recommended for prod)

systemd gives you proper service lifecycle, restart-on-failure, log integration via journald, and per-service resource limits. Slightly more setup, much better operability than cron.

Two units per worker: a `.service` defining the process and a `.timer` triggering it every minute.

```ini
# /etc/systemd/system/leaveflow-scheduler-notifications.service
[Unit]
Description=LeaveFlow scheduler worker (notifications)
After=mariadb.service

[Service]
Type=oneshot
WorkingDirectory=/var/www/leaveflow
ExecStart=/usr/bin/php bin/console messenger:consume scheduler_notifications --time-limit=60 --memory-limit=128M
User=www-data
Group=www-data

# Hardening
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
NoNewPrivileges=true
ReadWritePaths=/var/www/leaveflow/var
```

```ini
# /etc/systemd/system/leaveflow-scheduler-notifications.timer
[Unit]
Description=Triggers LeaveFlow notifications worker every minute

[Timer]
OnCalendar=*:*:00
Unit=leaveflow-scheduler-notifications.service
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
```

Same pair for `scheduler_maintenance`. Activate:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now leaveflow-scheduler-notifications.timer
sudo systemctl enable --now leaveflow-scheduler-maintenance.timer
```

Examples live at `docs/examples/systemd/`.

**Inspect:**
```bash
systemctl list-timers leaveflow-*           # schedule overview
systemctl status leaveflow-scheduler-*      # last invocation result
journalctl -u leaveflow-scheduler-notifications.service --since '1 hour ago'
```

**Persistent=true** makes systemd replay missed minute-ticks after the host comes back online — bridges short outages cleanly. For longer downtime spanning the year-transition window (Jan 1, 01:00) you'll still need the manual `app:entitlement:year-transition --year=N` command, because Symfony Scheduler doesn't backfill cron expressions across downtime. Cron has no equivalent recovery at all — that's another reason to prefer systemd in production.

### Verifying the setup

1. Make sure both workers can connect to the database:
   ```bash
   sudo -u www-data php bin/console doctrine:migrations:status
   ```
2. Trigger a manual run for the year-transition (without waiting until January):
   ```bash
   sudo -u www-data php bin/console app:entitlement:year-transition --year=2025 --dry-run
   ```
3. Open `/admin/scheduled-jobs` after a worker has tick'd through at least one cycle. The "Last run" column should populate.

### Monitoring

For now the `/admin/scheduled-jobs` page is the source of truth — it surfaces last-run timestamp, status (Success/Failure/Skipped) and the last error message per job. Admins should bookmark it.

**Future** (not shipped): expose a `/health/scheduled-jobs` JSON endpoint for external uptime monitoring. Open issue if you need it.

**Failure alerting** (not shipped): "send admin email when job fails N runs in a row." Open a separate issue with concrete rules — what counts as "in a row" depends on cadence.

## Environment variables

See `.env.example` for the canonical list. The non-obvious ones:

- `MESSENGER_TRANSPORT_DSN` — points async messenger work (mail dispatch, etc.) at the right transport. Use `doctrine://default` for SMB-scale deployments; switch to AMQP if you have a real broker.
- `MAILER_DSN` — production should use a real SMTP relay. Local dev is wired to Mailpit on `localhost:1025` via `compose.yaml`.
- `APP_SECRET` — must be unique per deployment. Symfony uses it for CSRF tokens and signing.

## Database

Single MariaDB instance. v1 is single-tenant, so one schema per company. Migrations are tracked in `migrations/` and applied via `php bin/console doctrine:migrations:migrate`.

**Backup:** outside this doc's scope — your hosting setup decides. The application doesn't ship a backup scheduler; treat the DB like any other Symfony app.

## Logs

Symfony writes to `var/log/<env>.log`. The scheduler workers also log per-job run summaries (created/skipped counts) at INFO level so a single `grep` shows the year-end report.

Recommend log rotation via `logrotate.d`:

```
/var/www/leaveflow/var/log/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

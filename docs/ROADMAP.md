# Post-port roadmap

Status input: the port is screen-complete (see docs/COVERAGE_AUDIT.md);
full suite 226 passed / 1 skipped, 3,297 assertions; deployment guide
(docs/DEPLOYMENT.md) already covers install, web server, production
settings, caches, scheduled tasks and rollback. Ordered by value.

## Phase 11 — admin master completion (the three audit gaps)

All three gaps are settled (slices 1–2 built, 3 an evidence-backed
skip) — Phase 11 is complete.

1. **User CRUD** ✅ done (Phase 11 slice 1): create/edit accounts with
   the legacy vocabulary — role, security Q&A, duplicate login/email
   checks (add_operator/add_indentrole.php), Active/Suspend already
   present. The admin can onboard without DB access.
2. **Company profile editor** ✅ done (Phase 11 slice 2): single-row
   form over `company_settings` (plant code feeds QR serials; address/
   licence feed printed docs), plus the `plantcode` column fix.
3. **Country/state masters** ✅ settled as an explicit skip (evidence
   in docs/COVERAGE_AUDIT.md): the legacy screens were menu-orphaned,
   the data near-empty (1 country / 0 states) and no consumer depends
   on the tables; the port's party form already covers the loose
   string behavior. If dropdowns are ever wanted, seed
   `countries`/`states` and switch the party-form inputs.

## Hardening

- **Password bootstrap** ✅ `php artisan admin:bootstrap` creates or
  resets an admin account (interactive prompts, or `--login` +
  `--password` for scripts) so a freshly migrated DB is loggable-into
  without hand-written SQL.
- **Dependency audit**: `composer audit` currently reports advisories
  on laravel/framework (pre-existing); track and bump when fixes land.
- **Session/HTTPS**: force HTTPS at the web server, review session
  lifetime and `secure`/`httponly` cookie flags for production.
- **Failed jobs**: `failed_jobs` table exists but nothing queues yet —
  decide either "no queues" (drop the table usage) or add a worker +
  Task Scheduler entry.

## Operations

- **Automated off-site backup** ✅ `db:backup` (scheduled 02:00 in
  routes/console.php behind the Task Scheduler heartbeat): `mysqldump`
  of BOTH databases into `BACKUP_DIR`, gzip + SHA-256 per file,
  N-day retention pruning after a fully successful run. Set
  `BACKUP_DIR` to the second disk/network share in production.
- **Health check endpoint + log rotation** ✅ the `/up` probe (registered
  in bootstrap/app.php, substance in App\Support\HealthChecks wired via
  the DiagnosingHealth event) verifies the DB connection AND the active
  fiscal year — a broken DB or an unset year answers 500, ready for an
  external uptime monitor. Logs rotate daily (config/logging.php: stack
  → daily, LOG_DAILY_DAYS retention, default 14).
- **Data-quality pass**: run `legacy:integrity-fix --strategy=null` for
  the dangling e_indent_items references surfaced by the dev-DB import,
  and wire `legacy:verify` row-parity into the cutover checklist.

## Testing / CI

- **CI pipeline** ✅ GitHub Actions (.github/workflows/tests.yml): Pint
  then the full suite on every push/PR, against a MariaDB service with
  the legacy fixture (encrypted in database/fixtures, decrypted via the
  FIXTURE_KEY secret) and the hermetic pipeline self-healing the test
  DB on a cold runner.
- **Browser smoke checklist**: a short scripted pass over each role's
  dashboard (the 8090 verification run is the template).

## Cutover

- **Cutover runbook** ✅ docs/CUTOVER.md: the day-of steps — rehearsal
  (T-7), freeze + final legacy backup, `stage-import → migrate-data →
  integrity-fix → add-constraints`, the `legacy:verify` and
  `phase1:smoke` gates, `admin:bootstrap`, go-live, parallel-run week,
  and the rollback paths (legacy untouched and reversible until users
  start writing to the port).
- **Training + parallel-run week**: operators double-entry key
  transactions; reconcile stock ledger + party ledger daily.

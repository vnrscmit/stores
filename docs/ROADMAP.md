# Post-port roadmap

Status input: the port is screen-complete (see docs/COVERAGE_AUDIT.md);
full suite 226 passed / 1 skipped, 3,297 assertions; deployment guide
(docs/DEPLOYMENT.md) already covers install, web server, production
settings, caches, scheduled tasks and rollback. Ordered by value.

## Phase 11 — admin master completion (the three audit gaps)

1. **User CRUD** ✅ done (Phase 11 slice 1): create/edit accounts with
   the legacy vocabulary — role, security Q&A, duplicate login/email
   checks (add_operator/add_indentrole.php), Active/Suspend already
   present. The admin can onboard without DB access.
2. **Company profile editor** ✅ done (Phase 11 slice 2): single-row
   form over `company_settings` (plant code feeds QR serials; address/
   licence feed printed docs), plus the `plantcode` column fix.
3. **Country/state masters** or an explicit documented skip: tables
   exist, legacy screens were bare-bones and near-unused.

## Hardening

- **Password bootstrap**: a seeder/command that creates or resets the
  admin account (discovered during browser verification: a migrated
  DB has no usable admin password until one is set by hand).
- **Dependency audit**: `composer audit` currently reports advisories
  on laravel/framework (pre-existing); track and bump when fixes land.
- **Session/HTTPS**: force HTTPS at the web server, review session
  lifetime and `secure`/`httponly` cookie flags for production.
- **Failed jobs**: `failed_jobs` table exists but nothing queues yet —
  decide either "no queues" (drop the table usage) or add a worker +
  Task Scheduler entry.

## Operations

- **Automated off-site backup**: the in-app dump covers restore-from-
  accident; add a Task Scheduler job that runs `mysqldump` nightly to
  a second disk/network share with N-day retention.
- **Health check endpoint + log rotation**: a cheap `/up`-style probe
  that verifies DB + active FY, and Laravel log rotation config.
- **Data-quality pass**: run `legacy:integrity-fix --strategy=null` for
  the dangling e_indent_items references surfaced by the dev-DB import,
  and wire `legacy:verify` row-parity into the cutover checklist.

## Testing / CI

- **CI pipeline**: Pint + `php artisan test` on every push (the suite
  is hermetic and self-contained; parallel worker DBs already built).
- **Browser smoke checklist**: a short scripted pass over each role's
  dashboard (the 8090 verification run is the template).

## Cutover

- **Cutover runbook**: extend docs/DEPLOYMENT.md with the day-of steps:
  freeze legacy, final `stage-import → migrate-data → integrity-fix →
  verify`, smoke pass, go-live, and the fallback (legacy untouched and
  reversible until users start writing to the port).
- **Training + parallel-run week**: operators double-entry key
  transactions; reconcile stock ledger + party ledger daily.

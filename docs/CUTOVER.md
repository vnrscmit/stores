# Cutover Runbook (legacy storesd → Laravel port)

Day-of procedure for switching the legacy Stores Management System over
to the Laravel port. Companion to docs/DEPLOYMENT.md, which covers the
first install, web server, production settings, caches and scheduled
tasks — do that once, rehearse, then follow this runbook on the day.

Naming used throughout:

| Name | Meaning |
|---|---|
| legacy app | The old Core PHP tree (its vhost / document root) |
| `storesd` | The legacy MariaDB database — the single source of truth until sign-off |
| port | This Laravel application, database `stores_laravel` |

The pipeline names in `.env` are `DB_DATABASE=stores_laravel` and
`DB_LEGACY_DATABASE=storesd` (config/database.php reads the latter from
`DB_LEGACY_DATABASE`; adjust if your legacy DB is named `stores`).

## Invariants (read before anything else)

1. **The legacy application and `storesd` are never modified** by the
   port. Every command below reads legacy data; none writes it.
2. **`stores_laravel` is disposable** at every point before go-live —
   it can be dropped and rebuilt from the pipeline in minutes.
3. **Rollback is a vhost edit** as long as no user has written to the
   port. After users start writing, rollback means re-keying the new
   data into legacy by hand — that is what the parallel-run week is
   for (see below).
4. Two people minimum: one drives the commands, one independently
   checks each gate and keeps the cutover log (appendix C).

## T-7 … T-1 — rehearsal and pre-flight

Goal: a full dress rehearsal on scratch databases so cutover day has no
unknowns. All steps run on the server that will host the port.

1. Install the port per docs/DEPLOYMENT.md ("First install") but into a
   **rehearsal** database (e.g. `stores_laravel_rehearsal`): set
   `DB_DATABASE` accordingly, run `php artisan migrate`, then the full
   pipeline from a current `storesd.sql` dump:

   ```bash
   php artisan legacy:schema-audit --dump=storesd.sql
   php artisan legacy:stage-import
   php artisan legacy:migrate-data
   php artisan legacy:integrity-fix --strategy=placeholder
   php artisan legacy:add-constraints
   php artisan legacy:verify
   php artisan phase1:smoke
   ```

   ⏱ Time each command. The sum is the cutover window length; add 50%
   buffer when announcing it.

2. `legacy:verify` must end `PASS` on every table (exit code 0). Rows
   added by `legacy:integrity-fix --strategy=placeholder` are expected
   additions and reported accordingly. Any `FAIL` row must be explained
   and fixed **now**, not on cutover day.
3. Review the `legacy:integrity-fix` report: `--strategy=dry` lists
   dangling references without changing anything. Decide, with the
   business, whether leftovers should be `null`-ed
   (`legacy:integrity-fix --strategy=null`) — record the decision in
   the log.
4. `php artisan admin:bootstrap` on the rehearsal DB, then have a
   business user log in and walk one transaction end-to-end (raise an
   e-indent, approve, issue against it, check the stock ledger).
5. Verify the production web server stack: vhost document root pointed
   at `public/`, HTTPS planned or in place, Task Scheduler entries for
   `schedule:run` created, nightly `mysqldump` job tested (it must back
   up **both** databases).
6. Freeze the plan: announce the cutover window to all stores users,
   appoint the two roles, print appendixes B and C.

## T-0 — freeze and final legacy backup

Run in order; each step gates the next.

1. ☐ Announce freeze: users stop entering data in the legacy app.
   Wait for in-flight work to finish (confirm with shift supervisor).
2. ☐ Stop legacy writes: stop the legacy vhost (Apache) or put the
   maintenance notice up. **Keep MariaDB running** — the pipeline reads
   it, or reads the dump, in the next phase.
3. ☐ Take the final legacy dump **out-of-band** (not into the web tree):

   ```bash
   C:/xampp/mysql/bin/mysqldump.exe -h 127.0.0.1 -P 3306 -u root \
     --single-transaction --routines --triggers storesd > storesd-final.sql
   ```

   Record its size and SHA-256 in the log. Copy it to a second disk /
   network share. This is the rollback anchor.
4. ☐ Snapshot pre-cutover row counts for the record (saved to a file,
   pasted into the log):

   ```bash
   for t in $(C:/xampp/mysql/bin/mysql.exe -h 127.0.0.1 -u root -N \
     -e "SELECT table_name FROM information_schema.tables \
         WHERE table_schema='storesd'"); do
     n=$(C:/xampp/mysql/bin/mysql.exe -h 127.0.0.1 -u root -N \
       -e "SELECT COUNT(*) FROM storesd.$t")
     echo "$t $n" >> pre-cutover-counts.txt
   done
   ```

5. ☐ Confirm the port `.env` is production-safe: `APP_ENV=production`,
   `APP_DEBUG=false`, dedicated DB user with privileges only on
   `stores_laravel`, correct `DB_LEGACY_DATABASE`.
6. ☐ Confirm disk space ≥ 3× the size of `storesd.sql`.
7. ☐ **GO / NO-GO checkpoint** (appendix B). If anything above failed,
   fix or abort — aborting here costs nothing (legacy still frozen at a
   consistent point; lift the freeze and reopen the legacy app).

## Cutover window — data migration

Drive the pipeline against the production port database
(`DB_DATABASE=stores_laravel` in `.env`). If this database already
contains earlier rehearsal data, drop and recreate it first, then
`php artisan migrate`.

1. Import and transform (identical to the rehearsal — no new flags):

   ```bash
   php artisan legacy:schema-audit --dump=storesd-final.sql
   php artisan legacy:stage-import
   php artisan legacy:migrate-data
   php artisan legacy:integrity-fix --strategy=placeholder
   php artisan legacy:add-constraints
   ```

   Reuse the T-7 decision if a `--strategy=null` pass was agreed:
   run `php artisan legacy:integrity-fix --strategy=null` before
   `add-constraints` and note it in the log.

2. **Gate — `php artisan legacy:verify`** must exit 0 with every table
   `PASS`. Compare the consolidated `users` count against the
   pre-cutover legacy logins. Any `FAIL` → stop, diagnose, rebuild.
3. **Gate — `php artisan phase1:smoke`** must pass.
4. Bootstrap the go-live admin account and store the password sealed
   (envelope / password manager), not in the log:

   ```bash
   php artisan admin:bootstrap --login=admin123
   ```

5. Verify `company_settings` has exactly one row with a filled
   `plantcode` (it feeds QR serials) and the company address/licence
   (it feeds printed docs). Fix via the admin Company profile screen,
   not SQL.
6. Production caches and storage:

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan storage:link
   ```

7. ☐ Baseline backup of the freshly built port database
   (`mysqldump stores_laravel > stores_laravel-baseline.sql`, stored
   next to the legacy final dump).
8. ☐ **GO / NO-GO checkpoint** (appendix B). Both gates green and the
   backup taken → proceed to go-live. Anything red → rollback
   (appendix D): the legacy app can be reopened immediately, nothing
   was written to `storesd`.

## Go-live

1. Point the serving vhost document root at the port's `public/`
   (Apache config per docs/DEPLOYMENT.md) and reload Apache. The legacy
   folder stays on disk, untouched, for rollback.
2. Record the go-live timestamp in the log.
3. First smoke pass over the live URL (appendix B smoke list):
   admin login, viewer reports, masters screens, one e-indent raised /
   approved / issued against, stock and party ledgers, backup download
   page.
4. Tell users the port is live. From this moment writes go only to the
   port — rollback now has a re-keying cost, which the parallel-run
   week exists to cover.

## Parallel-run week

Operators double-enter key transactions into **both** systems; the port
is the system of record for work, legacy remains untouched as the
comparison copy.

1. Daily, reconcile between the port and the legacy screens (or the
   day's dump): stock ledger totals per warehouse/bin, party ledger
   balances, document counters (indent numbers, issue numbers).
2. Log every discrepancy in the cutover log with the transaction IDs;
   investigate same-day. Expected noise: reports that depend on the
   placeholders/nulls chosen in the integrity-fix decision.
3. At day 7 the business signs off (or extends the parallel run).
   On sign-off, `stores_laravel` becomes the source of truth and
   `storesd` becomes an archive: keep backing it up, stop treating it
   as authoritative.

## Post-cutover

1. Drop the legacy DB grant: the dedicated `stores_laravel` user needs
   no rights on `storesd` after verification; remove them (least
   privilege) and keep the read-only legacy user for reconciliation.
2. Keep both nightly backups running (docs/DEPLOYMENT.md, Task
   Scheduler) — `storesd` remains recoverable for the retention period
   the business chooses.
3. File the cutover log (appendix C) with the dumps' checksums, the
   `legacy:verify` output, the smoke results and the sign-off.
4. Follow-ups from the roadmap worth scheduling now: HTTPS enforcement
   and session cookie flags, failed-jobs decision, health endpoint and
   log rotation.

## Appendix A — command quick sheet

| Command | Purpose | Fails when |
|---|---|---|
| `legacy:schema-audit` | dump → JSON audit (no DB) | unreadable/drifted dump |
| `legacy:stage-import` | legacy → `legacy_*` staging | legacy DB unreachable |
| `legacy:migrate-data` | transform staging → final tables | — (idempotent per table run) |
| `legacy:integrity-fix --strategy=placeholder` | audited `[legacy-missing #ID]` masters | — |
| `legacy:integrity-fix --strategy=null` | clear remaining dangling refs (business-approved only) | — |
| `legacy:add-constraints` | enforce the 18 FKs (idempotent) | genuine duplicate/orphan data |
| `legacy:verify` | row-parity check | **any table FAIL → exit != 0** |
| `phase1:smoke` | acceptance checks over migrated data | any check fails |
| `admin:bootstrap` | create/reset an admin login | — |

## Appendix B — go / no-go checklist

**Checkpoint 1 (freeze)**: users stopped · legacy vhost stopped · final
dump taken + checksummed + copied off-server · counts recorded · .env
production-safe · disk space OK.

**Checkpoint 2 (go-live)**: `legacy:verify` all PASS · `phase1:smoke`
pass · `admin:bootstrap` done and password sealed · `company_settings`
verified (plantcode, address, licence) · caches built · `storage:link`
exists · port baseline dump stored.

**Go-live smoke (browser)**: login OK · dashboard loads per role ·
viewer reports render · masters CRUD round-trip (create + edit a bin) ·
e-indent raise → approve → issue → ledger posted · party ledger shows
the posting · backup download page works.

## Appendix C — cutover log template

```
Cutover log — storesd → stores_laravel
Driver: ______   Checker: ______
T-0 freeze announced: ____  legacy vhost stopped: ____
Final dump: file ______  size ______  sha256 ______________
Checkpoint 1 (time, initials): ____
legacy:verify output attached: [ ]   phase1:smoke output attached: [ ]
integrity-fix decision (placeholder / +null): ______
Admin account: login ______  password stored: ______
company_settings verified by: ______
Checkpoint 2 (time, initials): ____
Go-live timestamp: ____
Smoke pass results: ____
Discrepancies during parallel run: (daily entries)
Business sign-off (name, date): ______
```

## Appendix D — rollback

- **Before go-live**: point the legacy vhost back on, lift the freeze,
  announce. Drop `stores_laravel` at leisure. No data was lost —
  `storesd` was never touched.
- **After go-live, before sign-off**: stop writes (put the port in
  maintenance), reopen the legacy vhost. Data entered in the port since
  go-live must be re-keyed into legacy by hand — use the port's audit
  log and the parallel-run reconciliation sheets to enumerate it. The
  port database is then rebuilt from the next final dump when the
  issue that forced rollback is fixed.
- **After sign-off**: there is no rollback to legacy; restore path is
  the nightly `stores_laravel` dumps.

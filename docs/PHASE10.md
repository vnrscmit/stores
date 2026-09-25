# Phase 10 — QR codes, database backup, audit trail (survey + plan)

Survey of the legacy sources behind the "QR / backup / audit screens"
milestone, verified against the live legacy database (`stores`) and the
Laravel port. Legacy scripts read in full; nothing in this document is
guesswork — dead links and orphaned scaffolding are marked as such.

## 1. What the legacy system actually has

The legacy menu contains exactly three entry points in this family:

| Menu (navbar) | Link target | Exists? | Reality |
|---|---|---|---|
| Operator → "Generate QR Code" | `utility/generate_qrcodes.php` | **NO — dead link** | The real screen is `Transaction/generate_qr_codes.php`; the menu never worked (or pointed to a file deleted after a redesign) |
| Admin → "Backup" | `utility/backup.php` (popup) | YES | Full-database SQL dump streamed as a download |
| — (no menu) | `Transaction/audit_trail_debug.php` | YES | A developer debug screen over the QR scan log, not a general audit trail |

Supporting scripts around them:

| Script | Purpose | Verdict |
|---|---|---|
| `utility/backup1.php` | Curated 51-table SQL dump (`Backup_stores_DD-MM-YYYY.sql`), no schema | Dead-end scaffolding; superseded by backup.php |
| `utility/getuser_qrcode.php` | AJAX: items of one classification (dropdown filler) | Used by the dead-link generator variant only |
| `utility/setup_qrcode_db.php` | Creates `tbl_item_qrcodes` + `tbl_qr_scan_log` + `tbl_item_type_code` at runtime, drops and recreates them | **Runtime DDL**, not a screen; the tables it creates DO NOT EXIST in the legacy DB |
| `utility/qrcode_recovery.php` | 0 bytes | Orphaned stub |
| `Transaction/qr_linking_report.php` | Linked from the debug screen's back-button | **Does not exist** — second dead link |
| `Transaction/save_qr_codes.php` | Saves generated QRs as `linked_status='linked'` rows; overwrites `tblarrival_sub.qty_good` with the Σ weights | Arrival-flow endpoint |
| `Transaction/save_qr_temp.php` | Saves generated QRs as `linked_status='draft'` rows (`arrival_id=0`) before the arrival is posted; purges same-user same-item drafts first | Arrival-flow endpoint |

**Central finding:** the QR system is an ARRIVAL-side subsystem, not a
standalone utility. The "Generate QR Code" popup is launched from the
arrival workspace (`add_arrival_vendor.php` passes
`arrival_id&arrsub_id&classification_id&item_id&ups_good`), generates one
QR per good UPS, and the save endpoints write back into the arrival.
None of its tables exist in the live legacy database, so the subsystem
was never actually run in production; the weight-total write-back
(`UPDATE tblarrival_sub SET qty_good = Σ weights`) was its only visible
effect on the movement data.

## 2. Verified legacy semantics

### 2.1 Database backup (utility/backup.php)

- Auth: admin session only; popup window from the admin navbar.
- Hardcoded credentials `localhost / root / (empty)` and db name
  `stores`; `memory_limit 100M`.
- Dumps EVERY table (`SHOW TABLES`): for each, `SHOW CREATE TABLE`
  followed by row-by-row `INSERT INTO … VALUES("…")` with only
  `addslashes` + newline escaping (no charset handling, no locks, no
  transactions).
- Streams in-memory as `Backup_stores_DD-MM-YYYY.sql`
  (`application/octet-stream`, attachment).
- `backup1.php` variant: same escaping, but only a fixed list of 51
  business tables, INSERTs only (no schema), per-table `# Dump of …`
  headers.

Port implications: Laravel offers a clean equivalent
(`Schema::getAllTables` + mysqldump-free streaming or `FOR UPDATE`
cursor inserts); the port should stream and never buffer the whole dump
in memory. Keep the legacy filename pattern. Admin-only gate
(`role:admin`).

### 2.2 QR generation (Transaction/generate_qr_codes.php)

Two modes, one script:

- **Form mode** (`arrival_id = 0`): reached from the arrival workspace
  BEFORE the arrival is saved; classification/item/ups_good are the only
  required params.
- **Preview mode** (`arrival_id > 0`): after the arrival is saved; also
  fetches `arrsub_id` (or resolves it from `tblarrival_sub`), shows the
  destination SLOC (wh/bin/sub names), and pulls `financial_year` from
  the arrival row.

Code construction (client-side JS after a "Generate" click, server
supplies prefix + starting serial):

- `qr_text = plantcode + financial_year(4d) + type_code(2d) + serial(5d)`
  e.g. `D25261100001` — plantcode from `tbl_parameters WHERE id = 41`
  (defaults `'DEF'`; column absent in live DB so always the default),
  yearcode = the session year with `-` stripped, type_code from
  `classification_type` (`Roll`=11 default, `Pouch(es)`=12,
  `Sticker(s)`=13 — column absent in live DB so always 11).
- Serial: `MAX(CAST(RIGHT(qr_code_text, 5) AS UNSIGNED))` over
  `tbl_qr_codes WHERE qr_code_text LIKE '$qr_prefix%'` + 1 — the series
  continues GLOBALLY per type+year (not per item), starting at 1 when
  the prefix is new. Race-prone (two popups can mint the same serial);
  the port will use a locked counter instead, preserving the format.
- UPS good count = number of codes minted (one per good UPS).
- Print view: POST `print_mode=true` renders an A4 print sheet (2×6 slip
  grid, 12 per page) with Name/Item/Wt. per slip and the QR image from
  the external `api.qrserver.com` service (no local QR library).

### 2.3 QR persistence (save_qr_codes.php / save_qr_temp.php)

- **Preview save** (`save_qr_codes.php`, requires arrival_id+arrsub_id):
  inserts one `tbl_qr_codes` row per code with
  `linked_status='linked'`, then — quirk — **overwrites
  `tblarrival_sub.qty_good` with the Σ of the entered weights** (the
  arrival's good quantity becomes the weighed total).
- **Draft save** (`save_qr_temp.php`, form mode): deletes the user's
  existing `linked_status='draft'` rows for the same item
  (`arrival_id=0`), then inserts the new batch as `draft` rows. The
  arrival post is then expected to link them (no script in the codebase
  performs that link — the flow is incomplete even in legacy).
- The generated popup calls `window.opener.totalWeightCallback(Σ)` to
  auto-fill the arrival's weight field, with a sessionStorage fallback.

### 2.4 tbl_qr_codes (the table the generator actually uses)

Created OUTSIDE setup_qrcode_db.php (which creates the OTHER pair); its
CREATE TABLE lives nowhere in the codebase — inferred column set from
the INSERTs: `arrival_id, arrsub_id, classification_id, item_id,
qr_code_text, weight, generated_date, linked_status('draft'|'linked'),
created_by` (+ an id PK). This missing DDL is itself evidence the
subsystem never went live.

### 2.5 Audit trail (Transaction/audit_trail_debug.php)

- Not an audit module: a hardcoded debug dashboard over
  `tbl_qr_scan_log` (last 50 scans, counts by
  action∈{scan,update_weight,discard,return}, "linking" rows = notes
  LIKE '%Linked%') and `tbl_item_qrcodes` (latest 20 linked codes), plus
  static "recommendations" text diagnosing why the QR audit trail is
  empty.
- Both tables are created by `setup_qrcode_db.php` at runtime and DO NOT
  EXIST in the legacy DB; the screen would fatal on `mysql_query` if
  opened. Back-button target `qr_linking_report.php` doesn't exist.
- `tbl_item_qrcodes` schema (from setup): `classification_id, item_id,
  serial_number UNIQUE, qrcodetext UNIQUE, financial_year,
  item_type_code, current_weight, status ENUM('active','discarded',
  'returned'), created_by, notes` with FKs to classification/item.
- The port's own `Audit` support class docblock already records the
  situation: "Legacy had no audit trail (only audit_trail_debug.php);
  this is additive and changes no legacy behaviour."

## 3. What to port — milestone plan

Four slices, each ending green (php -l + suites + Pint), committed
separately. Rationale for scope decisions at the end.

### Slice 1 — Database backup (admin) — DONE

- `App\Support\DatabaseBackup` + `Admin\BackupController` (`role:admin`,
  `auth`, `fy`), routes under `admin/backup` (index / download /
  download/business) with the admin dashboard card "Database backup".
- `GET /admin/backup` — screen: database name, table count, download
  buttons. `GET /admin/backup/download` — streamed full-database SQL
  dump (schema + data of every table), filename
  `Backup_{database}_{d-m-Y}.sql` (legacy pattern; legacy hardcoded the
  `stores` name — the port uses the live database name, which IS
  `stores` in production). `GET /admin/backup/download/business` — the
  backup1.php variant: the fixed 51-table legacy list mapped onto the
  port's tables (bins, stock_ledger_goods, …), data only, bare INSERTs
  without column lists; legacy names without a dedicated port table
  (tbl_opr, tbl_order, tbl_roles, tbl_viewer, tblissue, tblissue_sub)
  are recorded as comments so the parity gap stays visible in the file.
- Verbatim semantics: no DROP statements (legacy commented them out),
  restore into an EMPTY database; escaping = doubled single quotes (see
  the deviations in the DatabaseBackup docblock: streaming instead of
  buffering, NULL rendered as NULL instead of '', no addslashes
  backslash-corruption, deterministic PK read order, comment header).
- Suite: `Phase10BackupTest` (7 tests, hermetic, read-only): guest
  bounce to login, non-admin bounce home, screen render, full download
  (headers + filename + every schema + row INSERTs + no DROP), a
  round-trip of the writer's escaping against live rows, business dump
  (data only, bare INSERT form, mapping comments, per-table row counts
  matching the live DB). Full suite after the slice: 199 passed /
  1 skipped, 3,170 assertions.

### Slice 2 — Audit trail screen (viewer + admin) — DONE

- `AuditTrailController` over `audit.*` routes (`role:viewer,admin`)
  with the admin dashboard card "Audit trail": paginated (20/page)
  newest-first listing over the port's `audit_logs` (populated by every
  module's `Audit::log` calls since slice 1 of Phase 9), filterable by
  module (options collected from the present data), action, user login
  (LIKE) and inclusive date range; a counts-by-action summary under the
  current filter (legacy debug screen's section 2, minus its hardcoded
  action list); a per-entry detail view rendering the before/after
  snapshots as pretty JSON.
- Also fixed here (needed to make the trail readable): the provider's
  `Relation::morphMap` had un-imported `::class` references (Captive
  Sloc/IssueSloc silently resolved to wrong aliases) and lacked every
  Phase 9 movement model — gtods/dtogs/discards/excesses/item_transfers
  (+ their item/sloc twins) are now mapped, so audit record types
  render as readable table aliases instead of FQCNs.
- Suite: `Phase10AuditTest` (8 tests, hermetic — the suite writes its
  own rows through the port's writer under a swept test-module prefix):
  guest bounce, operator/eindent bounce home, viewer+admin access,
  newest-first ordering, all four filters, the counts summary under
  the filter, the detail view's snapshot rendering, and the morph-alias
  readability. Full suite after the slice: 207 passed / 1 skipped,
  3,207 assertions.

### Slice 3 — QR code subsystem (arrival-side) — DONE

- Migration `000054` creates `qr_codes` (column set reconstructed verbatim
  from the legacy INSERTs), `qr_scan_logs` + `qr_item_types` (verbatim
  setup_qrcode_db.php DDL incl. the 11/12/13 type seeds) and adds the
  `classification_type` column legacy's code referenced but its live
  schema lacked. `App\Models\QrCode` / `QrScanLog` mirror them.
- `App\Support\QrSerial` preserves the code format and continuation:
  `{plant}{year4}{type2}{serial5}` (D25261100001), plantcode from
  company_settings id=41 (default 'DEF' — the live legacy table has no
  plantcode column), yearcode = active year with '-' stripped, type via
  the verbatim mapping (Roll=11 default, Pouch(es)=12, Sticker(s)=13 —
  default 11, as legacy effectively ran). The serial continues GLOBALLY
  per year+type; the legacy racy MAX(RIGHT(text,5)) allocation is
  replaced by a locked counter (`document_counters` key
  `qr.{year4}{type2}`) with a peek/consume pair — serial drift between
  page load and save aborts the save (legacy race made loud).
- `Arrival\QrCodeController` over the arrivals route group (legacy menu
  link was dead; the generator is wired into the vendor-arrival
  workspace per-line as "Generate QR codes", opening in a new tab):
  `qr.form` (draft mode: classification/item/ups_good, required like
  legacy) / `qr.form-linked` + `qr.save-linked` (linked mode: arrival +
  line bound, pair consistency enforced), `qr.save` (draft: purges the
  user's earlier draft rows for the item first, verbatim save_qr_temp
  .php; linked: — verbatim quirk — overwrites arrival_items.qty_good
  with the Σ weights, Σ > 0 only), `qr.print` (POST, A4 2×6 slip grid,
  12 per page) and `qr.codes`. QRs render LOCALLY as inline SVG via
  chillerlan/php-qrcode v6 (deliberate deviation: legacy pulled images
  from api.qrserver.com). Audit module `arrival.qr`.
- Suite: `Phase10QrCodeTest` (10 tests, hermetic — qr_codes is
  suite-exclusive and swept whole): format/continuation, draft purge,
  linked write-back, serial drift abort, parameter guards, mismatched
  arrival/line pair, form + print rendering, table/type seeds. Full
  suite after the slice: 217 passed / 1 skipped, 3,250 assertions.

Net-new tables (no legacy tables to migrate — they never existed):

- Migration: `qr_codes` (verbatim port of the inferred `tbl_qr_codes`:
  arrival_id, arrsub_id, classification_id, item_id, qr_code_text,
  weight, generated_date, linked_status draft|linked, created_by) and
  `qr_scan_logs` (port of `tbl_qr_scan_log`: qrcode_id FK, action
  enum scan|update_weight|discard|return, operator_id,
  previous_weight, new_weight, notes, ip).
- `DocumentNumber` counter `qr` (or dedicated `QrSerial` support) with
  the legacy format preserved: `{plant}{year4}{type2}{serial5}` —
  plantcode from company parameters (default 'DEF' as legacy), type 11
  Roll / 12 Pouches / 13 Stickers (default 11 as legacy effectively
  did), locked serial per year+type replacing the racy MAX+5.
- `Arrival\QrCodeController`:
  - `GET /arrivals/{arrival}/items/{item}/qr/generate?ups=` — popup form
    (wire the real button into the vendor-arrival workspace; fixes the
    legacy dead menu link).
  - POST save: preview mode links codes to arrival+arrsub rows and —
    verbatim quirk — overwrites `arrival_items.qty_good` with Σ weights
    (guard: only when Σ > 0, as legacy); draft mode (no arrival yet)
    purges the user's same-item drafts first, verbatim.
  - A4 print sheet view, 12 slips/page; render QR locally (SVG via a
    tiny pure-PHP QR encoder or `chillerlan/php-qrcode` if allowed)
    instead of the external api.qrserver.com call (offline-safe; note
    as deliberate deviation).
- Suite: format + serial continuation per year+type, draft purge,
  linked save + qty_good write-back, print sheet render, gates.

### Slice 4 — Admin dashboard completion

- Replace the two remaining `#` placeholder cards: "Users & Roles" →
  real users listing route (new lightweight UsersIndex if absent),
  "Year Setting" → the year close screen. (Verify what exists; if the
  underlying screens don't exist yet, fold them into this milestone as
  sub-slices.)
- QR + Backup + Audit cards wired from slices 1–3.

## 4. Deliberate scope decisions (to confirm with the user)

Slice 1 notes: the business dump maps legacy names onto port tables (a
straight name list would have produced an all-comments empty file, since
no legacy table name exists in the port schema); `tbl_opr`/`tbl_order`/
`tbl_roles`/`tbl_viewer`/`tblissue`/`tblissue_sub` have no single port
table and are recorded as comments.

1. **Backup is a port, not an upgrade** — same full-database SQL dump,
   streamed; no scheduler, no S3, no retention policy unless asked.
2. **Audit screen reads the port's own audit_logs** — there is nothing
   legacy to port here; the legacy screen was a QR-debug page whose
   tables never existed in the live DB.
3. **QR subsystem is completed, not just mirrored**: legacy never ran it
   (no tables, dead links). Port the arrival-flow generator faithfully
   (format, serial continuation, weight write-back) but fix the two
   broken links by wiring the generator into the vendor-arrival
   workspace, and render QRs locally instead of via the external API.
   The scan-log table is created for future device scans but no scan
   endpoint is built (none existed).
4. **`qrcode_recovery.php` (0 bytes) and `setup_qrcode_db.php`
   (runtime DDL) are not ported** — the migration system replaces the
   latter by design.

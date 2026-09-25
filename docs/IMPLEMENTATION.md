# Implementation Record — Laravel Modernization (storesd → stores_laravel)

Companion to MIGRATION_MAPPING.md. This file records the recovered and
re-built implementation after the original Laravel tree was lost from the
checkout (only composer.json/artisan/vendor survived).

## Status

| Slice | State |
|---|---|
| Laravel skeleton (bootstrap/app.php, configs, public/, routes, phpunit) | ✅ rebuilt |
| `legacy:schema-audit` — dump → JSON audit (54 tables / 643 cols, no DB needed) | ✅ |
| `config/legacy-map-tables.php` + `legacy-map-relations.php` | ✅ |
| `legacy:generate-schema` — 49 migrations + 49 Eloquent models (verbatim legacy PKs/columns) | ✅ |
| System migration (users, audit_logs, document_counters, jobs/cache) | ✅ |
| `legacy:stage-import` → `legacy:migrate-data` → `legacy:integrity-fix` → `legacy:add-constraints` → `legacy:verify` | ✅ run against live `stores` — all 49 tables PASS row parity, 18 FKs live |
| `phase1:smoke` acceptance command | ✅ |
| Auth slice: consolidated users, login (gradual hash migration), Q&A reset, role middleware, Gates, FY contract | ✅ |
| Viewer reports: all nine ported (Phase 3 trio + Phase 8 six, see below) | ✅ |
| Masters CRUD (warehouse, bin, sub-bin, classification, item, party) + audit logging | ✅ |
| e-Indent raise module: draft workspace, numbering, submit, approval gate, audit | ✅ |
| Issue-against-e-Indent: pending queue, SLOC distribution workspace, StockLedgerService posting, indent closing | ✅ |
| Feature suites: Phase1PipelineTest, Phase2AuthTest, Phase3ViewerReportsTest, Phase4MastersTest, Phase5EIndentTest, Phase6IssueTest, Phase7MovementTypesTest + six Phase 8 report suites | ✅ **110 passed / 1 skipped, 2,544 assertions**; Pint clean; serial green (parallel infra unchanged) |

## Pipeline commands

```bash
php artisan legacy:schema-audit                 # storesd.sql -> storage/app/legacy-schema.json
php artisan migrate                             # schema (generated + system tables)
php artisan legacy:stage-import                 # verbatim legacy -> legacy_* staging (TEXT-typed)
php artisan legacy:migrate-data                 # transform: zero-dates->NULL, sentinels->NULL, auth->users (bcrypt)
php artisan legacy:integrity-fix --strategy=placeholder   # audited [legacy-missing #ID] masters
php artisan legacy:integrity-fix --strategy=null          # clear anything left
php artisan legacy:add-constraints              # idempotent FK enforcement
php artisan legacy:verify                       # row-count parity (exit != 0 on drift)
php artisan phase1:smoke                        # acceptance checks
```

## Documented legacy quirks (preserved deliberately, see StockLedgerService)

1. `add_issue_eindents_preview.php` references undefined `$totnog` — the
   sub-bin "Empty" flag therefore applies whenever the moved item's balance
   reaches zero (not the apparent "no other item in sub-bin" intent).
2. The reorder-flag query filters `stlg_tritemid != item` before re-checking
   the same item's balances — an effective no-op. The degenerate literal
   behaviour ("sum all positive balances of the item") is implemented.
3. `add_issue_mrtv_view.php` stores the literal string `'yearid_id'` instead
   of the variable (missing `$`) — the modern writer always uses the real
   yearcode; flagged for regression comparison.
4. Several print/view pages also insert ledger rows (open_print.php,
   tr_ci_addremark.php, add_cc_view.php…) — legacy double-posting risk;
   the modern flow posts once from the service layer.

## Data-quality rules (unchanged from the original audit)

- `0000-00-00` dates → NULL during transform.
- FK sentinels `'0'`/`''` → NULL on constraint-eligible columns.
- `party_id = 0` = "no party" sentinel on issues — kept verbatim; code filters.
- Auth consolidation: tbl_user + tbl_opr + tbl_roles + tbl_viewer → `users`
  (bcrypt at migration; legacy plaintext accepted once at login, re-hashed).
- Document counters seeded from per-year MAX(code); new documents continue
  the legacy number sequence without MAX+1 races.

## Live-data findings (2026-09-09 pipeline run)

- The live `stores` DB (not the `storesd.sql` dump) is the authoritative
  source; pipeline commands introspect the live schema, so dump-only columns
  (`pan`/`gst`) do not break staging.
- Legacy MyISAM ran without enforced keys: 1,843 duplicate-PK rows existed
  (e.g. `tbl_subbin sid=2000` twice). The transform keeps the first row per
  distinct PK and reports skips; parity uses the distinct-PK rule.
- 12,365 zero-dates scrubbed to NULL; 290 dangling header/master refs resolved
  as audited `[legacy-missing]` placeholder masters; 4 stragglers cleared via
  `--strategy=null` so all 18 FK constraints could be enforced.
- The previous migration's target DB (`stores_laravel`) was backed up to
  `storage/app/backups/` before this rebuild.

## Masters slice (Phase 9 — implemented 2026-09-09)

Six master modules ported: warehouse, bin, sub-bin (SLOC), classification,
item, party. Each has admin-gated routes (`can:manage-masters`), FormRequest
validation mirroring legacy duplicate checks, server-side search, pagination,
and audit rows via `App\Support\Audit` (before/after JSON, user, IP).

Legacy-parity decisions (documented in controller headers):
- Bin create seeds sub-bins 1..20 (legacy add_bin.php).
- Bin delete cascades sub-bins — now transactional (legacy was two-step).
- Sub-bin delete stays disabled (commented out of legacy include/delete.php).
- Hard deletes on all six masters (legacy parity); InnoDB FKs now guard
  referenced masters where legacy silently orphaned children.
- Deliberate fixes vs legacy: sub-bin uniqueness scoped per bin (legacy
  checked globally); sub-bin update targets a single sid (legacy rewrote all
  sub-bins of the bin); India parties require a state (was JS-only).

## e-Indent raise module (Phase 5 slice)

Ported from add_indents.php (draft workspace), getuser_indentupdate.php
family (AJAX row post/edit/delete), add_indents_preview.php (final submit),
home_pending _indents.php (my-indents listing) and add_indents_view.php
(detail).

- Lifecycle on the legacy flags, verbatim: `tflg` 0→1 at submit, `flg` 0→1
  when the operator closes it via issuance. A `status` column adds an
  approval gate between submit and issue: draft → pending → approved |
  rejected; rejected reopens to draft. Existing rows default to `approved`
  (open for issue), matching the 43,635 production indents.
- Numbering via DocumentNumber counters (race-safe vs legacy MAX+1):
  `eindent.draft` → code1 ("T{code1}", txn id "TIR{code1}/{year}/{login}"
  parity), `eindent` → code at submit ("IR{code}"). Counters seeded from
  legacy MAX per yearcode; resubmit-after-reject reuses the committed code.
- Draft row workspace is AJAX (JSON) with server-side validation:
  item must belong to the chosen classification, UoM forced from the item
  master (readonly in legacy), qty numeric (legacy maxlength 7).
- Legacy "up to 3 indents" limit enforced: per raiser, open (pending/
  approved) pipeline only; raise screen and first-row endpoint both guard.
- Legacy bugs fixed in the port (documented in code): row update wrote
  `qty=qty` so quantity could never change; home page rendered "IR"+null
  for drafts; select_eindentop.php updated tblarrival instead of the indent.
- Approvals queue (admin) with approve/reject, raiser reopen, and audit
  rows (submit/approve/reject/reopen + row edits) in audit_logs.

## Issue-against-e-Indent module (Phase 6 slice)

Ported from add_issue_indents.php (workspace entry),
getuser_issue_eindentupdate.php + getuser_issue_eindentedtupdate.php (AJAX
line save/edit), getuser_issue_eindentdelete.php (pending-close),
getuser_issue_eindent_etd.php (availability rows) and
add_issue_eindents_preview.php (final post — the stock ledger writer).

- Pending queue lists e-Indents with flg=0 AND tflg=1 AND status=approved
  (the legacy pair plus this port's approval gate); drafts and pending
  indents are structurally invisible.
- Workspace keys the `issues` header by dcrefno = indent code (legacy
  stored the indent number there); issue_code = MAX(issue_code)+1 per
  yearcode is the entry-time "TIE{code}/{year}/{login}" id, re-derived
  with lockForUpdate. Resuming reuses the open header.
- Per line: availability = latest ledger balance per (wh, bin, sub-bin);
  distribution rows are validated server-side (ledger row exists, issue
  qty ≤ balance, Σ qty = line qty); save upserts issue_items keyed
  (issue, classification, item) — tblissue_sub carries no eid — and writes
  issue_slocs rows; edit = delete-and-reinsert of the sloc rows (legacy
  semantics preserved).
- Final post (transactional, idempotent): re-validates coverage, then
  StockLedgerService::post per sloc row (opening = latest balance,
  balance = open − issue, UPS normalization, sub-bin Empty flip,
  applyReorderFlag), assigns iss_code + ncode from the seeded
  issue.eindent / issue.eindent.n counters, sets issuetrflag=1 +
  status=posted, closes the indent (flg=1). Re-post returns an error; a
  second ledger row is impossible.
- Legacy bugs fixed in the port (documented in code): posting was not
  transactional and could go negative or double-post; the sub-bin Empty
  check referenced an undefined $totnog (never marked bins Empty); the
  stock lookup omitted the item filter when taking the latest row;
  issue_code collision race; edit-time stale balances (JS-only guard).
- A `status` column (open|posted) mirrors issuetrflag as a readable state
  (see EIssueStatus); audit rows for open/line create/update/delete/post.

## Movement types: pindent, stocktr, MRTV, captive consumption (Phase 7 slice)

Ported from add_issu_physical_indent.php, add_issue_str_view.php,
add_issue_mrtv_view.php + the getuser_issue_{pindent,str,mrtv}* AJAX
families and add_issue_{pindents,str,mrtv}_preview.php (posting), plus the
parallel captive-consumption family add_internalcc.php /
getuser_capetdupdate.php / add_cc_preview.php (tbl_captive + tbl_captivesub
+ tbl_captive_sloc, trtype/trsubtype 'CC').

- The three issue types share the `issues` skeleton (issue_type column) and
  one controller (IssueController); the header is created on the FIRST line
  post (legacy trid=0 branch) with issue_code = MAX+1 per yearcode x type,
  lock-guarded. Lines are self-contained: item + qty + uom + distribution
  (no source indent). Header fields per type: pindent stores the physical
  indent no in dcrefno and the raiser in strefno; stocktr stores the
  transfer ref in strefno (+ party_id, strdate, rettyp); MRTV stores the
  party DC ref in dcrefno (+ party_id). Conditional transit fields
  (Transport/Courier/By Hand) validated server-side.
- Per line: distribution rows validated at save (ledger row exists +
  belongs to the item, qty <= live balance, Σ = line qty) and re-validated
  at post; edit = delete-and-reinsert (legacy semantics); line qty IS
  editable (legacy edtupdate wrote qty=qty).
- Final post (transactional, idempotent): StockLedgerService::post per sloc
  row (trtype 'Issue', trsubtype = issue_type, partyid = party_id or 0),
  applyReorderFlag per line item, then committed serials from per-type
  counters issue.{type} / issue.{type}.n (bootstrapped from legacy MAX),
  issuetrflag=1 + status=posted. Display prefixes preserved: TIP (pindent),
  TIS entry / IS committed (stocktr), IM (mrtv) — IssueNumbering handles
  the per-type prefix, workspace serials stay MAX+1 per type.
- Captive consumption (CC) is a separate controller (CaptiveController)
  over captives/captive_items/captive_slocs: header may reference a party
  master OR free-form party details (legacy txt12 branches); lines carry an
  item condition (`type`) and NO entered quantity — the line qty/ups are
  the distribution sums (legacy `update tbl_captivesub set ups=totups,
  qty=totqty`); post writes trtype/trsubtype 'CC' rows and assigns
  cc_code/ncode (captive.vendor / captive.vendor.n counters), ccflg=1.
- Legacy bugs fixed in the port (documented in code): posting was not
  transactional (could go negative or double-post); edit-time balance
  checks were JS-only; the CC note-print header wrote literal 'txtremarks'
  into the remarks column and duplicated lrno into docketno; MAX+1 races
  on issue_code/iss_code/ncode/cc_code.
- A shared `status` column (open|posted) now mirrors issuetrflag AND
  ccflg (see EIssueStatus); audit rows for open/line create/update/delete/
  header update/post per module (`issue.pindent`, `issue.stocktr`,
  `issue.mrtv`, `cc.consumption`). One availability endpoint
  (IssueAvailabilityController) serves all four entry screens.

## Viewer reports complete (Phase 8 slices)

The viewer reports catalogue (`viewer/reports`) is fully live — every entry
in the legacy reports1/viwerreports.php menu is ported, routed under
`auth, fy, role:viewer,admin`, and covered by a hermetic suite. Each port
follows one pattern: a private rows-reader on ReportController (or
BincardController) mirroring the legacy query, a Blade view, an Excel::stream
XLSX export with the legacy filename pattern, and a Phase 8 feature suite
that runs the legacy pipeline once and derives its data window from the
staged rows (the dataset is historical).

Ported in commit order, with sources:

| Report | Legacy sources | Notes |
|---|---|---|
| Sub-Bin Card (bincard) | report_bin.php + bincard + word_bin | `StockLedgerService::ledgerForItemAt` reader; per-item movement ledger inside the card |
| Consumption (item-wise) | consumption_report(1|2).php | Issue minus internal-return per date row, SUO excluded |
| Stock On Hand (Damage) | damage twin of report_stockhand.php | `damageBalanceAt` over `stock_ledger_damages`, latest-row-per-location balance |
| Discard | discardreport.php + report_discard.php + excel-discard.php | Damage-ledger `Discard`/`MD` rows, newest first, particulars from the discard document |
| Reorder Level | reorderlevelreport.php + report_reorder.php | `srl_status='Yes'` items with summed latest-row balance ≤ `srl`; R / "OR - date" remarks; no filters (legacy had none) |
| Party-wise Period | partywiseperiodreport2.php + excel-partywise.php | `party_ledgers` rows with the legacy particulars mapping and Opening/DC/Good/Arrival-Damage/Internal-Damage/Excess/Shortage/Issue/Balance split |

Deliberate deviations vs legacy (documented in code):
- Party-wise: the legacy "Net" column was blank (uninitialized `$slups/$slqty`
  leftover); the port computes it as receive − issue. A period-totals block
  (summed DC/good/damage/excess/shortage + closing balance) was added.
- Reorder: the legacy page's remarks read the last location's row while the
  totals loop ran over all locations; the port takes the order flags from the
  item's globally latest ledger row (deterministic superset of the intent).
- Catalogue flags: damage/consumption/discard/partywise/reorder cards were
  still marked "phase 11"; all flipped live.

## Test infrastructure: parallel execution with per-worker database clones

ParaTest (v7.4.9) splits test methods across worker processes; every suite
shares one populated MariaDB database, so workers need isolated copies.

- **Template DB** (`stores_laravel_test`, wired via `DB_DATABASE_TEST` in
  phpunit.xml) holds the pipeline-built schema and the reduced dataset.
- **Per-worker clones** (`stores_laravel_test_1` .. `_N`) are produced by
  dumping the template and re-importing it, so FK constraints survive
  (Phase 4 asserts them). `App\Support\ParallelDatabase` manages this
  boot-free (raw PDO + mysqldump) from `TestCase::setUp()` BEFORE the
  Laravel app initialises, so it works for every suite uniformly.
- **Serial runs** (no `TEST_TOKEN`) use the template directly.
- **Mutations are restored**: Phase 2 restores auth credentials via raw
  updates, Phase 3 restores the FY row by captured PK, Phase 6 restores
  the ledger row it shrinks in a `finally` — so workers may run any
  suite's methods in any order against the same clone without leaking.
- **Management**: `php artisan tests:parallel-db` (build/repair template
  and pre-clone workers), `--rebuild` (force a fresh template from the
  legacy pipeline + compact via dump/drop/reimport), `--cleanup` (drop
  worker clones), `--processes=N` (pre-clone N workers). Serial suite
  time dropped from ~226s to ~68s after compaction; 4 workers is the
  sweet spot on this hardware (8 workers contend on one MariaDB).
- **Phase 7 compatibility**: the `static $pipelineReady` gate is per-PHP-
  process, so each worker starts fresh. In parallel mode each worker's
  clone already has the `users` table, so the gate skips the pipeline and
  the worker uses the pre-cloned, pre-populated DB; the hermeticity
  cleanup runs per-method on the worker's own isolated clone. In serial
  mode the gate runs the pipeline once and cleans up per-method as before.
  Precondition: the template must be pre-built before `--parallel`.

```bash
php artisan tests:parallel-db                 # build/repair template, pre-clone workers
php artisan tests:parallel-db --rebuild       # force fresh template from legacy pipeline
php artisan tests:parallel-db --cleanup       # drop worker clones (keeps template)
php artisan test --parallel                   # run suites in parallel (default 8 workers)
php artisan test --parallel --processes=4    # 4-worker sweet spot
```

## Next phases (per approved plan)

Viewer reports — done (all nine: stock-on-hand good + damage, item ledger,
stock transfer, bincard, consumption, discard, reorder, party-wise) →
arrivals family (vendor GRN, stock transfer in, internal return) →
discard/excess-shortage/gate movements → QR/backup/audit screens →
UI unify → performance → cutover docs.

The **arrivals family** (Phase 9, plan in docs/PHASE9.md) is implemented
through the vendor GRN, stock transfer in and internal return types
sharing `ArrivalController` (slices 2–4) and the inter-item transfer
ITI/ITA module (`ItemTransferController`, slice 5):

- Schema: migration `000053` adds a guarded `status` (open|posted) to
  `arrivals` and `item_transfers`, backfilled from arrtrflag/iitrflg.
- Posting: header on first line (legacy trid=0 AJAX branch), AJAX line
  workspace with server-side validation, one transactional idempotent
  post through StockLedgerService (direction per side, sub-bin flips,
  reorder passes), per-type document counters, audit rows, immutable
  posted documents.
- Vendor GRN keeps the verbatim party-ledger excess/shortage math (ex
  floored at 0, sh stored ≤ 0) and the mixed good+damage sloc quirk
  (damage side only; counted in the audit row).
- ITI/ITA: source good-ledger row groups (item_transfer_items.rowid) post
  one ITI out per group (Σ ups_to/qty_to, bal = op − tr) and one ITA in
  per destination row with the verbatim legacy quirk bal = tr when the
  destination opening is 0/0 (reset instead of add) — see
  docs/PHASE9.md §2.4. Committed serial from the `iitr` counter (TIIT…):
  a documented deviation, the legacy numbering block was commented out.
- Suites: Phase9VendorArrivalTest, Phase9StocktrInternalTest and
  Phase9ItemTransferTest, all hermetic (pipeline once per process,
  artifact sweep per method, append-only-ledger revert).

The **Material Discard adjustment module** (Phase 9 slice 6) is ported as
`DiscardController` under `discards.*` with the operator dashboard card:

- Workspace: classification → item → damage availability (latest damage
  row per item×location with a positive balance, verbatim
  getuser_discard_slocshow semantics), AJAX line save/edit (delete-and-
  reinsert, returns the new did)/delete, header created on first line
  with tcode = tid (legacy trid=0 branch).
- Post: one transactional idempotent pass — one damage-ledger OUT per
  discard_slocs row (trtype 'Discard', subtype 'MD', stld_trpartyid =
  party_name verbatim), **no reorder pass** (verified legacy behaviour),
  the scoped cross-item Empty check (restoreLegacyEmptyScope) restoring
  'Good' when other same-class items still hold the sub-bin, dd_code/
  ncode/gpcode counters, ddflg = 1, gate pass trid "MD{dd_code}".
- Service fix surfaced by the first damage-ledger posting path:
  StockLedgerService::post was reading the good-ledger stlg_* opening
  columns for damage rows (stld_*) — every damage-side post would have
  opened at zero; now reads through the ledger-appropriate prefix.
- Audit module `discard.md`; suite Phase9DiscardTest (13 tests, hermetic,
  sweeps discard artifacts incl. trid=0 seed rows). Full suite after the
  slice: 163 passed / 1 skipped, 2,875 assertions.

The **Excess/Shortage adjustment module** (Phase 9 slice 7) is ported as
`ExcessShortageController` under `exshorts.*` with the operator card
replacing the placeholder Adjustments entry:

- One document adjusts ONE item at one or more SLOCs in the ledger the
  header `typ` selects ('good' or 'damage'); rows are selected from the
  latest positive ledger row per item×location (getuser_exsh_slocshow
  semantics for both ledgers) and each carries an excess pair OR a
  shortage pair — never both (server-side guard; the legacy UI enforced
  it in JS only).
- Post: one transactional idempotent pass — one ledger row per
  excess_items row (trtype 'ES', subtype 'ES' with bal = op + ex or 'SH'
  with bal = op − sh, trid = tid, no party id), the referenced row
  re-resolved against the live latest row, **no sub-bin status flip**
  (the legacy ES writer never touches tbl_subbin) and **no UPS
  normalization** (raw legacy math, shortage bounded by the row's
  balance), the class-scoped reorder pass, escode/ncode counters,
  esflg = 1, no gate pass.
- Deliberate deviations (documented in docs/PHASE9.md §2.6): the legacy
  queue screen deleted every unposted ES document on page load — the
  port keeps open workspaces with edit_exsh.php's delete-and-reinsert
  semantics; the legacy bin status sheet filtered subtype='ES' and hid
  shortage rows — the port lists both sides.
- Counters: `excess` (TES, already seeded) plus new `excess.n` and
  `excess.draft` seeds; audit module `adjustment.es`; suite
  Phase9ExcessShortageTest (14 tests, hermetic, sweeps ES artifacts in
  both ledgers). Full suite after the slice: 177 passed / 1 skipped,
  2,967 assertions.

The **Gate movement module** (Phase 9 slice 8, Good→Damage / Damage→Good)
is ported as `GateMovementController` under `gatemovements.*` with the two
operator cards replacing the placeholder Gate movements entry:

- One document converts ONE item, slot by slot: the source SLOC is
  referenced by its ledger row (`gtod_items.rowid` / `dtog_items.rowid` —
  the availability pane implements the getuser_gd_slocshow /
  getuser_dg_slocshowd latest-row-per-location semantics on the
  direction's SOURCE ledger: good for G2D, damage for D2G) and the
  destination SLOCs are free numeric wh/bin/sub-bin inputs validated
  against sub_bins (ITI destination semantics). G2D carries a party
  (required; tbl_dtog has no party_id column).
- Post (verbatim docs/PHASE9.md §2.7): good out row via the service
  writer (trtype/subtype 'GD', party id on the row) for G2D; the DG
  damage out row written by hand because of the VERBATIM legacy quirk
  stld_balups = op (UPS NOT decremented); ES-style destination in rows
  (bal = live op + tr with the UPS normalization) with UNCONDITIONAL
  'Damage'/'Good' sub-bin flips; on a full source drain the
  unconditional Empty flip (the legacy scoped cross-item check was dead
  code — undefined $totnog, same as the service writer); ONE party-ledger
  row per G2D document (damage = Σ tr, bal = opening − damage, every
  other side 0 as legacy wrote them); the G2D reorder pass only; commit
  gcode/dcode + ncode, flag = 1; NO gate pass (G2D/D2G never touch
  tbl_gate). Transactional + idempotent.
- Deliberate deviations (documented in docs/PHASE9.md §2.7): the legacy
  queues purged every unposted document on page load — the port keeps
  open workspaces with delete-and-reinsert row edits (same deviation as
  the ES slice); draft serials use new `gtod.draft` / `dtog.draft`
  counters (legacy left them blank).
- Counters: `gtod`/`dtog`/`gatepass` seeds already provisioned; audit
  modules `gatemovement.g2d` / `gatemovement.d2g`; suite
  Phase9GateMovementTest (15 tests, hermetic, sweeps GD/DG ledger rows,
  party-ledger rows and documents in both directions; the stocked-item
  helpers exclude srl-tracked items AND rows with trid <= 0 so other
  suites' unswept seed debris can never be picked). Full suite after
  the slice: 192 passed / 1 skipped, 3,088 assertions.

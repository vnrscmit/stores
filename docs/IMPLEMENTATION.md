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
| Viewer reports: Stock On Hand, Stores Item Ledger, Stock Transfer + XLSX | ✅ |
| Masters CRUD (warehouse, bin, sub-bin, classification, item, party) + audit logging | ✅ |
| e-Indent raise module: draft workspace, numbering, submit, approval gate, audit | ✅ |
| Issue-against-e-Indent: pending queue, SLOC distribution workspace, StockLedgerService posting, indent closing | ✅ |
| Feature suites: Phase1PipelineTest, Phase2AuthTest, Phase3ViewerReportsTest, Phase4MastersTest, Phase5EIndentTest, Phase6IssueTest | ✅ **54 passed / 266 assertions** on the migrated data; Pint clean (169 files) |

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

## Next phases (per approved plan)

Issue-against-e-Indent (done) and movement types pindent/stocktr/MRTV/CC
(done) → bincard → remaining reports → arrivals family (vendor GRN, stock
transfer in, internal) → discard/excess-shortage/gate movements →
QR/backup/audit screens → UI unify → performance → cutover docs.

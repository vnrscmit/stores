# Legacy → Laravel Migration Mapping (Phase 1 Foundation)

Authoritative record of the `stores` (procedural PHP/MyISAM) →
`stores_laravel` (Laravel 11 / InnoDB) migration. Source DB is treated as
read-only; all tooling lives in this repository.

## Pipeline Commands

| Command | Stage |
|---|---|
| `php artisan legacy:generate-schema` | Regenerates the 49 migrations + Eloquent models from `config/legacy-map-tables.php` + `config/legacy-map-relations.php` |
| `php artisan migrate:fresh --force` | Rebuilds the normalized schema (52 migrations). Integer FK columns are coerced to their parent PK's exact unsigned signature; FK columns are forced nullable |
| `php artisan legacy:stage-import` | Raw snapshot: `stores.*` → `legacy_*` staging tables (relaxed sql_mode session; verbatim copy) |
| `php artisan legacy:migrate-data` | Transformer: staging → final tables (chunked 1000); scrubs zero-dates and `0/''` FK sentinels to NULL; consolidates the 4 auth tables into bcrypt-hashed `users` |
| `php artisan legacy:integrity-fix --strategy=placeholder` | Rebuilds deleted master rows (`[legacy-missing #ID]`) so historical location/bin references stay joinable — every insert audited in `integrity_fixes_log` |
| `php artisan legacy:integrity-fix --strategy=null` | NULLs any remaining unresolvable references (audit-logged; `--strategy=dry` reports only) |
| `php artisan legacy:add-constraints` | Idempotent FK enforcement — **98 constraints live** |
| `php artisan legacy:verify` | Row-count parity vs legacy DB (+ placeholder offsets from audit log) |
| `php artisan phase1:smoke` | Acceptance test: counts, relationships, auth consolidation |
| `php artisan test` | Full PHPUnit suite (Phase 1–3 feature tests + unit) — **12 passed / 100 assertions** |

## Referential-Integrity Outcome

- **98 FOREIGN KEY constraints** active in `stores_laravel` (InnoDB).
- **290 placeholder masters restored** (audited): 4 warehouses, 41 bins, 243 sub_bins, 2 items — preserving full bincard/ledger joinability instead of destroying history.
- **6 orphaned references nulled** (audited): the known `issue_items.issue_id` stragglers.
- Constraint-ineligible by design: `e_indent_items.id_in` (non-unique parent key), polymorphic sloc columns (`arr_id`, `issue_id`, `isue_id`, `discard_id` leftovers), text-typed legacy columns (`cycle_counts.classification_id/items_id`, `captive_slocs.subbin varchar`). All remain indexed.

## Phase 2 — Auth & Ecosystem (delivered)

- Hand-built auth on the consolidated `users` table (`login` field), Active/Suspend gate, remember-me, session regeneration.
- `role:` middleware → four hard-gated module route groups (`admin/eindent/operator/viewer`); cross-role access lands users on their own module home (legacy `index*.php` parity).
- Fiscal-year session contract: `App\Support\FiscalYear` resolves the active `financial_years` row on login, guarded by `EnsureActiveYear`, shared to views as `$fy`.
- Legacy Q&A password reset (question → answer → new password); migrated plaintext answers still verify, new answers stored bcrypt-hashed.
- `maatwebsite/excel ^3.1` installed; PoC export verified as genuine `.xlsx`.
- Feature suite `Phase2AuthTest`: 6 tests / role-by-role login via staged plaintext credentials.

## Phase 3 — Viewer Module (delivered)

- Reports catalogue page reproducing legacy `reports1/viwerreports.php` (8 reports; 3 live now, 5 flagged for later phases).
- Implemented read-only reports over `stock_ledger_goods` (+ damages twin), logic ported from `report_stockhand.php`, `report_itemledger.php`, `stocktransferreport1.php`:
  - **Stock On Hand** — latest balance per item × location up to an as-of date (good + damaged sections).
  - **Stores Item Ledger** — daily movement summary grouped by type/sub-type/transaction id.
  - **Stock Transfer** — party-scoped ledger movements in a period.
- Every report: validated GET filters (dates/classification/item/party), Tailwind data tables with pagination, one-click XLSX export.
- Exports cap at the first 1,000 matching rows so workbook generation stays interactive on wide historical ranges (phpspreadsheet writer cost grows super-linearly). Narrow date filters remain the path to complete datasets; queued/chunked exports can be added as an Admin utility later if required.
- Performance indexes added to both ledger tables (date/item/class/location composite) — see migration `2026_08_25_100001_add_ledger_report_indexes`.
- Feature suite `Phase3ViewerTest`: guest/role isolation, catalogue + three live reports, filter narrowing, XLSX download headers.


## Design Rules

- **Legacy PKs and column names are preserved verbatim** — historical IDs never change; only table names are modernized.
- Models use `$timestamps = false` except `users`; `$guarded = []`.
- FK constraints are intentionally NOT yet enforced (legacy had zero). A referential-integrity migration will follow once orphan cleanup completes (see findings).

## Table Map

| Legacy | Laravel | Notes |
|---|---|---|
| tbl_user + tbl_opr + tbl_roles + tbl_viewer | **users** | consolidated identity; role = admin/operator/eindent/viewer; passwords bcrypt via `'hashed'` cast; `legacy_source`+`legacy_id` traceability; 18 accounts (1/4/8/5 by role) |
| tblyears | financial_years | active FY selected by `years_flg != 0 AND years_status='a'` (current: 20222023) |
| tbl_parameters | company_settings | single-row company profile |
| tbl_classification | classifications | |
| tbl_stores | items | ⚠️ this was the hidden item master |
| tbl_partymaser *(sic)* | parties | state/country stored as TEXT in legacy |
| tbl_report | report_definitions | |
| tbl_warehouse / tbl_bin / tbl_subbin | warehouses / bins / sub_bins | hierarchy whid → binid → sid |
| tblarrival (+_sub/_sloc) | arrivals (+arrival_items/arrival_slocs) | arrival_slocs is POLYMORPHIC (see below) |
| tblissue (+_sub/_sloc) | issues (+issue_items/issue_slocs) | issue_slocs is POLYMORPHIC (see below) |
| tbl_ieindent (+_sub) | e_indents (+e_indent_items) | details join via `id_in → e_indents.id` |
| tbl_captive (+sub/_sloc), tbl_ecaptive, tbl_icaptive | captives family | captive_slocs partially polymorphic |
| tbl_discard (+sub/_sloc) | discards (+discard_items/discard_slocs) | sloc links via `discard_trid` (477/477 verified) |
| tbl_excess (+_sub) | excesses (+excess_items) | |
| tbl_dtog / tbl_gtod (+_sub) | dtogs/gtods (+dtog_items/gtod_items) | gate movement notes |
| tbl_iitr (+_sub) | item_transfers (+item_transfer_items) | dual relations itemFrom/itemTo |
| tbl_ci (+tbl_ciupdation) | cycle_counts (+cycle_count_updates) | classification_id/items_id are TEXT in legacy (flagged anomaly) |
| tbl_sloc (+_sub) | slocs (+sloc_items) | stock location ledger |
| tbl_party_ldg | party_ledgers | |
| tbl_stldg_good / tbl_stldg_damage | stock_ledger_goods / stock_ledger_damages | 105K + 852 rows |
| tbl_gate | gate_passes | trid is polymorphic varchar — indexed only |
| tbl_order | reorder_levels | reorder-level config (empty) |
| tbl_pindents / tbl_eindents / tbl_stock / tbl_issuestock / tbl_ireturn | pindents / eindent_registers / stock_headers / issue_stock_headers / internal_returns | empty in source; structure kept |

## Verified Relationship Findings (join audits)

- `e_indent_items.id_in → e_indents.id`: 289,900 matches (**correct key**; `tid` joins nothing). `e_indents.id` is a per-year document number — NOT unique; safe for Eloquent reads, future FK needs composite (yearcode,id) UNIQUE.
- `*_sloc` ledgers are type-discriminated: `arr_type` / `issue_type` / partial captive coverage choose their header source (e.g. 74,371 of 75,390 issue_sloc rows target e_indents). No single belongsTo declared; relate conditionally per type in Phase 3+ report code.
- Full-match detail links kept as model relations: arrival_items(18,848), issue_items(74,211/74,217 — 6 legacy orphans), discard_items, excess_items, dtog/gtod/iitr items, sloc_items, ciupdation, captivesub.

## Data-Quality Findings & Handling

1. `'0000-00-00'` pseudo-dates → scrubbed to NULL during transform.
2. `party_id = 0` sentinel ("no party") on 42,294 of 42,635 issues → keep verbatim; relation queries must filter `party_id > 0`.
3. Plaintext passwords across 4 auth tables → bcrypt at seed time.
4. 6 orphaned `tblissue_sub` rows + 324 unmatched `captive_slocs` + assorted sloc orphans → logged for the Phase-later integrity migration (not silently dropped).
5. `tbl_ireturn.date int`, `tbl_ci.* text` FK columns → kept verbatim, flagged with column comments.

## Phase 4 � e-Indent Module (delivered)

- Raise pipeline rebuilt atomically with Form Request validation
  (App\Http\Requests\EIndent\StoreEIndentRequest): header + N item lines in one transaction.
- CRITICAL CORRECTION: detail linkage is e_indent_items.id_in -> e_indents.tid
  (the PK), proven by the legacy INSERT handler using mysql_insert_id()
  and a 97.3% join match. The earlier -> id hypothesis (raiser login-id)
  was coincidental cross-matching. FK k_e_indent_items_id_in now enforced;
  **99 constraints total**; the 2,111 legacy stragglers were audit-nulled by integrity-fix.
- Raiser identity: e_indents.id stores tbl_roles.id -> consolidated users.code
  (values verified against historical distinct set 0..122).
- Numbering: legacy assembled a TIR string but the INT column silently truncated
  it under MyISAM; the modern pipeline stores the per-year running integer in
  code1 at draft time, assigns code on COMMIT (tflg=1), and renders the
  pretty reference at view time (App\Support\EIndentNumber).
- Lifecycle: draft -> add/remove lines / edit remarks -> commit (immutable).
  Ownership enforced between raisers; other roles bounced to their own dashboards.
- Feature suite Phase4EIndentTest: 5 tests incl. atomicity, validation block,
  - Feature suite Phase4EIndentTest: 5 tests incl. atomicity, validation block,
  running-number commit + post-commit lock, ownership 403.

See **docs/PHASE5.md** for the Operator Module (slice 1: issue-against-e-Indent)
delivery record and full-suite status.

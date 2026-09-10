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
| Feature suites: Phase1PipelineTest, Phase2AuthTest, Phase3ViewerReportsTest | ✅ **17 passed / 59 assertions** on the migrated data; Pint clean (145 files) |

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

## Next phases (per approved plan)

Masters CRUD → e-Indent raise → Issue-against-e-Indent (StockLedgerService
first caller) → remaining movement types → bincard → remaining reports →
QR/backup/audit screens → UI unify → performance → cutover docs.

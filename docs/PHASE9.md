# Phase 9 — Arrivals family (vendor GRN, stock transfer in, internal return, ITI/ITA) + Material Discard + Excess/Shortage + Gate movements (G2D/D2G)

Survey of the legacy sources and the implementation plan. No code written yet.

## 1. Scope and legacy sources

Four inbound-movement modules, one phase:

| Module | Legacy sources (Transaction/) | Migrated tables |
|---|---|---|
| Vendor GRN | `add_arrival_vendor.php` (2,355 ln workspace), `add_arrival_vendor_preview.php` (posting), `add_arrival_vendor_view.php`, `getuser_arrv_slocshowg/d.php` (sloc panes), `getuser_arrv_sbinckv.php` (sloc availability), `add_arrival_vendor1.php` (line reset) | `arrivals`, `arrival_items`, `arrival_slocs` |
| Stock transfer in | `add_arrival_stocktransfer.php` (+2/22/1111 variants), `add_arrival_stocktr_preview.php` (posting), `add_arrival_stocktr_view.php`, `getuser_stupdateform.php` / `getuser_steditsubupdate.php` (line CRUD) | same |
| Internal return | `add_return_stores.php` (+2/22/old variants), `add_return_stores_preview.php` (posting), `add_returnd_stores*.php` (damage-return variant), `getuser_imroupdateform.php` / `getuser_imroeditsubupdate.php` / `getuser_imrodeditsubupdate.php` (line CRUD incl. the `tblarrival` header insert) | same |
| Inter-item transfer (ITI/ITA) | `add_interitem.php` (1,418 ln workspace), `add_iitr_preview.php` (posting) | `item_transfers`, `item_transfer_items` |
| Material Discard (MD) | `add_material_discard.php` (1,329 ln workspace), `add_discard.php` (queue/home), `add_discard_str_preview.php` (posting), `add_discard_str_view.php`, `getuser_discard3.php` (line save), `getuser_discard_slocshow.php` (damage availability) | `discards`, `discard_items`, `discard_slocs` |
| Excess/Shortage (ES) | `add_e.php` / `add_e1.php` (entry + line save), `add_exsh_preview.php` (posting), `add_exsh_view.php`, `edit_exsh.php` (line edit), `getuser_exsh_slocshow.php` (stock availability), `select_es_op.php` (bin status sheet), `add_shortage.php` / `add_shortage1.php` (queue), `es_note_print.php` (note print) | `excesses`, `excess_items` |
| Gate movements (G2D/D2G) | `add_g.php` / `add_d.php` (queues + purge), `getuser_gdupdate.php` + `getuser_gdetdupdate.php` (G2D line save), `getuser_dgupdate.php` (D2G line save), `add_gtod_preview.php` + `add_dtog_preview.php` (posting), `gtodnote.php` / `dtognote.php` (notes), `select_gtod.php` / `select_d2gn.php` (output type), `getuser_gd_slocshow.php` + `getuser_dg_slocshowd.php` (availability), `home_gtodsloc.php` + `gooddamage_stock_sloc.php` (dead-end home redirects) | `gtods`, `gtod_items`, `dtogs`, `dtog_items` |

The `internal_returns` table (rid/code/rfs/rbd…) is NOT the internal-return
document store — the live flow stores internal returns as `arrivals` rows
with `arrival_type='Internalreturn'`. Verify the internal_returns table's
actual legacy producer before touching it; the port ignores it.

## 2. Verified legacy posting semantics

### 2.1 Vendor GRN (add_arrival_vendor_preview.php)

Workspace: header row is created lazily (AJAX-era comment: "already created
via AJAX on first item add"); the final submit UPDATES the header with the
vendor/transport block (dcno, party_id, porefno, tmode, trans_* , courier_*,
docket_no, pname_byhand, remarks) and then posts.

Per arrival_item × sloc row (`tblarr_sloc` via arr_tr_id=arrival_id,
arr_id=arrsub_id):

- **Good branch** (`qty_damage==0 && ups_damage==0`): good ledger row
  `('Arrival','Vendor',trid=arrival_id,partyid,arrival_date,...)` with
  opening = latest balance at (item, wh, bin, subbin), tr = good ups/qty,
  bal = op + tr; sub-bin `status='Good'`.
- **Damage branch** (otherwise): damage ledger twin with the damage ups/qty;
  sub-bin `status='Damage'`.
- **Legacy quirk (preserve + flag):** a sloc row carrying BOTH good and
  damage quantities posts only the damage side; the good side is silently
  dropped (single if/else, not a split). Port preserves verbatim and
  documents; a follow-up can add a warning.

Per arrival_item — party ledger row (`tbl_party_ldg`), type/subtype
'Arrival'/'Vendor', trid = arrival_id:

- dcups/dcqty = ups_per_dc/qty_per_dc; good/damage from the item row;
- `exqty = goodqty + damageqty − dcqty` floored at 0 (excess);
- `shqty = dcqty − goodqty + damageqty`, then `if (shqty > 0) shqty = 0`
  (shortage is ≤ 0 in legacy — preserve verbatim);
- balance = latest party-ledger balance for (party, class, item) + good
  side; party-ledger balance seeded from good only (damage excluded).

Reorder pass (per item with `srl_status='Yes'`): the degenerate sum over
locations discovered by a query that filters `stlg_tritemid != item` then
re-filters by the item — the known no-op quirk already implemented as
`applyReorderFlag` in Phase 6/7; same helper applies. Condition
`tqty <= srl && srl_status != 'OR'` → `orstatus='R'` on positive-balance
rows.

Commit: `arr_code = MAX(arr_code)+1` and `ncode = MAX(ncode)+1` per
(yearcode, arrival_type='Vendor'), `arrtrflag=1`. NOT transactional in
legacy (die(mysql_error()) mid-loop leaves half-posted documents) — the
port wraps the whole post in one DB transaction.

### 2.2 Stock transfer in (add_arrival_stocktr_preview.php)

Identical skeleton; differences:
- header field `stnno` (STN no) instead of dcno; `arrival_type='Stocktransfer'`;
- ledger subtype `'Stocktransfer'` (both ledgers);
- **no party-ledger write**, no DC quantities, no excess/shortage;
- commit counters per (yearcode, arrival_type='Stocktransfer').

### 2.3 Internal return (add_return_stores_preview.php + getuser_imro* family)

- Header: `arrival_type='Internalreturn'`, `stageret` (rfs = returned-from
  stage), `retid` (rbd = returned-by/beneficiary?), `type='Good'`, party_id
  (the returning party), created by the `getuser_imroupdateform` AJAX
  family (which inserts `tblarrival` + `tblarrival_sub` + `tblarr_sloc`
  rows directly — the line CRUD entry points).
- Posting: good/damage branches identical to vendor; ledger subtype
  `'Internalreturn'`; party-ledger NOT written (verified: no tbl_party_ldg
  insert in the preview);
- Commit counters per (yearcode, arrival_type='Internalreturn').
- The consumption report reads `Arrival`/`Internalreturn` from the GOOD
  ledger — consistent.

### 2.4 Inter-item transfer ITI/ITA (add_iitr_preview.php)

Document = `tbl_iitr` (→ item_transfers: iitr_id, tdate, classification,
items_id_from, uom_from, typ, remarks, iitrflg) with sub rows
`tbl_iitr_sub` (→ item_transfer_items: per-destination-line items_id,
whid/binid/subbinid, ups_to, qty_to, classification_id, grouped by `rowid`
= source good-ledger row id).

Posting (per rowid group; trtype literal verified in
add_iitr_preview.php: **'IT'** for both sides, trid = iitr_id, no party
id — stlg_trpartyid absent from the legacy insert):
- **ITI (out)** at the SOURCE location (from the referenced stlg row):
  tr = Σ(ups_to), Σ(qty_to) of the group; bal = op − tr; subtype 'ITI'.
- **ITA (in)** per sub row at the DESTINATION: tr = ups_to/qty_to; bal =
  op + tr — with the legacy quirk `bal = tr` when opups == 0 / opqty == 0
  (balance reset instead of add; preserve verbatim, flag in docs); subtype
  'ITA'.
- Reorder pass after each side (same applyReorderFlag helper).
- **Numbering gap:** the active commit path assigns NO code (the
  MAX(code)-from-tbl_gtod block is commented out). Decision: bootstrap a
  `itemtransfer` DocumentNumber counter (pattern from issue counters) and
  assign iitr_code + ncode-style serial at commit; document the deviation.
- Commit flag: set `iitrflg=1` (column exists; verify legacy sets it —
  if not, still set it as the readable posted marker mirroring issues'
  status column).

### 2.5 Material Discard MD (add_discard_str_preview.php + getuser_discard3.php + getuser_discard_slocshow.php)

Document = `tbl_discard` (→ discards: tid, tdate, drno, party_name +
address block, tmode/tname/lrno/vno/cname/dcno/pmode/pname/rettyp,
remarks, yearcode, ddrole, ddflg, tcode, dd_code, ncode) with per-item
rows `tbl_discard_sub` (→ discard_items: did_s → tid, calssification_id
[legacy spelling], items_id, uom, ups, qty, type, remark) and
per-source-row rows `tbl_discard_sloc` (→ discard_slocs: discard_type
'MD', discard_trid → tid, discard_id → did, classification_id, item_id,
whid, binid, subbin, qty_discard, ups_discard, qty_balance, ups_balance,
discard_rowid → stld_id, eid).

Posting (verified in add_discard_str_preview.php):
- One damage-ledger OUT per discard_slocs row: trtype **'Discard'**,
  subtype **'MD'**, trid = tid, trdate = tdate, classid/item/location
  from the referenced stld row, tr = sloc qty/ups, op = latest damage
  row at that item×location, bal = op − tr. Legacy wrote party_name
  into stld_trpartyid verbatim (a string — no party id lookup).
- **NO reorder pass** (unlike the issue/ITI writers).
- **Empty flip is SCOPED**: when the moved item's balance hits 0,
  legacy collects the DISTINCT locations hosting OTHER items of the
  same class (stld_trclassid = class AND stld_tritemid != item), takes
  the latest row per location regardless of item, and only leaves the
  sub-bin at 'Empty' when NO such location still holds a positive
  balance. The check runs on the just-written row's balance (the
  legacy writer loops on balqty), not on the baseline row the UI
  picked. Ported as restoreLegacyEmptyScope() after the service's
  unconditional balqty==0 flip.
- Counters at commit: dd_code = MAX+1 per yearcode (DocumentNumber
  'discard', TDD…), ncode = MAX+1 per yearcode ('discard.n'),
  ddflg = 1; one gate_passes row with gpcode = MAX+1 per yearcode
  ('gatepass' counter) and trid = "MD{dd_code}". Header tcode = tid on
  create (the trid=0 AJAX branch wrote the header then set tcode).
- Availability (getuser_discard_slocshow.php): selectable rows =
  MAX(stld_id) per item×location with stld_balqty > 0 ON THAT ROW — a
  superseded row at a location (a later row exists) is never listed
  even when its stored balance is positive.

### 2.6 Excess/Shortage ES (add_e1.php + add_exsh_preview.php + getuser_exsh_slocshow.php)

Document = `tbl_excess` (→ excesses: tid, code [draft serial], tdate,
classification_id, items_id, uom, remarks, yearcode, ups/qty [totals],
typ 'good'|'damage', esflg, escode, ncode) with per-ledger-row rows
`tbl_excess_sub` (→ excess_items: essubid, esid → tid, whid/binid/
subbinid [copied from the referenced row], qtyex/upsex, qtysh/upssh,
balups/balqty [client-shown post balance], rowid → stlg_id/stld_id).

One document adjusts ONE item at one or more SLOCs, in the ledger the
header `typ` selects (the whole document is good OR damage):
- Availability (getuser_exsh_slocshow.php): selectable rows = MAX
  (stlg_id/stld_id) per item×location with balqty > 0 ON THAT ROW; each
  row carries an excess pair and a shortage pair — the legacy JS empties
  one pair when the other is filled (upschk/upschk1), so a row adjusts
  up (ES) or down (SH), never both.
- Posting (add_exsh_preview.php frm_action=submit), per excess_items
  row: ONE ledger row in the header's ledger, trtype **'ES'**, trid =
  tid, trdate = tdate, op = the referenced row's balance, **no party
  id** (stlg_trpartyid NULL on all legacy ES rows), and
  - excess side (upsex/qtyex present): subtype **'ES'**, bal = op + ex;
  - shortage side (else): subtype **'SH'**, bal = op − sh. (The legacy
    branch keys on upse==0 && qtye==0 — a row with BOTH sides empty
    posts a shortage of 0/0. Preserved.)
- **NO sub-bin status flip** — the legacy ES writer never touches
  tbl_subbin (verified: a full drain leaves the sub-bin status alone).
- **NO UPS normalization** — the raw bal values were inserted into
  UNSIGNED columns; the port clamps to 0 the way the columns silently
  did, and bounds the shortage with a server-side balance check (the
  legacy UI checked in JS only).
- Reorder pass (verbatim): once per document, when the item is
  srl-tracked and its summed positive good-ledger balance drops to/
  below `srl`, all positive rows are flagged orstatus='R' (the
  class-scoped legacy query degenerates to the item sum — same
  documented semantics as applyReorderFlag).
- Commit: escode = MAX+1 per yearcode ('excess' counter), ncode =
  MAX+1 per yearcode ('excess.n'), esflg = 1. No gate pass. Draft
  `  code` = MAX+1 per yearcode at creation (add_e1.php).
- Legacy quirks handled deliberately: the queue screen (add_shortage
  .php) DELETED every esflg=0 document on page load — the port keeps
  open workspaces with edit_exsh.php's delete-and-reinsert semantics;
  the bin status sheet (select_es_op.php) filtered subtype='ES' and hid
  shortage rows — the port lists both sides.

### 2.7 Gate movements G2D/D2G (getuser_gdupdate.php + add_gtod_preview.php / getuser_dgupdate.php + add_dtog_preview.php)

Legacy flow: the home pages (home_gtodsloc.php / gooddamage_stock_sloc
.php) are dead-end redirects to the queues (add_g.php / add_d.php). The
line save (AJAX getuser_gdupdate.php / getuser_gdetdupdate.php for G2D,
getuser_dgupdate.php for D2G) creates the header lazily (trid=0 branch,
flag=0) and inserts only the DESTINATION slots as document rows — the
SOURCE SLOC is referenced by its ledger row id (rowid), whose ups/qty
the operator picks from the availability pane (getuser_gd_slocshow.php /
getuser_dg_slocshowd.php: latest row per location with balqty > 0). The
preview screen (add_gtod_preview.php / add_dtog_preview.php) posts on
frm_action=submit:

- G2D — per tbl_gtod_sub rowid group (`SELECT sum(ups),sum(qty) … GROUP
  BY rowid`):
  - GOOD ledger out row: trtype/subtype 'GD', trid = gid, trpartyid =
    the header's party; opening = the LIVE latest good balance at the
    source location (the preview re-queried MAX(stlg_id) at post time);
    bal = op − tr (balups with the legacy max(0,…) UNSIGNED clamp);
    on balqty == 0 the Empty flip runs through a scoped cross-item
    check that was DEAD CODE (the counter `$totnog` is undefined), so
    the flip was UNCONDITIONAL — same as the service writer.
  - DAMAGE ledger in row PER gtod_items row: trtype/subtype 'GD',
    opening = the latest damage balance at the destination (0 when
    none), bal = op + tr with the ES-style UPS normalization; subbin
    status → 'Damage' UNCONDITIONALLY (the GD writer never scopes the
    flip).
  - PARTY ledger row: ONE per document (legacy summed the whole
    tbl_gtod_sub, keyed on the header's party/class/item) — damage =
    Σ tr, bal = opening − damage; every other side (dc/good/ex/sh)
    written as 0.
  - Reorder pass once per document (good ledger, verbatim degenerate
    sum semantics — see applyReorderFlag).
  - Commit: gcode/ncode = MAX+1 per yearcode, gdflg = 1. No gate pass
    (the GD writer never touches tbl_gate — G2D is a bin-level
    conversion, not a physical gate movement).
- D2G — per tbl_dtog_sub rowid group, trtype/subtype 'DG' both ledgers:
  - DAMAGE ledger out row: trid = did, trpartyid 0 (the D2G writer
    passes no party); balqty = op − tr BUT stld_balups = op VERBATIM
    (the insert literally writes the opening into the balance column —
    UPS is NOT decremented; preserved, then unsigned-clamped).
  - GOOD ledger in row per dtog_items row: ES-style balance (op + tr,
    normalization), subbin status → 'Good' unconditionally.
  - NO party-ledger row, NO reorder pass (the DG writer has neither).
  - Commit: dcode/ncode = MAX+1 per yearcode, dgflg = 1. No gate pass.
- Legacy quirks handled deliberately: the queues (add_g.php /
  add_d.php) PURGED every open document (flag=0) on page load — the
  port keeps open workspaces with delete-and-reinsert row edits (same
  deviation as the ES slice); the header's party is G2D-only (tbl_dtog
  has no party_id column); draft serials use new `gtod.draft` /
  `dtog.draft` counters (legacy left them blank).


## 3. Laravel-side reuse (verified present)

- `StockLedgerService::post(array $p)` — direction in/out, `damage` flag,
  latest-row opening, UNSIGNED UPS normalization, returns the ledger row.
  Covers all four posting paths unchanged.
- `applyReorderFlag` (same degenerate legacy semantics) — reuse for all
  four flows' reorder passes.
- Models exist verbatim: `Arrival`, `ArrivalItem`, `ArrivalSloc`,
  `ItemTransfer`, `ItemTransferItem` (+ `PartyLedger` for the vendor GRN).
- `DocumentNumber` counters seeded from legacy MAX per yearcode (Phase 5/6
  pattern) — add `arrival.vendor`, `arrival.stocktransfer`,
  `arrival.internalreturn` (each with a `.n` ncode twin) and
  `itemtransfer`.
- Controller/workspace pattern from Phase 6/7: header created on FIRST
  line post (trid=0 branch), AJAX line save/edit/delete with server-side
  validation, edit = delete-and-reinsert (legacy semantics), transactional
  idempotent final post, readable `status` column (open|posted) via
  migration, audit rows per action.
- Availability endpoint pattern: `IssueAvailabilityController` → new
  `ArrivalAvailabilityController` implementing the `getuser_arrv_sbinckv`
  semantics (latest balance per sub-bin for the item, readonly display).

## 4. Implementation slices (each ends green: lint + suite + Pint)

1. **Schema + foundations** — DONE: migration `000053` adds `status`
   (open|posted) on `arrivals` and `item_transfers` with backfill from
   `arrtrflag`/`iitrflg` (Phase 6 idiom); `arrival.internal` added to the
   `LegacyMigrateData` counter seeder; `App\Support\ArrivalNumbering`
   provides per-type committed/note counters (`arrival.{vendor,stocktr,
   internal}` + `.n` twins, lazy-primed from MAX(arr_code)/MAX(ncode)) and
   `primeItemTransferCommitted` on the `iitr` counter. The sub-bin
   Good/Damage flip was ALREADY present in StockLedgerService::post
   (direction 'in' branch) — no new helper needed. Verified:
   `item_transfer_items.rowid` is the source good-ledger row id (and a
   `rowid_to` twin exists); the seeder's lowercase arrival_type literals
   match under the case-insensitive collation.
2. **Vendor GRN slice** — `Arrival\ArrivalController` (type=vendor):
   create/list queue, AJAX line + sloc workspace (good/damage split panes,
   sloc availability), preview/post (transactional, idempotent): ledger
   rows both ledgers, sub-bin flips, party-ledger row with excess/shortage
   math (verbatim formulas incl. the `shqty>0 → 0` and mixed-sloc quirks),
   reorder pass, commit `arr_code`/`ncode`/`arrtrflag`+status. Views:
   queue, workspace, view/posted. Audit `arrival.vendor`.
3. **Stock-transfer-in slice** — same controller, type=stocktransfer:
   stnno header, no party ledger/DC, subtype 'Stocktransfer', own
   counters. Audit `arrival.stocktr`.
4. **Internal-return slice** — same controller, type=internalreturn:
   stageret/retid header, `type='Good'` column, subtype 'Internalreturn',
   own counters. Audit `arrival.internalreturn`.
5. **ITI/ITA slice** — DONE: `Arrival\ItemTransferController` routes
   under `itransfers.*` (index/create/sources/destinations/lines/
   workspace/header/post/show); workspace keyed by source good-ledger
   row groups (rowid), group-level edit = legacy delete-and-reinsert;
   post = one ITI out per group + one ITA in per destination row
   (verbatim bal-reset quirk computed per side, sub-bin flips per
   StockLedgerService semantics), reorder pass both sides,
   iitrflg=1 + status, `iitr` counter (documented deviation for the
   legacy numbering gap). Audit `itransfer.conversion`.
   Suite: `Phase9ItemTransferTest` (14 tests: conservation, both quirk
   branches, overflow guard, group edit, idempotency, immutability,
   numbering isolation, render/gates).
7. **Material Discard slice** — DONE: `DiscardController` routes under
   `discards.*` (index/create/items/availability/lines.store/lines.update/
   lines.delete/workspace/header.update/post/show) with the operator
   card on the dashboard; damageRowsFor() implements the
   getuser_discard_slocshow semantics; post() = one MD out per
   discard_slocs row, transactional + idempotent, no reorder pass,
   scoped Empty flip restored per §2.5, dd_code/ncode/gpcode counters,
   ddflg = 1, audit module `discard.md`. Also fixed here:
   StockLedgerService::post read the good-ledger stlg_* opening
   columns for damage rows (stld_*) — latent since the service was
   written, exposed by the first damage-ledger posting path.
   Suite: `Phase9DiscardTest` (13 tests, hermetic, sweeps discard
   artifacts incl. the trid=0 seed rows; differential counter
   assertions primed via DocumentNumber::prime).
7. **Excess/Shortage slice** — DONE: `ExcessShortageController` routes
   under `exshorts.*` (index/create/items/availability/store/workspace/
   header.update/post/show) with the operator card replacing the
   placeholder Adjustments entry; stockRowsFor() implements the
   getuser_exsh_slocshow semantics for both ledgers; post() = one ES or
   SH row per excess_items row (side per row, verbatim math, no
   sub-bin flip, no UPS normalization), transactional + idempotent,
   escode/ncode counters, esflg = 1, audit module `adjustment.es`.
   Also added here: `excess.n` + `excess.draft` counter seeds
   (DocumentNumber::PREFIXES already carried 'excess' => 'TES').
   Suite: `Phase9ExcessShortageTest` (14 tests, hermetic, sweeps ES
   artifacts in both ledgers incl. the negative-trid seed rows; the
   stocked-item helpers exclude srl-tracked items so the reorder pass
   can never re-flag migrated rows).
8. **Gate movement slice** — DONE: `GateMovementController` routes
   under `gatemovements.*` (index, index-d2g, create + create-d2g,
   items, availability, store, workspace/workspace-d2g, header.update
   + header.update-d2g, post + post-d2g, show/show-d2g) with the two
   operator cards replacing the placeholder Gate movements entry;
   stockRowsFor() implements the getuser_gd_slocshow /
   getuser_dg_slocshowd semantics for the direction's SOURCE ledger
   (good for G2D, damage for D2G); post() = verbatim §2.7 math — good
   out row via StockLedgerService (G2D) and the hand-written DG damage
   out row with the balups = op quirk (D2G), ES-style destination in
   rows with unconditional Damage/Good flips, one party-ledger row per
   G2D document (all non-damage sides 0, as legacy wrote them), the
   G2D reorder pass, gcode/dcode + ncode commits, flags = 1, no gate
   pass, transactional + idempotent. Audit modules `gatemovement.g2d`
   / `gatemovement.d2g`. No seeder change needed (the gtod/dtog/
   gatepass counter seeds were already provisioned).
   Suite: `Phase9GateMovementTest` (15 tests, hermetic, sweeps GD/DG
   ledger rows, party-ledger rows and documents in both directions
   incl. the negative-trid seed rows; the stocked-item helpers exclude
   srl-tracked items and rows with trid <= 0 so other suites' unswept
   seed debris can never be picked).
6. **Test suites** — `tests/Feature/Phase9ArrivalsTest.php` (hermetic,
   pipeline-gated like Phase 6/7): auth/role gates, FY gate, vendor
   good-only / damage-only / mixed-sloc happy paths (assert both ledgers,
   sub-bin status, party-ledger row + excess/shortage values, counter
   sequencing, idempotent re-post rejection), stock-transfer happy path
   (no party-ledger row), internal-return happy path, ITI/ITA conservation
   (Σ out = Σ in; source decremented; destination incremented incl. the
   op==0 reset quirk), negative-balance guard, per-type numbering
   isolation.

## 5. Open items to resolve during implementation

- Mixed good+damage sloc row: preserve legacy (damage side only wins) and
  log a warning row; decide with the user whether a split-post fix is
  wanted (default: preserve).
- `shqty` sign: legacy stores shortage as ≤ 0 values (dc − good +
  damage, then floored to 0 from above); keep verbatim for party-ledger
  parity with the partywise report.
- ~~item_transfer_items column names~~ RESOLVED in slice 1: `rowid` holds
  the source good-ledger row id (grouping key), `rowid_to` a second ref;
  `items_id_from`/`ups_from`/`qty_from` exist on the sub table.
- internal_returns table provenance — likely an unused/parallel legacy
  store; confirm before Phase 9 touches it (default: untouched).

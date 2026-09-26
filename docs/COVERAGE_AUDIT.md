# Legacy coverage audit — every legacy screen accounted for

Run 2026-09-26, after the Phase 10 completion commit `ffa9216`.
Scope: all legacy PHP (`*.php` at the repo root plus `Masters/`,
`Transaction/`, `reports*/`, `utility/`, `help/` — ~660 files, many of
them copies/variants), classified against the port's route surface
(159 named routes) and the Phase records in docs/IMPLEMENTATION.md.

## 1. Reachable legacy surface (menu → screens)

The legacy menus are Dreamweaver MM_showMenu popups; the real targets
live in `include/menu.js`, which links exactly seven hub pages:

| Hub | Contents |
|---|---|
| `companyhome.php` | company profile CRUD (`add_company.php` → tbl_parameters) |
| `stores_home.php` | store (warehouse) home |
| `home_classification.php` | classification/items |
| `party_Masterhome.php` | parties |
| `selectbin.php` | bins |
| `role_home.php` | user/role management (`add_indentrole.php`, `edit_indentrole.php`) |
| `regionmaster_home.php` | **does not exist anywhere in the legacy tree** — dead menu link |

Plus the role dashboards (`index.php`, `indexopr.php`, `indexview.php`,
`indexindet.php`) and utility/backup.

## 2. Ported (screen-for-screen, verified by suites)

| Legacy area | Port |
|---|---|
| Login/logout/forgot-password Q&A (`validatelogin.php`, `forgotpassword*.php`) | Auth slice (Phase 2), gradual hash migration |
| 4 role dashboards | DashboardController (all cards now real routes) |
| All Masters CRUD: warehouse, bin, sub-bin, classification, item, party | Phase 4 `masters.*` |
| Company profile (`add_company` / tbl_parameters) | `company_settings` table + pipeline migration; profile used by QR plantcode & PDFs. **No edit screen** — see gaps |
| e-Indent raise + approval (`indexindet` family) | Phase 5 + admin approvals card |
| Issue against e-Indent, SLOC distribution | Phase 6 |
| Movement types: pindent, stock transfer, MRTV, captive consumption, discard, excess/shortage, gate movements | Phase 7 + Phase 9 slices 6–7 |
| Arrivals: vendor GRN, stock transfer in, internal return, ITI/ITA | Phase 9 `arrivals.*`, `itransfers.*` (incl. print views: arrivals.show supersedes `Grnnote.php`/`STRN_note.php` print pages) |
| All nine viewer reports (`reports*/`) | Phase 3 + Phase 8 |
| Backup (`utility/backup.php`) | Phase 10 slice 1 |
| QR subsystem (`utility/getuser_qrcode.php` + save/print pages) | Phase 10 slice 3 |
| Year state machine (`current_year.php`, `closeyear.php`) | Phase 10 slice 4 `admin.years.*` |
| User listing + suspend (`role_home.php` read side, add_operator vocabulary) | Phase 10 slice 4 `admin.users.*` |

## 3. Gaps (legacy screens with no port counterpart)

1. **User creation/edit screens** — legacy `add_operator.php`,
   `add_viewer.php`, `add_indentrole.php`, `edit_indentrole.php` create
   and edit accounts (with duplicate login/email checks and security
   question). ~~The port deliberately consolidated identity into `users`;
   account creation currently happens outside the app (SQL/seed) and
   `admin.users` is oversight-only.~~ **CLOSED — Phase 11 slice 1:**
   admin create/edit with the legacy vocabulary (see
   docs/IMPLEMENTATION.md); the admin is self-sufficient.
   **Remaining value: the country/state and company-profile gaps.**
2. **Company profile editor** — `add_company.php`/`edit_company.php`
   edit the single `tbl_parameters` row (now `company_settings`).
   ~~The row is migrated, but there is no admin screen to edit plant
   code, address, licence/TIN, etc.~~ **CLOSED — Phase 11 slice 2:**
   `admin.company.edit` over the id=41 row, including the plant code
   the legacy schema never actually stored (see
   docs/IMPLEMENTATION.md). The editor also fixed a latent QR bug:
   QrSerial read the multi-line plant address as the code prefix.
3. **Country/state masters — explicitly SKIPPED (settled 2026-09-26)** —
   `add_country.php`, `add_state.php`, `edit_*`, `home_country.php`,
   `home_state.php` over `tbl_country`/`tbl_state` (now
   `countries`/`states`). The evidence says these screens were already
   dead in legacy:

   - **Menu-orphaned**: `include/menu.js` — the only menu definition —
     links none of them; the closest menu entry is
     `regionmaster_home.php`, a file that does not exist anywhere in
     the legacy tree. No nav path reaches these screens; the only
     inbound links are each other's (home ↔ add/edit).
   - **Near-empty data**: the live legacy rows migrated to 1 country
     (India) and 0 states; nothing wrote to them in production.
   - **The one consumer used them loosely**: the party form populated
     its country `<select>` with `SELECT DISTINCT country FROM
     tbl_country` (add_party_master.php:552) but `state` was free
     text and the country was stored as a plain string on the party
     row — no FK in either direction.

   Porting them would re-introduce maintenance surface for screens no
   user flow can reach and no business logic depends on. The port's
   party form already covers the actual legacy behavior (country
   pre-filled 'India', free-text state). If a future party-form
   upgrade wants dropdowns, seed `countries`/`states` and switch the
   inputs — the models and migrated tables are already there.
4. **Dead/absent legacy screens, intentionally not ported** —
   `regionmaster_home.php` (menu link to a file that doesn't exist),
   `qrcode_recovery.php` (0 bytes), `setup_qrcode_db.php` (runtime DDL,
   replaced by migrations), per-year `expro*` databases (single-DB
   design), the dozens of `*1.php`/`*_old.php`/`Copy of *.php` variants.

## 4. Verdict

Every reachable legacy *business* screen has a port counterpart with
suite coverage. Of the three admin CRUD gaps, two are closed in
Phase 11 (slices 1–2: user accounts, company profile) and one is an
explicit, evidence-backed skip (country/state — see gap 3): no menu
path reached those screens in legacy, no consumer depends on the
tables, and the party form already reproduces the loose string-based
behavior legacy actually exhibited.

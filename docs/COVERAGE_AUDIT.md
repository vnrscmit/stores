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
3. **Country/state masters** — `add_country.php`, `add_state.php`
   (`tbl_country`/`tbl_state` → `countries`/`states`). Tables migrated
   (`countries` has rows; `states` is empty); no screens. Legacy kept
   them barely populated; party master does not depend on them.
4. **Dead/absent legacy screens, intentionally not ported** —
   `regionmaster_home.php` (menu link to a file that doesn't exist),
   `qrcode_recovery.php` (0 bytes), `setup_qrcode_db.php` (runtime DDL,
   replaced by migrations), per-year `expro*` databases (single-DB
   design), the dozens of `*1.php`/`*_old.php`/`Copy of *.php` variants.

## 4. Verdict

Every reachable legacy *business* screen has a port counterpart with
suite coverage. The gaps are three small admin CRUD screens (users,
company profile, country/state) — none affect transactions, ledgers or
reports. Together they'd form a natural "Phase 11: admin master
completion" slice (estimate: the users CRUD is the only non-trivial
one).

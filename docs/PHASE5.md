
## Phase 5 — Operator Module, slice 1: Issue-against-e-Indent (delivered)

- Operator picks a COMMITTED (tflg=1), open (flg=0) e-Indent, chooses
  warehouse/bin/sub-bin and quantity per line, then commits the issue atomically.
- Core writer App\Support\StockLedgerService::writeIssue() ported from
  Transaction/add_issue_eindents_preview.php:
  - opening balance = stlg_balups/stlg_balqty of latest row (MAX stlg_id)
    at the item x warehouse x bin x sub-bin
  - inserts an Issue/eindent ledger movement; closing = opening - issued
  - exhausted sub-bin flagged status=Empty
  - older positive rows of the item flagged orstatus=R (superseded)
- Creates the issues header (issue_type=eindent) with per-year running
  issue_code, then closes the indent (flg=1).
- UI: operator queue of committed indents (open/issued badge filter), per-line
  location + quantity form with available-stock display.
- Feature suite Phase5OperatorTest (5 tests): role isolation, atomic
  header+ledger+close with correct running balance, closed-indent rejection
  (422), missing-location validation.

Full suite status: 21/21 feature tests passing (152 assertions) across
Phases 1-5 (Phase1 smoke green, 99 FK constraints, integrity log intact).

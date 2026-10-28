# Finance: transfers

Money moving between the user's own accounts is a transfer: neither income nor spending, and it has no category. A transfer is either a pair (both sides imported, each pointing at the other) or has no counterpart, when the other side is an account the app does not track (e.g. `Payment from <name>`, a top-up from another bank). Each transaction records who decided it (`transfer_source`): `auto` (pairing), `rule` (a `Mark as transfer` rule action) or `manual` (the user; nothing automatic touches the row again).

Pairing runs on import (after rules, on the rows the import created or completed) and on demand via `Find transfers`. Candidates are two completed, unpaired transactions in different accounts with opposite amounts to the Rappen, at most 3 days apart. Ranked by closeness (same date and time, then same day, then within 3 days, then fewer seconds apart), two transactions pair automatically only when each is the other's single closest candidate; the rest become suggestions to confirm or dismiss. Pending rows pair once they complete.

Help article: none

## Sub-features

- `transfers-auto` import pairs unambiguous transfers; the batch summary shows `Paired as transfers`; a tie (one side with several equally close candidates) becomes suggestions.
- `transfers-list` `/finance/transactions`: a transfer shows `Transfer to|from <account>` or `Transfer, no counterpart` instead of the category select, with `paired automatically` / `marked by rule` / `set by hand`; rows in an open suggestion show `suggested transfer`. Filter `Transfers`: All transactions / Transfers / Not transfers / Suggested transfers. `Find transfers` button and an `N transfer suggestions` link.
- `transfers-actions` the row menu `Transfer actions for <description>`: `Pair with…` (dialog `Pair as transfer` with candidates, `Search counterparts`, `Pair with <description> on <date> in <account>`), `Unpair` (both sides stay transfers without a counterpart, set by hand), `Mark as transfer (no counterpart)`, `Not a transfer` (unmark; an automatic counterpart goes back to plain).
- `transfers-suggestions` `/finance/transfers` (sidebar `Transfers`): `transfer-counts`, one `suggestion` row per candidate pair with `Confirm …` / `Dismiss …`. Dismissed pairs are never suggested or paired again.
- `transfers-rule` rule action `Mark as transfer` (no value) marks matches as transfers without a counterpart and clears their category; pairing may still give them one.
- `transfers-undo` undoing a batch breaks every pair with a row it created (or completed in place); the surviving side goes back to plain (and gets its category back) when pairing made it a transfer, or stays a transfer without counterpart when a rule or the user marked it.

## How to reach it

- Sidebar, Finance → `Transfers` (`/finance/transfers`) and `Transactions` (`/finance/transactions?transfer=yes|no|suggested`).
- Batch page `/finance/batches/<id>`: `Paired as transfers` in `batch-summary`, a `Review suggestions` link when there are some.

## Drive and prove

Preconditions:

- Scenario with `finance-empty`, then the owner's `storage/app/private/revolut-sample.csv` imported through [finance-import](./finance-import.md) (one mapping per `Product`, date column `Started Date` with `Y-m-d H:i:s`). Without that file, use `finance-transfers` (3 pairs, a three-way tie as 2 suggestions, an unpaired `Payment from Verify Person`).

- **Paired on import.** Batch page: screenshot `batch-summary` (`Paired as transfers`). `verify:inspect` → `finance.transfers`: `pairs`, `by_source.auto`, `pairs_with_different_timestamps` 0, `pairs_with_unequal_amounts` 0, `orphans` 0. `pair_list` lists each pair with both timestamps; compare it with the file (same `Started Date`, opposite `Amount`, other `Product`).
- **Rule.** `New rule`: `Condition 1 value` `Payment from` (starts with), `Action 1` `Mark as transfer`, `Create rule`; `Apply all rules…` shows `Marked as transfers`; apply. Inspect: `by_source.rule` equals the matches, `without_counterpart` too, their category null. Filter `Transfers` shows `Transfer, no counterpart` / `marked by rule`.
- **Unpair sticks.** Open `Transfer actions for <description>` on a paired row → `Unpair`. Both rows show `Transfer, no counterpart` / `set by hand`. `Find transfers`; inspect: `pairs` one less, `by_source.manual` 2, the two ids still without counterpart.
- **Suggestions.** With `finance-transfers`: `/finance/transfers` lists 2 suggestions; `Dismiss` one, `Find transfers`: the other side pairs automatically, `suggestions_dismissed` 1, `suggestions_open` 0.
- **Undo.** Undo the import: inspect `transactions.total` 0 (or only rows from other batches), `pairs` 0, `orphans` 0.
- **Re-import.** Upload the same file again: the batch shows `Paired as transfers` again without `Find transfers`; the rule marks its matches on import.
- **Negative.** As a second scenario user: `GET /finance/transactions/<id>/transfer/candidates`, `POST|DELETE /finance/transactions/<id>/transfer/pair`, `POST|DELETE /finance/transactions/<id>/transfer`, `POST /finance/transfers/suggestions/<id>/confirm|dismiss` of the first user's rows return 404; `POST /finance/transactions/<own id>/transfer/pair` with `counterpart_id` of the first user's transaction returns 404 and changes nothing.

## Evidence

- `batch-import.png`, `transactions-transfers.png`, `rule-form.png`, `rule-apply.png`, `unpaired.png`, `suggestions.png`, `batch-undone.png`, `batch-reimport.png`
- `inspect-after-import.json`, `pair-check.json`, `inspect-after-rule.json`, `inspect-after-unpair.json`, `inspect-after-find.json`, `inspect-after-undo.json`, `inspect-after-reimport.json`, `negative-404.txt`

## Gotchas

- Unpair keeps both sides as transfers without a counterpart; use `Not a transfer` to make a row count as income or spending again. Neither is ever paired automatically afterwards.
- A row with a hand-set `Not a transfer` still gets categories from rules; a transfer never does, and the category select is replaced by the marker.
- Dismissing one side of a tie resolves it: the next `Find transfers` pairs the remaining unique candidate.
- `Find transfers` re-reads every unpaired transaction; on import, only pairs touching the batch's rows are written, so undo can remove them.

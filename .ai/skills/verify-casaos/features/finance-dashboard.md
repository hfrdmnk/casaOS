# Finance: dashboard

The dashboard at `/` sums up the user's finances (`DashboardSummary`, also served by the MCP tool `get_dashboard_summary`). Amounts are CHF.

- `net-worth`: today's balance of every non-archived account (investment and crypto snapshots included). `stale-since` (`Includes a balance last updated <d MMM yyyy>`) appears with the oldest latest-snapshot date when an account's newest snapshot is more than 45 days old.
- `balance-chart` inside `net-worth`: daily net worth of the non-archived accounts from their first snapshot or transaction to today, with a caption (e.g. `+3'832.20 (+64.3%) past year`) and a `Time range` group (`1M`, `3M`, `1Y`, `All`, `aria-pressed`; default `1Y` when the history is long enough, else `All`). The same component as on account pages (see [finance-accounts](./finance-accounts.md)). Neither `verify:inspect` nor `get_dashboard_summary` returns the history; assert the caption and range buttons.
- `balances`: accounts grouped by type with a total per type; `reconciliation-flag` (`Balance differs`) on an account with any snapshot that disagrees with the balance computed from the one before it.
- `spending`: one month's completed outflows by category, transfers and pending rows excluded, with an `Uncategorized` bucket; `Previous month` / `Next month` switch `?month=Y-m`. Each category links to its filtered transactions.
- `recurring`: the monthly recurring cost of active subscriptions (weekly × 52/12, quarterly / 3, yearly / 12) and the active ones due within 30 days (overdue included).
- `needs-attention`: the opening sentence. It names the month's spending and net worth, and links each non-zero count of uncategorized transactions (transfers excluded, `attention-uncategorized`), open transfer suggestions (`attention-transfers`) and open subscription suggestions (`attention-subscriptions`) to its filtered page. Zero counts are left out; with none it says `Nothing is waiting for review.`
- `dashboard-empty`: a user without accounts sees `Welcome to casaOS.` with `Import a CSV` and `Add an account`, and no `net-worth` or chart.

Help article: none

## Sub-features

- `dashboard-empty`, `dashboard-net-worth`, `dashboard-chart`, `dashboard-balances`, `dashboard-spending` (with month switch), `dashboard-recurring`, `dashboard-attention`.

## How to reach it

- `/` (top bar `Overview`, logo). `/?month=2026-09`.

## Drive and prove

Preconditions:

- Empty: scenario `finance-empty` (or none).
- Real: the owner's `storage/app/private/revolut-sample.csv` imported as in [finance-subscriptions](./finance-subscriptions.md), a few rules applied (e.g. `migros`/`coop`/`aldi` → Groceries, `chatgpt`/`anthropic`/`apple`/`amazon web services` → Software & Subscriptions, `sbb` → Transport, through the UI or MCP `create_rule` + `apply_rules`), and Hetzner and ente.io marked as subscriptions.
- Synthetic: scenario `finance-subscriptions` (includes an investment account whose only snapshot is 60 days old).

- **Empty.** `dashboard-empty` visible, title `Dashboard - casaOS`.
- **Ground truth.** Before looking, compute from the file: the last `Balance` per `Product` (445.46 + 1463.09 = 1908.55), and per category the October `COMPLETED`, non-`Transfer`, negative rows' `Amount - Fee` with the same contains patterns (Groceries 60.70, Software & Subscriptions 28.89, Transport 1.50, Uncategorized 49.69, total 140.78; September total 421.34). Save them in `$RUN`.
- **Populated.** Screenshot the dashboard; `verify:inspect` → `finance.dashboard` (`month`, `net_worth`, `spending_total`, `spending_by_category`, `upcoming`, `monthly_recurring`, `attention`) equals the ground truth. The inspector always reports the current month, so check other months on `/?month=Y-m` or with MCP. `Previous month` → URL `?month=2026-09`, total 421.34. The 54 October pocket transfers are not in the spending.
- **Synthetic ground truth.** Without the owner's file, compute the month's completed negative rows from `php artisan tinker --execute 'echo App\Verification\Finance\FinanceSubscriptions::csv();'` (dates relative to today); the `needs-attention` sentence and `spending` total match it for this month and for `Previous month`. `net_worth` is 9796.20 (4796.20 + 5000.00).
- **Chart.** With `finance-subscriptions`: `balance-chart` shows `1M`, `3M`, `1Y`, `All` with `1Y` pressed and a caption ending `past year`.
- **MCP.** `get_dashboard_summary` with `month` returns the same numbers.
- **Stale and flagged.** With `finance-subscriptions`: `stale-since` shows the broker's snapshot date. No state has a disagreeing snapshot, so for `reconciliation-flag` save one on `Verify Broker` (e.g. 5100 a month ago, see the date-input gotcha in [finance-accounts](./finance-accounts.md)): the row shows `Balance differs`, `accounts_flagged` lists it, and the newer snapshot clears `stale_since`.
- **Negative.** A second user's dashboard shows the empty state; their `get_dashboard_summary` has `has_accounts` false and 0 spending.

## Evidence

- `dashboard-empty.png`, `dashboard-populated-real.png`, `dashboard-real-september.png`, `dashboard-populated-synthetic.png`
- `expected.json`, `independent-sums.txt`, `independent-categories.txt`, `inspect-after-rules.json`, `mcp/NN-dashboard-*.json`

## Gotchas

- Spending uses the transaction date, which is the mapped date column (`Started Date` for Revolut), not the completion date.
- Upcoming is always relative to today, not to the month shown.
- The `needs-attention` links are inline text that can wrap onto two lines; `agent-browser click` aims at the centre of the bounding box and then misses the link. Check its `href` and follow it with `agent-browser eval "document.querySelector('[data-test=attention-subscriptions]').click()"`.
- The populated page is taller than 720 px; `net-worth`, the chart and `recurring` are below the fold of a viewport screenshot (`screenshot --full` or scroll first).

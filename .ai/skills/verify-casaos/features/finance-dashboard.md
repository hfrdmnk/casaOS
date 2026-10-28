# Finance: dashboard

The dashboard at `/` sums up the user's finances (`DashboardSummary`, also served by the MCP tool `get_dashboard_summary`). Amounts are CHF.

- `net-worth`: today's balance of every non-archived account (investment and crypto snapshots included). `stale-since` appears with the oldest latest-snapshot date when an account's newest snapshot is more than 45 days old.
- `balances`: accounts grouped by type with a total per type; `reconciliation-flag` (`balance differs`) on an account with any snapshot that disagrees with the balance computed from the one before it.
- `spending`: one month's completed outflows by category, transfers and pending rows excluded, with an `Uncategorized` bucket; `Previous month` / `Next month` switch `?month=Y-m`. Each category links to its filtered transactions.
- `recurring`: the monthly recurring cost of active subscriptions (weekly × 52/12, quarterly / 3, yearly / 12) and the active ones due within 30 days (overdue included).
- `needs-attention`: uncategorized transactions (transfers excluded), open transfer suggestions, open subscription suggestions, each linking to its filtered page.
- `dashboard-empty`: a user without accounts sees `Welcome to casaOS` with `Import a CSV` and `Add an account`.

Help article: none

## Sub-features

- `dashboard-empty`, `dashboard-net-worth`, `dashboard-balances`, `dashboard-spending` (with month switch), `dashboard-recurring`, `dashboard-attention`.

## How to reach it

- `/` (sidebar `Dashboard`, logo). `/?month=2026-09`.

## Drive and prove

Preconditions:

- Empty: scenario `finance-empty` (or none).
- Real: the owner's `storage/app/private/revolut-sample.csv` imported as in [finance-subscriptions](./finance-subscriptions.md), a few rules applied (e.g. `migros`/`coop`/`aldi` → Groceries, `chatgpt`/`anthropic`/`apple`/`amazon web services` → Software & Subscriptions, `sbb` → Transport, through the UI or MCP `create_rule` + `apply_rules`), and Hetzner and ente.io marked as subscriptions.
- Synthetic: scenario `finance-subscriptions` (includes an investment account whose only snapshot is 60 days old).

- **Empty.** `dashboard-empty` visible, title `Dashboard - casaOS`.
- **Ground truth.** Before looking, compute from the file: the last `Balance` per `Product` (445.46 + 1463.09 = 1908.55), and per category the October `COMPLETED`, non-`Transfer`, negative rows' `Amount - Fee` with the same contains patterns (Groceries 60.70, Software & Subscriptions 28.89, Transport 1.50, Uncategorized 49.69, total 140.78; September total 421.34). Save them in `$RUN`.
- **Populated.** Screenshot the dashboard; `verify:inspect` → `finance.dashboard` (`net_worth`, `spending_by_category`, `upcoming`, `monthly_recurring`, `attention`) equals the ground truth. `Previous month` → URL `?month=2026-09`, total 421.34. The 54 October pocket transfers are not in the spending.
- **MCP.** `get_dashboard_summary` with `month` returns the same numbers.
- **Stale and flagged.** With `finance-subscriptions`: `stale-since` shows the broker's snapshot date.
- **Negative.** A second user's dashboard shows the empty state; their `get_dashboard_summary` has `has_accounts` false and 0 spending.

## Evidence

- `dashboard-empty.png`, `dashboard-populated-real.png`, `dashboard-real-september.png`, `dashboard-populated-synthetic.png`
- `expected.json`, `independent-sums.txt`, `independent-categories.txt`, `inspect-after-rules.json`, `mcp/NN-dashboard-*.json`

## Gotchas

- Spending uses the transaction date, which is the mapped date column (`Started Date` for Revolut), not the completion date.
- Upcoming is always relative to today, not to the month shown.

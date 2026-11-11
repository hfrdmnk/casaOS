# Finance: transactions and categories

The transaction list at Finance → Transactions shows every transaction newest first, with filters (search in the description or payee, account, category or uncategorized, date range), pagination (50 per page), and a payee and a category select per row (see [finance-payees](./finance-payees.md) and [finance-rules](./finance-rules.md)). Categories (Finance → Categories) are a flat per-user list seeded with 12 defaults; they can be added, renamed and deleted (transactions become uncategorized).

Help article: none

## Sub-features

- `transactions-filter` the Filters form; `result-count` reads `1–N of total`.
- `transactions-categorize` the `Category for <description>` select saves on change with a `Category saved.` toast.
- `transactions-pending` pending rows carry a `pending` badge; fees show in the Fee column.
- `categories-crud` `Add category` (field `New category`), `Rename` (field `Category name`, `Save`), `Delete` (native confirm `Delete <name>? Its transactions become uncategorized.`); duplicate names show `You already have a category with this name.`

## How to reach it

- Top bar, `Transactions` (`/finance/transactions`); top bar, More → `Categories` (`/finance/categories`).
- An account page's `All transactions` link (`?account=<id>`).

## Drive and prove

Preconditions:

- Scenario with `finance-imported`. `verify:login "$ID" --path=/finance/transactions`.

- **Categorize.** `agent-browser select 'select[aria-label="Category for Migros"]' Groceries` (`find` has no `select` action); toast `Category saved.`. `verify:inspect` `.finance.transactions.uncategorized` drops by one.
- **Filter.** `agent-browser select '#filter-category' Uncategorized`, `Apply filters`: `result-count` reads `1–3 of 3` and Migros is gone. Search `coffee` (category back to `All categories`): one row with a `pending` badge; `Card payment abroad` shows its 0.10 fee.
- **Account link.** On the account page, `All transactions` opens `/finance/transactions?account=<id>`.
- **Categories.** Add `Travel`; rename it; delete it (accept the native confirm: `agent-browser dialog accept`, or stub `window.confirm` with `eval` before clicking).
- **Negative.** As a second scenario user, `PATCH /finance/transactions/<id>` of the first user (or open the first user's account) returns 404; their list reads `No transactions match.`.

## Evidence

- `transactions-categorized.png`, `transactions-filtered.png`, `categories.png`
- `inspect-before.json`, `inspect-after.json`

# Finance: transactions and categories

The transaction list at Finance → Transactions shows every transaction newest first, with filters (search in the description or payee, account, category or uncategorized, date range), pagination (50 per page), and a payee and a category select per row (see [finance-payees](./finance-payees.md) and [finance-rules](./finance-rules.md)). Categories (Finance → Categories) are a flat per-user list seeded with 12 defaults; they can be added, renamed and deleted (transactions become uncategorized).

Help article: none

## Sub-features

- `transactions-filter` the Filters form; `result-count` reads `1–N of total`.
- `transactions-categorize` the `Category for <description>` select saves on change with a `Category saved.` toast.
- `transactions-pending` pending rows carry a `pending` badge; fees show in the Fee column.
- `categories-crud` `Add category`, `Rename`, `Delete` (confirm dialog); duplicate names show `You already have a category with this name.`

## How to reach it

- Sidebar, Finance → `Transactions` (`/finance/transactions`), Finance → `Categories` (`/finance/categories`).
- An account page's `All transactions` link (`?account=<id>`).

## Drive and prove

Preconditions:

- Scenario with `finance-imported`. `verify:login "$ID" --path=/finance/transactions`.

- **Categorize.** `agent-browser find label "Category for Migros" select Groceries` (or `select` on the element); toast `Category saved.`. `verify:inspect` `transactions.uncategorized` drops by one.
- **Filter.** Category `Uncategorized`, `Apply filters`: Migros is gone. Search `coffee`: one row with a `pending` badge.
- **Categories.** Add `Travel`; rename it; delete it.
- **Negative.** As a second scenario user, `PATCH /finance/transactions/<id>` of the first user (or open the first user's account) returns 404; their list is empty.

## Evidence

- `transactions-categorized.png`, `transactions-filtered.png`, `categories.png`
- `inspect-before.json`, `inspect-after.json`

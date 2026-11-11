# Finance: payees

Payees are a per-user list of clean merchant names (`Coop`, `Migros`, `Hetzner`). A transaction has at most one payee; when it is set, the transaction list shows the payee name with the raw description underneath, and account pages show the payee name alone. Rules assign payees (see [finance-rules](./finance-rules.md)); payees are never created per description automatically.

Help article: none

## Sub-features

- `payees-crud` Finance → Payees: `Add payee` (field `New payee`), `Rename`, `Delete` (native confirm `Delete <name>? Its transactions lose their payee.`). Duplicate names show `You already have a payee with this name.`
- `payees-merge` `Merge…` on a row, choose the target in `Merge <name> into`, click `Merge`: transactions and rules move to the target and the merged payee is gone. Toast `Merged into <target>; N transactions moved.` (`1 transaction moved.` for one).
- `transactions-payee` the `Payee for <raw description>` select on each transaction row sets the payee by hand (`set by hand` note; rules never change it). `No payee` hands the field back to the rules.
- `transactions-search` the Search filter matches the raw description or the payee name.

## How to reach it

- Top bar, More → `Payees` (`/finance/payees`).
- Rules create payees inline: `Set payee` → `New payee…` in the rule form.

## Drive and prove

Preconditions:

- Scenario with `finance-rules` (payee `Migros` on the Migros transaction, set by the rule). `verify:login "$ID" --path=/finance/payees`.

- **List.** `Migros` shows 1 transaction.
- **Create and merge.** Add `Migros Basel`; on `Migros Basel` click `Merge…`, `agent-browser select 'select[aria-label="Merge Migros Basel into"]' Migros`, `Merge` (`find role button click --name "Merge" --exact`): one row `Migros` remains. `verify:inspect` `.finance.payees` has one entry.
- **Display and search.** `/finance/transactions?search=migros`: the row reads `Migros` with the raw description under it and `set by rule`. The account page shows `Migros` alone.
- **Hand-set.** `agent-browser select 'select[aria-label="Payee for Coffee"]' Migros`: toast `Payee saved.`, note `set by hand`; `verify:inspect` `.finance.transactions.payee_source.manual` is 1.
- **Negative (before Delete, while the payee exists).** As a second scenario user with no states, `PATCH /finance/payees/<id>`, `POST /finance/payees/<id>/merge` and `DELETE /finance/payees/<id>` of the first user return 404 (send them with `fetch` from the second user's page so the XSRF token passes); their Payees page reads `No payees yet.`
- **Delete.** As the first user, delete `Migros` (accept the native confirm): `transactions.without_payee` equals `transactions.total`; the rule that set it loses its payee action (`rules[0].actions`) and stays enabled.

## Evidence

- `payees.png`, `payees-merged.png`, `transactions-payee.png`
- `inspect-before.json`, `inspect-after.json`, `negative-404.txt`

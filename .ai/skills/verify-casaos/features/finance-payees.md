# Finance: payees

Payees are a per-user list of clean merchant names (`Coop`, `Migros`, `Hetzner`). A transaction has at most one payee; the transaction list and account pages show the payee name instead of the raw description when it is set, with the raw description underneath. Rules assign payees (see [finance-rules](./finance-rules.md)); payees are never created per description automatically.

Help article: none

## Sub-features

- `payees-crud` Finance → Payees: `Add payee`, `Rename`, `Delete` (confirm dialog; the payee's transactions lose their payee). Duplicate names show `You already have a payee with this name.`
- `payees-merge` `Merge…` on a row, choose the target in `Merge <name> into`, click `Merge`: transactions and rules move to the target and the merged payee is gone. Toast `Merged into <target>; N transactions moved.`
- `transactions-payee` the `Payee for <raw description>` select on each transaction row sets the payee by hand (`set by hand` note; rules never change it). `No payee` hands the field back to the rules.
- `transactions-search` the Search filter matches the raw description or the payee name.

## How to reach it

- Sidebar, Finance → `Payees` (`/finance/payees`).
- Rules create payees inline: `Set payee` → `New payee…` in the rule form.

## Drive and prove

Preconditions:

- Scenario with `finance-rules` (payee `Migros` on the Migros transaction, set by the rule). `verify:login "$ID" --path=/finance/payees`.

- **List.** `Migros` shows 1 transaction.
- **Create and merge.** Add `Migros Basel`; on `Migros Basel` click `Merge…`, choose `Migros`, `Merge`: one row `Migros` remains. `verify:inspect` `payees` has one entry.
- **Display and search.** `/finance/transactions?search=migros`: the row reads `Migros` with the raw description under it.
- **Hand-set.** Select a payee on the `Coffee` row: note `set by hand`; `verify:inspect` `transactions.payee_source.manual` is 1.
- **Delete.** Delete `Migros` (accept the dialog): `transactions.without_payee` equals `transactions.total`; the rule that set it loses its payee action (`rules[0].actions`).
- **Negative.** As a second scenario user, `PATCH /finance/payees/<id>`, `POST /finance/payees/<id>/merge` and `DELETE /finance/payees/<id>` of the first user return 404; their Payees page is empty.

## Evidence

- `payees.png`, `payees-merged.png`, `transactions-payee.png`
- `inspect-before.json`, `inspect-after.json`, `negative-404.txt`

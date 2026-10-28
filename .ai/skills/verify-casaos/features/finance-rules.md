# Finance: rules

Deterministic categorization rules at Finance → Rules. A rule has an optional name, a position, an enabled flag, conditions that must all match and one or more actions (`Set category`, `Set payee`). Every matching enabled rule applies, top to bottom; a later rule overrides an earlier one for the same field, and conditions see values earlier rules set in the same run. Each transaction records who set its category and payee (`manual` or `rule`): rules never overwrite a manual value, and clearing a manual value hands the field back to the rules, which fill it at once. Rules set values but never clear them.

Conditions: `Description` contains / equals / starts with / matches regex (case-insensitive, whitespace collapsed; regexes are checked on save), `Payee` is, `Amount` and `Absolute amount` equals / greater than / less than / between (CHF in the form, signed: spending is negative; stored as Rappen), `Account` is.

Help article: none

## Sub-features

- `rules-list` `/finance/rules`: order with `Move <rule> up/down`, `Enable <rule>` checkbox, `Edit`, `Apply…`, `Delete` (confirm dialog).
- `rules-form` `New rule` / `Edit`: `Name (optional)`, `Condition N field/operator/value`, `Add condition`, `Action N` and `Action N value` (`New payee…` shows `Action N new payee name`), `Create rule` / `Save rule`. The right column `Matching transactions` updates live (`preview-count`: `N matching transactions`).
- `rules-from-transaction` the wand button `Create rule from <raw description>` on a transaction row opens the form with `Description contains <raw description>`.
- `rules-apply` `Apply all rules…` (or `Apply…` on one rule) opens a dry run: `apply-counts` (transactions to change, category changes, payee changes) and a sample with `old → new`; `Apply to N transactions` writes it and toasts `Rules changed N transactions (C categories, P payees).`
- `rules-import` imports run the rules on created rows and on rows completed in place (rule-owned fields only). The batch summary shows `Set by rules`; undo restores what the import's rules changed.
- `rules-manual-wins` a category or payee chosen by hand (`set by hand`) survives every apply.

## How to reach it

- Sidebar, Finance → `Rules` (`/finance/rules`), `/finance/rules/create`, `/finance/rules/<id>/edit`, `/finance/rules/apply`, `/finance/rules/<id>/apply`.
- Transactions list: the wand button on each row.

## Drive and prove

Preconditions:

- Scenario with `finance-empty` and a file imported through [finance-import](./finance-import.md) (the owner's `storage/app/private/revolut-sample.csv` when present, else `tests/Fixtures/finance/two-products.csv`); or `finance-rules` for a ready rule.

- **Create.** `New rule`; fill `Condition 1 value`, pick operator, add actions; wait for `preview-count` and screenshot the form with its matches; `Create rule`. For a payee use `New payee…` and a name.
- **From a transaction.** On `/finance/transactions` click `Create rule from <description>`: `Condition 1 value` is pre-filled.
- **Dry run and apply.** `Apply all rules…`: screenshot `apply-counts` and the sample; save `inspect` before; `Apply to N transactions`; `verify:inspect` `transactions.category_source.rule` and `by_category` match the dry run.
- **Manual wins.** Hand-set one categorized row to another category (`set by hand`); `Apply all rules…` again: the row is not in the sample and keeps its category in `inspect`.
- **On import.** Undo every batch (`transactions.total` 0), re-import the same file: the batch shows `Set by rules` > 0 and `category_source.rule` equals the earlier count, without applying.
- **Negative.** As a second scenario user, `GET /finance/rules/<id>/edit`, `PUT /finance/rules/<id>`, `GET|POST /finance/rules/<id>/apply` and `DELETE /finance/rules/<id>` of the first user return 404; their Rules page is empty.

## Evidence

- `rule-form-preview.png`, `rules.png`, `apply-dry-run.png`, `apply-done.png`, `manual-wins-dry-run.png`, `batch-reimport.png`
- `inspect-before.json`, `inspect-after-apply.json`, `inspect-after-manual.json`, `inspect-after-reimport.json`, `negative-404.txt`

## Gotchas

- The live preview posts to `/finance/rules/matches` 300 ms after typing stops; wait for `preview-count` to change before the screenshot.
- `Apply…` on one rule runs all enabled rules on the rows that rule matches, so the outcome equals a full run for those rows.
- Rules apply only when run (on import or by Apply); saving a rule changes nothing by itself.

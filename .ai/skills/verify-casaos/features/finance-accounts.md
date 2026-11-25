# Finance: accounts and balances

A user creates, edits and archives accounts (checking, savings, credit card, investment, crypto; CHF only) at Finance → Accounts and records balance snapshots on an account. An account's balance on a day is the nearest snapshot plus or minus the completed transactions in between; a snapshot that disagrees with the computed balance shows its difference.

Help article: none

## Sub-features

- `account-create` `New account` dialog; investment and crypto are balance only (no import, no transaction count).
- `account-edit` `Edit` dialog renames or changes type.
- `account-archive` `Archive` / `Restore`; archived accounts sort last and dimmed, and their mappings stop matching.
- `snapshot-manual` `Save balance` with a date and CHF amount (`1234.50`, `1'234.50`, `-80`); a second entry on the same day replaces it.
- `snapshot-difference` a red alert and the `Difference` column when a snapshot disagrees; `Balance differs` badge on the list.
- `snapshot-delete` `Delete` on a snapshot row (native confirm `Delete the balance snapshot from <date>?`), toast `Balance deleted.`
- `balance-chart` the account page charts the daily balance (`data-test="balance-chart"`): a caption with the change over the range (`-65.40 (-2.2%) since 1 Oct 2026`, `past month`, …) and a `Time range` group (`1M`, `3M`, `1Y`, `All`, `aria-pressed`). A range only shows when the history is longer than it; the default is `1Y` when available, else `All`. Hidden with fewer than 2 days of history. The plot is a canvas without an accessible name; assert the caption and buttons.

## How to reach it

- Top bar, `Accounts` (`/finance/accounts`; `/finance` redirects there), then an account name (`/finance/accounts/<id>`).

## Drive and prove

Preconditions:

- Scenario with `finance-imported` (account `Verify Checking`, balance 2934.60 with a 2026-10-03 snapshot). `verify:login "$ID" --path=/finance/accounts`.

- **Balance.** The list shows `Verify Checking` at CHF 2’934.60; the detail page's `account-balance` matches `verify:inspect` `balance_today`.
- **Chart.** `balance-chart` reads `-65.40 (-2.2%) since 1 Oct 2026` under `All` (the scenario's history starts 2026-10-01, so which range buttons show depends on today); `1M` switches the caption to `past month`.
- **Create and edit.** `New account` with a name and type `Investment (balance only)`, `Create account`: the account page opens and the list shows `—` for its transactions. `Edit` renames it (`Save`).
- **Matching snapshot.** Save balance `2934.60` for 2026-10-04: toast `Balance saved.`, the new row reads `matches`.
- **Mismatch.** Save balance `3000` for 2026-10-05: the alert appears and the row shows a difference of CHF 65.40; the list shows `Balance differs`.
- **Formats.** `1'234.50` and `-80` are accepted; saving the same day again replaces that day's snapshot. Delete one row; it is gone from the table and `inspect`.
- **Archive.** `Archive`, then the list shows `Archived`; `Restore` brings it back.
- **Negative.** As a second scenario user, open `/finance/accounts/<account_id>` of the first (`account_id` from `scenario.json` `states`; ids are never 1 after earlier runs): 404 page titled `This page doesn’t exist - casaOS`.

## Evidence

- `account-list.png`, `account-detail.png`, `account-mismatch.png`, `account-404.png`
- `inspect-before.json`, `inspect-after.json`

## Gotchas

- The snapshot `Date` is a native `type="date"` input. `agent-browser fill` clears it and typed keys do not reach it in headless Chromium, so the browser's `required` check silently blocks the submit. Set it directly before saving (the form reads it on submit): `agent-browser eval "document.querySelector('#snapshot-date').value='2026-10-04'"`.
- Headless Chromium formats de-CH amounts as `CHF` + no-break space + `2'934.60` with an ASCII apostrophe, not `’`. Match on the digits.

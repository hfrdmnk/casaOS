# Finance: accounts and balances

A user creates, edits and archives accounts (checking, savings, credit card, investment, crypto; CHF only) at Finance → Accounts and records balance snapshots on an account. An account's balance on a day is the nearest snapshot plus or minus the completed transactions in between; a snapshot that disagrees with the computed balance shows its difference.

Help article: none

## Sub-features

- `account-create` `New account` dialog; investment and crypto are balance only (no import, no transaction count).
- `account-edit` `Edit` dialog renames or changes type.
- `account-archive` `Archive` / `Restore`; archived accounts sort last and dimmed, and their mappings stop matching.
- `snapshot-manual` `Save balance` with a date and CHF amount (`1234.50`, `1'234.50`, `-80`); a second entry on the same day replaces it.
- `snapshot-difference` a red alert and the `Difference` column when a snapshot disagrees; `Balance differs` badge on the list.

## How to reach it

- Sidebar, Finance → `Accounts` (`/finance/accounts`), then an account name (`/finance/accounts/<id>`).

## Drive and prove

Preconditions:

- Scenario with `finance-imported` (account `Verify Checking`, balance 2934.60 with a 2026-10-03 snapshot). `verify:login "$ID" --path=/finance/accounts`.

- **Balance.** The list shows `Verify Checking` at CHF 2’934.60; the detail page's `account-balance` matches `verify:inspect` `balance_today`.
- **Matching snapshot.** Save balance `2934.60` for 2026-10-04: the new row reads `matches`.
- **Mismatch.** Save balance `3000` for 2026-10-05: the alert appears and the row shows a difference of CHF 65.40; the list shows `Balance differs`.
- **Archive.** `Archive`, then the list shows `Archived`; `Restore` brings it back.
- **Negative.** As a second scenario user, open `/finance/accounts/<account_id>` of the first: 404 page.

## Evidence

- `account-list.png`, `account-mismatch.png`, `account-404.png`
- `inspect-before.json`, `inspect-after.json`

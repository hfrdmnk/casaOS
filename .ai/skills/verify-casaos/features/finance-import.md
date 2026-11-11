# Finance: CSV import

A user uploads a bank CSV at Finance → Import. A file whose shape (encoding, delimiter, preamble lines, header row) matches saved mappings imports straight away; any other file opens the mapper wizard, which previews the parsed rows and saves one mapping per account. One file can feed several accounts through row filters. Each import is a batch with a summary that can be undone.

Help article: none

## Sub-features

- `import-wizard` an unknown shape opens `Map <file>` with the detected delimiter and preamble, a raw preview, and a parsed preview (`parse-summary`) once date, description and amount are chosen.
- `import-mappings` saving a mapping per account, with a row filter (column equals value), status map, fee, balance and currency columns.
- `import-commit` `Import now` imports through every matching mapping and lands on `Import of <file>` with created/updated/duplicate/not CHF/reverted counts and a snapshot per account.
- `import-dedup` re-uploading the same file skips the wizard and creates 0 transactions (all `Skipped as duplicate`).
- `import-pending` a later file with a pending row completed updates it in place; a reverted row removes it.
- `import-reject` a file where any selected row fails to parse is rejected with line-level errors; nothing is written.
- `import-undo` `Undo import` on a batch reverts it; an earlier batch is blocked while a later one on the same account is active.
- `mapping-edit` `/finance/mappings/<id>/edit` changes columns and account; the file shape is locked.

## How to reach it

- Top bar, More → `Import` (`/finance/import`).
- A batch: `/finance/batches/<id>`, linked from Recent imports.

## Drive and prove

Preconditions:

- Scenario with `finance-empty`. Logged in via `verify:login "$ID" --path=/finance/import`.
- A test file. The owner's real export may sit at `storage/app/private/revolut-sample.csv` (gitignored, never commit it); otherwise use `tests/Fixtures/finance/two-products.csv` (Current ends at 166.50, Savings at 450.30; 9 rows import, 1 reverted row has nothing to remove).

- **Ground truth.** Before importing, compute the expected balance per account from the file itself, e.g. the last `Balance` value per `Product` in file order, and save it as `$RUN/expected.json`.
- **Upload.** `agent-browser upload "#import-file" <file>`, click `Upload`. Title becomes `Map <file> - casaOS`.
- **Map.** For each account: check the pre-filled column guesses and status map, `agent-browser select '#mapping-filter_column' Product`, then `#mapping-filter_value` (a select when the column has few values), leave `Import into account` on `New account…`, fill `New account name`, select `#mapping-new_account_type`, fill `Mapping name`, click `Save mapping` (toast `Mapping saved.`; the form keeps its values for the next account). `parse-summary` reads e.g. `8 rows selected · all parse · 5 completed · 1 reverted · 2 pending · last balance CHF 166.50`; screenshot it before saving.
- **Import.** Click `Import now`. Title `Import of <file> - casaOS`; screenshot `batch-summary`.
- **State.** `verify:inspect "$ID" --only=finance`: each account's `balance_today` equals `expected.json`, snapshots have `difference` null or `0.00`.
- **Re-upload.** Upload the same file again: no wizard, the batch summary shows Created 0 and `Skipped as duplicate` 9 for the fixture. `transactions.total` is unchanged.
- **Pending completed.** With the fixture, upload `tests/Fixtures/finance/two-products-later.csv`: Created 2, Updated 1 (Kiosk completed in place), `Removed as reverted` 1 (Train ticket), `Skipped as duplicate` 3.
- **Undo.** An earlier batch's `Undo import` is disabled with `Undo the later import of <file> first.` Undo newest first (native confirm `Undo this import? …`, toast `Import undone.`, the date line under the heading ends ` · undone`): `transactions.total` drops to 0, `batches[*].undone` true, no snapshots left.
- **Mapping edit.** On Import, the mapping name links to `/finance/mappings/<id>/edit` (`Edit mapping <name> - casaOS`); encoding and delimiter are disabled; `Save changes` toasts `Mapping updated.` and returns to Import.
- **Negative.** Upload `tests/Fixtures/finance/simple.csv` and save a mapping for it (the guesses fit; one `New account…`), import it, then upload `tests/Fixtures/finance/broken.csv` (same shape, two bad rows). The red alert reads `2 of 3 rows could not be read. Nothing was imported.` with `Line 3 (<mapping>): Date …` and `Line 4 (<mapping>): Amount …`; `transactions.total` and the batch count are unchanged. Without a saved mapping for the shape, broken.csv only opens the wizard. Another user's `/finance/import/<upload>` is a 404 page.

## Evidence

- `wizard-raw.png`, `wizard-parsed.png`, `batch-first.png`, `batch-reupload.png`, `batch-later.png`, `batch-undone.png`, `import-rejected.png`
- `expected.json`, `inspect-before.json`, `inspect-after-import.json`, `inspect-after-reupload.json`, `inspect-after-later.json`, `inspect-after-undo.json`

## Gotchas

- The confirm dialogs (`Undo import`, delete) are native `window.confirm`. Stub it before clicking with `agent-browser eval "window.confirm=()=>true"`, or run `agent-browser dialog accept` right after the click; before the click it fails with `No dialog is showing`, and while the dialog is open other commands hang.
- Uploads live under `storage/app/private/finance-imports/<user>/<uuid>/` until imported; another user's upload id is a 404.
- `Started Date`-style datetime columns need the format `Y-m-d H:i:s`; the time is kept and is part of the de-duplication fingerprint.

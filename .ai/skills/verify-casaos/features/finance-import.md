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

- Sidebar, Finance → `Import` (`/finance/import`).
- A batch: `/finance/batches/<id>`, linked from Recent imports.

## Drive and prove

Preconditions:

- Scenario with `finance-empty`. Logged in via `verify:login "$ID" --path=/finance/import`.
- A test file. The owner's real export may sit at `storage/app/private/revolut-sample.csv` (gitignored, never commit it); otherwise use `tests/Fixtures/finance/two-products.csv`.

- **Ground truth.** Before importing, compute the expected balance per account from the file itself, e.g. the last `Balance` value per `Product` in file order, and save it as `$RUN/expected.json`.
- **Upload.** `agent-browser upload "#import-file" <file>`, click `Upload`. Title becomes `Map <file> - casaOS`.
- **Map.** For each account: choose columns (the wizard pre-fills guesses), set `Row filter column`/`Row filter value`, map every status value, pick `New account…` with a name and type, fill `Mapping name`, click `Save mapping`. Screenshot the parsed preview before saving.
- **Import.** Click `Import now`. Title `Import of <file> - casaOS`; screenshot the summary.
- **State.** `verify:inspect "$ID" --only=finance`: each account's `balance_today` equals `expected.json`, snapshots have `difference` null or `0.00`.
- **Re-upload.** Upload the same file again: no wizard, the batch summary shows Created 0. `transactions.total` is unchanged.
- **Undo.** On the second batch click `Undo import` (confirm dialog), then on the first: `transactions.total` drops to 0, `batches[*].undone` true.
- **Negative.** Upload a file with one unparseable row for a saved shape: the red alert lists the line and nothing is imported.

## Evidence

- `wizard-raw.png`, `wizard-parsed.png`, `batch-first.png`, `batch-reupload.png`, `batch-undone.png`
- `expected.json`, `inspect-before.json`, `inspect-after-import.json`, `inspect-after-reupload.json`, `inspect-after-undo.json`

## Gotchas

- The confirm dialogs (`Undo import`, delete) are native `window.confirm`; run `agent-browser dialog accept` before clicking (or as the click's handler).
- Uploads live under `storage/app/private/finance-imports/<user>/<uuid>/` until imported; another user's upload id is a 404.
- `Started Date`-style datetime columns need the format `Y-m-d H:i:s`; the time is kept and is part of the de-duplication fingerprint.

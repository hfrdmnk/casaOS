# Finance MCP server

The finance module's MCP server at `POST /mcp/finance` (streamable HTTP, JSON-RPC) gives the user's own agent the finance module through 47 tools and one prompt, `categorize_transactions` (the workflow plus the rule condition and action format). It authenticates with a bearer token from [API tokens](./api-tokens.md). Every tool runs as the token's user and calls the same actions as the web UI (RuleEngine, ManageRules, EditTransaction, ManagePayees, ManageCategories, ManageTransfers, ManageSubscriptions, DashboardSummary, ImportFile, UndoImportBatch, RecordBalanceSnapshot, FilterTransactions), so results match the web pages.

- Amounts are integers in Rappen in and out; every amount in output has a `<key>_chf` string (`CHF 1'463.09`).
- Read tools carry `readOnlyHint: true` and need `finance:read`; every other tool needs `finance:write`. A read-only token on a write tool gets a tool error (`isError: true`, `Permission denied: <tool> changes data and needs the finance:write ability…`), and nothing changes.
- No, malformed or revoked token: HTTP 401 with `WWW-Authenticate: Bearer realm="mcp", error="invalid_token"`. A browser session does not authenticate it.
- Another user's id is a tool error `Not found: no <thing> with id <id>.`, the same as an id that does not exist. Ids of another user inside arguments (a rule's category, a condition's account) fail validation with `Choose one of your …`.
- Validation errors keep field keys: `Invalid input. conditions.0.value: …`.

Help article: none

## Sub-features

- `mcp-auth` bearer token, 401s, abilities.
- `mcp-accounts` `list_accounts` (balance today, pending total, latest snapshot difference), `get_account` (snapshots with reconciliation), `add_balance_snapshot`.
- `mcp-transactions` `list_transactions` (filters `account`, `category` id or `none`, `payee` id or `none`, `from`, `to`, `search`, `transfer` yes/no/suggested; `page`, `per_page` ≤ 200), `get_transaction`, `update_transaction` (manual value; null hands it back to the rules, `rules_filled` says what they set).
- `mcp-categories` / `mcp-payees` list, create, rename (`update_category`, `rename_payee`), `merge_payees`, delete.
- `mcp-rules` `list_rules`, `create_rule`, `update_rule`, `delete_rule`, `set_rule_enabled`, `reorder_rules`, `preview_rule_matches` (unsaved conditions → count and sample), `preview_apply_rules` (dry run, all rules or one `rule_id`), `apply_rules`.
- `mcp-transfers` `find_transfers`, `list_transfer_suggestions`, `confirm_transfer_suggestion`, `dismiss_transfer_suggestion`, `pair_transfer`, `unpair_transfer`, `mark_transfer`, `unmark_transfer`, `transfer_candidates`.
- `mcp-subscriptions` `list_subscriptions` (with open suggestions and `monthly_total`), `detect_subscriptions`, `confirm_subscription_suggestion`, `dismiss_subscription_suggestion`, `create_subscription` (with `transaction_id` = Mark as subscription), `update_subscription`, `cancel_subscription`, `link_subscription_transaction`, `unlink_subscription_transaction`. See [finance-subscriptions](./finance-subscriptions.md).
- `mcp-dashboard` `get_dashboard_summary` (`month` Y-m): the dashboard's numbers. See [finance-dashboard](./finance-dashboard.md).
- `mcp-import` `list_import_batches`, `get_import_batch`, `undo_import_batch` (newest first per account), `import_csv` (file content as a string; only through saved mappings, else an error pointing to Finance → Import in the web UI).
- `mcp-prompt` `prompts/get categorize_transactions`.

## How to reach it

- `POST <APP_URL>/mcp/finance` with headers `Authorization: Bearer <token>`, `Content-Type: application/json`, `Accept: application/json, text/event-stream`, `MCP-Protocol-Version: 2025-06-18` (after `initialize`).
- Token: `php artisan verify:mcp-token "$ID" [--read-only]`, or create one through [API tokens](./api-tokens.md) (that is the user path; prefer it for the main token of a run).
- A real client works too: `npx -y @modelcontextprotocol/inspector --cli <url> --transport http --header "Authorization: Bearer $TOKEN" --method tools/list`.

## Drive and prove

Preconditions:

- Scenario with `finance-empty`, the file imported through the web flow of [finance-import](./finance-import.md) (the owner's `storage/app/private/revolut-sample.csv` when present, else `tests/Fixtures/finance/two-products.csv`), and `expected.json` with the last balance per product.
- A second scenario (`finance-empty`) for the cross-user checks.
- A small curl wrapper (kept outside `$RUN`) that saves each request with the token redacted and the raw response under `$RUN/mcp/NN-<step>.*`.

- **Handshake.** `initialize` (serverInfo `casaOS Finance`), `tools/list` (47 tools, 15 with `readOnlyHint`, no `nextCursor`), `prompts/list`, `prompts/get categorize_transactions`.
- **Balances.** `list_accounts`: `balance_today` per account equals `expected.json` (Revolut sample: 445.46 and 1463.09).
- **Uncategorized.** `list_transactions` with `category: "none"`, `transfer: "no"`, `per_page: 200`; group descriptions to choose a rule.
- **Rule.** `list_categories` for the id; `preview_rule_matches` until the sample holds only the intended rows; `create_rule` (or `update_rule` to tighten); `preview_apply_rules` with its `rule_id`, and `inspect` shows nothing changed yet; `apply_rules`: counts equal the dry run. In the browser, `/finance/transactions?category=<id>` shows the same count and `/finance/rules` the rule.
- **By hand.** `update_transaction` with a category: `category_source` `manual`. On a rule-categorized row, set another category then `null`: the rule refills it (`rules_filled`).
- **Transfers.** `list_transfer_suggestions`, `find_transfers`, `list_transactions` with `transfer: "yes"`.
- **Re-import.** `import_csv` with the same file's content: `created` 0, every row `skipped_duplicate`. Replace the content with a placeholder in the saved request.
- **Undo.** `list_import_batches` (the web batch is `blocked_by` the MCP one); `undo_import_batch` on the web batch fails with `Undo the later import batch …`; undo the MCP batch, then the web batch; `list_accounts` shows 0.
- **Subscriptions and dashboard.** `get_dashboard_summary` for the file's month equals the web dashboard; `list_subscriptions` after marking. A `--read-only` token on every subscription write tool gets `Permission denied…`; the second user gets `Not found` on `create_subscription` (their `transaction_id`), `update_subscription`, `cancel_subscription`, `link_subscription_transaction`, `unlink_subscription_transaction`, and an empty `list_subscriptions` and dashboard.
- **Negatives.** A `--read-only` token on `create_rule` and `apply_rules` gets `Permission denied…` and `inspect` is unchanged, while `list_rules` works. No token, `999|casaos_x` and `garbage` get 401. With the second user's token, `get_account`, `add_balance_snapshot`, `get_transaction`, `update_transaction`, `mark_transfer`, `update_rule`, `delete_rule`, `apply_rules`, `rename_payee`, `delete_payee`, `delete_category`, `get_import_batch` and `undo_import_batch` on the first user's ids are `Not found`; their list tools return no accounts, transactions, payees, rules, suggestions or batches (categories are their own defaults); `inspect` of the first user is unchanged apart from `inspected_at`.
- **Cleanup.** After `verify:cleanup` the token from the UI gets 401.

## Evidence

- `expected.json`, `inspect-after-web-import.json`, `inspect-after-apply.json`, `inspect-after-undo.json`, `inspect-user1-before-cross.json`, `inspect-user1-after-cross.json`, `user1-ids.json`
- `mcp/NN-<step>.request.json`, `.response.txt` (status and headers), `.response.json`
- `web-groceries-after-mcp-apply.png`, `web-rules-after-mcp.png`, optional `mcp-inspector-cli/*.json`

## Gotchas

- Without `MCP-Protocol-Version` after `initialize`, requests still work (legacy clients); with a mismatched `MCP-Method` header the server answers 400.
- Tool errors are HTTP 200 with `result.isError: true`; only authentication failures are HTTP 401.
- `category` and `payee` filters are strings (`"12"` or `"none"`); an integer is accepted too.
- Shell-quoting a regex rule value through curl: escape `$` (`^coop( pronto)?\$`).

# API tokens

Settings → API tokens lets the user give their own AI agent (Amp, Claude Code) access to casaOS over MCP. A token is a Sanctum personal access token with a name and an access level: `Read only` (`finance:read`) or `Read and write` (`finance:read` and `finance:write`). The plain value is shown once, right after creation, with ready-to-paste Claude Code and Amp snippets; only its hash is stored. Revoking deletes the token, and it gets 401 from then on. Deleting the user deletes their tokens.

Help article: none

## Sub-features

- `api-tokens-create` `Token name`, the `Access` radios (`Read only` default, `Read and write`), `Create token`. The green panel (`data-test="new-token"`) shows the token (`New token`, with `Copy New token`) and the snippets with the real token.
- `api-tokens-once` reloading the page removes the panel; the plain token appears nowhere in the page.
- `api-tokens-list` each token (`data-test="api-token"`) shows its name, a `Read only` / `Read and write` badge, `Created …` and `Last used …` or `Never used`.
- `api-tokens-revoke` `Revoke <name>` opens a `Revoke token` dialog; `Revoke token` deletes it and toasts `Token revoked.`
- `api-tokens-connect` with no new token, `Connect an agent` shows the snippets with `<token>` and the MCP URL.

## How to reach it

- Settings sidebar → `API tokens` (`/settings/api-tokens`). The page is behind password confirmation, like Security: `verify:login` lands on `Confirm password`; fill `Password` with the scenario's `password` and click `Confirm password`.

## Drive and prove

Preconditions:

- Any scenario (`finance-empty` if the token will drive [finance-mcp](./finance-mcp.md)).

- **Create.** Fill `Token name`, pick `Read and write`, `Create token`. Screenshot the panel. Read the token with `agent-browser get text '[aria-label="New token"]'` (keep it out of evidence). `verify:inspect "$ID" --only=account` lists it under `api_tokens` with both abilities.
- **Once.** Reload: `agent-browser get count '[data-test=new-token]'` is 0 and the token's secret part is not in `get html body`.
- **Works.** `curl -X POST <APP_URL>/mcp/finance -H "Authorization: Bearer <token>" ...` with `tools/call list_accounts` answers 200; `last_used_at` is set.
- **Revoke.** `Revoke <name>`, screenshot the dialog, `Revoke token`. The list no longer has it; `api_tokens` in `inspect` neither.
- **Negative.** The same curl with the revoked token returns `401` with `WWW-Authenticate: Bearer realm="mcp", error="invalid_token"`. `DELETE /settings/api-tokens/<id>` of another user's token is 404.

## Evidence

- `api-tokens-empty.png`, `api-token-created.png`, `api-token-after-reload.png`, `api-token-revoke-dialog.png`, `api-token-revoked.png`
- `mcp/30-*.response.txt` (200 before revoking), `mcp/31-*.response.txt` (401 after)

## Gotchas

- The form resets after creating, so the radio shows `Read only` again; the created token keeps the level that was chosen (check `api_tokens` in `inspect`).
- Tokens start with `<id>|casaos_`; never paste one into evidence or a report. Redact it to its first characters.
- `agent-browser upload` elsewhere needs an absolute path; a relative one hangs the page.

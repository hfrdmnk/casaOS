# Passkeys

A user registers passkeys at Settings → Security (`Passkeys` section, behind password confirmation), then signs in with one on `/login` (`Sign in with a passkey`, no password and no two-factor challenge) or uses one to pass the password confirmation gate (`Confirm with passkey`). Removing a passkey asks for confirmation; a removed passkey no longer signs in. `GET /.well-known/passkey-endpoints` points password managers at the enroll and manage page. Laravel Passkeys (`Features::passkeys` in `config/fortify.php`) serves the WebAuthn routes under `/user/passkeys`, `/passkeys/login` and `/passkeys/confirm`.

Help article: none

## Sub-features

- `passkeys-register` `Add passkey` shows `Passkey name` (prefilled with the browser name) and `Register passkey`; the list shows the name and `Added … ago`.
- `passkeys-login` `/login`, `Sign in with a passkey` lands on the dashboard.
- `passkeys-confirm` the `Confirm password` page's `Confirm with passkey` opens the gated page.
- `passkeys-remove` `Remove` (sr-only name) on a passkey opens `Remove passkey` (`Are you sure you want to remove the "<name>" passkey? …`); `Remove passkey` deletes it.
- `passkeys-well-known` `GET /.well-known/passkey-endpoints` returns `{"enroll": "<APP_URL>/settings/security", "manage": "<APP_URL>/settings/security"}`.

## How to reach it

- Settings tabs under the page header, `Security` (`/settings/security`), `Passkeys` section.
- `/login` → `Sign in with a passkey`; `/user/confirm-password` → `Confirm with passkey`.

## Drive and prove

Preconditions:

- Scenario with no states. Logged in via `verify:login "$ID" --path=/settings/profile`; note `password`.
- A WebAuthn virtual authenticator on the agent-browser page, held by [scripts/virtual-authenticator.mjs](../scripts/virtual-authenticator.mjs). Start it after the first `agent-browser open` and keep it running for the whole drive: `CDP_URL="$(agent-browser get cdp-url)" node .ai/skills/verify-casaos/scripts/virtual-authenticator.mjs http://localhost:8000 300 > "$RUN/authenticator.log" 2>&1 &`. The first log line has an `authenticatorId` and `error: null`. Stay in the same tab: the authenticator belongs to that page target and survives navigation, not a new tab or session.

- **Register.** `Security` tab, confirm the password, `Add passkey`, fill `Passkey name` with `Verify virtual key`, `Register passkey`. The list shows `Verify virtual key` / `Added 0 seconds ago`; `verify:inspect "$ID"` → `account.passkeys` is `["Verify virtual key"]`; the authenticator log shows one resident credential for `rpId` `localhost`.
- **Sign in.** `Account menu`, `agent-browser wait '[role=menuitem]'`, `find role menuitem click --name "Log out"`, then `Sign in with a passkey` on `/login`: the title is `Dashboard - casaOS`.
- **Confirm.** Open `/settings/security`: `Confirm password - casaOS`. `Confirm with passkey`: `Security settings - casaOS`.
- **Well-known.** `curl -s "$APP_URL/.well-known/passkey-endpoints"` prints the enroll and manage URLs above.
- **Negative, other user.** In a second agent-browser session (`AGENT_BROWSER_SESSION="$AGENT_BROWSER_SESSION-b" agent-browser …`, its own cookies, and the first session and its authenticator stay up), a second scenario confirms its own password, then sends `DELETE /user/passkeys/<id>` for the first user's passkey with `fetch` from its page: 403, and the passkey is still listed. Without confirming first the answer is 423 (password confirmation required), which proves nothing.
- **Remove.** As the first user, `Remove` → dialog `Remove passkey` → `Remove passkey`. `account.passkeys` is `[]`.
- **Negative, removed key.** Log out and `Sign in with a passkey` again: the page stays on `/login` with `Passkey not recognized. It may have been removed from your account.`
- Stop the authenticator script (`kill` the PID `$!` printed when you started it) before `agent-browser close`.

## Evidence

- `passkey-registered.png`, `passkey-login-dashboard.png`, `passkey-confirmed.png`, `passkey-remove-dialog.png`, `passkey-login-after-remove.png`
- `authenticator.log`, `well-known.json`, `negative-403.txt`, `inspect-before.json`, `inspect-after-register.json`, `inspect-after-remove.json`

## Gotchas

- The passkey ids come from the `passkeys` table; `verify:inspect` lists names only. `php artisan tinker --execute 'echo DB::table("passkeys")->where("user_id", <user_id>)->value("id");'` reads one without writing.
- Passkey removal answers 403, not 404, for another user's passkey: Laravel Passkeys checks the owner itself (`abort_unless($passkey->user_id === $user->getKey(), 403)`).
- `verify:login` in a browser that is already signed in keeps that session's password confirmation (it regenerates the session but does not clear it), so the second user skips `Confirm password` and gets 403 without confirming. Use a second agent-browser session to see the 423 and the gate.
- An id that no longer exists answers 404 before the confirmation check, so the 423 and 403 checks need a passkey that still exists.
- `verify:cleanup` deletes the scenario's passkeys with the user (they cascade); the virtual credential only lives in the script's DevTools session.

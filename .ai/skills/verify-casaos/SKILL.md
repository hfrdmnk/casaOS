---
name: verify-casaos
description: 'Drive the real casaOS app like a user and prove a change works: isolated scenario user, app UI and public auth pages in agent-browser, captured scenario mail, artisan CLI, evidence in storage/verify/runs/, outcome pass/fail/blocked. Use after any user-facing change, before opening a PR, when asked to verify, reproduce or show proof, and when a feature file in features/ matches the work.'
---

# Verify casaOS

Prove behavior on the running app, not in tests. Pick the feature file in [features/](features/README.md) that matches the change, run the protocol below, and report the outcome.

casaOS is single-user personal software (Laravel 13, Inertia React, Fortify auth, Postgres). Accounts come from `php artisan user:create`; there is no signup form. Every lever is a `php artisan verify:*` command that refuses to run unless `APP_ENV` is `local` or `testing`. Each prints JSON and has a full `--help`.

## Doctor

`php artisan verify:doctor`

Run it before the first drive and again after anything surprising. It exits non-zero and prints a `fix` per failing check:

- `environment`: `APP_ENV` is `local`.
- `database`: the `DB_CONNECTION` database answers and is migrated.
- `frontend`: `public/build/manifest.json` (from `npm run build`) or `public/hot` (from `npm run dev`) exists.
- `app_url`: `GET <APP_URL>/up` returns 200.
- `mail`: every mailer the default can send through (following failover/round-robin) is `log`, `array`, or SMTP to `127.0.0.1`/`localhost`/`mailpit` (`MAIL_MAILER`, `MAIL_HOST`).
- `queue`: `QUEUE_CONNECTION` is `database` or `sync`.
- `session_cookie`: `SESSION_SECURE_COOKIE` is not set over an `http://` `APP_URL`.

It prints the env keys it read under `env_keys_read`, never their values.

Fixing a fresh orb: `.agents/setup` provisions PHP, Postgres, deps, migrations and the frontend build. After a pause, `.agents/resume` restarts Postgres. Start the app as a supervised service, matching `APP_URL=http://localhost:8000`:

```bash
amp orb service start casaos --command "bash -lc 'cd /home/user/workspace/repo && php artisan serve --host=127.0.0.1 --port=8000'"
```

If the session cannot get a database, frontend build or running server (a CI or cloud sandbox without them), the run is `blocked` with that reason, never `fail`.

## Levers

| Need                                        | Command                                                                                                                  |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Check the environment is safe and ready     | `php artisan verify:doctor`                                                                                              |
| Isolated user in a named state              | `php artisan verify:scenario [states...]` (`--list`; `two-factor`, `finance-empty`, `finance-imported`, `finance-rules`) |
| Log the browser in as the scenario user     | `php artisan verify:login <scenario> [--path=/settings/profile]` prints `.url`                                           |
| Read state as JSON                          | `php artisan verify:inspect <scenario> [--only=account] [--only=finance]`                                                |
| Read mail sent to the scenario              | `php artisan verify:mail <scenario> [--latest]`                                                                          |
| Current TOTP code for a two-factor scenario | `php artisan verify:inspect <scenario> \| jq -r .account.two_factor.current_code`                                        |
| Browser                                     | `agent-browser` (preinstalled in orbs; `agent-browser skills get core --full` for usage)                                 |
| Drain background jobs                       | `php artisan queue:work --stop-when-empty`                                                                               |
| Clean up this run                           | `php artisan verify:cleanup <scenario>`                                                                                  |
| Sweep orphans from failed runs              | `php artisan verify:cleanup --stale [--hours=0] [--dry-run]`                                                             |

What they do:

- `verify:scenario` creates one user `verify-<id>@casaos.test` with a generated password and prints `scenario` (the id), `user_id`, `email`, `password` and per-state values (the `two-factor` state prints `two_factor_secret` and `recovery_codes`).
- `verify:login` prints a signed, expiring `/_verify/login/<user>` URL. Opening it logs in (skipping password and two-factor) and redirects to `--path`. It only works for scenario users; any other user gets 404, and a changed query string gets 403. To prove the real login form, use `email` and `password` from the scenario on `/login` instead.
- `verify:inspect` prints `finance` (accounts with `balance_today`, snapshots and their `difference`, transaction counts by status, by category and by who set the category and payee (`category_source`/`payee_source`: `manual`, `rule`), categories, payees with counts, rules in order, mappings, batches) and `account`: name, email, timestamps, `sessions` count, `password_reset_pending`, `passkeys`, and `two_factor` (`enabled`, `confirmed_at`, `recovery_codes_remaining`, `current_code`).
- `verify:mail` reads mail captured for any `verify-<id>[+tag]@casaos.test` address, whatever the mailer: subject, from, to, text, html, `links`, attachment names. Captures live in `storage/verify/mail/<id>/`.
- `verify:cleanup` deletes only scenario users, plus their sessions, password reset tokens, captured mail and leftover finance uploads (`storage/app/private/finance-imports/<user_id>/`). User-owned rows (passkeys) cascade. It never touches `storage/verify/runs/`.

Browser setup for every run:

```bash
export AGENT_BROWSER_SESSION="$(agent-browser session id --scope worktree --prefix verify)"
agent-browser open "$(php artisan verify:login "$ID" --path=/ | jq -r .url)"
agent-browser set viewport 1280 720 2
```

Prefer stable handles: roles and accessible names (`agent-browser find role button click --name "Save"`, `find label "Name" fill ...`), then `data-test` attributes (`logout-button`). Never generated class names or DOM position. Inertia pages set the document title to `<Page> - casaOS`; use it (`agent-browser get title`) to confirm where you are.

Add module levers without touching the commands: implement `App\Verification\Contracts\ScenarioState` (a named state) or `App\Verification\Contracts\Inspector` (a top-level key in `verify:inspect`) and tag the class with `ScenarioState::TAG` / `Inspector::TAG` in the module's service provider (see `App\Providers\VerificationServiceProvider`). Module tables must reference `users` with `cascadeOnDelete()` so cleanup removes their scenario rows. Add a feature file for the module in `features/`.

## Protocol

1. Run doctor. Not ready and not fixable here: report `blocked`.
2. Create the evidence dir `storage/verify/runs/<YYYY-MM-DD>-<slug>/` (gitignored).
3. Create a scenario: `php artisan verify:scenario [states] > "$RUN/scenario.json"`, then `ID=$(jq -r .scenario "$RUN/scenario.json")`.
4. Save `php artisan verify:inspect "$ID" > "$RUN/inspect-before.json"`.
5. Open the feature file and follow its steps through the user path. After an action that queues work, drain the queue, then assert.
6. Capture the evidence the feature file names, then `inspect-after.json`.
7. Run the feature file's negative proofs.
8. `agent-browser close`, then `php artisan verify:cleanup "$ID" > "$RUN/cleanup.json"`. Confirm `verify:inspect "$ID"` now fails and `$RUN` still exists. After a failed attempt, still clean up; sweep with `verify:cleanup --stale --hours=0` if ids were lost.
9. Report.

## Proof standards

- Drive the real user path. The `verify:*` levers may arrange preconditions (user, login, two-factor state); the behavior under test comes from a user action in the browser or the user-facing command.
- Capture the action and the resulting state, not only a final screen. Confirm side effects (rows via `verify:inspect`, mail via `verify:mail`) alongside what is visible.
- A step that could not run is not a pass. Proving a feature through a different entry point than the one changed does not count; `verify:login` does not prove the login form.

## Report

```
verify-casaos: pass | fail | blocked
reason: <one line>
features: <feature ids driven>
evidence: storage/verify/runs/<YYYY-MM-DD>-<slug>/
commands: <levers and drives run>
state delta: <inspect-before vs inspect-after summary>
```

## Safety

- One scenario user per run, created by `verify:scenario`, deleted by `verify:cleanup`. Never drive the owner's account or any user that is not `verify-*@casaos.test`.
- Scenario addresses use the `.test` TLD; when a recipe changes the email, use a plus address (`verify-<id>+new@casaos.test`) so cleanup and mail capture still find it.
- Never kill processes by name; stop what you started (`agent-browser close`, `amp orb service stop casaos` only if this run started it).
- Cleanup never deletes evidence.

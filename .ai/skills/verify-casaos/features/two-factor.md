# Two-factor authentication

A user enables TOTP two-factor authentication at Settings → Security: they scan a QR code (or copy the setup key), confirm with a 6-digit code, and can view and regenerate recovery codes. Logging in with the password then asks for an authentication code or a recovery code at `/two-factor-challenge`. The user can disable 2FA again.

Help article: none

## Sub-features

- `2fa-enable` `Enable 2FA` opens the setup dialog with a QR code and setup key.
- `2fa-confirm` entering the current code confirms 2FA.
- `2fa-recovery-codes` view and regenerate recovery codes.
- `2fa-challenge-code` login with password then the authentication code.
- `2fa-challenge-recovery` login with a recovery code (consumes it).
- `2fa-disable` `Disable 2FA` turns it off.

## How to reach it

- Settings → Security, `Two-factor authentication` section (behind password confirmation).
- `/login` → `/two-factor-challenge` for a user with 2FA enabled.

## Drive and prove

Preconditions for enabling: scenario with no states, logged in via `verify:login "$ID" --path=/settings/security`, password confirmed with the scenario password.
Preconditions for the challenge: `php artisan verify:scenario two-factor` (prints `two_factor_secret` and `recovery_codes`), not logged in.

- **Enable.** Click `Enable 2FA`. A dialog titled `Enable two-factor authentication` shows a QR code and a `Setup key`. Click `Continue`; the dialog becomes `Verify authentication code`.
- **Confirm.** Get the code with `php artisan verify:inspect "$ID" | jq -r .account.two_factor.current_code` (available as soon as the secret exists), click the `Authentication code` input and `agent-browser keyboard type` the code; click `Confirm`. The dialog closes and the section shows `Disable 2FA` and `View recovery codes`. `verify:inspect` shows `two_factor.enabled: true`, `confirmed_at` set, `recovery_codes_remaining: 8`.
- **Recovery codes.** `View recovery codes` lists 8 codes and shows `Regenerate codes`; regenerating keeps 8.
- **Challenge with code.** In a fresh browser session open `/login`, fill the scenario email and password, click `Log in`. The URL ends in `/two-factor-challenge` (title `Two-factor authentication - casaOS`). Type `current_code` from `verify:inspect` and click `Continue`; the dashboard renders.
- **Challenge with recovery code.** Repeat the login, click `login using a recovery code`, fill `Enter recovery code` with one of `recovery_codes`, Continue. The dashboard renders. `recovery_codes_remaining` stays 8: Fortify replaces the used code with a new one, so prove consumption by reusing it (negative below).
- **Negative.** On the challenge, `000000` shows `The provided two factor authentication code was invalid.`; the recovery code already used shows `The provided two factor recovery code was invalid.`. The URL stays on `/two-factor-challenge`.
- **Disable.** Back on Settings → Security, click `Disable 2FA` (no confirmation dialog). `Enable 2FA` returns and `verify:inspect` shows `two_factor.enabled: false`.

## Evidence

- `2fa-setup-dialog.png`, `2fa-enabled.png`, `2fa-challenge.png`, `2fa-dashboard-after-challenge.png`
- `inspect-before.json`, `inspect-after-enable.json`, `inspect-after-recovery.json`

## Gotchas

- TOTP codes rotate every 30 seconds; fetch `current_code` right before typing it. A code is accepted once, so a second login in the same window needs the next code.
- The challenge rate limit is 5 per minute per user (keyed by the session's `login.id`), across sessions.
- The setup key and QR code are scenario secrets; screenshots in the gitignored evidence dir are fine, never paste them into a PR.

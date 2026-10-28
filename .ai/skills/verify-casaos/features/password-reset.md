# Password reset

A guest who forgot their password requests a reset link at `/forgot-password`, receives an email titled `Reset your password`, follows its link to `/reset-password/{token}`, sets a new password, and can log in with it.

Help article: none

## Sub-features

- `reset-request` requesting a link shows the status message.
- `reset-mail` the email arrives at the scenario address with a reset link.
- `reset-complete` the link's form sets a new password.
- `reset-login` the new password logs in.

## How to reach it

- `/login`, `Forgot your password?` link.
- `/forgot-password` directly.
- The link in the reset email.

## Drive and prove

Preconditions:

- Scenario with no states, not logged in.

- **Request.** `agent-browser open "$APP_URL/forgot-password"`, fill `Email address` with the scenario email, click `Email password reset link`. A status message appears. `verify:inspect "$ID"` shows `password_reset_pending: true`.
- **Mail.** The notification sends synchronously; if it is ever queued, run `php artisan queue:work --stop-when-empty` first. `php artisan verify:mail "$ID" --latest > "$RUN/mail.json"`: `count` 1, `to` is the scenario email, `subject` is `Reset your password`, `links` contains a `/reset-password/` URL.
- **Reset.** Open that link (`jq -r '.messages[0].links[] | select(test("/reset-password/"))' "$RUN/mail.json"`). Fill `Password` and `Confirm password` with `reset-Password-123`, click `Reset password`. The browser lands on `/login` with a status message.
- **Log in.** Log in with `reset-Password-123`; the dashboard renders. `password_reset_pending` is now `false`.
- **Negative.** Reopen the same reset link and submit again: the error says the token is invalid. Requesting a reset for `nobody@casaos.test` sends no mail to the scenario mailbox.

## Evidence

- `forgot-password-status.png`, `mail.json`, `reset-form.png`, `login-after-reset.png`, `reset-token-reused.png`
- `inspect-before.json`, `inspect-after-request.json`, `inspect-after.json`

## Gotchas

- Reset links use `APP_URL`; when the browser reaches the app through another host, swap the origin and keep the path and query.
- Fortify throttles reset link requests per email (one per minute by default); a second request too soon shows a throttle message and sends nothing.
- `MAIL_MAILER=log` also writes the message to `storage/logs/laravel.log`; `verify:mail` is the supported way to read it.

# Password update

A user changes their password at Settings → Security by entering the current password and the new one twice. The security page sits behind password confirmation: arriving without a recent confirmation shows the `Confirm password` page first.

Help article: none

## Sub-features

- `password-confirm-gate` `/settings/security` asks for the password when it was not confirmed recently.
- `password-update` saving a new password shows `Password updated.`.
- `password-wrong-current` a wrong current password shows an error.

## How to reach it

- Settings side nav, `Security`.
- `/settings/security` directly.

## Drive and prove

Preconditions:

- Scenario with no states. Logged in via `verify:login "$ID" --path=/settings/security`. Note `password` from `scenario.json` as `$PW`.

- **Confirm gate.** The title is `Confirm password - casaOS`. Fill `Password` with `$PW` and click `Confirm password`. The title becomes `Security settings - casaOS`.
- **Negative.** Fill `Current password` with `wrong-password`, new and confirm with `new-Password-123`, Save. An inline error appears under `Current password`.
- **Update.** Fill `Current password` with `$PW`, `New password` and `Confirm password` with `new-Password-123`, Save. A toast reads `Password updated.`.
- **Prove the new password.** Log out (user menu, `Log out`), log in on `/login` with the scenario email and `new-Password-123`; the dashboard renders. The old `$PW` is rejected with `These credentials do not match our records.`.

## Evidence

- `confirm-password.png`, `password-wrong-current.png`, `password-updated-toast.png`, `login-new-password.png`

## Gotchas

- `verify:login` does not confirm the password, so the gate always shows first; that is the expected path.
- `PUT /settings/password` is throttled to 6 per minute and login to 5 per minute per email and IP; repeated failed attempts in one run lock you out briefly.

# casaOS feature map

What casaOS does today, how a user reaches each feature, and what proves it works. Each file is a recipe for [verify-casaos](../SKILL.md). Modules (finance, later fitness) add their own files here.

## Baseline

- `php artisan verify:doctor` reports `"outcome": "ready"`.
- A fresh scenario exists for this run (`php artisan verify:scenario [states] > "$RUN/scenario.json"`, `ID=$(jq -r .scenario "$RUN/scenario.json")`).
- Base URL is `APP_URL` (`http://localhost:8000` in an orb). The browser uses a named `agent-browser` session.
- Evidence goes to `storage/verify/runs/<YYYY-MM-DD>-<slug>/` (`$RUN`).
- casaOS is single-user: there is no tenant model. Each feature proves its own guest/other-user denial.

## Features

App UI (logged in):

- [Dashboard and navigation](./dashboard.md): `/`, sidebar, user menu, guest redirect.
- [Profile settings](./settings-profile.md): `/settings/profile` name and email update.
- [Delete account](./delete-account.md): `/settings/profile` Delete account dialog.
- [Password update](./settings-password.md): `/settings/security` Update password form, behind password confirmation.
- [Two-factor authentication](./two-factor.md): `/settings/security` enable/confirm/disable, recovery codes, `/two-factor-challenge`.
- [Appearance](./settings-appearance.md): `/settings/appearance` Light/Dark/System.

Public pages and email:

- [Log in and log out](./login.md): `/login` form, remember me, throttle, `Log out` menu item.
- [Password reset](./password-reset.md): `/forgot-password`, reset email, `/reset-password/{token}`.

CLI:

- [Create a user](./user-create.md): `php artisan user:create`.

## Unmapped

- Passkeys (register, log in, confirm password with a passkey): `resources/js/components/manage-passkeys.tsx`, `resources/js/components/passkey-register.tsx`, `config/fortify.php` (`Features::passkeys`). Needs a WebAuthn virtual authenticator in the browser; blocked until a recipe for one is proven. `verify:inspect` already reports `account.passkeys`.
- Passkey discovery endpoint `GET /.well-known/passkey-endpoints`: `routes/settings.php`.
- Health check `GET /up`: `bootstrap/app.php` (exercised by doctor, no user-facing behavior).

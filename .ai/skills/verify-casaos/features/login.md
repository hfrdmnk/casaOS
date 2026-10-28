# Log in and log out

A user logs in at `/login` with email and password (optionally `Remember me`) and lands on the dashboard. Wrong credentials show an error. `Log out` in the user menu ends the session.

Help article: none

## Sub-features

- `login-form` email and password login.
- `login-wrong-password` wrong credentials show `These credentials do not match our records.`.
- `login-forgot-link` `Forgot your password?` links to `/forgot-password`.
- `logout` the user menu `Log out` item ends the session.

## How to reach it

- `/login` (and any guest request to an `auth` page redirects there).
- User menu, `Log out` (`data-test="logout-button"`).

## Drive and prove

Preconditions:

- Scenario with no states, not logged in. Use `email` and `password` from `scenario.json`. Do not use `verify:login` here; it bypasses the form under test.

- **Negative first.** `agent-browser open "$APP_URL/login"`, fill `Email address` and `Password` with the scenario email and `wrong-password`, click `Log in`. The error `These credentials do not match our records.` shows; URL stays `/login`.
- **Log in.** Fill the real password, click `Log in`. The title is `Dashboard - casaOS`. `verify:inspect "$ID"` shows `account.sessions` ≥ 1.
- **Log out.** Open the user menu and click `Log out`. The browser ends on `/login`; opening `/` redirects back to `/login`.

## Evidence

- `login-error.png`, `login-dashboard.png`, `after-logout.png`
- `inspect-after-login.json`

## Gotchas

- Login is throttled to 5 attempts per minute per email and IP.
- `account.sessions` counts database sessions; the session row stays after logout until it is garbage-collected, so do not assert it drops to 0.

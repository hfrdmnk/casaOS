# Dashboard and navigation

A logged-in user lands on the dashboard at `/`, which shows the app shell: a top bar with the casaOS logo, the links `Overview`, `Transactions`, `Accounts`, `Transfers` and a `More` menu (`Subscriptions`, `Categories`, `Payees`, `Rules`, `Import`), and an `Account menu` (the avatar) with `Settings` and `Log out`. Guests are sent to the login page. The dashboard body is the finance overview, see [finance-dashboard](./finance-dashboard.md).

Help article: none

## Sub-features

- `dashboard-render` the dashboard renders for a logged-in user.
- `nav-top-bar` the top bar `Overview` link and the logo return to `/`; `More` opens the remaining finance pages.
- `nav-user-menu` the user menu shows the user's name and links to `Settings`.
- `dashboard-guest` a guest is redirected to `/login`.

## How to reach it

- `/` after logging in (Fortify home).
- Top bar, `Overview` link; logo.
- `Account menu` (avatar, top right), `Settings`.

## Drive and prove

Preconditions:

- Scenario with no states. Logged in via `verify:login "$ID" --path=/`.

- **Render.** Open the login URL. `agent-browser get title` is `Dashboard - casaOS`; `agent-browser get url` ends in `/`.
- **User menu.** Click the avatar (`agent-browser find role button click --name "Account menu"`). The menu shows the scenario name, `Settings` and `Log out`. Choose `Settings`; the title becomes `Profile settings - casaOS`.
- **Top bar.** Click the `Overview` link; the title is `Dashboard - casaOS` again.
- **Negative.** In a fresh session (`AGENT_BROWSER_SESSION=verify-guest-$ID agent-browser open "$APP_URL/"`), the URL ends in `/login`. Or `curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$APP_URL/"` prints `302 .../login`.

## Evidence

- `dashboard.png`, `user-menu.png`
- `guest-redirect.txt` (status and redirect URL)

## Gotchas

- Below 768px the top bar links collapse into the `Menu` dropdown; keep `set viewport 1280 720 2`.

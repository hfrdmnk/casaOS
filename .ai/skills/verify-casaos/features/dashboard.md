# Dashboard and navigation

A logged-in user lands on the dashboard at `/`, which shows the app shell: a sidebar with the casaOS logo and a `Dashboard` link, and a user menu with `Settings` and `Log out`. Guests are sent to the login page. The dashboard body is a placeholder until modules (finance, fitness) add content.

Help article: none

## Sub-features

- `dashboard-render` the dashboard renders for a logged-in user.
- `nav-sidebar` the sidebar `Dashboard` link returns to `/`.
- `nav-user-menu` the user menu shows the user's name and links to `Settings`.
- `dashboard-guest` a guest is redirected to `/login`.

## How to reach it

- `/` after logging in (Fortify home).
- Sidebar, `Dashboard` link; sidebar logo.
- User menu (sidebar footer, the user's name), `Settings`.

## Drive and prove

Preconditions:

- Scenario with no states. Logged in via `verify:login "$ID" --path=/`.

- **Render.** Open the login URL. `agent-browser get title` is `Dashboard - casaOS`; `agent-browser get url` ends in `/`.
- **User menu.** Click the button carrying the scenario name (`agent-browser find role button click --name "Verify $ID"` or the ref from `snapshot -i`). The menu shows `Settings` and `Log out`. Choose `Settings`; the title becomes `Profile settings - casaOS`.
- **Sidebar.** Click the `Dashboard` link; the title is `Dashboard - casaOS` again.
- **Negative.** In a fresh session (`AGENT_BROWSER_SESSION=verify-guest-$ID agent-browser open "$APP_URL/"`), the URL ends in `/login`. Or `curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$APP_URL/"` prints `302 .../login`.

## Evidence

- `dashboard.png`, `user-menu.png`
- `guest-redirect.txt` (status and redirect URL)

## Gotchas

- On narrow viewports the sidebar collapses behind a trigger; keep `set viewport 1280 720 2`.

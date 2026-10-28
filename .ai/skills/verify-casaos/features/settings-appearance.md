# Appearance

A user picks Light, Dark or System appearance at Settings → Appearance. The choice applies immediately and survives a reload (stored in `localStorage` and the `appearance` cookie, which the server uses to render the right theme on first paint).

Help article: none

## Sub-features

- `appearance-light`, `appearance-dark`, `appearance-system` the three options.
- `appearance-persist` the choice survives a reload.

## How to reach it

- Settings side nav, `Appearance`; `/settings/appearance`.

## Drive and prove

Preconditions:

- Scenario with no states. Logged in via `verify:login "$ID" --path=/settings/appearance`.

- **Dark.** Click `Dark`. `agent-browser eval "document.documentElement.classList.contains('dark')"` returns `true`; screenshot.
- **Persist.** Reload; still `true`. `agent-browser cookies get` (or `eval "document.cookie"`) shows `appearance=dark`.
- **Light.** Click `Light`; the check returns `false`.

## Evidence

- `appearance-dark.png`, `appearance-light.png`, `appearance-cookie.txt`

## Gotchas

- Appearance is browser state, not user state; `verify:inspect` does not change. Each new browser session starts at `system`.
- `System` follows the headless browser's color scheme (light by default).

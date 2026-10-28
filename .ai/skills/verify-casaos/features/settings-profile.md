# Profile settings

A user changes their name and email address at Settings → Profile and sees a `Profile updated.` toast. The new values are stored and shown in the user menu.

Help article: none

## Sub-features

- `profile-update-name` saving a new name.
- `profile-update-email` saving a new email address (must be unique).
- `profile-validation` an empty name or a taken email shows an inline error and saves nothing.

## How to reach it

- User menu, `Settings` (lands on Profile).
- `/settings` redirects to `/settings/profile`.
- Settings side nav, `Profile`.

## Drive and prove

Preconditions:

- Scenario with no states. Logged in via `verify:login "$ID" --path=/settings/profile`.

- **Before.** `php artisan verify:inspect "$ID" > "$RUN/inspect-before.json"`.
- **Update name.** `agent-browser find label "Name" fill "Verify Renamed"`, then `agent-browser find role button click --name "Save"`. A toast reads `Profile updated.`; screenshot it immediately.
- **Update email.** Fill `Email address` with `verify-$ID+new@casaos.test` and Save. Same toast.
- **State.** `verify:inspect "$ID"` shows `account.name` `Verify Renamed` and `account.email` `verify-$ID+new@casaos.test`. Save as `inspect-after.json`.
- **Second view.** Reload; the inputs show the saved values and the user menu shows the new name.
- **Negative.** Clear `Name` and Save: the browser's `required` check blocks submission (or, after removing it, the server shows an error). Fill `Email address` with the address of another scenario (create a second one) and Save: the inline error reads `The email has already been taken.` and `verify:inspect` is unchanged.

## Evidence

- `profile-before.png`, `profile-updated-toast.png`, `profile-after-reload.png`
- `inspect-before.json`, `inspect-after.json`
- `profile-email-taken.png` for the negative proof

## Gotchas

- Keep any new email on the `verify-<id>+tag@casaos.test` pattern, or `verify:cleanup "$ID"` cannot find the user and you must clean up by hand.
- The toast disappears after a few seconds; screenshot right after clicking Save.

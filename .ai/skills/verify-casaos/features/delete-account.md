# Delete account

A user deletes their own account from Settings → Profile. A dialog asks for the current password; confirming logs the user out, deletes the account, and returns to the login page.

Help article: none

## Sub-features

- `delete-dialog` the `Delete account` button opens a confirmation dialog.
- `delete-wrong-password` a wrong password shows an error and deletes nothing.
- `delete-confirm` the right password deletes the user and logs out.

## How to reach it

- Settings → Profile, `Delete account` section, `Delete account` button.

## Drive and prove

Preconditions:

- Scenario with no states. Logged in via `verify:login "$ID" --path=/settings/profile`. Note `password` from `scenario.json`.

- **Open.** `agent-browser find role button click --name "Delete account"`. A dialog titled `Are you sure you want to delete your account?` appears.
- **Negative.** Fill `Password` with `wrong-password` and confirm with the dialog's `Delete account` button. The inline error `The password is incorrect.` appears; `verify:inspect "$ID"` still succeeds.
- **Delete.** Fill the scenario password and confirm. The browser ends on `/login`.
- **State.** `php artisan verify:inspect "$ID"` fails with `No scenario user for [$ID]`. Save its output as `inspect-after.txt`.

## Evidence

- `delete-dialog.png`, `delete-wrong-password.png`, `after-delete.png`
- `inspect-before.json`, `inspect-after.txt`

## Gotchas

- Once the dialog is open, the page's `Delete account` button leaves the accessibility tree, so `snapshot -i` and `find role button` see only the dialog's. The stable handles are `[data-test=delete-user-button]` (page) and `[data-test=confirm-delete-user-button]` (dialog).
- Cleanup for this scenario prints `No scenario user`; that is expected. Still run `verify:cleanup --stale --hours=0 --dry-run` to confirm nothing is left, and delete the mailbox via `--stale` if one exists.

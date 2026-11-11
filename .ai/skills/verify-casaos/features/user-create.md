# Create a user

The owner creates accounts from the CLI, since registration is disabled: `php artisan user:create <name> <email>` prompts for a password, or generates one when run with `--no-interaction` and prints it with a reminder to change it.

Help article: none

## Sub-features

- `user-create-prompt` interactive password prompt.
- `user-create-generated` non-interactive run prints a generated password.
- `user-create-duplicate` a taken email is refused.
- `user-create-login` the created user can log in.

## How to reach it

- `php artisan user:create <name> <email>` (no web entry point; `/register` does not exist).

## Drive and prove

Preconditions:

- A scenario id for the address, without a user: run `php artisan verify:scenario`, note `$ID`, then `php artisan verify:cleanup "$ID"` so the address is free but recognisable to cleanup. Use `EMAIL=verify-$ID@casaos.test`.

- **Create.** `php artisan user:create "CLI User" "$EMAIL" --no-interaction | tee "$RUN/user-create.txt"`. Output shows `Created user $EMAIL.` and `Generated password`.
- **State.** `php artisan verify:inspect "$ID"` shows `account.name` `CLI User`.
- **Log in.** Log in on `/login` with `$EMAIL` and the generated password (the last field of the `Generated password` line; quote it, it contains shell symbols); the dashboard renders with `dashboard-empty`.
- **Prompt.** For `user-create-prompt`, use a second freed scenario address and feed the prompt through a pseudo-terminal: `(sleep 3; printf 'Prompted-Pass-123\r'; sleep 3) | script -qc "php artisan user:create 'Prompt User' verify-<id2>@casaos.test" /dev/null`. It asks `Password` and prints `Created user …` without a generated password.
- **Negative.** Run the same command again, saving to `user-create-duplicate.txt`; it exits 1 with `The email has already been taken.`. `curl -s -o /dev/null -w '%{http_code}' "$APP_URL/register" > "$RUN/register.status"` prints `404`.

## Evidence

- `user-create.txt`, `user-create-prompt.txt`, `user-create-duplicate.txt`, `register.status`, `login-cli-user.png`

## Gotchas

- Always use a `verify-<id>@casaos.test` address so `verify:cleanup "$ID"` deletes the user afterwards.

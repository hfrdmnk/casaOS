# Finance: subscriptions

A subscription is a recurring payment: a name, a payee (preferred) or a description match (lowercased, letters only, matched as whole words, e.g. `ente io`), an optional account, an expected amount, an interval (weekly, monthly, quarterly, yearly), the next expected date, a status (`active`/`cancelled`) and a source (`manual`/`detected`). Its charges are linked to it (`transactions.subscription_id`, with `subscription_source` `auto` or `manual`); that is its payment history. A charge matches when it is a non-transfer outflow (completed or pending) with the payee (or the description words), in the account when set, within ±10% of the amount. Linking moves the next date to one interval after the newest linked charge.

Detection runs after every import (after rules and transfer pairing) and on `Detect subscriptions`. It groups completed, non-transfer outflows that are not linked (and not unlinked by hand) by payee, else by normalized description; keeps the charges within ±10% of their amount cluster's median; and suggests a group when the newest run of at least 3 charges has every gap in one interval (weekly 6–8 days, monthly 28–33, quarterly 85–97, yearly 355–375). Confirm turns a suggestion into a detected subscription; dismiss sticks (same payee/description and interval is never suggested again). No notifications: upcoming payments show on the dashboard and this page only.

Help article: none

## Sub-features

- `subscriptions-page` `/finance/subscriptions` (top bar, More → `Subscriptions`): `Active` (rows `data-test="subscription"`, `monthly-total` = count and CHF per month), `Suggestions` (rows `subscription-suggestion` with their charges, `Confirm <name>` / `Dismiss <name>`), `Cancelled` (with `Reactivate <name>`). `Edit <name>` opens a dialog (name, amount, interval, payee, description, account, next date). `Detect subscriptions`.
- `subscriptions-mark` `/finance/transactions`: on a charge, `Subscription actions for <description>` → `Mark as subscription…` (dialog `Mark as subscription`, prefilled name, amount and Monthly; `Create subscription`), `Link to <subscription>`, `Unlink from <subscription>`. A linked row shows the `subscription-marker` badge. Filter `Subscriptions`: all / payments / not subscriptions / one subscription.
- `subscriptions-import` an import links new matching charges (`Linked to subscriptions` in the batch summary) and moves the next date; new suggestions show as `N possible subscriptions need a decision`.
- `subscriptions-undo` undoing a batch deletes the charges it created, unlinks pre-existing charges it linked, recomputes next dates and re-runs detection. Subscriptions are never deleted.
- `subscriptions-manual-final` a hand link or unlink is final: nothing automatic changes that row's subscription again.

## How to reach it

- Top bar, More → `Subscriptions`; Dashboard → `N possible subscriptions` in the opening sentence (`attention-subscriptions`, only when suggestions are open) and the `Coming up` aside `N subscriptions · CHF x a month` (`recurring`).
- `/finance/transactions?subscription=any|none|<id>`.

## Drive and prove

Preconditions:

- Real data (no false positives, manual marking): scenario `finance-empty`, the owner's `storage/app/private/revolut-sample.csv` imported through [finance-import](./finance-import.md) (one mapping per `Product`, `Started Date` with `Y-m-d H:i:s`). The file covers 2026-09-01 to 2026-10-28, so recurring charges appear at most twice.
- Synthetic (positive detection): scenario `finance-subscriptions`: monthly `STREAMFLIX *<ref>` (4 charges), yearly `DOMAIN RENEWAL VERIFY.CH` (3), `Gym Fit Verify` creeping 49.00 → 52.00 (within ±10%), `Cloud storage usage` drifting 3.10 → 12.75, irregular `Kiosk Bahnhof`. Dates are relative to today.

- **No false positives.** After the real import, `verify:inspect` → `finance.subscriptions.suggestions_open` is 0 and the batch's `subscription_suggestions` 0; `Detect subscriptions` keeps it at 0.
- **Mark as subscription.** Search `Hetzner`, open `Subscription actions for Hetzner` on the 2026-09-27 row → `Mark as subscription…` → `Create subscription`. Toast `Subscription Hetzner created with 2 payments.`; both rows show the marker. Same for `ente.io`. Inspect: `subscriptions.list` Hetzner payments 2026-09-27 (completed) and 2026-10-27 (pending), `next_date` 2026-11-27; ente.io 2026-09-03 and 2026-10-03, `next_date` 2026-11-03.
- **Suggestions.** With `finance-subscriptions`: the page lists exactly Gym Fit Verify, Streamflix, Domain Renewal Verify Ch. `Confirm Streamflix` (toast `Subscription Streamflix confirmed.`; 4 payments, next date one month after the newest), `Dismiss Gym Fit Verify` (`Suggestion dismissed. It will not be suggested again.`), `Confirm Domain Renewal Verify Ch`, `Cancel Domain Renewal Verify Ch` (`Subscription cancelled.`; the `Cancelled` section shows `Reactivate Domain Renewal Verify Ch`), `Detect subscriptions`: `suggestions_open` 0, `suggestions_dismissed` 1, `by_status` active 1 / cancelled 1.
- **Mark, link and unlink without the owner's file.** With `finance-subscriptions`: `/finance/transactions?search=Kiosk`, a row's `Subscription actions for Kiosk Bahnhof` → `Mark as subscription…` (dialog prefilled `Kiosk Bahnhof`, `9.50`, `monthly`) → `Create subscription`: toast `Subscription Kiosk Bahnhof created with 5 payments.`, 5 markers, `link_source` `auto`. `?search=Cloud`: mark the 12.75 row (`… created with 1 payment.`; the other charges are outside ±10%), `Link to Cloud storage usage` on the 8.90 row (toast `Linked to Cloud storage usage.`, marker title `Linked by hand`, `link_source` `manual`), `Unlink from Cloud storage usage` on the 12.75 row (`Unlinked from the subscription.`). `Detect subscriptions` leaves the 12.75 row unlinked.
- **Filter.** `#filter-subscription` offers `Subscription payments` (`any`), `Not subscriptions` (`none`), each active subscription and a `Cancelled` group.
- **Undo.** Undo the import: `transactions.total` 0, every subscription still there with 0 payments and its next date. Re-import the same file: the batch shows `Linked to subscriptions` and the payments and next dates come back without any action (a cancelled subscription stays at 0). For `finance-subscriptions` the file is `php artisan tinker --execute 'echo App\Verification\Finance\FinanceSubscriptions::csv();' > /tmp/verify-subscriptions.csv` (dates relative to today; its saved mapping matches, so the upload imports straight away).
- **Negative.** As a second scenario user: `PATCH /finance/subscriptions/<id>`, `POST /finance/subscriptions/<id>/cancel`, `POST /finance/subscriptions/suggestions/<id>/confirm|dismiss`, `POST|DELETE /finance/transactions/<id>/subscription` and `POST /finance/subscriptions` with the first user's `transaction_id` return 404 and change nothing.

## Evidence

- `subscription-menu.png`, `mark-hetzner-dialog.png` (or `mark-kiosk-dialog.png`), `hetzner-marked.png` (or `kiosk-marked.png`), `ente-marked.png`, `subscriptions-real-after-detect.png`, `subscriptions-suggestions.png`, `confirmed-streamflix.png`, `dismissed-gym.png`, `cancelled-domain.png`, `after-detect.png`, `batch-undone.png`, `subscriptions-after-undo.png`, `batch-reimport.png`
- `inspect-after-import.json`, `inspect-after-mark.json`, `inspect-after-cancel-detect.json`, `inspect-after-undo.json`, `inspect-after-reimport.json`, `negative-404.txt`

## Gotchas

- `Undo import` uses a native confirm: click, then `agent-browser dialog accept`; until then every other command fails. Stubbing it first (`agent-browser eval "window.confirm=()=>true"`) avoids the race.
- Every charge of a payee has the same `Subscription actions for <description>` button. Take the ref of the `button` line from `snapshot -i` (not the table cell that also holds `Transfer actions for …`), or narrow the list with `?search=` first.
- Toasts stack bottom right at 1280×720 and cover the last row's `Confirm`/`Dismiss` buttons; a fixed 4 s sleep is not enough. Before the next click run `agent-browser wait --fn "!document.querySelector('[data-sonner-toast]')"`.
- Hand links and unlinks do not survive undo and re-import: afterwards `Cloud storage usage` links the 12.75 charge again (`auto`) instead of the 8.90 one, so its next date moves, and the cancelled subscription's charges come back as an open suggestion.
- The charge you mark is linked like the others (`link_source` `auto` when it matches); only `Link to` / `Unlink` from the menu are `manual`.
- A subscription that loses all its charges on undo keeps its last next date.
- Detection ignores pending charges; matching an existing subscription includes them, so a pending charge moves the next date.

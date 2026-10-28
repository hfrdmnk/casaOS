<?php

namespace App\Actions\Finance\Subscriptions;

use App\Models\Finance\SubscriptionInterval;
use App\Models\Finance\SubscriptionLinkSource;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds recurring charges among a user's transactions and stores them as subscription
 * suggestions. Imports run it after rules and transfer pairing; "Detect subscriptions" runs it
 * on demand.
 *
 * Charges are completed outflows that count as spending (no transfers), not linked to a
 * subscription and not unlinked by hand. They are grouped by payee, or by normalized description
 * (see normalize()) when they have none. Within a group, charges of a similar size form an amount
 * cluster (sorted by size, a cluster takes charges up to 20% above its smallest), and only those
 * within ±10% of the cluster's median stay. Walking back from the newest charge of a cluster, the
 * run of consecutive gaps that fit one interval (SubscriptionInterval::gapDays(), e.g. monthly
 * 28–33 days) is the candidate; with at least 3 charges it is suggested, with the median as its
 * amount and the newest charge plus one interval as its next date.
 *
 * Each run replaces the open suggestions. A dismissed group (same match key and interval) is
 * never suggested again.
 */
final class DetectSubscriptions
{
    public const MIN_CHARGES = 3;

    public const AMOUNT_TOLERANCE = 0.10;

    /**
     * @return array{suggested: int}
     */
    public function __invoke(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $dismissed = SubscriptionSuggestion::query()->ownedBy($user)->whereNotNull('dismissed_at')->get()
                ->mapWithKeys(fn (SubscriptionSuggestion $suggestion): array => ["{$suggestion->match_key}|{$suggestion->interval->value}" => true])
                ->all();

            SubscriptionSuggestion::query()->ownedBy($user)->whereNull('dismissed_at')->delete();

            $suggested = 0;

            foreach ($this->candidates($user) as $candidate) {
                if (isset($dismissed["{$candidate['match_key']}|{$candidate['interval']->value}"])) {
                    continue;
                }

                $suggestion = new SubscriptionSuggestion($candidate);
                $suggestion->user_id = $user->id;
                $suggestion->save();
                $suggested++;
            }

            return ['suggested' => $suggested];
        });
    }

    /**
     * Every recurring group, keyed `<match key>|<interval>` (the longer run wins when two amount
     * clusters of one group share an interval).
     *
     * @return array<string, array{match_key: string, name: string, payee_id: int|null, description_match: string|null, account_id: int|null, amount: int, interval: SubscriptionInterval, next_date: string, transaction_ids: list<int>}>
     */
    public function candidates(User $user): array
    {
        $charges = Transaction::query()->ownedBy($user)
            ->countsAsIncomeOrSpending()
            ->where('status', TransactionStatus::Completed)
            ->where('amount', '<', 0)
            ->whereNull('subscription_id')
            ->where(fn ($query) => $query->whereNull('subscription_source')->orWhere('subscription_source', '!=', SubscriptionLinkSource::Manual))
            ->with('payee')
            ->orderBy('date')->orderBy('time')->orderBy('id')
            ->get();

        $groups = [];

        foreach ($charges as $charge) {
            $key = self::matchKey($charge);

            if ($key !== null) {
                $groups[$key][] = $charge;
            }
        }

        $candidates = [];

        foreach ($groups as $key => $group) {
            foreach ($this->clusters($group) as $cluster) {
                $run = $this->run($cluster);

                if ($run === null) {
                    continue;
                }

                ['interval' => $interval, 'charges' => $runCharges] = $run;
                $id = "{$key}|{$interval->value}";

                if (isset($candidates[$id]) && count($candidates[$id]['transaction_ids']) >= count($runCharges)) {
                    continue;
                }

                $newest = $runCharges[count($runCharges) - 1];
                $accounts = array_unique(array_map(fn (Transaction $charge): int => $charge->account_id, $runCharges));

                $candidates[$id] = [
                    'match_key' => $key,
                    'name' => $newest->payee !== null ? $newest->payee->name : Str::title(substr($key, strlen('description:'))),
                    'payee_id' => $newest->payee_id,
                    'description_match' => $newest->payee_id === null ? substr($key, strlen('description:')) : null,
                    'account_id' => count($accounts) === 1 ? $accounts[0] : null,
                    'amount' => self::median(array_map(fn (Transaction $charge): int => -$charge->amount, $runCharges)),
                    'interval' => $interval,
                    'next_date' => $interval->after($newest->date),
                    'transaction_ids' => array_map(fn (Transaction $charge): int => $charge->id, $runCharges),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Lowercase letters only, single-spaced: `Hetzner Online 2026-10` and `HETZNER ONLINE` both
     * become `hetzner online`, `ente.io` becomes `ente io`.
     */
    public static function normalize(string $description): string
    {
        return trim((string) preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($description)));
    }

    /**
     * The group a charge belongs to: its payee, else its normalized description. Null when the
     * description has no letters.
     */
    public static function matchKey(Transaction $transaction): ?string
    {
        if ($transaction->payee_id !== null) {
            return "payee:{$transaction->payee_id}";
        }

        $description = self::normalize($transaction->description);

        return $description === '' ? null : "description:{$description}";
    }

    /**
     * Whether a charge is within ±10% of an amount (both positive Rappen).
     */
    public static function similarAmount(int $charge, int $amount): bool
    {
        return abs($charge - $amount) <= $amount * self::AMOUNT_TOLERANCE;
    }

    /**
     * @param  list<int>  $amounts
     */
    public static function median(array $amounts): int
    {
        sort($amounts);
        $middle = intdiv(count($amounts), 2);

        return count($amounts) % 2 === 1 ? $amounts[$middle] : (int) round(($amounts[$middle - 1] + $amounts[$middle]) / 2);
    }

    /**
     * Charges of a similar size, each in date order, keeping only those within ±10% of their
     * cluster's median.
     *
     * @param  list<Transaction>  $group
     * @return list<non-empty-list<Transaction>>
     */
    private function clusters(array $group): array
    {
        usort($group, fn (Transaction $a, Transaction $b): int => $b->amount <=> $a->amount);
        $clusters = [];
        $current = [];

        foreach ($group as $charge) {
            if ($current !== [] && -$charge->amount > -$current[0]->amount * (1 + 2 * self::AMOUNT_TOLERANCE)) {
                $clusters[] = $current;
                $current = [];
            }

            $current[] = $charge;
        }

        if ($current !== []) {
            $clusters[] = $current;
        }

        return array_values(array_filter(array_map(function (array $cluster): array {
            $median = self::median(array_map(fn (Transaction $charge): int => -$charge->amount, $cluster));
            $kept = array_values(array_filter($cluster, fn (Transaction $charge): bool => self::similarAmount(-$charge->amount, $median)));
            usort($kept, fn (Transaction $a, Transaction $b): int => [$a->date, $a->time, $a->id] <=> [$b->date, $b->time, $b->id]);

            return $kept;
        }, $clusters), fn (array $cluster): bool => count($cluster) >= self::MIN_CHARGES));
    }

    /**
     * The newest run of at least 3 charges whose gaps all fit one interval, trying the shortest
     * interval first.
     *
     * @param  non-empty-list<Transaction>  $cluster  in date order
     * @return array{interval: SubscriptionInterval, charges: list<Transaction>}|null
     */
    private function run(array $cluster): ?array
    {
        foreach (SubscriptionInterval::cases() as $interval) {
            [$min, $max] = $interval->gapDays();
            $run = [$cluster[count($cluster) - 1]];

            for ($i = count($cluster) - 2; $i >= 0; $i--) {
                $gap = intdiv(strtotime($run[0]->date.' UTC') - strtotime($cluster[$i]->date.' UTC'), 86400);

                if ($gap < $min || $gap > $max) {
                    break;
                }

                array_unshift($run, $cluster[$i]);
            }

            if (count($run) >= self::MIN_CHARGES) {
                return ['interval' => $interval, 'charges' => $run];
            }
        }

        return null;
    }
}

<?php

namespace App\Actions\Finance\Transfers;

use App\Models\Finance\ImportBatch;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\Finance\TransferSource;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds transfer pairs among a user's transactions: imports run it on the rows they create or
 * complete, and "Find transfers" on everything.
 *
 * Candidates are two completed transactions in different accounts of the user, same currency,
 * opposite amounts to the Rappen, dated at most 3 days apart, neither paired nor decided by hand,
 * and not a dismissed suggestion. Closeness ranks them: same date and time beats same day beats
 * within 3 days, and within a tier the smaller time difference wins.
 *
 * Pairing is greedy and only acts on unambiguous matches: two transactions pair when each is the
 * other's single closest candidate (no other candidate at the same rank). Paired rows leave the
 * pool and the next round runs, until a round pairs nothing. Whatever candidates remain become
 * suggestions for the user to confirm or dismiss.
 */
final class PairTransfers
{
    public const WINDOW_DAYS = 3;

    private const TIER_SAME_TIME = 0;

    private const TIER_SAME_DAY = 1;

    private const TIER_WINDOW = 2;

    /**
     * Pair what is unambiguous and store the rest as suggestions. With `$scope`, only pairs and
     * suggestions that involve one of those transaction ids are written (every candidate still
     * counts for ambiguity). With `$batch`, pre-existing transactions the pairing changes are
     * journaled so undoing the batch can restore them.
     *
     * @param  list<int>|null  $scope
     * @return array{paired: int, suggested: int}
     */
    public function __invoke(User $user, ?array $scope = null, ?ImportBatch $batch = null): array
    {
        return DB::transaction(function () use ($user, $scope, $batch): array {
            $pool = self::eligible(Transaction::query()->ownedBy($user))->orderBy('id')->get()->keyBy('id');
            $inScope = $scope === null ? null : array_flip($scope);
            $touches = fn (int $a, int $b): bool => $inScope === null || isset($inScope[$a]) || isset($inScope[$b]);

            ['pairs' => $pairs, 'open' => $open] = $this->match($pool, $this->dismissed($user));

            $paired = 0;

            foreach ($pairs as [$outflow, $inflow]) {
                if ($touches($outflow, $inflow)) {
                    $this->link($pool[$outflow], $pool[$inflow], $batch, $inScope ?? []);
                    $paired++;
                }
            }

            $stale = TransferSuggestion::query()->ownedBy($user)->whereNull('dismissed_at');

            if ($inScope !== null) {
                $stale->where(fn (Builder $query) => $query->whereIn('outflow_id', $scope)->orWhereIn('inflow_id', $scope));
            }

            $stale->delete();

            $now = now();
            $rows = [];

            foreach ($open as [$outflow, $inflow]) {
                if ($touches($outflow, $inflow)) {
                    $rows[] = ['user_id' => $user->id, 'outflow_id' => $outflow, 'inflow_id' => $inflow, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                TransferSuggestion::query()->insertOrIgnore($chunk);
            }

            return ['paired' => $paired, 'suggested' => count($rows)];
        });
    }

    /**
     * Transactions pairing may still give a counterpart: completed, unpaired, non-zero and not
     * decided by hand. A transfer a rule marked is eligible.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public static function eligible(Builder $query): Builder
    {
        return $query->where('transactions.status', TransactionStatus::Completed)
            ->whereNull('transactions.transfer_counterpart_id')
            ->where('transactions.amount', '!=', 0)
            ->where(fn (Builder $query) => $query->whereNull('transactions.transfer_source')
                ->orWhere('transactions.transfer_source', '!=', TransferSource::Manual));
    }

    /**
     * The candidate rank of two transactions, lower is closer: [tier, seconds apart]. Null when
     * they are not candidates by date (more than 3 days apart).
     *
     * @return array{int, int}|null
     */
    public static function rank(Transaction $a, Transaction $b): ?array
    {
        $days = intdiv(abs(self::day($a->date) - self::day($b->date)), 86400);

        if ($days > self::WINDOW_DAYS) {
            return null;
        }

        if ($a->time === null || $b->time === null) {
            return [$days === 0 ? self::TIER_SAME_DAY : self::TIER_WINDOW, $days * 86400];
        }

        $seconds = abs(self::day($a->date) + self::seconds($a->time) - self::day($b->date) - self::seconds($b->time));

        return match (true) {
            $seconds === 0 => [self::TIER_SAME_TIME, 0],
            $days === 0 => [self::TIER_SAME_DAY, $seconds],
            default => [self::TIER_WINDOW, $seconds],
        };
    }

    /**
     * Mark two transactions as each other's counterpart. A side a rule marked keeps `rule` as its
     * source; any other side becomes `auto`. Transfers carry no category.
     *
     * @param  array<int, int>  $journaled  ids the batch already journaled (created or updated)
     */
    private function link(Transaction $outflow, Transaction $inflow, ?ImportBatch $batch, array $journaled): void
    {
        foreach ([[$outflow, $inflow], [$inflow, $outflow]] as [$side, $other]) {
            if ($batch !== null && ! isset($journaled[$side->id])) {
                $batch->changes()->create([
                    'subject_type' => 'transaction',
                    'subject_id' => $side->id,
                    'action' => 'paired',
                    'before' => $side->only(['is_transfer', 'transfer_source', 'transfer_counterpart_id', 'category_id', 'category_source']),
                ]);
            }

            $side->forceFill([
                'is_transfer' => true,
                'transfer_source' => $side->transfer_source === TransferSource::Rule ? TransferSource::Rule : TransferSource::Auto,
                'transfer_counterpart_id' => $other->id,
                'category_id' => null,
                'category_source' => null,
            ])->save();
        }

        TransferSuggestion::query()->whereNull('dismissed_at')->where(fn (Builder $query) => $query
            ->whereIn('outflow_id', [$outflow->id, $inflow->id])->orWhereIn('inflow_id', [$outflow->id, $inflow->id]))->delete();
    }

    /**
     * Greedy matching on the pool (see the class doc). Returns pairs and the remaining candidate
     * pairs, each as [outflow id, inflow id], in a deterministic order.
     *
     * @param  Collection<int, Transaction>  $pool  keyed by id
     * @param  array<string, true>  $dismissed  keyed `outflow:inflow`
     * @return array{pairs: list<array{int, int}>, open: list<array{int, int}>}
     */
    private function match(Collection $pool, array $dismissed): array
    {
        $groups = [];

        foreach ($pool as $transaction) {
            $groups[$transaction->currency.'|'.abs($transaction->amount)][$transaction->amount < 0 ? 'out' : 'in'][] = $transaction;
        }

        /** @var list<array{int, int, array{int, int}}> $edges [outflow id, inflow id, rank] */
        $edges = [];

        foreach ($groups as $group) {
            foreach ($group['out'] ?? [] as $outflow) {
                foreach ($group['in'] ?? [] as $inflow) {
                    if ($outflow->account_id === $inflow->account_id || isset($dismissed["{$outflow->id}:{$inflow->id}"])) {
                        continue;
                    }

                    $rank = self::rank($outflow, $inflow);

                    if ($rank !== null) {
                        $edges[] = [$outflow->id, $inflow->id, $rank];
                    }
                }
            }
        }

        $matched = [];
        $pairs = [];

        do {
            /** @var array<int, array{rank: array{int, int}, partners: list<int>}> $best */
            $best = [];

            foreach ($edges as [$outflow, $inflow, $rank]) {
                if (isset($matched[$outflow]) || isset($matched[$inflow])) {
                    continue;
                }

                foreach ([[$outflow, $inflow], [$inflow, $outflow]] as [$node, $partner]) {
                    $current = $best[$node] ?? null;

                    if ($current === null || $rank < $current['rank']) {
                        $best[$node] = ['rank' => $rank, 'partners' => [$partner]];
                    } elseif ($rank === $current['rank']) {
                        $best[$node]['partners'][] = $partner;
                    }
                }
            }

            $round = [];

            foreach ($edges as [$outflow, $inflow]) {
                if (isset($matched[$outflow]) || isset($matched[$inflow])) {
                    continue;
                }

                if ($best[$outflow]['partners'] === [$inflow] && $best[$inflow]['partners'] === [$outflow]) {
                    $round[] = [$outflow, $inflow];
                }
            }

            foreach ($round as [$outflow, $inflow]) {
                $matched[$outflow] = $matched[$inflow] = true;
                $pairs[] = [$outflow, $inflow];
            }
        } while ($round !== []);

        $open = [];

        foreach ($edges as [$outflow, $inflow]) {
            if (! isset($matched[$outflow]) && ! isset($matched[$inflow])) {
                $open[] = [$outflow, $inflow];
            }
        }

        return ['pairs' => $pairs, 'open' => $open];
    }

    /**
     * @return array<string, true>
     */
    private function dismissed(User $user): array
    {
        $dismissed = [];

        foreach (TransferSuggestion::query()->ownedBy($user)->whereNotNull('dismissed_at')->get(['outflow_id', 'inflow_id']) as $suggestion) {
            $dismissed["{$suggestion->outflow_id}:{$suggestion->inflow_id}"] = true;
        }

        return $dismissed;
    }

    private static function day(string $date): int
    {
        return (int) strtotime(substr($date, 0, 10).' 00:00:00 UTC');
    }

    private static function seconds(string $time): int
    {
        [$hours, $minutes, $seconds] = array_map('intval', explode(':', $time) + [0, 0, 0]);

        return $hours * 3600 + $minutes * 60 + $seconds;
    }
}
